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
    ];
}

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
        ['key' => 'round',    'cmd' => '本轮班费',          'desc' => '最新一轮班费信息',            'always' => false],
        ['key' => 'overview', 'cmd' => '班级概况 / 结余',   'desc' => '总收入 / 总支出 / 结余',      'always' => false],
        ['key' => 'progress', 'cmd' => '收缴进度',          'desc' => '已缴 / 未缴人数与收缴率',     'always' => false],
        ['key' => 'recent',   'cmd' => '最近收支',          'desc' => '最近 10 笔收支记录',          'always' => false],
        ['key' => 'unpaid',   'cmd' => '未缴名单',          'desc' => '未缴清学生与金额（管理员）',  'always' => false],
        ['key' => 'urge',     'cmd' => '催缴',              'desc' => '生成催缴名单，群内 @ 未缴同学（管理员）', 'always' => false],
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

    if (qqCmdOn($c, 'urge') && preg_match('/^(?:催缴|催交|催收|提醒缴费)(?:[\s:：]+(.+))?$/u', $cmd, $m)) {
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

    // 我的链接
    if (qqCmdOn($c, 'link') && preg_match('/^(?:我的链接|缴费链接|我的二维码|链接)$/u', $cmd)) {
        $bind = qqBindingStudent($key);
        if (!$bind) { qqReply($c, $scope, $target, $msgId, $noBind); return; }
        qqReply($c, $scope, $target, $msgId,
            "【" . $bind['name'] . " 专属缴费链接】\n" . payBaseUrl() . '/?pay=' . (int)$bind['student_id']
            . "\n\n点开后选择本人姓名即可在线缴纳。");
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
