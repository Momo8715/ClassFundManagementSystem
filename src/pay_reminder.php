<?php
/**
 * 班级班费管理系统 - 催缴通知与群机器人推送
 */
require_once __DIR__ . '/pay_core.php';


// ==================== 催缴通知（未缴名单 + 群机器人推送，v1.11） ====================
/** 催缴推送配置（存 system_meta，Webhook 地址不落代码/仓库） */
function reminderConfig(): array {
    $type = getMeta('reminder_type', 'wecom');
    if (!in_array($type, ['wecom', 'feishu', 'dingtalk', 'custom', 'qq'], true)) $type = 'wecom';
    $scope = getMeta('reminder_qq_scope', 'group');
    if (!in_array($scope, ['group', 'c2c', 'channel'], true)) $scope = 'group';
    // 多目标：reminder_qq_targets = JSON [{scope,id},...]；兼容旧的单目标字段
    $targets = [];
    $jt = getMeta('reminder_qq_targets', '');
    if ($jt !== '') {
        $arr = json_decode($jt, true);
        if (is_array($arr)) {
            foreach ($arr as $t) {
                if (!is_array($t)) continue;
                $sc = in_array($t['scope'] ?? '', ['group', 'c2c', 'channel'], true) ? $t['scope'] : 'group';
                $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($t['id'] ?? ''));
                if ($id !== '') $targets[] = ['scope' => $sc, 'id' => $id];
                if (count($targets) >= 20) break;
            }
        }
    }
    $legacyTarget = preg_replace('/[^A-Za-z0-9_\-]/', '', getMeta('reminder_qq_target', ''));
    if (empty($targets) && $legacyTarget !== '') $targets[] = ['scope' => $scope, 'id' => $legacyTarget];
    return [
        'enabled'    => getMeta('reminder_enabled', '0') === '1',
        'type'       => $type,
        'webhook'    => getMeta('reminder_webhook', ''),
        'qq_appid'   => getMeta('reminder_qq_appid', ''),
        'qq_secret'  => getMeta('reminder_qq_secret', ''),
        'qq_scope'   => $scope,
        'qq_target'  => $legacyTarget,
        'qq_targets' => $targets,
        'qq_sandbox' => getMeta('reminder_qq_sandbox', '0') === '1',
        'qq_cmd_disabled'   => (array)(json_decode(getMeta('qq_cmd_disabled', '[]'), true) ?: []),
        'qq_menu_intro'     => getMeta('qq_menu_intro', ''),
        'qq_menu_footer'    => getMeta('qq_menu_footer', ''),
        'qq_custom_replies' => (array)(json_decode(getMeta('qq_custom_replies', '[]'), true) ?: []),
        'qq_c2c_open'       => getMeta('qq_c2c_open', '1') !== '0',
        'auto_remind'       => getMeta('reminder_auto_enabled', '0') === '1',
    ];
}

/** 未缴清学生名单（口径与缴费追踪一致：应缴=各轮 per_person 相加） */
function reminderUnpaidList(): array {
    $roster = db()->query("SELECT id, name, exempt FROM class_roster ORDER BY id")->fetchAll();
    $fs = payAllFeeStatus();
    $unpaid = [];
    foreach ($roster as $s) {
        if (!empty($s['exempt'])) continue;                 // 永久免缴不催缴
        $sid = (int)$s['id'];
        $st = $fs[$sid] ?? ['due' => 0, 'paid' => 0, 'outstanding' => 0];
        if ((float)($st['outstanding'] ?? 0) > 0.001) {
            $unpaid[] = [
                'id' => $sid, 'name' => (string)$s['name'],
                'due' => round((float)$st['due'], 2),
                'paid' => round((float)$st['paid'], 2),
                'outstanding' => round((float)$st['outstanding'], 2),
            ];
        }
    }
    usort($unpaid, function ($a, $b) {
        $c = $b['outstanding'] <=> $a['outstanding'];
        return $c !== 0 ? $c : strcmp($a['name'], $b['name']);
    });
    return $unpaid;
}

/** 催缴汇总文案（服务端与前端共用同一份） */
function reminderSummary(): array {
    $unpaid = reminderUnpaidList();
    $total = 0.0;
    foreach ($unpaid as $u) $total += $u['outstanding'];
    $cfg = getPayConfig();
    $site = trim((string)($cfg['sitename'] ?? '')) ?: SITE_NAME;
    $base = payBaseUrl();
    $lines = ['【班费催缴】' . $site];
    $lines[] = '共 ' . count($unpaid) . ' 人未缴清，合计待缴 ¥' . number_format($total, 2, '.', '');
    if (!empty($unpaid)) {
        $lines[] = '';
        $max = 50;
        foreach ($unpaid as $i => $u) {
            if ($i >= $max) { $lines[] = '……（其余 ' . (count($unpaid) - $max) . ' 人请在系统中查看）'; break; }
            $lines[] = ($i + 1) . '. ' . $u['name'] . '　待缴 ¥' . number_format($u['outstanding'], 2, '.', '');
        }
    }
    $lines[] = '';
    $lines[] = '在线缴费入口：' . $base . '/';
    if (payBackupBaseUrl() !== payBaseUrl()) $lines[] = '备用入口：' . payBackupBaseUrl() . '/';
    $text = implode("\n", $lines);
    return ['text' => $text, 'summary' => $text, 'count' => count($unpaid), 'total' => round($total, 2), 'unpaid' => $unpaid, 'base_url' => $base];
}

/** 各平台群机器人 payload */
function reminderWebhookPayload(string $type, string $text): array {
    switch ($type) {
        case 'feishu':   return ['msg_type' => 'text', 'content' => ['text' => $text]];
        case 'dingtalk': return ['msgtype' => 'text', 'text' => ['content' => $text]];
        case 'wecom':    return ['msgtype' => 'markdown', 'markdown' => ['content' => $text]];
        default:         return ['content' => $text, 'text' => $text];
    }
}

/** POST 到群机器人，返回是否成功 */
function reminderWebhookPost(string $url, array $payload): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nUser-Agent: ClassFund/1.0\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    $ok = ($code >= 200 && $code < 300);
    $json = $resp ? json_decode($resp, true) : null;
    if (is_array($json)) {
        if (isset($json['errcode']) && (int)$json['errcode'] !== 0) $ok = false;   // 企业微信/钉钉
        if (isset($json['code']) && (int)$json['code'] !== 0) $ok = false;         // 飞书
        if (isset($json['StatusCode']) && (int)$json['StatusCode'] !== 0) $ok = false;
    }
    return ['ok' => $ok, 'http' => $code, 'resp' => mb_substr((string)$resp, 0, 200)];
}

/** 获取 QQ 官方机器人 access_token（缓存到 system_meta，提前 60 秒刷新） */
function reminderQqToken(array $c): array {
    $now = time();
    $cached = getMeta('reminder_qq_token', '');
    $exp = (int)getMeta('reminder_qq_token_exp', '0');
    if ($cached !== '' && $exp > $now + 60) return ['ok' => true, 'token' => $cached];
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nUser-Agent: ClassFund/1.0\r\n",
        'content' => json_encode(['appId' => $c['qq_appid'], 'clientSecret' => $c['qq_secret']], JSON_UNESCAPED_UNICODE),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents('https://bots.qq.com/app/getAppAccessToken', false, $ctx);
    $json = $resp ? json_decode($resp, true) : null;
    if (!is_array($json) || empty($json['access_token'])) {
        return ['ok' => false, 'error' => mb_substr((string)$resp, 0, 200)];
    }
    $expires = (int)($json['expires_in'] ?? 7200);
    setMeta('reminder_qq_token', (string)$json['access_token']);
    setMeta('reminder_qq_token_exp', (string)($now + $expires));
    return ['ok' => true, 'token' => (string)$json['access_token']];
}

/** 主动推送 QQ 官方机器人消息（支持多目标：QQ群 / QQ用户 / 频道） */
function reminderQqSend(array $c, string $text): array {
    $targets = $c['qq_targets'] ?? [];
    if (empty($targets) && ($c['qq_target'] ?? '') !== '') {
        $targets = [['scope' => $c['qq_scope'] ?? 'group', 'id' => $c['qq_target']]];
    }
    if ($c['qq_appid'] === '' || $c['qq_secret'] === '') return ['ok' => false, 'http' => 0, 'resp' => 'QQ 机器人缺少 AppID / AppSecret'];
    if (empty($targets)) return ['ok' => false, 'http' => 0, 'resp' => 'QQ 机器人未配置推送目标'];
    $t = reminderQqToken($c);
    if (!$t['ok']) return ['ok' => false, 'http' => 0, 'resp' => '获取 access_token 失败：' . ($t['error'] ?? '')];
    $sent = 0; $errs = []; $lastHttp = 0;
    foreach ($targets as $tg) {
        list($url, $body) = qqTargetRequest($c, $tg['scope'], $tg['id'], $text);
        $r = qqApiPost($t['token'], $c['qq_appid'], $url, $body);
        $lastHttp = $r['http'];
        if ($r['ok']) $sent++;
        else $errs[] = $tg['scope'] . ':' . $tg['id'] . ' HTTP' . $r['http'] . ' ' . $r['resp'];
    }
    if (!empty($errs)) {
        return ['ok' => false, 'http' => $lastHttp, 'sent' => $sent,
            'resp' => '成功 ' . $sent . '/' . count($targets) . '；失败：' . implode(' | ', array_slice($errs, 0, 2))];
    }
    return ['ok' => true, 'http' => $lastHttp, 'sent' => $sent, 'resp' => ''];
}

/**
 * 每日自动催缴提醒（由 cron.php?task=remind 或 task=all 触发）
 * 同一天只发一次；全部缴清时只记日期，不发消息。
 */
function reminderAutoSend(): array {
    if (getMeta('reminder_auto_enabled', '0') !== '1') return ['ok' => true, 'skipped' => 'disabled'];
    $today = date('Y-m-d');
    if (getMeta('reminder_auto_last', '') === $today) return ['ok' => true, 'skipped' => 'already_sent_today'];
    $c = reminderConfig();
    if ($c['type'] === 'qq') {
        if ($c['qq_appid'] === '' || $c['qq_secret'] === '' || empty($c['qq_targets'])) {
            return ['ok' => false, 'skipped' => 'qq_not_configured'];
        }
    } elseif ($c['webhook'] === '') {
        return ['ok' => false, 'skipped' => 'webhook_not_configured'];
    }
    $sum = reminderSummary();
    if ((int)$sum['count'] === 0) {
        setMeta('reminder_auto_last', $today);
        return ['ok' => true, 'count' => 0, 'message' => '全部已缴清，无需催缴'];
    }
    $res = ($c['type'] === 'qq')
        ? reminderQqSend($c, $sum['text'])
        : reminderWebhookPost($c['webhook'], reminderWebhookPayload($c['type'], $sum['text']));
    if (!empty($res['ok'])) {
        setMeta('reminder_auto_last', $today);
        addLog(0, 'system', 'reminder_auto_send', 'payment', null, ['count' => $sum['count'], 'total' => $sum['total'], 'type' => $c['type']]);
        return ['ok' => true, 'count' => $sum['count'], 'total' => $sum['total'], 'sent' => $res['sent'] ?? 1];
    }
    return ['ok' => false, 'count' => $sum['count'], 'total' => $sum['total'], 'error' => mb_substr((string)($res['resp'] ?? $res['error'] ?? '发送失败'), 0, 300)];
}

function handlePayReminders() {
    requirePermission('viewPayments');
    jsonOutput(reminderSummary());
}

function handlePayReminderCfgGet() {
    requirePermission('viewSecurity');
    $c = reminderConfig();
    // 应用自动托管：进入配置页即确保网关客户端在运行
    if ($c['type'] === 'qq' && $c['qq_appid'] !== '' && $c['qq_secret'] !== '') qqGatewayEnsureRunning();
    $host = '';
    if ($c['webhook'] !== '') {
        $p = parse_url($c['webhook']);
        $host = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/****';
    }
    $secret = $c['qq_secret'];
    jsonOutput(['config' => [
        'enabled' => $c['enabled'], 'type' => $c['type'],
        'webhook_set' => $c['webhook'] !== '', 'webhook_hint' => $host,
        'qq_appid' => $c['qq_appid'],
        'qq_secret_set' => $secret !== '',
        // 显示首 3 + 末 4 位指纹，方便与 QQ 开放平台上的 AppSecret 核对（不泄露完整密钥）
        'qq_secret_hint' => $secret !== '' ? (substr($secret, 0, min(3, strlen($secret))) . '****' . (strlen($secret) > 4 ? substr($secret, -4) : '')) : '',
        'qq_scope' => $c['qq_scope'],
        'qq_target' => $c['qq_target'],
        'qq_targets' => $c['qq_targets'],
        'qq_sandbox' => $c['qq_sandbox'],
        'qq_c2c_open' => $c['qq_c2c_open'],
        'auto_remind' => $c['auto_remind'],
        'webhook_log' => (json_decode(getMeta('qq_webhook_log', '[]'), true) ?: []),
        'gateway' => qqGatewayStatus(),
        'qq_cmd_disabled' => $c['qq_cmd_disabled'],
        'qq_menu_intro' => $c['qq_menu_intro'],
        'qq_menu_footer' => $c['qq_menu_footer'],
        'qq_custom_replies' => $c['qq_custom_replies'],
        'commands' => array_map(function ($x) use ($c) {
            $x['enabled'] = !empty($x['always']) || !in_array($x['key'], $c['qq_cmd_disabled'], true);
            return $x;
        }, qqCommands()),
        'menu_preview' => qqMenu(),
    ]]);
}

function handlePayReminderCfgSave() {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $input = jsonInput();
    $enabled = !empty($input['enabled']) && $input['enabled'] !== '0' && $input['enabled'] !== 'false';
    $type = in_array($input['type'] ?? '', ['wecom', 'feishu', 'dingtalk', 'custom', 'qq'], true) ? $input['type'] : 'wecom';
    $old = reminderConfig();

    // Webhook（企业微信 / 飞书 / 钉钉 / 自定义）
    $urlIn = trim((string)($input['webhook'] ?? ''));
    if ($urlIn !== '') {
        if (!preg_match('#^https://[^\s]{1,1000}$#i', $urlIn)) jsonOutput(['error' => 'Webhook 地址需为 https:// 开头的合法 URL'], 400);
        if (mb_strlen($urlIn) > 1000) jsonOutput(['error' => 'Webhook 地址过长'], 400);
    }
    $webhook = $urlIn !== '' ? $urlIn : $old['webhook'];

    // QQ 官方机器人
    $appidIn = trim((string)($input['qq_appid'] ?? ''));
    if ($appidIn !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $appidIn)) jsonOutput(['error' => 'QQ AppID 格式不正确'], 400);
    $appid = $appidIn !== '' ? $appidIn : $old['qq_appid'];
    $secretIn = trim((string)($input['qq_secret'] ?? ''));
    if ($secretIn !== '' && !preg_match('/^[^\s\'"\r\n]{1,256}$/', $secretIn)) jsonOutput(['error' => 'QQ AppSecret 含非法字符'], 400);
    $secret = $secretIn !== '' ? $secretIn : $old['qq_secret'];
    $sandbox = !empty($input['qq_sandbox']) && $input['qq_sandbox'] !== '0' && $input['qq_sandbox'] !== 'false';
    // 单聊是否不限制用户（默认 true；仅当显式传 0/false 时收紧）
    $c2cOpen = true;
    if (array_key_exists('qq_c2c_open', $input)) {
        $c2cOpen = !empty($input['qq_c2c_open']) && $input['qq_c2c_open'] !== '0' && $input['qq_c2c_open'] !== 'false';
    }
    $autoRemind = !empty($input['auto_remind']) && $input['auto_remind'] !== '0' && $input['auto_remind'] !== 'false';

    // 多目标：QQ群 / QQ用户（单聊）/ 频道，最多 20 个
    $targetsIn = $input['qq_targets'] ?? [];
    if (is_string($targetsIn)) $targetsIn = json_decode($targetsIn, true) ?: [];
    if (!is_array($targetsIn)) $targetsIn = [];
    $targets = [];
    foreach ($targetsIn as $t) {
        if (!is_array($t)) continue;
        $sc = in_array($t['scope'] ?? '', ['group', 'c2c', 'channel'], true) ? $t['scope'] : 'group';
        $id = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string)($t['id'] ?? '')));
        if ($id === '') continue;
        if (!preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $id)) jsonOutput(['error' => 'QQ 目标ID格式不正确'], 400);
        $targets[] = ['scope' => $sc, 'id' => $id];
        if (count($targets) >= 20) break;
    }
    // 兼容旧的单目标字段
    if (empty($targets)) {
        $legacy = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string)($input['qq_target'] ?? $old['qq_target'])));
        if ($legacy !== '') {
            $sc0 = $input['qq_scope'] ?? $old['qq_scope'];
            $sc = in_array($sc0, ['group', 'c2c', 'channel'], true) ? $sc0 : 'group';
            $targets[] = ['scope' => $sc, 'id' => $legacy];
        }
    }
    $scope = $targets[0]['scope'] ?? 'group';
    $target = $targets[0]['id'] ?? '';

    // 指令开关 / 菜单文案 / 自定义关键词回复
    $disIn = $input['qq_cmd_disabled'] ?? [];
    if (is_string($disIn)) $disIn = json_decode($disIn, true) ?: [];
    if (!is_array($disIn)) $disIn = [];
    $validKeys = array_column(qqCommands(), 'key');
    $disabled = [];
    foreach ($disIn as $k) {
        $k = preg_replace('/[^a-z_]/', '', (string)$k);
        if ($k !== '' && $k !== 'help' && in_array($k, $validKeys, true) && !in_array($k, $disabled, true)) $disabled[] = $k;
    }
    $menuIntro = mb_substr(trim((string)($input['qq_menu_intro'] ?? '')), 0, 120);
    $menuFooter = mb_substr(trim((string)($input['qq_menu_footer'] ?? '')), 0, 200);
    $crIn = $input['qq_custom_replies'] ?? [];
    if (is_string($crIn)) $crIn = json_decode($crIn, true) ?: [];
    if (!is_array($crIn)) $crIn = [];
    $customReplies = [];
    foreach ($crIn as $cr) {
        if (!is_array($cr)) continue;
        $ck = mb_substr(trim((string)($cr['k'] ?? '')), 0, 30);
        $cv = mb_substr(trim((string)($cr['v'] ?? '')), 0, 500);
        if ($ck === '' || $cv === '') continue;
        $customReplies[] = ['k' => $ck, 'v' => $cv];
        if (count($customReplies) >= 20) break;
    }

    if ($enabled) {
        if ($type === 'qq') {
            if ($appid === '' || $secret === '') jsonOutput(['error' => '启用 QQ 机器人推送前需填 AppID / AppSecret'], 400);
            if (empty($targets)) jsonOutput(['error' => '启用 QQ 机器人推送前请至少添加一个推送目标'], 400);
        } elseif ($webhook === '') {
            jsonOutput(['error' => '启用前请先填写群机器人 Webhook 地址'], 400);
        }
    }

    setMeta('reminder_enabled', $enabled ? '1' : '0');
    setMeta('reminder_type', $type);
    setMeta('reminder_webhook', $webhook);
    setMeta('reminder_qq_appid', $appid);
    setMeta('reminder_qq_secret', $secret);
    setMeta('reminder_qq_scope', $scope);
    setMeta('reminder_qq_target', $target);
    setMeta('reminder_qq_targets', json_encode($targets, JSON_UNESCAPED_UNICODE));
    setMeta('qq_cmd_disabled', json_encode($disabled, JSON_UNESCAPED_UNICODE));
    setMeta('qq_menu_intro', $menuIntro);
    setMeta('qq_menu_footer', $menuFooter);
    setMeta('qq_custom_replies', json_encode($customReplies, JSON_UNESCAPED_UNICODE));
    setMeta('qq_c2c_open', $c2cOpen ? '1' : '0');
    setMeta('reminder_qq_sandbox', $sandbox ? '1' : '0');
    setMeta('reminder_auto_enabled', $autoRemind ? '1' : '0');
    setMeta('reminder_qq_token', '');
    setMeta('reminder_qq_token_exp', '0');

    // 同步给常驻 WebSocket 网关客户端（变更后客户端会自动重连），并确保其在运行
    qqGatewayWriteConfig($type, $appid, $secret, $sandbox, (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($type === 'qq' && $appid !== '' && $secret !== '') qqGatewayEnsureRunning();

    $u = currentUser();
    addLog($u['id'], $u['username'], 'pay_reminder_cfg_save', 'system', null, ['enabled' => $enabled, 'type' => $type]);
    jsonOutput(['ok' => true]);
}

function handlePayReminderSend() {
    requirePermission('viewPayments');
    requireCsrfToken();
    $c = reminderConfig();
    if ($c['type'] === 'qq') qqGatewayEnsureRunning();
    if (!$c['enabled']) jsonOutput(['error' => '未启用催缴推送（管理员可在 配置管理 → 群机器人 配置）'], 400);
    if ($c['type'] !== 'qq' && $c['webhook'] === '') jsonOutput(['error' => '未配置催缴推送 Webhook'], 400);
    $sum = reminderSummary();
    if ($sum['count'] === 0) jsonOutput(['ok' => true, 'count' => 0, 'total' => 0, 'message' => '全部已缴清，无需催缴']);
    $res = ($c['type'] === 'qq')
        ? reminderQqSend($c, $sum['text'])
        : reminderWebhookPost($c['webhook'], reminderWebhookPayload($c['type'], $sum['text']));
    if (!$res['ok']) jsonOutput(['error' => '推送失败：HTTP ' . $res['http'] . ' ' . $res['resp']], 502);
    $u = currentUser();
    addLog($u['id'], $u['username'], 'pay_reminder_send', 'payment', null, ['count' => $sum['count'], 'total' => $sum['total'], 'type' => $c['type']]);
    jsonOutput(['ok' => true, 'count' => $sum['count'], 'total' => $sum['total']]);
}

// ==================== 自定义群消息推送（v1.12） ====================
function handlePayReminderPushText() {
    requirePermission('viewPayments');
    requireCsrfToken();
    $input = jsonInput();
    $text = trim((string)($input['text'] ?? ''));
    if ($text === '') jsonOutput(['error' => '消息内容不能为空'], 400);
    if (mb_strlen($text) > 1000) jsonOutput(['error' => '消息过长（最多 1000 字）'], 400);
    $c = reminderConfig();
    if ($c['type'] === 'qq') qqGatewayEnsureRunning();
    if ($c['type'] === 'qq') {
        if ($c['qq_appid'] === '' || $c['qq_secret'] === '' || empty($c['qq_targets'])) {
            jsonOutput(['error' => '请先配置 QQ 机器人（AppID / AppSecret / 目标ID）'], 400);
        }
        $res = reminderQqSend($c, $text);
    } else {
        if ($c['webhook'] === '') jsonOutput(['error' => '请先配置群机器人 Webhook'], 400);
        $res = reminderWebhookPost($c['webhook'], reminderWebhookPayload($c['type'], $text));
    }
    if (!$res['ok']) jsonOutput(['error' => '发送失败：HTTP ' . $res['http'] . ' ' . $res['resp']], 502);
    $u = currentUser();
    addLog($u['id'], $u['username'], 'pay_reminder_push_text', 'payment', null, ['type' => $c['type'], 'len' => mb_strlen($text)]);
    jsonOutput(['ok' => true]);
}

