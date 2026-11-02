<?php
/**
 * 班级班费管理系统 - 机器人插件 API（v1.18）
 *
 * 入口：/?bot_api=1&action=...&token=...（也支持 X-Bot-Token 请求头）
 * 绑定关系存 bot_bindings；通知存 bot_notifications，由插件 poll/ack。
 */
require_once __DIR__ . '/fee_ledger.php';
require_once __DIR__ . '/pay_core.php';
require_once __DIR__ . '/pay.php';
require_once __DIR__ . '/bot_notify.php';

function botApiOut(array $data): void {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function botApiErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 鉴权：X-Bot-Token 或 ?token=；失败记录安全日志 */
function botApiAuth(): void {
    $given = trim((string)($_SERVER['HTTP_X_BOT_TOKEN'] ?? ($_GET['token'] ?? '')));
    $expect = botApiToken();
    if ($given === '' || $expect === '' || !hash_equals($expect, $given)) {
        if (function_exists('securityLog')) securityLog('bot_api_unauthorized', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        botApiErr('unauthorized', 401);
    }
}

function botApiChannel(): string {
    $c = strtolower(trim((string)($_GET['channel'] ?? ($_POST['channel'] ?? 'wechat'))));
    $c = preg_replace('/[^a-z0-9_\-]/', '', $c);
    return $c !== '' ? mb_substr($c, 0, 20) : 'wechat';
}

function botApiUser(): string {
    $u = trim((string)($_GET['user'] ?? ($_POST['user'] ?? ($_GET['remote_user'] ?? ($_GET['remote_id'] ?? ($_GET['openid'] ?? ($_GET['user_id'] ?? '')))))));
    if ($u === '') botApiErr('缺少 user（远端用户唯一标识）');
    return mb_substr($u, 0, 191);
}

/** 迁移旧 bot_bindings.json（仅在首次读取 wechat 绑定时执行一次） */
function botMigrateLegacyBindings(string $channel): void {
    if ($channel !== 'wechat') return;
    $file = dirname(__DIR__) . '/bot_bindings.json';
    if (!is_file($file)) return;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return;
    $ins = db()->prepare("INSERT IGNORE INTO bot_bindings (channel, remote_user, student_id, name)
        VALUES ('wechat', :u, :sid, :n)");
    foreach ($data as $user => $v) {
        $name = trim((string)($v['name'] ?? ''));
        if ($name === '') continue;
        $st = db()->prepare("SELECT id FROM class_roster WHERE name=:n LIMIT 1");
        $st->execute([':n' => $name]);
        $sid = (int)$st->fetchColumn();
        if ($sid > 0) $ins->execute([':u' => mb_substr((string)$user, 0, 191), ':sid' => $sid, ':n' => $name]);
    }
}

function botBindingGet(string $channel, string $user): ?array {
    try {
        $st = db()->prepare("SELECT b.channel, b.remote_user, b.student_id, b.name, b.created_at, b.updated_at, r.exempt
            FROM bot_bindings b LEFT JOIN class_roster r ON r.id=b.student_id
            WHERE b.channel=:c AND b.remote_user=:u LIMIT 1");
        $st->execute([':c' => $channel, ':u' => $user]);
        $row = $st->fetch();
        if ($row) return $row;
        botMigrateLegacyBindings($channel);
        $st->execute([':c' => $channel, ':u' => $user]);
        return $st->fetch() ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function botBindingSet(string $channel, string $user, int $studentId, string $name): array {
    db()->prepare("INSERT INTO bot_bindings (channel, remote_user, student_id, name)
        VALUES (:c, :u, :s, :n)
        ON DUPLICATE KEY UPDATE student_id=VALUES(student_id), name=VALUES(name), updated_at=NOW()")
        ->execute([':c' => $channel, ':u' => $user, ':s' => $studentId, ':n' => $name]);
    return ['channel' => $channel, 'user' => $user, 'student_id' => $studentId, 'name' => $name];
}

function botStudentByName(string $name): ?array {
    $st = db()->prepare("SELECT id, name, exempt FROM class_roster WHERE name=:n LIMIT 1");
    $st->execute([':n' => $name]);
    $row = $st->fetch();
    return $row ?: null;
}

/** 插件缴费：为已绑定学生创建订单，返回支付链接/二维码 */
function botCreatePayOrder(int $studentId, int $roundId, string $type, string $channelId): array {
    if (!payConfigured()) return ['ok' => false, 'http' => 400, 'error' => '支付通道未配置或未开启'];
    $cfg = getPayConfig();
    if ($type === '') $type = (string)$cfg['default_type'];
    $channel = payPickChannel($type, $channelId);
    if (!$channel) return ['ok' => false, 'http' => 400, 'error' => '所选支付方式当前不可用'];
    $st = db()->prepare("SELECT id, name FROM class_roster WHERE id=:id");
    $st->execute([':id' => $studentId]);
    $srow = $st->fetch();
    if (!$srow) return ['ok' => false, 'http' => 404, 'error' => '学生不存在'];
    if ($roundId > 0) {
        $rt = db()->prepare("SELECT id, amount, expected_amount, per_person, payer_ids FROM transactions WHERE id=:id AND deleted_at IS NULL");
        $rt->execute([':id' => $roundId]);
        $rtx = $rt->fetch();
        if (!$rtx) return ['ok' => false, 'http' => 404, 'error' => '缴费轮次不存在'];
        $amount = payRoundPerPerson($rtx);
    } else {
        $amount = payStudentStatus($studentId)['outstanding'];
    }
    if ($amount <= 0) return ['ok' => false, 'http' => 400, 'error' => '该学生当前已缴清或轮次无金额'];
    if ($amount < (float)$cfg['min_amount'] || $amount > (float)$cfg['max_amount']) return ['ok' => false, 'http' => 400, 'error' => '金额超出允许范围'];
    $orderNo = 'CFB' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    db()->prepare("INSERT INTO payment_orders
        (order_no, student_id, student_name, round_id, amount, channel, channel_id, status, settle_mode, created_by)
        VALUES (:o, :sid, :sn, :rid, :a, :ch, :cid, 'pending', :sm, 0)")
        ->execute([
            ':o' => $orderNo, ':sid' => $studentId, ':sn' => $srow['name'],
            ':rid' => $roundId, ':a' => $amount, ':ch' => $type, ':cid' => $channel['id'],
            ':sm' => $cfg['settle_mode'],
        ]);
    $name = '班费缴费-' . $srow['name'];
    $res = payCreateOrder(['order_no' => $orderNo, 'channel' => $type, 'amount' => $amount, 'name' => $name], $channel);
    if (empty($res['ok'])) {
        db()->prepare("UPDATE payment_orders SET status='closed' WHERE order_no=:o")->execute([':o' => $orderNo]);
        return ['ok' => false, 'http' => 502, 'error' => '下单失败：' . ($res['error'] ?? '未知错误')];
    }
    db()->prepare("UPDATE payment_orders SET pay_url=:u WHERE order_no=:o")
        ->execute([':u' => mb_substr((string)$res['payurl'], 0, 1000), ':o' => $orderNo]);
    return ['ok' => true, 'order_no' => $orderNo, 'amount' => $amount, 'channel' => $type,
        'channel_id' => $channel['id'], 'payurl' => $res['payurl'], 'qrcode' => $res['qrcode'] ?? ''];
}

function botPollNotifications(): void {
    $after = max(0, (int)($_GET['after_id'] ?? ($_GET['after'] ?? ($_GET['cursor'] ?? 0))));
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $status = (string)($_GET['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'sent', 'failed', 'all'], true)) $status = 'pending';
    $sql = "SELECT id, event, student_id, student_name, title, content, payload, scope, status, push_status,
        attempts, last_error, created_at, acked_at, pushed_at
        FROM bot_notifications WHERE id > :after";
    if ($status !== 'all') $sql .= " AND status=:st";
    $sql .= " ORDER BY id ASC LIMIT :l";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':after', $after, PDO::PARAM_INT);
    if ($status !== 'all') $stmt->bindValue(':st', $status);
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();
    foreach ($items as &$it) {
        $it['id'] = (int)$it['id'];
        $it['student_id'] = (int)$it['student_id'];
        $it['attempts'] = (int)$it['attempts'];
        $it['payload'] = json_decode((string)($it['payload'] ?? ''), true) ?: [];
    }
    unset($it);
    $next = $items ? (int)$items[count($items) - 1]['id'] : $after;
    botApiOut(['items' => $items, 'next_id' => $next, 'has_more' => count($items) >= $limit, 'server_time' => date('c')]);
}

function botAckNotifications(): void {
    $raw = $_GET['ids'] ?? ($_GET['notification_ids'] ?? ($_POST['ids'] ?? ($_POST['notification_ids'] ?? '')));
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', $raw);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$raw), function ($v) { return $v > 0; })));
    if (empty($ids)) botApiErr('缺少 ids');
    $ids = array_slice($ids, 0, 200);
    $status = (string)($_GET['ack_status'] ?? ($_POST['ack_status'] ?? 'sent'));
    if (!in_array($status, ['sent', 'failed'], true)) $status = 'sent';
    $err = mb_substr((string)($_GET['error'] ?? ($_POST['error'] ?? '')), 0, 500);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("UPDATE bot_notifications SET status=?, acked_at=NOW(), last_error=? WHERE id IN ($ph) AND status='pending'");
    $params = array_merge([$status, $err ?: null], $ids);
    $stmt->execute($params);
    botApiOut(['updated' => $stmt->rowCount(), 'status' => $status]);
}

function handleBotApi(): void {
    // 入口由 index.php 保证已安装并完成 autoMigrate
    if (function_exists('autoMigrate')) autoMigrate();
    // 兼容插件 POST JSON（GET 参数仍优先）
    if (empty($_POST)) {
        $raw = (string)file_get_contents('php://input');
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) $_POST = $json;
        }
    }
    botApiAuth();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $action = (string)($_GET['action'] ?? 'ping');
    try {
        switch ($action) {
            case 'ping':
                botApiOut(['pong' => true, 'time' => date('c'), 'version' => '1.18.0', 'api' => 'bot_v1']);

            case 'roster':
                $names = [];
                foreach (feeRoster() as $s) $names[] = $s['name'];
                botApiOut(['names' => $names, 'count' => count($names)]);

            case 'status':
                $name = trim((string)($_GET['name'] ?? ''));
                if ($name === '') botApiErr('缺少 name');
                $hit = null;
                foreach (feeRoster() as $s) if ((string)$s['name'] === $name) { $hit = $s; break; }
                if ($hit === null) botApiErr('花名册中没有「' . $name . '」', 404);
                $all = feeAllStatuses();
                $sid = (int)$hit['id'];
                $st = $all[$sid] ?? null;
                if ($st === null) botApiErr('未找到该学生的缴费数据', 404);
                $unpaidRounds = [];
                foreach (feeRounds(true) as $r) {
                    foreach ($r['unpaid_list'] as $u) if ((int)$u['id'] === $sid) {
                        $unpaidRounds[] = ['date' => (string)$r['date'], 'description' => (string)$r['description'], 'per_person' => (float)$r['per_person']];
                        break;
                    }
                }
                botApiOut(['name' => (string)$hit['name'], 'exempt' => !empty($hit['exempt']),
                    'due' => (float)$st['due'], 'paid' => (float)$st['paid'], 'outstanding' => (float)$st['outstanding'],
                    'unpaid_rounds' => $unpaidRounds]);

            case 'unpaid':
                $limit = max(1, min(300, (int)($_GET['limit'] ?? 100)));
                $rows = [];
                foreach (feeAllStatuses() as $st) {
                    $out = (float)($st['outstanding'] ?? 0);
                    if ($out > 0.001) $rows[] = ['name' => (string)$st['name'], 'outstanding' => $out,
                        'due' => (float)$st['due'], 'paid' => (float)$st['paid']];
                }
                usort($rows, static fn($a, $b) => $b['outstanding'] <=> $a['outstanding']);
                botApiOut(['count' => count($rows), 'items' => array_slice($rows, 0, $limit)]);

            case 'summary':
                $all = feeAllStatuses();
                $due = $paid = $outstanding = 0.0; $unpaidCount = 0; $exemptCount = 0;
                foreach ($all as $st) {
                    if (!empty($st['exempt'])) { $exemptCount++; continue; }
                    $due += (float)$st['due']; $paid += (float)$st['paid']; $outstanding += (float)$st['outstanding'];
                    if ((float)$st['outstanding'] > 0.001) $unpaidCount++;
                }
                botApiOut(['students' => count($all), 'exempt' => $exemptCount,
                    'due' => round($due, 2), 'paid' => round($paid, 2), 'outstanding' => round($outstanding, 2),
                    'unpaid_count' => $unpaidCount, 'rounds' => count(feeRounds(false)), 'generated_at' => date('c')]);

            case 'bind':
                $channel = botApiChannel();
                $user = botApiUser();
                $name = trim((string)($_GET['name'] ?? ($_POST['name'] ?? '')));
                if ($name === '') botApiErr('缺少 name');
                $hit = botStudentByName($name);
                if ($hit === null) botApiErr('花名册中没有「' . $name . '」', 404);
                $b = botBindingSet($channel, $user, (int)$hit['id'], (string)$hit['name']);
                botApiOut($b + ['ok' => true]);

            case 'whoami':
                $channel = botApiChannel();
                $user = botApiUser();
                $b = botBindingGet($channel, $user);
                if (!$b) botApiOut(['bound' => false, 'channel' => $channel, 'user' => $user]);
                $st = feeAllStatuses()[(int)$b['student_id']] ?? null;
                botApiOut(['bound' => true, 'channel' => $channel, 'user' => $user,
                    'student_id' => (int)$b['student_id'], 'name' => (string)$b['name'],
                    'outstanding' => $st ? (float)$st['outstanding'] : 0]);

            case 'paylink':
            case 'pay_link':
            case 'link':
                $channel = botApiChannel();
                $user = botApiUser();
                $b = botBindingGet($channel, $user);
                if (!$b) botApiErr('该用户尚未绑定姓名', 404);
                $sid = (int)$b['student_id'];
                $st = feeAllStatuses()[$sid] ?? null;
                if ($st === null) botApiErr('未找到缴费数据', 404);
                botApiOut(['name' => (string)$b['name'], 'student_id' => $sid,
                    'outstanding' => (float)$st['outstanding'],
                    'url' => payBaseUrl() . '/?pay=' . $sid . '#page-pay']);

            case 'pay':
            case 'pay_create':
                $channel = botApiChannel();
                $user = botApiUser();
                $b = botBindingGet($channel, $user);
                if (!$b) botApiErr('该用户尚未绑定姓名', 404);
                $roundId = (int)($_GET['round_id'] ?? ($_POST['round_id'] ?? 0));
                $type = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['channel_type'] ?? ($_POST['channel_type'] ?? ($_GET['type'] ?? ($_POST['type'] ?? '')))));
                $cid = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['channel_id'] ?? ($_POST['channel_id'] ?? '')));
                $res = botCreatePayOrder((int)$b['student_id'], $roundId, $type, $cid);
                if (empty($res['ok'])) botApiErr((string)$res['error'], (int)($res['http'] ?? 400));
                botApiOut($res);

            case 'poll':
            case 'notifications':
                botPollNotifications();

            case 'ack':
            case 'ack_notifications':
                botAckNotifications();

            case 'notify':
                $content = trim((string)($_GET['content'] ?? ($_GET['text'] ?? ($_POST['content'] ?? ($_POST['text'] ?? '')))));
                if ($content === '') botApiErr('缺少 content');
                $title = trim((string)($_GET['title'] ?? ($_POST['title'] ?? '机器人通知')));
                $id = botNotifyPlugin($title, $content, ['source' => 'plugin']);
                if ($id <= 0) botApiErr('写入通知失败', 500);
                botApiOut(['id' => $id, 'queued' => true]);

            case 'help':
            case 'actions':
                botApiOut(['actions' => ['ping', 'roster', 'status', 'unpaid', 'summary', 'bind', 'whoami', 'paylink', 'pay', 'poll', 'ack', 'notify'], 'version' => '1.18.0']);

            default:
                botApiErr('未知 action', 404);
        }
    } catch (\Throwable $e) {
        error_log('[bot_api] ' . $e->getMessage());
        botApiErr('服务器内部错误', 500);
    }
}
