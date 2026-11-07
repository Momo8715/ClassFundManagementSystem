<?php
/**
 * 班级班费管理系统 - QQ 官方机器人（Webhook / 指令 / 网关托管）
 */
require_once __DIR__ . '/pay_core.php';
require_once __DIR__ . '/pay_reminder.php';


/**
 * QQ 网关客户端目录（常驻 WebSocket，保持机器人在线；QQ 官方要求在线才能发消息）
 * 解析顺序：安装脚本写入的 .qqgw_dir → 环境变量 QQGW_DIR → 默认路径
 */
function qqGatewayDir(): string {
    $dir = '';
    $marker = dirname(__DIR__) . '/.qqgw_dir';
    if (is_file($marker)) $dir = trim((string)@file_get_contents($marker));
    if ($dir === '') { $env = getenv('QQGW_DIR'); if ($env !== false) $dir = trim($env); }
    if ($dir === '') $dir = dirname(__DIR__) . '/.qqgw';
    // 站内点目录：在 open_basedir 允许范围内，且 nginx 已禁止访问点目录（.qqgw 返回 404）
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !@is_writable($dir)) $dir = dirname(__DIR__) . '/.qqgw';
    return rtrim($dir, "/\\");
}

/** 同步网关客户端配置（AppID / AppSecret / 沙箱 / 站点域名） */
function qqGatewayWriteConfig(string $type, string $appid, string $secret, bool $sandbox, string $host = ''): void {
    $dir = qqGatewayDir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir)) return;
    $file = $dir . '/config.json';
    if ($type === 'qq' && $appid !== '' && $secret !== '') {
        $cfg = ['appid' => $appid, 'secret' => $secret, 'sandbox' => $sandbox];
        if ($host !== '') $cfg['host'] = $host;
        @file_put_contents($file, json_encode($cfg, JSON_UNESCAPED_UNICODE));
        @chmod($file, 0640);
    } else {
        @unlink($file);
    }
}

/** PID 是否存活 */
function qqGatewayPidAlive($pid): bool {
    $pid = (int)$pid;
    if ($pid <= 0) return false;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return is_dir('/proc/' . $pid);
}

/** 查找可用的 node */
function qqGatewayNodeBin(): string {
    foreach (['/usr/bin/node', '/usr/local/bin/node', '/opt/node/bin/node'] as $p) {
        if (@is_executable($p)) return $p;
    }
    // FPM 常禁用 shell_exec，先判断再调用，避免致命错误
    if (function_exists('shell_exec')) {
        $w = @trim((string)@shell_exec('command -v node 2>/dev/null'));
        if ($w !== '') return $w;
    }
    return '';
}

/** 读取网关客户端状态（由 qqgw.js 写入） */
function qqGatewayStatus(): array {
    $dir = qqGatewayDir();
    $s = @file_get_contents($dir . '/status.json');
    $j = $s ? json_decode($s, true) : null;
    // 只有 exec 可用时才能可靠探测 node；FPM 禁用 exec 时不做判断，避免误报
    $nodeOk = true;
    if (function_exists('exec')) { $node = qqGatewayNodeBin(); $nodeOk = ($node !== ''); }
    if (!is_array($j)) return ['connected' => false, 'phase' => 'unknown', 'error' => '网关客户端未运行', 'node_ok' => $nodeOk, 'dir' => $dir];
    $j['node_ok'] = $nodeOk;
    $j['dir'] = $dir;
    $pid = (int)($j['pid'] ?? 0);
    $j['running'] = $pid > 0 ? qqGatewayPidAlive($pid) : (!empty($j['connected']));
    // 进程已退出时不要用 status.json 里的残留状态误报「已连接」
    if (empty($j['running'])) {
        $j['connected'] = false;
        if (($j['phase'] ?? '') === 'online') $j['phase'] = 'stopped';
    }
    return $j;
}

/** 确保网关客户端已安装并在运行（应用自动托管，无需单独安装/配置） */
function qqGatewayEnsureRunning(): array {
    $dir = qqGatewayDir();
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) return ['ok' => false, 'error' => '网关目录不可写：' . $dir];

    // 自动同步脚本（从项目 tools/qqgw/qqgw.js 复制最新）
    $src = dirname(__DIR__) . '/tools/qqgw/qqgw.js';
    $dst = $dir . '/qqgw.js';
    if (is_file($src)) {
        if (!is_file($dst) || @filesize($src) !== @filesize($dst) || @filemtime($src) > @filemtime($dst)) @copy($src, $dst);
    }
    if (!is_file($dst)) return ['ok' => false, 'error' => '缺少 qqgw.js（请确认 tools/qqgw/qqgw.js 存在）'];

    $pidFile = $dir . '/gateway.pid';
    if (is_file($pidFile) && qqGatewayPidAlive((int)trim((string)@file_get_contents($pidFile)))) {
        return ['ok' => true, 'running' => true, 'already' => true];
    }
    // FPM 常禁用 exec，此时应用无法自行拉起，交由系统常驻（install.sh / systemd）负责
    if (!function_exists('exec')) {
        return ['ok' => false, 'error' => 'PHP 已禁用 exec，无法由应用自动启动；请用 tools/qqgw/install.sh 配置系统常驻'];
    }
    $node = qqGatewayNodeBin();
    if ($node === '' || !@is_executable($node)) return ['ok' => false, 'error' => '未找到 node（需 Node.js 18+）'];

    $log = $dir . '/qqgw.out';
    // setsid -f 强制 fork 到新会话，彻底脱离当前请求/进程组，避免请求结束后被回收
    @exec('setsid -f ' . escapeshellarg($node) . ' ' . escapeshellarg($dst) . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null');
    return ['ok' => true, 'running' => true, 'spawned' => true];
}

/** QQ 指令定义（网站指令面板与机器人菜单共用） */
/** 生成管理员绑定码（网页端调用），10 分钟有效、一次性 */
function qqAdminCodeNew(int $uid, string $name): string {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    setMeta('qq_admin_code', json_encode(['code' => $code, 'uid' => $uid, 'name' => $name, 'exp' => time() + 600], JSON_UNESCAPED_UNICODE));
    return $code;
}

/** 校验管理员绑定码（校验通过即作废） */
function qqAdminCodeCheck(string $code): ?array {
    $j = json_decode(getMeta('qq_admin_code', ''), true);
    if (!is_array($j) || (string)($j['code'] ?? '') === '' || (int)($j['exp'] ?? 0) < time()) return null;
    if (!hash_equals((string)$j['code'], $code)) return null;
    setMeta('qq_admin_code', '');
    return $j;
}

/** 当前 QQ 绑定是否为管理员 */
function qqIsAdmin(string $key): bool {
    try {
        $st = db()->prepare("SELECT role FROM qq_bindings WHERE bind_key=:k AND role='admin' LIMIT 1");
        $st->execute([':k' => 'admin:' . $key]);      // 管理员用独立键，可与学生身份并存
        if ((string)$st->fetchColumn() === 'admin') return true;
        $st->execute([':k' => $key]);                  // 兼容早期直接覆盖的绑定
        return ((string)$st->fetchColumn()) === 'admin';
    } catch (\Exception $e) { return false; }
}

/** 查某学生在某群的 member_openid（来自其绑定记录，用于群内 @ 催缴） */
function qqMemberOpenid(string $groupOpenid, int $studentId): string {
    try {
        $st = db()->prepare("SELECT openid FROM qq_bindings WHERE scope='group' AND target=:t AND student_id=:s AND openid<>'' LIMIT 1");
        $st->execute([':t' => $groupOpenid, ':s' => $studentId]);
        return (string)$st->fetchColumn();
    } catch (\Exception $e) { return ''; }
}

/** 查某学生最近一次单聊（C2C）的 user_openid，用于主动私信催缴 */
function qqUserOpenid(int $studentId): string {
    try {
        // 兼容 v1.15 之前的旧绑定：那时只存 bind_key（c2c:<openid>:<openid>），没有 scope/openid 列
        $st = db()->prepare("SELECT bind_key, openid FROM qq_bindings WHERE student_id=:s AND (scope='c2c' OR bind_key LIKE 'c2c:%') ORDER BY id DESC LIMIT 1");
        $st->execute([':s' => $studentId]);
        $row = $st->fetch();
        if (!$row) return '';
        if ((string)($row['openid'] ?? '') !== '') return (string)$row['openid'];
        $p = explode(':', (string)($row['bind_key'] ?? ''));
        return (string)($p[1] ?? '');
    } catch (\Exception $e) { return ''; }
}

/** 构造催缴文案：群聊时对已知 member_openid 的未缴同学 @ 出来 */
function qqBuildUrge(array $c, string $scope, string $target, string $extra = ''): array {
    $un = reminderUnpaidList();
    $lines = ['【班费催缴】'];
    if ($extra !== '') $lines[] = $extra;
    if (!$un) { $lines[] = '🎉 全班已全部缴清，无需催缴。'; return [implode("\n", $lines), 0]; }
    $sum = 0.0; $mentioned = 0;
    foreach ($un as $u) {
        $sum += (float)$u['outstanding'];
        $line = $u['name'] . ' ¥' . number_format((float)$u['outstanding'], 2, '.', '');
        if ($scope === 'group') {
            $mo = qqMemberOpenid($target, (int)$u['id']);
            if ($mo !== '') { $line = '<qqbot-at-user id="' . $mo . '" /> ' . $line; $mentioned++; }
        }
        $lines[] = $line;
    }
    $lines[] = '合计待缴 ¥' . number_format($sum, 2, '.', '') . '，请尽快缴纳，谢谢配合～';
    return [implode("\n", $lines), $mentioned];
}

/** 管理员绑定码生成（网页端，仅管理员） */
function handleQqAdminCode() {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $u = currentUser();
    $code = qqAdminCodeNew((int)$u['id'], (string)$u['username']);
    jsonOutput(['ok' => true, 'code' => $code, 'expires_in' => 600]);
}

function qqCommands(): array {
    return [
        ['key' => 'help',     'cmd' => '帮助 / 菜单',       'desc' => '显示功能菜单',                'always' => true],
        ['key' => 'bind',     'cmd' => '绑定 姓名',         'desc' => '绑定花名册学生',              'always' => false],
        ['key' => 'unbind',   'cmd' => '解绑',              'desc' => '解除当前绑定',                'always' => false],
        ['key' => 'query',    'cmd' => '查班费 / 我的班费', 'desc' => '应缴 / 已缴 / 待缴 + 缴费链接', 'always' => false],
        ['key' => 'detail',   'cmd' => '我的明细',          'desc' => '逐轮缴费情况',                'always' => false],
        ['key' => 'link',     'cmd' => '我的链接',          'desc' => '专属在线缴费链接',            'always' => false],
        ['key' => 'pay',      'cmd' => '缴费 / 交费',       'desc' => '发送本人专属缴费链接',        'always' => false],
        ['key' => 'round',    'cmd' => '本轮班费',          'desc' => '最新一轮班费信息',            'always' => false],
        ['key' => 'overview', 'cmd' => '班级概况 / 结余',   'desc' => '总收入 / 总支出 / 结余',      'always' => false],
        ['key' => 'progress', 'cmd' => '收缴进度',          'desc' => '已缴 / 未缴人数与收缴率',     'always' => false],
        ['key' => 'recent',   'cmd' => '最近收支',          'desc' => '最近 10 笔收支记录',          'always' => false],
        ['key' => 'unpaid',   'cmd' => '未缴名单',          'desc' => '未缴清学生与金额（管理员）',  'always' => false],
        ['key' => 'urge',     'cmd' => '催缴 / 催缴提醒',   'desc' => '生成催缴名单，群内 @ 未缴同学（管理员）', 'always' => false],
        ['key' => 'urge_dm',  'cmd' => '私信催缴',          'desc' => '给有单聊记录的同学发催缴私信（管理员）', 'always' => false],
        ['key' => 'notify',   'cmd' => '发通知 内容',       'desc' => '推送到已配置的推送目标（管理员）', 'always' => false],
    ];
}

/** 指令是否启用 */
function qqCmdOn(array $cfg, string $key): bool {
    return !in_array($key, $cfg['qq_cmd_disabled'] ?? [], true);
}

/** 单聊是否允许该用户：qq_c2c_open=1（默认）不限制；否则仅允许「QQ 用户（单聊）」目标中的 openid */
function qqC2cAllowed(array $c, string $openid): bool {
    if (!empty($c['qq_c2c_open'])) return true;
    foreach (($c['qq_targets'] ?? []) as $t) {
        if (($t['scope'] ?? '') === 'c2c' && (string)($t['id'] ?? '') === $openid) return true;
    }
    return false;
}

/** 通用 QQ API POST（群 / 用户 / 频道） */
function qqApiPost(string $token, string $appid, string $url, array $body): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: QQBot " . $token
            . "\r\nX-Union-Appid: " . $appid . "\r\nUser-Agent: ClassFund/1.0\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_UNICODE),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    $ok = ($code >= 200 && $code < 300);
    $json = $resp ? json_decode($resp, true) : null;
    if (is_array($json) && isset($json['code']) && (int)$json['code'] !== 0) $ok = false;
    return ['ok' => $ok, 'http' => $code, 'resp' => mb_substr((string)$resp, 0, 200)];
}

/** 按方向构造 QQ 消息请求：[url, body] */
function qqTargetRequest(array $c, string $scope, string $target, string $text, string $msgId = ''): array {
    $base = !empty($c['qq_sandbox']) ? 'https://sandbox.api.sgroup.qq.com' : 'https://api.sgroup.qq.com';
    $tg = rawurlencode($target);
    if ($scope === 'channel') {
        $body = ['content' => $text];
        if ($msgId !== '') $body['msg_id'] = $msgId;
        return [$base . '/channels/' . $tg . '/messages', $body];
    }
    if ($scope === 'c2c') {
        $body = ['content' => $text, 'msg_type' => 0];
        if ($msgId !== '') { $body['msg_id'] = $msgId; $body['msg_seq'] = 1; }
        return [$base . '/v2/users/' . $tg . '/messages', $body];
    }
    $body = ['content' => $text, 'msg_type' => 0];
    if ($msgId !== '') { $body['msg_id'] = $msgId; $body['msg_seq'] = 1; }
    return [$base . '/v2/groups/' . $tg . '/messages', $body];
}

// ==================== QQ 官方机器人 Webhook（被动回复，v1.12） ====================
/** Ed25519 私钥种子：AppSecret 重复/截断到 32 字节（与官方 webhook 协议一致） */
function qqWebhookSeed(string $secret): string {
    if ($secret === '') return '';
    $seed = $secret;
    while (strlen($seed) < 32) $seed .= $secret;
    return substr($seed, 0, 32);
}

/** Ed25519 签名 -> hex（QQ webhook 协议） */
function qqSign(string $secret, string $message): string {
    $seed = qqWebhookSeed($secret);
    if ($seed === '' || !function_exists('sodium_crypto_sign_seed_keypair')) return '';
    $kp = sodium_crypto_sign_seed_keypair($seed);
    return bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($kp)));
}

/** 校验 QQ Webhook 请求签名（timestamp + body） */
function qqVerifySignature(string $secret, string $timestamp, string $body, string $sigHex): bool {
    if ($timestamp === '' || $sigHex === '') return false;
    $seed = qqWebhookSeed($secret);
    if ($seed === '' || !function_exists('sodium_crypto_sign_verify_detached')) return false;
    $sig = @hex2bin($sigHex);
    if ($sig === false || strlen($sig) !== 64) return false;
    $kp = sodium_crypto_sign_seed_keypair($seed);
    try { return sodium_crypto_sign_verify_detached($sig, $timestamp . $body, sodium_crypto_sign_publickey($kp)); }
    catch (\Exception $e) { return false; }
}

/** 绑定键：群消息按 (群 openid + 成员 openid)，单聊/频道同理 */
function qqBindKey(string $scope, string $target, string $openid): string {
    return $scope . ':' . $target . ':' . $openid;
}

/** QQ 被动回复消息 */
function qqReply(array $c, string $scope, string $target, string $msgId, string $content): array {
    $t = reminderQqToken($c);
    if (!$t['ok']) return ['ok' => false, 'http' => 0, 'resp' => 'token:' . ($t['error'] ?? '')];
    list($url, $body) = qqTargetRequest($c, $scope, $target, $content, $msgId);
    return qqApiPost($t['token'], $c['qq_appid'], $url, $body);
}

/** 读取绑定到的学生（含姓名/免缴标记） */
function qqBindingStudent(string $key): ?array {
    $st = db()->prepare("SELECT b.student_id, r.name, r.exempt FROM qq_bindings b LEFT JOIN class_roster r ON r.id=b.student_id WHERE b.bind_key=:k LIMIT 1");
    $st->execute([':k' => $key]);
    $row = $st->fetch();
    return $row ?: null;
}

/** 某生各轮班费的缴纳情况（已缴/待缴/免缴） */
function payStudentRounds(int $sid): array {
    $eligible = (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
    $rows = db()->query("SELECT id, date, description, amount, expected_amount, per_person, payer_ids, exempt_ids FROM transactions
        WHERE sub_category='班费收缴' AND deleted_at IS NULL ORDER BY date ASC, id ASC")->fetchAll();
    $online = [];
    $st = db()->prepare("SELECT round_id, COALESCE(SUM(amount),0) v FROM fee_payments WHERE student_id=:s GROUP BY round_id");
    $st->execute([':s' => $sid]);
    foreach ($st->fetchAll() as $r) $online[(int)$r['round_id']] = (float)$r['v'];
    $out = [];
    foreach ($rows as $r) {
        $pp = payRoundPerPerson($r, $eligible);
        $exids = array_map('intval', json_decode($r['exempt_ids'] ?? '', true) ?: []);
        $isAll = (($r['payer_ids'] ?? '') === 'all');
        $pids = $isAll ? [] : array_map('intval', json_decode($r['payer_ids'] ?? '', true) ?: []);
        if (in_array($sid, $exids, true)) $status = '免缴';
        elseif ($isAll || in_array($sid, $pids, true) || !empty($online[(int)$r['id']])) $status = '已缴';
        else $status = '待缴';
        $out[] = ['date' => (string)$r['date'], 'desc' => (string)$r['description'], 'per_person' => $pp, 'status' => $status];
    }
    return $out;
}

/** QQ 机器人功能菜单（可在「配置管理 → 群机器人」自定义标题/底部/启停指令） */
function qqMenu(): string {
    $cfg = reminderConfig();
    $intro = trim((string)($cfg['qq_menu_intro'] ?? ''));
    if ($intro === '') $intro = '【班费机器人 · 功能菜单】';
    $footer = trim((string)($cfg['qq_menu_footer'] ?? ''));
    if ($footer === '') $footer = '示例：@机器人 查班费';
    $groups = [
        ['title' => '📌 查询', 'items' => [
            ['key' => 'query',    'text' => '查班费 —— 我的应缴 / 已缴 / 待缴'],
            ['key' => 'detail',   'text' => '我的明细 —— 我每轮班费的缴纳情况'],
            ['key' => 'link',     'text' => '我的链接 —— 我的专属在线缴费链接'],
            ['key' => 'pay',      'text' => '缴费 / 交费 —— 直接发送本人缴费链接'],
            ['key' => 'round',    'text' => '本轮班费 —— 最新一轮班费信息'],
            ['key' => 'overview', 'text' => '班级概况 —— 总收入 / 总支出 / 结余'],
            ['key' => 'progress', 'text' => '收缴进度 —— 已缴 / 未缴人数与收缴率'],
            ['key' => 'recent',   'text' => '最近收支 —— 最近 10 笔收支记录'],
        ]],
        ['title' => '⚙️ 设置', 'items' => [
            ['key' => 'bind',   'text' => '绑定 姓名 —— 绑定身份'],
            ['key' => 'unbind', 'text' => '解绑 —— 解除当前绑定'],
            ['key' => 'help',   'text' => '帮助 / 菜单 —— 显示本菜单'],
        ]],
    ];
    $lines = [$intro, '首次使用请先发送：绑定 姓名（例：绑定 张三）'];
    foreach ($groups as $g) {
        $items = [];
        foreach ($g['items'] as $it) if (qqCmdOn($cfg, $it['key'])) $items[] = '· ' . $it['text'];
        if (empty($items)) continue;
        $lines[] = '';
        $lines[] = $g['title'];
        foreach ($items as $t) $lines[] = $t;
    }
    $custom = $cfg['qq_custom_replies'] ?? [];
    if (!empty($custom)) {
        $lines[] = '';
        $lines[] = '💡 其他';
        foreach ($custom as $cr) {
            $k = trim((string)($cr['k'] ?? ''));
            if ($k !== '') $lines[] = '· ' . $k;
        }
    }
    $lines[] = '';
    $lines[] = $footer;
    return implode("\n", $lines);
}

/** 处理 @机器人 指令并被动回复 */
function qqHandleCommand(array $c, string $scope, string $target, string $openid, string $msgId, string $raw): void {
    $cmd = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    $key = qqBindKey($scope, $target, $openid);
    $noBind = "你还没有绑定身份。\n请发送「绑定 姓名」（例如：绑定 张三），绑定后再查询。";

    // 菜单 / 帮助 / 空消息
    if ($cmd === '' || preg_match('/^(?:帮助|help|菜单|指令|说明|功能|\?|？)$/ui', $cmd)) {
        qqReply($c, $scope, $target, $msgId, qqMenu());
        return;
    }

    // 绑定
    // 注意：(?!管理员) 让学生绑定不要吞掉「绑定管理员 <验证码>」，交给后面的管理员分支处理
    if (qqCmdOn($c, 'bind') && preg_match('/^(?:绑定|bind)[\s:：]*(?!管理员)(.+)$/u', $cmd, $m)) {
        $name = trim($m[1]);
        $st = db()->prepare("SELECT id, name FROM class_roster WHERE name=:n LIMIT 1");
        $st->execute([':n' => $name]);
        $row = $st->fetch();
        if (!$row) { qqReply($c, $scope, $target, $msgId, '未找到学生「' . $name . '」，请核对花名册姓名后重试。'); return; }
        db()->prepare("INSERT INTO qq_bindings (bind_key, student_id, role, scope, target, openid) VALUES (:k, :s, 'student', :sc, :tg, :oid)
            ON DUPLICATE KEY UPDATE student_id=VALUES(student_id), role='student', user_id=0, scope=VALUES(scope), target=VALUES(target), openid=VALUES(openid)")
            ->execute([':k' => $key, ':s' => (int)$row['id'], ':sc' => $scope, ':tg' => $target, ':oid' => $openid]);
        qqReply($c, $scope, $target, $msgId, '✅ 已绑定：' . $row['name'] . "\n发送「查班费」查看应缴 / 已缴 / 待缴，或发送「帮助」查看全部指令。");
        return;
    }

    // 解绑
    if (qqCmdOn($c, 'unbind') && preg_match('/^(?:解绑|解除绑定|unbind)$/ui', $cmd)) {
        db()->prepare("DELETE FROM qq_bindings WHERE bind_key=:k")->execute([':k' => $key]);
        qqReply($c, $scope, $target, $msgId, "已解除绑定。\n发送「绑定 姓名」可重新绑定。");
        return;
    }

    // ==================== 管理员绑定与指令 ====================
    // 绑定管理员 <6位码>（码在网页「配置管理 → 群机器人」生成，10 分钟有效、一次性）
    if (preg_match('/^(?:绑定管理员|管理员绑定|adminbind)[\s:：]*(\d{6})$/u', $cmd, $m)) {
        $info = qqAdminCodeCheck($m[1]);
        if (!$info) { qqReply($c, $scope, $target, $msgId, "绑定码无效或已过期。\n请在网页「配置管理 → 群机器人 → 管理员绑定」重新生成。"); return; }
        db()->prepare("INSERT INTO qq_bindings (bind_key, student_id, role, user_id, scope, target, openid) VALUES (:k, 0, 'admin', :u, :sc, :tg, :oid)
            ON DUPLICATE KEY UPDATE role='admin', user_id=VALUES(user_id), student_id=0, scope=VALUES(scope), target=VALUES(target), openid=VALUES(openid)")
            ->execute([':k' => 'admin:' . $key, ':u' => (int)($info['uid'] ?? 0), ':sc' => $scope, ':tg' => $target, ':oid' => $openid]);
        qqReply($c, $scope, $target, $msgId, '✅ 已绑定为管理员（' . (string)($info['name'] ?? '') . "）。\n发送「管理员指令」查看管理功能。");
        return;
    }

    // 只发了「绑定管理员」但没带验证码 → 给用法提示（放在验证码分支之后，避免抢匹配）
    if (preg_match('/^(?:绑定管理员|管理员绑定|adminbind)/u', $cmd)) {
        qqReply($c, $scope, $target, $msgId, "用法：绑定管理员 验证码\n验证码在网页「配置管理 → 群机器人 → 管理员绑定」生成（6 位数字，10 分钟有效、一次性）。");
        return;
    }

    $isAdmin = qqIsAdmin($key);

    if (preg_match('/^(?:管理员指令|管理指令|admin)$/ui', $cmd)) {
        if (!$isAdmin) { qqReply($c, $scope, $target, $msgId, "你还没有管理员权限。\n请在网页「配置管理 → 群机器人 → 管理员绑定」生成绑定码，再发送「绑定管理员 验证码」。"); return; }
        qqReply($c, $scope, $target, $msgId,
            "【管理员指令】\n未缴名单 —— 未缴清学生与金额\n催缴 —— 生成催缴名单（群里会 @ 未缴同学）\n"
            . "私信催缴 —— 给有单聊记录的同学发催缴私信\n发通知 内容 —— 推送到已配置的推送目标\n"
            . "收缴进度 / 班级概况 —— 统计");
        return;
    }

    if (qqCmdOn($c, 'unpaid') && preg_match('/^(?:未缴名单|未缴清|未交名单|欠费名单)$/u', $cmd)) {
        if (!$isAdmin) { qqReply($c, $scope, $target, $msgId, '该指令仅管理员可用。发送「管理员指令」查看方式。'); return; }
        $un = reminderUnpaidList();
        if (!$un) { qqReply($c, $scope, $target, $msgId, '🎉 全班已全部缴清，没有待缴学生。'); return; }
        $lines = ['【未缴名单】共 ' . count($un) . ' 人']; $sum = 0.0;
        foreach ($un as $u) { $sum += (float)$u['outstanding']; $lines[] = $u['name'] . ' ¥' . number_format((float)$u['outstanding'], 2, '.', ''); }
        $lines[] = '合计待缴 ¥' . number_format($sum, 2, '.', '');
        qqReply($c, $scope, $target, $msgId, implode("\n", $lines));
        return;
    }

    if (qqCmdOn($c, 'urge') && preg_match('/^(?:催缴|催交|催收|提醒缴费|催缴提醒|缴费提醒)(?:[\s:：]+(.+))?$/u', $cmd, $m)) {
        if (!$isAdmin) { qqReply($c, $scope, $target, $msgId, '该指令仅管理员可用。发送「管理员指令」查看方式。'); return; }
        list($text) = qqBuildUrge($c, $scope, $target, trim((string)($m[1] ?? '')));
        qqReply($c, $scope, $target, $msgId, $text);
        return;
    }

    if (qqCmdOn($c, 'urge_dm') && preg_match('/^(?:私信催缴|催缴私信|私聊催缴)$/u', $cmd)) {
        if (!$isAdmin) { qqReply($c, $scope, $target, $msgId, '该指令仅管理员可用。发送「管理员指令」查看方式。'); return; }
        $un = reminderUnpaidList();
        if (!$un) { qqReply($c, $scope, $target, $msgId, '🎉 全班已全部缴清，无需催缴。'); return; }
        $okCnt = 0; $fail = []; $noOpen = 0;
        foreach ($un as $u) {
            $uo = qqUserOpenid((int)$u['id']);
            if ($uo === '') { $noOpen++; continue; }
            $msg = '【班费催缴】' . $u['name'] . ' 同学，你还有 ¥' . number_format((float)$u['outstanding'], 2, '.', '') . " 班费待缴，请尽快缴纳，谢谢配合～";
            $r = qqReply($c, 'c2c', $uo, '', $msg);
            if (!empty($r['ok'])) $okCnt++; else $fail[] = $u['name'];
        }
        $msg = '【私信催缴结果】成功 ' . $okCnt . ' 人';
        if ($noOpen > 0) $msg .= '；' . $noOpen . ' 人没有单聊记录（无法私信，可在群里 @）';
        if ($fail) $msg .= '；失败：' . implode('、', array_slice($fail, 0, 5));
        qqReply($c, $scope, $target, $msgId, $msg);
        return;
    }

    if (qqCmdOn($c, 'notify') && preg_match('/^(?:发通知|群发|通知|推送)[\s:：]+(.+)$/u', $cmd, $m)) {
        if (!$isAdmin) { qqReply($c, $scope, $target, $msgId, '该指令仅管理员可用。发送「管理员指令」查看方式。'); return; }
        $text = trim($m[1]);
        $res = reminderQqSend($c, $text);
        qqReply($c, $scope, $target, $msgId, !empty($res['ok'])
            ? ('✅ 已推送到 ' . (int)($res['sent'] ?? 0) . ' 个目标')
            : ('❌ 推送失败：' . (string)($res['resp'] ?? '')));
        return;
    }

    // 班级概况
    if (qqCmdOn($c, 'overview') && preg_match('/^(?:班级概况|概况|总览|余额|结余)$/u', $cmd)) {
        $inc = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND deleted_at IS NULL")->fetchColumn();
        $exp = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND deleted_at IS NULL")->fetchColumn();
        $un = reminderUnpaidList();
        $sum = 0.0; foreach ($un as $u) $sum += $u['outstanding'];
        qqReply($c, $scope, $target, $msgId,
            "【班级概况】\n总收入 ¥" . number_format($inc, 2, '.', '')
            . "\n总支出 ¥" . number_format($exp, 2, '.', '')
            . "\n结余 ¥" . number_format($inc - $exp, 2, '.', '')
            . "\n未缴清 " . count($un) . " 人，合计待缴 ¥" . number_format($sum, 2, '.', ''));
        return;
    }

    // 收缴进度
    if (qqCmdOn($c, 'progress') && preg_match('/^(?:收缴进度|进度|收缴率|缴纳情况)$/u', $cmd)) {
        $eligible = (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
        $un = reminderUnpaidList();
        $total = 0.0; foreach ($un as $u) $total += $u['outstanding'];
        $paidCount = max(0, $eligible - count($un));
        $rate = $eligible > 0 ? round($paidCount / $eligible * 100, 1) : 0;
        qqReply($c, $scope, $target, $msgId,
            "【收缴进度】\n应缴 " . $eligible . " 人\n已缴清 " . $paidCount . " 人\n未缴清 " . count($un) . " 人"
            . "（待缴合计 ¥" . number_format($total, 2, '.', '') . "）\n收缴率 " . $rate . "%");
        return;
    }

    // 最近收支
    if (qqCmdOn($c, 'recent') && preg_match('/^(?:最近收支|最近记录|最近账目|收支记录|收支明细|最近10笔)$/u', $cmd)) {
        $rows = db()->query("SELECT type, amount, date, description FROM transactions WHERE deleted_at IS NULL ORDER BY date DESC, id DESC LIMIT 10")->fetchAll();
        if (!$rows) { qqReply($c, $scope, $target, $msgId, '暂无收支记录。'); return; }
        $lines = ['【最近 10 笔收支】'];
        foreach ($rows as $r) {
            $lines[] = $r['date'] . ' ' . ($r['type'] === 'income' ? '+' : '-')
                . '¥' . number_format((float)$r['amount'], 2, '.', '') . ' ' . mb_substr((string)$r['description'], 0, 20);
        }
        qqReply($c, $scope, $target, $msgId, implode("\n", $lines));
        return;
    }

    // 本轮班费
    if (qqCmdOn($c, 'round') && preg_match('/^(?:本轮班费|最新一轮|最近一轮|本轮|班费轮次)$/u', $cmd)) {
        $r = db()->query("SELECT date, description, amount, expected_amount, per_person FROM transactions
            WHERE sub_category='班费收缴' AND deleted_at IS NULL ORDER BY date DESC, id DESC LIMIT 1")->fetch();
        if (!$r) { qqReply($c, $scope, $target, $msgId, '暂无班费收缴记录。'); return; }
        $eligible = (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
        $pp = payRoundPerPerson($r, $eligible);
        qqReply($c, $scope, $target, $msgId,
            "【最新一轮班费】\n" . $r['date'] . ' ' . $r['description']
            . "\n每人应缴 ¥" . number_format($pp, 2, '.', '')
            . "\n全班应收 ¥" . number_format($pp * $eligible, 2, '.', '') . "（" . $eligible . " 人）");
        return;
    }

    // 我的明细（逐轮）
    if (qqCmdOn($c, 'detail') && preg_match('/^(?:我的明细|缴费明细|我的账单|缴纳明细|轮次明细|明细|历史)$/u', $cmd)) {
        $bind = qqBindingStudent($key);
        if (!$bind) { qqReply($c, $scope, $target, $msgId, $noBind); return; }
        $rounds = payStudentRounds((int)$bind['student_id']);
        if (!$rounds) { qqReply($c, $scope, $target, $msgId, '暂无班费轮次记录。'); return; }
        $lines = ['【' . $bind['name'] . ' · 缴费明细】'];
        $paid = 0.0; $due = 0.0;
        foreach ($rounds as $r) {
            $lines[] = $r['date'] . ' ¥' . number_format($r['per_person'], 2, '.', '') . ' [' . $r['status'] . ']';
            if ($r['status'] !== '免缴') $due += $r['per_person'];
            if ($r['status'] === '已缴') $paid += $r['per_person'];
        }
        $lines[] = '——';
        $lines[] = '应缴 ¥' . number_format($due, 2, '.', '') . ' / 已缴 ¥' . number_format($paid, 2, '.', '')
            . ' / 待缴 ¥' . number_format(max(0, $due - $paid), 2, '.', '');
        qqReply($c, $scope, $target, $msgId, implode("\n", $lines));
        return;
    }

    // 缴费 / 交费：绑定后直接发本人专属缴费链接（不要求先发“我的链接”）
    if (qqCmdOn($c, 'pay') && preg_match('/^(?:缴费|交费|我要缴费|去缴费|付款)$/u', $cmd)) {
        $bind = qqBindingStudent($key);
        if (!$bind) { qqReply($c, $scope, $target, $msgId, $noBind); return; }
        $sid = (int)$bind['student_id'];
        $link = payBaseUrl() . '/?pay=' . $sid;
        qqReply($c, $scope, $target, $msgId,
            "【" . $bind['name'] . " 专属缴费链接】\n" . $link
            . "\n\n点开后会自动选中你本人，直接在线缴纳。"
            . (payBackupBaseUrl() !== payBaseUrl() ? "\n备用入口：" . payBackupBaseUrl() . '/?pay=' . $sid : ''));
        return;
    }

    // 我的链接
    if (qqCmdOn($c, 'link') && preg_match('/^(?:我的链接|缴费链接|我的二维码|链接)$/u', $cmd)) {
        $bind = qqBindingStudent($key);
        if (!$bind) { qqReply($c, $scope, $target, $msgId, $noBind); return; }
        qqReply($c, $scope, $target, $msgId,
            "【" . $bind['name'] . " 专属缴费链接】\n" . payBaseUrl() . '/?pay=' . (int)$bind['student_id']
            . "\n\n点开后选择本人姓名即可在线缴纳。"
            . (payBackupBaseUrl() !== payBaseUrl() ? "\n备用入口：" . payBackupBaseUrl() . '/?pay=' . (int)$bind['student_id'] : ''));
        return;
    }

    // 查班费
    if (qqCmdOn($c, 'query') && preg_match('/查班费|我的班费|班费|缴费查询|班费查询|我的费用/u', $cmd)) {
        $bind = qqBindingStudent($key);
        if (!$bind) { qqReply($c, $scope, $target, $msgId, $noBind); return; }
        if (!empty($bind['exempt'])) {
            qqReply($c, $scope, $target, $msgId, "【" . $bind['name'] . "】你在花名册中为永久免缴，无需缴纳班费。");
            return;
        }
        $s = payStudentStatus((int)$bind['student_id']);
        $link = payBaseUrl() . '/?pay=' . (int)$bind['student_id'];
        qqReply($c, $scope, $target, $msgId,
            "【" . $bind['name'] . " 班费】\n累计应缴 ¥" . number_format($s['due'], 2, '.', '')
            . "\n已缴 ¥" . number_format($s['paid'], 2, '.', '')
            . "\n待缴 ¥" . number_format($s['outstanding'], 2, '.', '')
            . "\n\n在线缴纳（点开选本人姓名）：\n" . $link);
        return;
    }

    // 自定义关键词回复（可在「配置管理 → 群机器人」添加）
    foreach (($c['qq_custom_replies'] ?? []) as $cr) {
        $kw = trim((string)($cr['k'] ?? ''));
        if ($kw !== '' && mb_strlen($kw) >= 2 && mb_strpos($cmd, $kw) !== false) {
            qqReply($c, $scope, $target, $msgId, (string)($cr['v'] ?? ''));
            return;
        }
    }

    // 未识别
    qqReply($c, $scope, $target, $msgId, "未识别的指令～\n\n" . qqMenu());
}

/** 记录 QQ 回调请求（供网页「回调协议监测」查看，最多保留 20 条） */
function qqWebhookLog(array $entry): void {
    // 会话事件（READY/RESUMED）不写入监测日志：网关每约 30 分钟重连一次，
    // 若记录会把真实的群/单聊消息挤出 20 条上限（连接状态看「网关连接」即可）。
    if ((int)($entry['op'] ?? -1) === 0 && in_array((string)($entry['event'] ?? ''), ['READY', 'RESUMED'], true)) return;
    try {
        $logs = json_decode(getMeta('qq_webhook_log', '[]'), true);
        if (!is_array($logs)) $logs = [];
        array_unshift($logs, $entry);
        if (count($logs) > 20) $logs = array_slice($logs, 0, 20);
        // 控制序列化体积：万一字段被改回短 VARCHAR，也只丢旧记录而不是整条日志静默失败
        $json = json_encode($logs, JSON_UNESCAPED_UNICODE);
        while ($json !== false && strlen($json) > 8000 && count($logs) > 1) {
            array_pop($logs);
            $json = json_encode($logs, JSON_UNESCAPED_UNICODE);
        }
        setMeta('qq_webhook_log', $json === false ? '[]' : $json);
    } catch (\Exception $e) { /* 记录失败不影响回调 */ }
}

/** QQ 官方机器人 Webhook 入口（免登录，QQ 平台回调） */
function handleQqWebhook() {
    header('Content-Type: application/json; charset=utf-8');
    // 本地网关客户端转发事件时带上真实域名，保证回复里的缴费链接正确
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST']) && ($remote === '127.0.0.1' || $remote === '::1')) {
        $_SERVER['HTTP_HOST'] = (string)$_SERVER['HTTP_X_FORWARDED_HOST'];
    }
    $body = (string)file_get_contents('php://input');
    $ts = (string)($_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '');
    $sig = (string)($_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '');
    $data = json_decode($body, true);

    $base = [
        't' => date('Y-m-d H:i:s'),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 60),
        'op' => is_array($data) ? (int)($data['op'] ?? -1) : -1,
        'event' => is_array($data) ? (string)($data['t'] ?? '') : '',
        'sig' => ($ts !== '' && $sig !== '') ? 1 : 0,
        'body' => mb_substr($body, 0, 300),
    ];
    // 记录事件目标（群 group_openid / 频道 channel_id / 单聊 user_openid），方便在后台复制
    if (is_array($data)) {
        $dd = is_array($data['d'] ?? null) ? $data['d'] : [];
        $base['target'] = (string)($dd['group_openid'] ?? ($dd['channel_id'] ?? ($dd['author']['user_openid'] ?? ($dd['user_openid'] ?? ''))));
    }

    if (!is_array($data)) { http_response_code(400); echo '{}'; qqWebhookLog($base + ['resp' => '400 非 JSON']); return; }

    $c = reminderConfig();
    if ($c['qq_appid'] === '' || $c['qq_secret'] === '') {
        http_response_code(400); echo '{}';
        qqWebhookLog($base + ['resp' => '400 未配置 AppID/AppSecret']);
        return;
    }
    // 自愈：收到平台回调时确保网关客户端在运行（无需单独安装）
    qqGatewayEnsureRunning();

    // op=13：QQ 回调地址校验（无需验签），用 Ed25519 对 event_ts+plain_token 签名
    if ((int)($data['op'] ?? -1) === 13) {
        $d = is_array($data['d'] ?? null) ? $data['d'] : [];
        $plain = (string)($d['plain_token'] ?? '');
        $eventTs = (string)($d['event_ts'] ?? '');
        $signature = qqSign($c['qq_secret'], $eventTs . $plain);
        echo json_encode(['plain_token' => $plain, 'signature' => $signature], JSON_UNESCAPED_UNICODE);
        qqWebhookLog($base + ['resp' => '200 回调校验（签名长度 ' . strlen($signature) . '）']);
        return;
    }

    // 事件验签：非回调校验事件必须携带有效签名，缺失一律拒绝（防伪造事件）
    if ($ts === '' || $sig === '' || !qqVerifySignature($c['qq_secret'], $ts, $body, $sig)) {
        if (function_exists('securityLog')) securityLog('qq_webhook_bad_signature', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        http_response_code(401); echo '{}';
        qqWebhookLog($base + ['resp' => '401 验签失败']);
        return;
    }

    // 先回 200 + op=12 ACK（HTTP Callback ACK，代表已收到平台推送），避免超时重推；随后异步被动回复
    http_response_code(200);
    echo '{"opcode":12}';
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }

    if ((int)($data['op'] ?? -1) !== 0) {
        qqWebhookLog($base + ['resp' => '200 ACK（忽略 op=' . (int)($data['op'] ?? -1) . '）']);
        return;
    }
    $t = (string)($data['t'] ?? '');
    $d = is_array($data['d'] ?? null) ? $data['d'] : [];
    $msgId = (string)($d['id'] ?? '');
    $content = (string)($d['content'] ?? '');

    $handled = '未知事件';
    try {
        if ($t === 'GROUP_AT_MESSAGE_CREATE') {
            $target = (string)($d['group_openid'] ?? '');
            $openid = (string)($d['author']['member_openid'] ?? '');
            if ($target !== '' && $openid !== '') { qqHandleCommand($c, 'group', $target, $openid, $msgId, $content); $handled = '群消息'; }
        } elseif ($t === 'C2C_MESSAGE_CREATE') {
            $openid = (string)($d['author']['user_openid'] ?? ($d['user_openid'] ?? ''));
            if ($openid !== '') {
                // 单聊默认不限制用户；关闭 qq_c2c_open 后仅允许名单内的 openid
                if (qqC2cAllowed($c, $openid)) { qqHandleCommand($c, 'c2c', $openid, $openid, $msgId, $content); $handled = '单聊消息'; }
                else { qqReply($c, 'c2c', $openid, $msgId, '本机器人单聊仅限名单内用户使用，请联系班委添加。'); $handled = '单聊（不在名单）'; }
            }
        } elseif ($t === 'AT_MESSAGE_CREATE' || $t === 'DIRECT_MESSAGE_CREATE') {
            $target = (string)($d['channel_id'] ?? '');
            $openid = (string)($d['author']['id'] ?? '');
            if ($target !== '') { qqHandleCommand($c, 'channel', $target, $openid, $msgId, $content); $handled = '频道消息'; }
        }
    } catch (\Exception $e) {
        error_log('[班费系统] QQ webhook 处理失败: ' . $e->getMessage());
        $handled = '处理异常';
    }
    qqWebhookLog($base + ['resp' => '200 ' . $handled]);
}
