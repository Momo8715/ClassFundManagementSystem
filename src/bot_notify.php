<?php
/**
 * 班级班费管理系统 - 机器人通知出站队列（v1.18）
 *
 * 设计：
 *  - 业务事件先写 bot_notifications（插件轮询取走），保证事务/支付回调不被外部推送阻塞；
 *  - 请求结束（或 cron）再尝试按已配置的群机器人做一次直接推送，push_status 单独记录；
 *  - 插件 ack 只改 status，不影响 push_status；插件可用 push_status 判断站点是否已代推。
 */

function botPushEnabled(): bool {
    return getMeta('bot_push_enabled', '1') !== '0';
}

function botNotifyMoney(float $n): string {
    return '¥' . number_format($n, 2, '.', '');
}

/**
 * 写入一条通知；dedupe_key 唯一，重复事件返回已有 id。
 * @return int 通知 id，失败返回 0
 */
function botNotifyCreate(
    string $event,
    string $title,
    string $content,
    array $payload = [],
    string $dedupe = '',
    int $studentId = 0,
    string $studentName = '',
    string $scope = 'group'
): int {
    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) $json = '{}';
        $stmt = db()->prepare("INSERT IGNORE INTO bot_notifications
            (event, dedupe_key, student_id, student_name, title, content, payload, scope, status, push_status)
            VALUES (:e, :d, :sid, :sn, :t, :c, :p, :sc, 'pending', 'pending')");
        $stmt->execute([
            ':e' => mb_substr($event, 0, 50),
            ':d' => $dedupe !== '' ? mb_substr($dedupe, 0, 191) : null,
            ':sid' => $studentId,
            ':sn' => $studentName !== '' ? mb_substr($studentName, 0, 50) : null,
            ':t' => mb_substr($title, 0, 191),
            ':c' => mb_substr($content, 0, 8000),
            ':p' => $json,
            ':sc' => in_array($scope, ['group', 'plugin', 'all'], true) ? $scope : 'group',
        ]);
        if ($stmt->rowCount() > 0) {
            $id = (int)db()->lastInsertId();
            botScheduleDispatch();
            return $id;
        }
        if ($dedupe !== '') {
            $q = db()->prepare("SELECT id FROM bot_notifications WHERE dedupe_key=:d LIMIT 1");
            $q->execute([':d' => mb_substr($dedupe, 0, 191)]);
            return (int)$q->fetchColumn();
        }
    } catch (\Throwable $e) {
        error_log('[班费系统] botNotifyCreate 失败: ' . $e->getMessage());
    }
    return 0;
}

/** 请求结束尝试直接推送（不改变插件 ack 状态） */
function botScheduleDispatch(): void {
    static $scheduled = false;
    if ($scheduled || !function_exists('reminderConfig')) return;
    if (!botPushEnabled()) return;
    $scheduled = true;
    register_shutdown_function(function () {
        @ignore_user_abort(true);
        botDispatchPending(10);
    });
}

/**
 * 推送 push_status=pending 的通知。
 * @return array{ok:bool,skipped?:bool,sent?:int,failed?:int,reason?:string}
 */
function botDispatchPending(int $limit = 10): array {
    if (!botPushEnabled()) return ['ok' => false, 'skipped' => true, 'reason' => '已关闭站点代推'];
    if (!function_exists('reminderConfig')) return ['ok' => false, 'skipped' => true, 'reason' => '通知模块未加载'];
    try {
        $c = reminderConfig();
        $send = null;
        $channel = '';
        if (($c['type'] ?? '') === 'qq' && !empty($c['qq_appid']) && !empty($c['qq_secret'])
            && !empty($c['qq_targets']) && function_exists('reminderQqSend')) {
            $channel = 'qq';
            $send = function (string $text) use ($c) { return reminderQqSend($c, $text); };
        } elseif (!empty($c['webhook']) && function_exists('reminderWebhookPost')) {
            $channel = (string)$c['type'];
            $send = function (string $text) use ($c) { return reminderWebhookPost((string)$c['webhook'], reminderWebhookPayload((string)$c['type'], $text)); };
        }
        if ($send === null) return ['ok' => false, 'skipped' => true, 'reason' => '未配置可用的群机器人'];

        $limit = max(1, min(50, $limit));
        $stmt = db()->prepare("SELECT id, content FROM bot_notifications
            WHERE push_status='pending' AND scope IN ('group','all') ORDER BY id ASC LIMIT :l");
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $sent = 0; $failed = 0; $lastErr = '';
        $upd = db()->prepare("UPDATE bot_notifications SET push_status=:ps, attempts=attempts+1,
            pushed_at=NOW(), last_error=:err WHERE id=:id");
        foreach ($rows as $r) {
            $res = $send((string)$r['content']);
            if (!empty($res['ok'])) {
                $upd->execute([':ps' => 'sent', ':err' => null, ':id' => (int)$r['id']]);
                $sent++;
            } else {
                $lastErr = mb_substr((string)($res['resp'] ?? $res['error'] ?? '推送失败'), 0, 500);
                $upd->execute([':ps' => 'failed', ':err' => $lastErr, ':id' => (int)$r['id']]);
                $failed++;
            }
        }
        return ['ok' => $failed === 0, 'sent' => $sent, 'failed' => $failed, 'channel' => $channel, 'error' => $lastErr];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** 支付成功通知（订单已变为 paid 后调用；按订单号去重） */
function botNotifyPaymentSuccess(array $order): int {
    $orderNo = (string)($order['order_no'] ?? '');
    if ($orderNo === '') return 0;
    $sid = (int)($order['student_id'] ?? 0);
    $name = (string)($order['student_name'] ?? '');
    if ($name === '') $name = $sid > 0 ? ('学生#' . $sid) : '未知学生';
    $amount = (float)($order['amount'] ?? 0);
    $channel = (string)($order['channel'] ?? '');
    $paidAt = (string)($order['paid_at'] ?? date('Y-m-d H:i:s'));
    $title = '班费到账';
    $content = "【班费到账】\n" . $name . ' 已缴纳 ' . botNotifyMoney($amount)
        . "\n订单：{$orderNo}" . ($channel !== '' ? "\n方式：{$channel}" : '')
        . "\n时间：{$paidAt}"
        . (function_exists('payBaseUrl') && $sid > 0 ? "\n查看：\n" . payBaseUrl() . '/?pay=' . $sid . '#page-pay' : '');
    return botNotifyCreate('payment_success', $title, $content, [
        'order_no' => $orderNo, 'student_id' => $sid, 'student_name' => $name,
        'amount' => round($amount, 2), 'channel' => $channel, 'trade_no' => (string)($order['trade_no'] ?? ''),
        'pay_url' => function_exists('payBaseUrl') && $sid > 0 ? payBaseUrl() . '/?pay=' . $sid . '#page-pay' : '',
    ], 'payment:' . $orderNo, $sid, $name);
}

/**
 * 金额/类型变动通知。
 * @param string $action create|update|delete
 * @param array $before 旧记录（create 时为空）
 * @param array $after  新记录（delete 时为空）
 */
function botNotifyAmountChange(string $action, array $before, array $after, string $actor = ''): int {
    $b = $before ?: $after;
    $a = $after ?: $before;
    $txId = (int)($a['id'] ?? $b['id'] ?? 0);
    $oldAmount = isset($before['amount']) ? (float)$before['amount'] : null;
    $newAmount = isset($after['amount']) ? (float)$after['amount'] : null;
    $type = (string)($a['type'] ?? $b['type'] ?? '');
    $desc = (string)($a['description'] ?? $b['description'] ?? '');
    $date = (string)($a['date'] ?? $b['date'] ?? '');
    if ($action === 'update' && $oldAmount !== null && $newAmount !== null
        && abs($oldAmount - $newAmount) < 0.00001
        && (string)($before['type'] ?? '') === (string)($after['type'] ?? '')) {
        return 0; // 金额与收支方向都没变，不推送
    }
    if ($newAmount === null && $oldAmount === null) return 0;
    $amt = $newAmount !== null ? $newAmount : (float)$oldAmount;
    if ($action === 'create' && abs($amt) < 0.005) return 0;
    $label = $type === 'expense' ? '支出' : ($type === 'income' ? '收入' : '账目');
    $actionLabel = ['create' => '新增', 'update' => '修改', 'delete' => '删除'][$action] ?? '变动';
    $title = '账目变动';
    $lines = ['【账目变动】' . $actionLabel . $label . ' ' . botNotifyMoney($amt)];
    if ($action === 'update' && $oldAmount !== null && $newAmount !== null && abs($oldAmount - $newAmount) >= 0.00001) {
        $lines[] = '金额：' . botNotifyMoney($oldAmount) . ' → ' . botNotifyMoney($newAmount);
    }
    $lines[] = ($date !== '' ? $date . ' ' : '') . $desc;
    if ($actor !== '') $lines[] = '操作人：' . $actor;
    $content = implode("\n", $lines);
    $payload = [
        'action' => $action, 'tx_id' => $txId, 'type' => $type, 'description' => $desc, 'date' => $date,
        'before_amount' => $oldAmount, 'after_amount' => $newAmount, 'actor' => $actor,
    ];
    $hash = sha1(json_encode([$action, $oldAmount, $newAmount, $type, $date, $desc], JSON_UNESCAPED_UNICODE));
    $dedupe = $txId > 0 ? ('tx:' . $txId . ':' . $action . ':' . substr($hash, 0, 16)) : '';
    return botNotifyCreate('amount_change', $title, $content, $payload, $dedupe, 0, '', 'group');
}

/** 插件自定义通知（notify 接口） */
function botNotifyPlugin(string $title, string $content, array $payload = []): int {
    $title = trim($title) !== '' ? $title : '机器人通知';
    return botNotifyCreate('plugin_notify', $title, $content, $payload, '', 0, '', 'group');
}

/** 清理已送达/已推送的旧通知（cron 用） */
function botPruneNotifications(int $days = 30): int {
    try {
        $days = max(1, min(365, $days));
        $stmt = db()->prepare("DELETE FROM bot_notifications
            WHERE created_at < (NOW() - INTERVAL :d DAY)
              AND status IN ('sent','failed') AND push_status IN ('sent','failed','skipped')
            LIMIT 2000");
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$stmt->rowCount();
    } catch (\Throwable $e) { return 0; }
}


// ==================== 管理端：通知队列 / 重试 / Token ====================
function handleBotNotifications(): void {
    requirePermission('viewSecurity');
    $rows = db()->query("SELECT id, event, student_id, student_name, title, content, payload, scope,
        status, push_status, attempts, last_error, created_at, acked_at, pushed_at
        FROM bot_notifications ORDER BY id DESC LIMIT 50")->fetchAll();
    foreach ($rows as &$r) {
        $r['payload'] = json_decode((string)($r['payload'] ?? ''), true) ?: [];
        $r['id'] = (int)$r['id'];
    }
    unset($r);
    $sum = db()->query("SELECT
        SUM(status='pending') AS plugin_pending,
        SUM(push_status='pending') AS push_pending,
        SUM(push_status='failed') AS push_failed,
        COUNT(*) AS total
        FROM bot_notifications")->fetch() ?: [];
    jsonOutput(['items' => $rows, 'summary' => [
        'plugin_pending' => (int)($sum['plugin_pending'] ?? 0),
        'push_pending' => (int)($sum['push_pending'] ?? 0),
        'push_failed' => (int)($sum['push_failed'] ?? 0),
        'total' => (int)($sum['total'] ?? 0),
    ], 'push_enabled' => botPushEnabled()]);
}

function handleBotNotificationRetry(): void {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        db()->prepare("UPDATE bot_notifications SET push_status='pending', attempts=0, last_error=NULL WHERE id=:id")
            ->execute([':id' => $id]);
    } else {
        db()->exec("UPDATE bot_notifications SET push_status='pending', attempts=0, last_error=NULL
            WHERE push_status IN ('failed','skipped') LIMIT 50");
    }
    $res = botDispatchPending(20);
    $u = currentUser();
    addLog((int)$u['id'], (string)$u['username'], 'bot_notify_retry', 'system', $id ?: null, $res);
    jsonOutput(['ok' => true, 'result' => $res]);
}

function handleBotTokenRotate(): void {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $token = botApiRotateToken();
    $u = currentUser();
    addLog((int)$u['id'], (string)$u['username'], 'bot_token_rotate', 'system', null, []);
    jsonOutput(['ok' => true, 'token' => $token]);
}

function handleBotPushToggle(): void {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $input = jsonInput();
    $enabled = !empty($input['enabled']) && $input['enabled'] !== '0' && $input['enabled'] !== 'false';
    setMeta('bot_push_enabled', $enabled ? '1' : '0');
    $u = currentUser();
    addLog((int)$u['id'], (string)$u['username'], 'bot_push_toggle', 'system', null, ['enabled' => $enabled]);
    jsonOutput(['ok' => true, 'push_enabled' => $enabled]);
}
