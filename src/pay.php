<?php
/**
 * 班级班费管理系统 - 在线支付模块（易支付 / 彩虹易支付协议，支持多通道）
 */

// ==================== 签名 ====================
function epaySign(array $params, string $key): string {
    unset($params['sign'], $params['sign_type']);
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) continue;
        $pairs[] = $k . '=' . $v;
    }
    return md5(implode('&', $pairs) . $key);
}
function epayVerify(array $params, string $key): bool {
    if (empty($params['sign'])) return false;
    return hash_equals(strtolower(epaySign($params, $key)), strtolower((string)$params['sign']));
}

// ==================== URL / 金额 ====================
function payBaseUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
    return ($https ? 'https://' : 'http://') . $host;
}
function payNotifyUrl(): string {
    $c = getPayConfig();
    return $c['notify_url'] !== '' ? $c['notify_url'] : payBaseUrl() . '/api.php?action=pay_notify';
}
function payReturnUrl(): string {
    $c = getPayConfig();
    return $c['return_url'] !== '' ? $c['return_url'] : payBaseUrl() . '/api.php?action=pay_return';
}

/** 某轮班费收缴的「每人应缴」（优先显式 per_person，兼容旧数据兜底） */
function payRoundPerPerson(array $tx, int $eligible = -1): float {
    if ($eligible < 0) $eligible = (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
    if (isset($tx['per_person']) && $tx['per_person'] !== null && (float)$tx['per_person'] > 0) return round((float)$tx['per_person'], 2);
    $exp = (float)($tx['expected_amount'] ?? 0);
    if ($exp > 0 && $eligible > 0) return round($exp / $eligible, 2);
    $pids = json_decode($tx['payer_ids'] ?? '', true) ?: [];
    if (is_array($pids) && count($pids) > 0) return round((float)$tx['amount'] / count($pids), 2);
    return 0.0;
}

/**
 * 全班各学生的「应缴 / 已缴 / 待缴」。
 * 应缴 = 各轮 per_person 相加（不做除法）；已缴 = 在线 fee_payments + 线下该轮 payer 的 per_person。
 */
function payAllFeeStatus(): array {
    $roster = db()->query("SELECT id, exempt FROM class_roster")->fetchAll();
    $eligible = 0;
    foreach ($roster as $r) if (empty($r['exempt'])) $eligible++;
    $rounds = db()->query("SELECT id, amount, expected_amount, per_person, payer_ids, exempt_ids FROM transactions WHERE sub_category='班费收缴' AND deleted_at IS NULL")->fetchAll();
    $parsed = [];
    foreach ($rounds as $r) {
        $parsed[] = [
            'pp'    => payRoundPerPerson($r, $eligible),
            'all'   => (($r['payer_ids'] ?? '') === 'all'),
            'pids'  => array_map('intval', json_decode($r['payer_ids'] ?? '', true) ?: []),
            'exids' => array_map('intval', json_decode($r['exempt_ids'] ?? '', true) ?: []),
        ];
    }
    $online = [];
    foreach (db()->query(payOnlineUnattributedSql())->fetchAll() as $r) {
        $online[(int)$r['student_id']] = (float)$r['v'];
    }
    $out = [];
    foreach ($roster as $s) {
        $sid = (int)$s['id'];
        $due = 0.0; $paid = $online[$sid] ?? 0.0;
        foreach ($parsed as $p) {
            if (in_array($sid, $p['exids'], true)) continue;         // 本轮免缴
            $due += $p['pp'];
            if ($p['all'] || in_array($sid, $p['pids'], true)) $paid += $p['pp'];
        }
        $out[$sid] = ['due' => round($due, 2), 'paid' => round($paid, 2), 'outstanding' => round(max(0, $due - $paid), 2)];
    }
    return $out;
}

/** 某个学生的 应缴/已缴/待缴 */
function payStudentStatus(int $studentId): array {
    $all = payAllFeeStatus();
    return $all[$studentId] ?? ['due' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
}

// ==================== 下单 ====================
/** 用指定通道调用易支付 mapi.php；失败回退 submit.php 跳转地址 */
function epayCreate(array $order, array $channel): array {
    $cfg = getPayConfig();
    // 易支付的 mapi.php 强制要求 clientip（否则报"用户IP地址(clientip)不能为空"）
    $clientIp = '';
    if (function_exists('getClientIPs')) {
        $ips = getClientIPs();
        if (!empty($ips[0]) && filter_var($ips[0], FILTER_VALIDATE_IP)) {
            $clientIp = (string)$ips[0];
        }
    }
    if ($clientIp === '') {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $clientIp = filter_var($remote, FILTER_VALIDATE_IP) ? (string)$remote : '127.0.0.1';
    }
    $params = [
        'pid'          => $channel['pid'],
        'type'         => $order['channel'],       // 支付方式 alipay/wxpay/qqpay
        'out_trade_no' => $order['order_no'],
        'notify_url'   => payNotifyUrl(),
        'return_url'   => payReturnUrl(),
        'name'         => $order['name'],
        'money'        => number_format((float)$order['amount'], 2, '.', ''),
        'sitename'     => $cfg['sitename'],
        'clientip'     => $clientIp,
    ];
    $params['sign'] = epaySign($params, $channel['key']);
    $params['sign_type'] = $cfg['sign_type'];

    $url = rtrim($channel['gateway'], '/') . '/mapi.php';
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: ClassFund/1.0\r\n",
        'content' => http_build_query($params),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $json = $resp ? json_decode($resp, true) : null;

    if (is_array($json) && isset($json['code']) && (int)$json['code'] === 1
        && (!empty($json['payurl']) || !empty($json['qrcode']))) {
        return ['ok' => true, 'payurl' => (string)($json['payurl'] ?? ''), 'qrcode' => (string)($json['qrcode'] ?? ''), 'trade_no' => (string)($json['trade_no'] ?? '')];
    }
    $msg = is_array($json) ? ($json['msg'] ?? '') : mb_substr((string)$resp, 0, 200);
    $submit = rtrim($channel['gateway'], '/') . '/submit.php?' . http_build_query($params);
    return ['ok' => true, 'payurl' => $submit, 'qrcode' => '', 'trade_no' => '', 'mapi_error' => $msg];
}

// ==================== 下单：V免签（V免签Fox 协议） ====================
/** 建单签名：payId&param&type&price&notifyUrl&returnUrl */
function vmqfoxSignCreate(string $payId, string $param, string $type, string $price, string $notifyUrl, string $returnUrl, string $key): string {
    return hash_hmac('sha256', 'payId=' . $payId . '&param=' . $param . '&type=' . $type . '&price=' . $price
        . '&notifyUrl=' . $notifyUrl . '&returnUrl=' . $returnUrl, $key);
}
/** 回调/回跳签名：payId&param&type&price&reallyPrice */
function vmqfoxSignCallback(string $payId, string $param, string $type, string $price, string $reallyPrice, string $key): string {
    return hash_hmac('sha256', 'payId=' . $payId . '&param=' . $param . '&type=' . $type . '&price=' . $price
        . '&reallyPrice=' . $reallyPrice, $key);
}
/**
 * V免签下单：POST {gateway}/api/order/create
 * type: 1=微信 2=支付宝；返回 data.payUrl（支付宝二维码链接）与 data.redirectUrl（收银台）
 */
function vmqfoxCreate(array $order, array $channel): array {
    $payType = $order['channel'] === 'alipay' ? '2' : ($order['channel'] === 'wxpay' ? '1' : '');
    if ($payType === '') return ['ok' => false, 'error' => 'V免签仅支持支付宝 / 微信'];
    $payId  = (string)$order['order_no'];
    $param  = '';
    $price  = number_format((float)$order['amount'], 2, '.', '');
    $notify = payNotifyUrl();
    $ret    = payReturnUrl();
    $sign   = vmqfoxSignCreate($payId, $param, $payType, $price, $notify, $ret, (string)$channel['key']);
    $url = rtrim($channel['gateway'], '/') . '/api/order/create';
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded
User-Agent: ClassFund/1.0
",
        'content' => http_build_query(['payId' => $payId, 'param' => $param, 'type' => $payType, 'price' => $price,
            'notifyUrl' => $notify, 'returnUrl' => $ret, 'sign' => $sign]),
        'timeout' => 15, 'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $json = $resp ? json_decode($resp, true) : null;
    if (!is_array($json) || (int)($json['code'] ?? 0) !== 200) {
        $msg = is_array($json) ? ($json['msg'] ?? '') : mb_substr((string)$resp, 0, 200);
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'V免签下单失败'];
    }
    $d = is_array($json['data'] ?? null) ? $json['data'] : [];
    $qr = (string)($d['payUrl'] ?? '');
    return [
        'ok'      => true,
        'payurl'  => (string)($d['redirectUrl'] ?? '') !== '' ? (string)$d['redirectUrl'] : $qr,
        'qrcode'  => $qr,
        'trade_no' => (string)($d['orderId'] ?? ''),
    ];
}

/** 按通道类型下单：epay=易支付，vmqfox=V免签 */
function payCreateOrder(array $order, array $channel): array {
    return payChannelDriver($channel) === 'vmqfox' ? vmqfoxCreate($order, $channel) : epayCreate($order, $channel);
}

// ==================== 核销 ====================
/**
 * 把一笔在线支付并入「班费收缴」轮次账目。
 *   指定轮次：直接并入该轮；通用缴费（round_id=0）：按各轮待缴从旧到新依次并入。
 * 返回 ['merged' => [轮次ID => 并入金额], 'rest' => 未能并入的余额]。
 */
function payAttributeToRounds(int $studentId, int $roundId, float $amount): array {
    $merged = [];
    $left = round($amount, 2);
    if ($studentId <= 0 || $left <= 0) return ['merged' => $merged, 'rest' => $left];

    $eligible = (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
    $sql = "SELECT id, amount, expected_amount, per_person, payer_ids, exempt_ids FROM transactions
            WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL";
    if ($roundId > 0) {
        $st = db()->prepare($sql . " AND id=:id");
        $st->execute([':id' => $roundId]);
        $rounds = $st->fetchAll();
    } else {
        $rounds = db()->query($sql . " ORDER BY date ASC, id ASC")->fetchAll();
    }

    foreach ($rounds as $r) {
        if ($left <= 0) break;
        if ((string)($r['payer_ids'] ?? '') === 'all') continue;   // 全班轮次：该生已计入
        $pids = array_map('intval', json_decode($r['payer_ids'] ?? '', true) ?: []);
        if (in_array($studentId, $pids, true)) continue;           // 该生已在本轮缴费名单
        $exids = array_map('intval', json_decode($r['exempt_ids'] ?? '', true) ?: []);
        if (in_array($studentId, $exids, true)) continue;          // 本轮免缴
        $pp = payRoundPerPerson($r, $eligible);
        if ($pp <= 0 || $left + 0.001 < $pp) break;                // 余额不足以完整缴清本轮
        $pids[] = $studentId;
        db()->prepare("UPDATE transactions SET payer_ids=:p, amount=:a WHERE id=:id")->execute([
            ':p'  => json_encode(array_values(array_unique($pids))),
            ':a'  => round((float)$r['amount'] + $pp, 2),
            ':id' => (int)$r['id'],
        ]);
        $merged[(int)$r['id']] = round(($merged[(int)$r['id']] ?? 0) + $pp, 2);
        $left = round($left - $pp, 2);
    }
    return ['merged' => $merged, 'rest' => round($left, 2)];
}

/** 核销：写入 fee_payments，并把款项并入对应的班费收缴轮次（不再单独生成「线上缴费」账目） */
function paySettle(array $order, string $tradeNo): bool {
    try {
        db()->beginTransaction();
        $ins = db()->prepare("INSERT IGNORE INTO fee_payments
            (order_no, student_id, student_name, round_id, amount, channel, trade_no, paid_at)
            VALUES (:o, :sid, :sn, :rid, :a, :ch, :t, NOW())");
        $ins->execute([
            ':o' => $order['order_no'], ':sid' => (int)$order['student_id'], ':sn' => $order['student_name'],
            ':rid' => (int)$order['round_id'], ':a' => $order['amount'], ':ch' => $order['channel'], ':t' => $tradeNo,
        ]);
        if ($ins->rowCount() === 0) { db()->commit(); return true; }

        $amount = round((float)$order['amount'], 2);
        $attr = payAttributeToRounds((int)$order['student_id'], (int)$order['round_id'], $amount);
        $rest = round((float)$attr['rest'], 2);
        $txId = 0;
        if (!empty($attr['merged'])) $txId = (int)array_key_first($attr['merged']);
        if ($rest > 0) {
            // 无法并入轮次的余额（例如通用缴费时该生已缴清）：仍单独入账，避免总账丢失
            $desc = '线上缴费' . (!empty($order['student_name']) ? '-' . $order['student_name'] : '') . '（订单 ' . $order['order_no'] . '）';
            $tx = db()->prepare("INSERT INTO transactions (type, sub_category, amount, date, description, category, recorded_by)
                VALUES ('income', '线上缴费', :a, CURDATE(), :d, '班费', :rb)");
            $tx->execute([':a' => $rest, ':d' => mb_substr($desc, 0, 500), ':rb' => (int)($order['created_by'] ?? 0)]);
            $txId = (int)db()->lastInsertId();
        }
        db()->prepare("UPDATE fee_payments SET tx_id=:tid WHERE order_no=:o")->execute([':tid' => $txId, ':o' => $order['order_no']]);
        db()->prepare("UPDATE payment_orders SET settled=1 WHERE id=:id")->execute([':id' => (int)$order['id']]);
        db()->commit();
        addLog(0, 'system', 'pay_settle', 'payment', (int)$order['id'], [
            'order_no' => $order['order_no'], 'amount' => $amount, 'student_id' => $order['student_id'],
            'merged_rounds' => array_keys($attr['merged']), 'unattributed' => $rest,
        ]);
        return true;
    } catch (\Exception $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[班费系统] paySettle 失败: ' . $e->getMessage());
        return false;
    }
}

// ==================== 配置读取/保存 ====================
function payConfigPublic(): array {
    $c = getPayConfig();
    $file = __DIR__ . '/../pay_config.php';
    $channels = [];
    foreach (payChannels() as $ch) {
        $key = (string)$ch['key'];
        $channels[] = [
            'id' => $ch['id'], 'name' => $ch['name'], 'driver' => $ch['driver'] ?? 'epay',
            'gateway' => $ch['gateway'], 'pid' => $ch['pid'],
            'types' => $ch['types'], 'enabled' => $ch['enabled'],
            'key_set' => $key !== '',
            'key_hint' => $key !== '' ? ('****' . (strlen($key) > 4 ? substr($key, -4) : '')) : '',
        ];
    }
    return [
        'enabled'      => !empty($c['enabled']),
        'channels'     => $channels,
        'methods'      => payMethods(),
        'available_types' => payAvailableTypes(),
        'default_type' => (string)$c['default_type'],
        'settle_mode'  => (string)$c['settle_mode'],
        'sign_type'    => (string)$c['sign_type'],
        'notify_url'   => payNotifyUrl(),
        'return_url'   => payReturnUrl(),
        'min_amount'   => (float)$c['min_amount'],
        'max_amount'   => (float)$c['max_amount'],
        'sitename'     => (string)$c['sitename'],
        'configured'   => payConfigured(),
        'file_exists'  => is_file($file),
        'writable'     => is_file($file) ? is_writable($file) : is_writable(dirname($file)),
    ];
}

function handlePayConfigGet() {
    requirePermission('viewSecurity');
    jsonOutput(['config' => payConfigPublic()]);
}

function handlePayConfigSave() {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $input = jsonInput();
    $old = getPayConfig();

    $enabled = !empty($input['enabled']) && $input['enabled'] !== '0' && $input['enabled'] !== 'false';
    $settleMode = (($input['settle_mode'] ?? '') === 'manual') ? 'manual' : 'auto';
    $minAmount = round((float)($input['min_amount'] ?? 0.01), 2);
    $maxAmount = round((float)($input['max_amount'] ?? 2000), 2);
    $sitename  = mb_substr(trim((string)($input['sitename'] ?? SITE_NAME)), 0, 50);
    $defaultType = in_array($input['default_type'] ?? '', ['alipay', 'wxpay', 'qqpay'], true) ? $input['default_type'] : 'alipay';
    if ($maxAmount <= 0 || $minAmount < 0 || $minAmount > $maxAmount) jsonOutput(['error' => '金额范围不正确'], 400);

    // 支付方式定义
    $methodsIn = $input['methods'] ?? [];
    if (is_string($methodsIn)) $methodsIn = json_decode($methodsIn, true) ?: [];
    if (!is_array($methodsIn)) $methodsIn = [];
    $methods = []; $methodCodes = [];
    foreach ($methodsIn as $m) {
        if (!is_array($m)) continue;
        $code = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($m['code'] ?? ''));
        if ($code === '' || in_array($code, $methodCodes, true)) continue;
        $label = mb_substr(trim((string)($m['label'] ?? '')), 0, 20) ?: $code;
        $methodCodes[] = $code;
        $methods[] = ['code' => $code, 'label' => $label];
    }
    if (empty($methods)) {
        $methods = payMethods();
        $methodCodes = array_column($methods, 'code');
    }

    $channelsIn = $input['channels'] ?? [];
    if (is_string($channelsIn)) $channelsIn = json_decode($channelsIn, true) ?: [];
    if (!is_array($channelsIn)) $channelsIn = [];
    if (count($channelsIn) > 20) jsonOutput(['error' => '通道数量过多（最多 20 个）'], 400);

    $existing = [];
    foreach (payChannels() as $ch) $existing[$ch['id']] = $ch;

    $channels = [];
    foreach ($channelsIn as $i => $ch) {
        if (!is_array($ch)) continue;
        $id   = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($ch['id'] ?? ''));
        if ($id === '') $id = 'ch' . ($i + 1);
        $name = mb_substr(trim((string)($ch['name'] ?? '')), 0, 50) ?: ('通道' . ($i + 1));
        $gw   = rtrim(trim((string)($ch['gateway'] ?? '')), '/');
        $pid  = trim((string)($ch['pid'] ?? ''));
        $keyIn = (string)($ch['key'] ?? '');
        $types = array_values(array_intersect(array_map('strval', (array)($ch['types'] ?? [])), $methodCodes));
        $en   = !empty($ch['enabled']) && $ch['enabled'] !== '0' && $ch['enabled'] !== 'false';
        $driver = strtolower(trim((string)($ch['driver'] ?? 'epay')));
        if (!in_array($driver, ['epay', 'vmqfox'], true)) $driver = 'epay';

        if ($gw !== '' && !preg_match('#^https?://[^\s/]+#i', $gw)) jsonOutput(['error' => '通道「' . $name . '」网关地址格式不正确'], 400);
        if ($pid !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $pid)) jsonOutput(['error' => '通道「' . $name . '」商户ID格式不正确'], 400);
        if ($keyIn !== '' && !preg_match('/^[^\s\'"\r\n]{1,128}$/', $keyIn)) jsonOutput(['error' => '通道「' . $name . '」密钥含非法字符'], 400);
        $key = $keyIn !== '' ? $keyIn : (string)($existing[$id]['key'] ?? '');
        if (empty($types)) $types = $methodCodes;
        if ($en && ($gw === '' || $key === '' || ($driver === 'epay' && $pid === ''))) {
            jsonOutput(['error' => '通道「' . $name . '」启用前需填完整：' . ($driver === 'vmqfox' ? '网关 / 通讯密钥' : '网关 / 商户ID / 密钥')], 400);
        }

        $channels[] = ['id' => $id, 'name' => $name, 'driver' => $driver, 'gateway' => $gw, 'pid' => $pid, 'key' => $key, 'types' => $types, 'enabled' => $en];
    }
    $usable = array_filter($channels, function ($c) { return function_exists('payChannelReady') ? payChannelReady($c) : ($c['enabled'] && $c['gateway'] !== '' && $c['key'] !== ''); });
    if ($enabled && count($usable) === 0) jsonOutput(['error' => '开启在线支付前，至少需要一个「配置完整且已启用」的通道'], 400);

    // 默认支付方式若不在可用集合内，自动回退到第一个可用类型
    $avail = [];
    foreach ($usable as $c) foreach ($c['types'] as $t) if (!in_array($t, $avail, true)) $avail[] = $t;
    if (!empty($avail) && !in_array($defaultType, $avail, true)) $defaultType = $avail[0];

    $cfg = [
        'enabled' => $enabled, 'channels' => $channels, 'methods' => $methods,
        'gateway' => '', 'pid' => '', 'key' => '',
        'sign_type' => 'MD5', 'default_type' => $defaultType, 'settle_mode' => $settleMode,
        'notify_url' => '', 'return_url' => '',
        'min_amount' => $minAmount, 'max_amount' => $maxAmount, 'sitename' => $sitename,
    ];
    $php  = "<?php\n/** 由管理界面生成于 " . date('Y-m-d H:i:s') . "（含密钥，请勿提交仓库） */\n";
    $php .= 'return ' . var_export($cfg, true) . ";\n";
    $file = __DIR__ . '/../pay_config.php';
    $tmp  = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $php) === false) {
        @chmod(dirname($file), 0755);
        if (@file_put_contents($tmp, $php) === false) jsonOutput(['error' => '无法写入 pay_config.php，请检查站点目录权限'], 500);
    }
    @chmod($tmp, 0600);
    if (is_file($file)) @chmod($file, 0640);
    if (!@rename($tmp, $file)) { @unlink($tmp); jsonOutput(['error' => '保存失败（文件替换失败）'], 500); }
    @chmod($file, 0600);

    getPayConfig(true);
    $user = currentUser();
    addLog($user['id'], $user['username'], 'pay_config_save', 'system', null, [
        'enabled' => $enabled, 'channels' => count($channels),
        'enabled_channels' => count($usable), 'settle_mode' => $settleMode,
    ]);
    jsonOutput(['ok' => true, 'config' => payConfigPublic()]);
}

// ==================== 接口：元数据 ====================
function handlePayMeta() {
    requireLogin();
    $cfg = getPayConfig();
    $roster = db()->query("SELECT id, name, exempt FROM class_roster ORDER BY id")->fetchAll();
    $rounds = db()->query("SELECT id, date, description, amount, expected_amount, per_person FROM transactions
        WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL
        ORDER BY date DESC, id DESC LIMIT 100")->fetchAll();
    $eligibleNow = (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
    foreach ($rounds as &$rd) { $rd['per_person'] = payRoundPerPerson($rd, $eligibleNow); }
    unset($rd);
    $paidMap = [];
    foreach (db()->query(payOnlineUnattributedSql())->fetchAll() as $r) {
        $paidMap[(int)$r['student_id']] = round((float)$r['v'], 2);
    }
    $usable = array_values(array_filter(payChannels(), function ($c) { return $c['enabled'] && $c['gateway'] !== '' && $c['pid'] !== '' && $c['key'] !== ''; }));
    jsonOutput([
        'roster' => $roster, 'rounds' => $rounds,
        'per_person' => (float)getMeta('per_person', '0'),
        'paid_map' => $paidMap,
        'fee_status' => payAllFeeStatus(),
        'config' => [
            'enabled' => payConfigured(),
            'settle_mode' => $cfg['settle_mode'],
            'default_type' => $cfg['default_type'],
            'available_types' => payAvailableTypes(),
            'methods' => payMethods(),
            'channels' => array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'types' => $c['types']]; }, $usable),
        ],
    ]);
}

// ==================== 接口：创建订单 ====================
function handlePayCreate() {
    requireLogin();
    requireCsrfToken();
    requireRateLimit('pay_create', 20, 60);
    if (!payConfigured()) jsonOutput(['error' => '支付通道未配置或未开启，请联系管理员'], 400);

    $cfg = getPayConfig();
    $input = jsonInput();
    $studentId = (int)($input['student_id'] ?? 0);
    $roundId   = (int)($input['round_id'] ?? 0);
    $type      = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($input['channel'] ?? ''));
    if ($type === '') $type = (string)$cfg['default_type'];
    $channelId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($input['channel_id'] ?? ''));

    $channel = payPickChannel($type, $channelId);
    if (!$channel) jsonOutput(['error' => '所选支付方式当前不可用'], 400);

    $studentName = '';
    if ($studentId > 0) {
        $st = db()->prepare("SELECT id, name FROM class_roster WHERE id=:id");
        $st->execute([':id' => $studentId]);
        $row = $st->fetch();
        if (!$row) jsonOutput(['error' => '学生不存在'], 400);
        $studentName = $row['name'];
    }

    // 应缴金额：指定轮次=该轮每人应缴；通用=该学生各轮 per_person 相加后的待缴余额
    if ($roundId > 0) {
        $rt = db()->prepare("SELECT id, amount, expected_amount, per_person, payer_ids FROM transactions WHERE id=:id AND deleted_at IS NULL");
        $rt->execute([':id' => $roundId]);
        $rtx = $rt->fetch();
        if (!$rtx) jsonOutput(['error' => '缴费轮次不存在'], 400);
        $amount = payRoundPerPerson($rtx);
    } else {
        if ($studentId <= 0) jsonOutput(['error' => '请先选择缴费学生'], 400);
        $amount = payStudentStatus($studentId)['outstanding'];
    }
    if ($amount <= 0) jsonOutput(['error' => $roundId > 0 ? '该轮次未设置每人应缴金额' : '该学生当前已缴清，无需缴费'], 400);
    if ($amount < (float)$cfg['min_amount'] || $amount > (float)$cfg['max_amount']) jsonOutput(['error' => '金额超出允许范围'], 400);

    $orderNo = 'CF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    $user = currentUser();
    db()->prepare("INSERT INTO payment_orders
        (order_no, student_id, student_name, round_id, amount, channel, channel_id, status, settle_mode, created_by)
        VALUES (:o, :sid, :sn, :rid, :a, :ch, :cid, 'pending', :sm, :cb)")
        ->execute([
            ':o' => $orderNo, ':sid' => $studentId, ':sn' => $studentName ?: null,
            ':rid' => $roundId, ':a' => $amount, ':ch' => $type, ':cid' => $channel['id'],
            ':sm' => $cfg['settle_mode'], ':cb' => (int)$user['id'],
        ]);

    $name = '班费缴费' . ($studentName !== '' ? '-' . $studentName : '');
    $res = payCreateOrder(['order_no' => $orderNo, 'channel' => $type, 'amount' => $amount, 'name' => $name], $channel);
    if (!$res['ok']) {
        db()->prepare("UPDATE payment_orders SET status='closed' WHERE order_no=:o")->execute([':o' => $orderNo]);
        jsonOutput(['error' => '下单失败：' . ($res['error'] ?? '未知错误')], 502);
    }
    db()->prepare("UPDATE payment_orders SET pay_url=:u WHERE order_no=:o")->execute([':u' => mb_substr($res['payurl'], 0, 1000), ':o' => $orderNo]);

    jsonOutput([
        'ok' => true, 'order_no' => $orderNo, 'amount' => $amount, 'channel' => $type, 'channel_id' => $channel['id'],
        'payurl' => $res['payurl'], 'qrcode' => $res['qrcode'],
    ]);
}

// ==================== 接口：异步回调（免登录） ====================
function handlePayNotify() {
    header('Content-Type: text/plain; charset=utf-8');
    if (!payConfigured()) { echo 'fail'; exit; }

    $params = array_merge($_GET, $_POST);
    unset($params['action']);
    // 易支付回调用 out_trade_no；V免签回调用 payId
    $outNo = (string)($params['out_trade_no'] ?? $params['payId'] ?? '');
    if ($outNo === '') { echo 'fail'; exit; }

    $stmt = db()->prepare("SELECT * FROM payment_orders WHERE order_no=:o LIMIT 1");
    $stmt->execute([':o' => $outNo]);
    $order = $stmt->fetch();
    if (!$order) { securityLog('pay_notify_order_missing', ['out_trade_no' => $outNo]); echo 'fail'; exit; }

    // 用订单所属通道的密钥验签
    $channel = null;
    foreach (payChannels() as $ch) { if ($ch['id'] === (string)($order['channel_id'] ?? '')) { $channel = $ch; break; } }
    if (!$channel) $channel = payPickChannel((string)$order['channel']);

    $driver  = $channel ? payChannelDriver($channel) : 'epay';
    $tradeNo = '';
    $money   = '0';
    $status  = 'TRADE_SUCCESS';

    if ($driver === 'vmqfox') {
        if (!$channel) { echo 'fail'; exit; }
        $payId  = (string)($params['payId'] ?? '');
        $param  = (string)($params['param'] ?? '');
        $ptype  = (string)($params['type'] ?? '');
        $price  = (string)($params['price'] ?? '0');
        $really = (string)($params['reallyPrice'] ?? '0');
        $sign   = (string)($params['sign'] ?? '');
        $expect = vmqfoxSignCallback($payId, $param, $ptype, $price, $really, (string)$channel['key']);
        if ($sign === '' || !hash_equals($expect, $sign)) {
            securityLog('pay_notify_bad_sign', ['out_trade_no' => $outNo, 'driver' => 'vmqfox']);
            echo 'fail'; exit;
        }
        $tradeNo = (string)($params['orderId'] ?? '');
        $money   = (float)$really > 0 ? $really : $price;
    } else {
        if (!$channel || !epayVerify($params, $channel['key'])) {
            securityLog('pay_notify_bad_sign', ['out_trade_no' => $outNo, 'channel' => $order['channel_id'] ?? '']);
            echo 'fail'; exit;
        }
        $tradeNo = (string)($params['trade_no'] ?? '');
        $money   = (string)($params['money'] ?? '0');
        $status  = (string)($params['trade_status'] ?? '');
    }
    if ($status !== 'TRADE_SUCCESS') { echo 'success'; exit; }

    if (!hash_equals(number_format((float)$order['amount'], 2, '.', ''), number_format((float)$money, 2, '.', ''))) {
        securityLog('pay_notify_amount_mismatch', ['out_trade_no' => $outNo, 'order' => $order['amount'], 'notify' => $money]);
        echo 'fail'; exit;
    }
    if ($order['status'] === 'paid') { echo 'success'; exit; }

    db()->prepare("UPDATE payment_orders SET status='paid', trade_no=:t, paid_at=NOW(), notify_raw=:r WHERE id=:id")
        ->execute([':t' => mb_substr($tradeNo, 0, 64), ':r' => mb_substr((string)json_encode($params, JSON_UNESCAPED_UNICODE), 0, 60000), ':id' => (int)$order['id']]);
    $order['status'] = 'paid';

    if (($order['settle_mode'] ?? getPayConfig()['settle_mode']) === 'auto') paySettle($order, $tradeNo);
    echo 'success';
    exit;
}

// ==================== 接口：同步返回 ====================
function handlePayReturn() {
    $outNo = escapeHtml((string)($_GET['out_trade_no'] ?? ''));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>支付结果</title><style>body{font-family:-apple-system,"PingFang SC",sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.card{background:#fff;border-radius:14px;padding:32px 28px;box-shadow:0 10px 30px rgba(0,0,0,.08);text-align:center;max-width:90vw}'
        . 'h2{color:#10b981;margin:0 0 10px}p{color:#64748b;font-size:14px;margin:6px 0}a{display:inline-block;margin-top:16px;padding:10px 22px;background:#6366f1;color:#fff;border-radius:8px;text-decoration:none}</style>'
        . '</head><body><div class="card"><h2>✅ 支付已提交</h2><p>订单号：' . $outNo . '</p><p>若已支付成功，系统将自动核销；如状态未更新，请稍后刷新或联系班委。</p>'
        . '<a href="/">返回班费系统</a></div></body></html>';
    exit;
}

// ==================== 接口：订单管理 ====================
function handlePayOrders() {
    requirePermission('viewPayments');
    $status = $_GET['status'] ?? '';
    $where = "WHERE 1=1"; $params = [];
    if (in_array($status, ['pending', 'paid', 'closed'], true)) { $where .= " AND status=:s"; $params[':s'] = $status; }
    $stmt = db()->prepare("SELECT * FROM payment_orders {$where} ORDER BY id DESC LIMIT 200");
    $stmt->execute($params);
    $sum = db()->query("SELECT
        SUM(status='paid') paid_cnt,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid_amount,
        SUM(status='pending') pending_cnt,
        SUM(status='paid' AND settled=0) unsettled_cnt
        FROM payment_orders")->fetch();
    jsonOutput([
        'orders' => $stmt->fetchAll(),
        'summary' => [
            'paid_cnt' => (int)($sum['paid_cnt'] ?? 0), 'paid_amount' => round((float)($sum['paid_amount'] ?? 0), 2),
            'pending_cnt' => (int)($sum['pending_cnt'] ?? 0), 'unsettled_cnt' => (int)($sum['unsettled_cnt'] ?? 0),
        ],
    ]);
}

function handlePayConfirm() {
    requirePermission('manageRoster');
    requireCsrfToken();
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonOutput(['error' => '无效订单'], 400);
    $stmt = db()->prepare("SELECT * FROM payment_orders WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $order = $stmt->fetch();
    if (!$order) jsonOutput(['error' => '订单不存在'], 404);
    if ($order['status'] !== 'paid') jsonOutput(['error' => '订单尚未支付成功，无法核销'], 400);
    if (!empty($order['settled'])) jsonOutput(['error' => '该订单已核销'], 400);
    $ok = paySettle($order, (string)$order['trade_no']);
    $user = currentUser();
    addLog($user['id'], $user['username'], 'pay_confirm', 'payment', $id, ['order_no' => $order['order_no']]);
    $ok ? jsonOutput(['ok' => true]) : jsonOutput(['error' => '核销失败'], 500);
}

function handlePayCancel() {
    requirePermission('manageRoster');
    requireCsrfToken();
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) jsonOutput(['error' => '无效订单'], 400);
    db()->prepare("UPDATE payment_orders SET status='closed' WHERE id=:id AND status='pending'")->execute([':id' => $id]);
    $user = currentUser();
    addLog($user['id'], $user['username'], 'pay_cancel', 'payment', $id);
    jsonOutput(['ok' => true]);
}
