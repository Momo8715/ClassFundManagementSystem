<?php
/**
 * 班级班费管理系统 - 安全分析模块（v1.8 细化版）
 * 登录审计 / 异常检测 / 用户画像 / IP 黑名单 / 安全事件 / 审计导出
 */

/** 解析时间范围参数，返回 [range, 登录表别名 l 的条件, 中文标签, 映射表] */
function securityRangeInfo(): array {
    $range = $_GET['range'] ?? '7d';
    if (!in_array($range, ['24h', '7d', '30d', 'all'], true)) $range = '7d';
    $map = ['24h' => '24 HOUR', '7d' => '7 DAY', '30d' => '30 DAY'];
    $sql = $range === 'all' ? '' : " AND l.created_at >= (NOW() - INTERVAL {$map[$range]})";
    $labels = ['24h' => '近24小时', '7d' => '近7天', '30d' => '近30天', 'all' => '全部时间'];
    return [$range, $sql, $labels[$range], $map];
}

/** 构建登录明细筛选条件（面板与导出复用），返回 [where, params, rangeLabel, fUser, fResult, fKw, fIp] */
function securityDetailFilters(): array {
    [$range, $rangeSql, $rangeLabel, $map] = securityRangeInfo();
    $where = "WHERE 1=1" . $rangeSql;
    $params = [];

    $fUser = (int)($_GET['f_user'] ?? 0);
    $fResult = $_GET['f_result'] ?? '';
    $fKw = trim((string)($_GET['f_kw'] ?? ''));
    $fIp = trim((string)($_GET['f_ip'] ?? ''));

    if ($fUser > 0) { $where .= " AND l.user_id = :fu"; $params[':fu'] = $fUser; }
    if ($fResult === 'ok') { $where .= " AND l.success = 1"; }
    elseif ($fResult === 'fail') { $where .= " AND l.success = 0"; }
    if ($fKw !== '') {
        $where .= " AND (l.username LIKE :kw OR l.ipv4_address LIKE :kw OR l.fail_reason LIKE :kw)";
        $params[':kw'] = '%' . $fKw . '%';
    }
    if ($fIp !== '') { $where .= " AND l.ipv4_address = :fip"; $params[':fip'] = $fIp; }

    return [$where, $params, $rangeLabel, $fUser, $fResult, $fKw, $fIp];
}

function handleSecurityAnalysis() {
    requirePermission('viewSecurity');

    [$range, $rangeSql, $rangeLabel, $map] = securityRangeInfo();
    [$detailWhere, $detailParams, $rangeLabel2, $fUser, $fResult, $fKw, $fIp] = securityDetailFilters();

    // ---- 概览（按所选时间范围） ----
    $s = db()->query("SELECT COUNT(*) total, SUM(l.success=1) success_cnt, SUM(l.success=0) fail_cnt,
        COUNT(DISTINCT l.ipv4_address) ips, COUNT(DISTINCT l.user_id) users
        FROM login_history l WHERE 1=1 {$rangeSql}")->fetch();
    $failN = (int)($s['fail_cnt'] ?? 0);

    // ---- 同指纹多账号 ----
    $multiAccount = db()->query("SELECT l.fingerprint,
        COUNT(DISTINCT l.user_id) accounts, GROUP_CONCAT(DISTINCT l.username) names, COUNT(*) logins
        FROM login_history l
        WHERE l.fingerprint IS NOT NULL AND l.fingerprint != '' AND l.success=1 {$rangeSql}
        GROUP BY l.fingerprint HAVING accounts > 1 ORDER BY accounts DESC LIMIT 30")->fetchAll();

    // ---- 同账号多 IP ----
    $multiIp = db()->query("SELECT l.user_id, MAX(l.username) username,
        COUNT(DISTINCT l.ipv4_address) ip_count, GROUP_CONCAT(DISTINCT l.ipv4_address) ips, COUNT(*) logins
        FROM login_history l WHERE l.success=1 {$rangeSql}
        GROUP BY l.user_id HAVING ip_count > 1 ORDER BY ip_count DESC LIMIT 30")->fetchAll();

    // ---- 同 IP 多账号（信息展示，不计入风险分） ----
    $multiIpAccounts = db()->query("SELECT l.ipv4_address,
        COUNT(DISTINCT l.user_id) accounts, GROUP_CONCAT(DISTINCT l.username) names,
        COUNT(*) logins, MAX(l.created_at) last_login
        FROM login_history l
        WHERE l.success=1 AND l.ipv4_address IS NOT NULL AND l.ipv4_address != '' {$rangeSql}
        GROUP BY l.ipv4_address HAVING accounts > 1 ORDER BY accounts DESC, logins DESC LIMIT 30")->fetchAll();

    // ---- 登录失败统计（按账号） ----
    $failures = db()->query("SELECT l.username, COUNT(*) attempts, MAX(l.created_at) last_attempt
        FROM login_history l WHERE l.success=0 {$rangeSql}
        GROUP BY l.username ORDER BY attempts DESC LIMIT 30")->fetchAll();

    // ---- 失败原因分布 ----
    $failReasons = db()->query("SELECT COALESCE(NULLIF(l.fail_reason,''),'未注明') reason, COUNT(*) cnt
        FROM login_history l WHERE l.success=0 {$rangeSql}
        GROUP BY reason ORDER BY cnt DESC LIMIT 20")->fetchAll();

    // ---- 可疑 IP（失败 >= 3） ----
    $ipRisk = db()->query("SELECT l.ipv4_address ip, COUNT(*) attempts, COUNT(DISTINCT l.username) accounts,
        GROUP_CONCAT(DISTINCT l.username) names, MIN(l.created_at) first_attempt, MAX(l.created_at) last_attempt
        FROM login_history l
        WHERE l.success=0 AND l.ipv4_address IS NOT NULL AND l.ipv4_address != '' {$rangeSql}
        GROUP BY l.ipv4_address HAVING attempts >= 3 ORDER BY attempts DESC LIMIT 30")->fetchAll();
    $suspectIpCount = count($ipRisk);

    // ---- 用户安全画像 ----
    $profiles = db()->query("SELECT l.user_id, MAX(l.username) username,
        SUM(l.success=1) ok, SUM(l.success=0) fail,
        COUNT(DISTINCT l.ipv4_address) ips, COUNT(DISTINCT l.fingerprint) fps,
        MAX(l.created_at) last_login
        FROM login_history l WHERE 1=1 {$rangeSql}
        GROUP BY l.user_id ORDER BY fail DESC, ok DESC LIMIT 100")->fetchAll();
    $userMeta = [];
    foreach (db()->query("SELECT id, banned, ban_reason FROM users")->fetchAll() as $u) { $userMeta[(int)$u['id']] = $u; }
    foreach ($profiles as &$p) {
        $uid = (int)$p['user_id'];
        $p['user_id'] = $uid;
        $p['ok'] = (int)$p['ok']; $p['fail'] = (int)$p['fail'];
        $p['ips'] = (int)$p['ips']; $p['fps'] = (int)$p['fps'];
        $p['banned'] = isset($userMeta[$uid]) ? (int)$userMeta[$uid]['banned'] : 0;
        $p['ban_reason'] = $userMeta[$uid]['ban_reason'] ?? null;
    }
    unset($p);

    // ---- 登录时段分布（0-23） ----
    $hourRows = db()->query("SELECT HOUR(l.created_at) h, SUM(l.success=1) s, SUM(l.success=0) f
        FROM login_history l WHERE 1=1 {$rangeSql} GROUP BY h")->fetchAll();
    $hourly = [];
    for ($i = 0; $i < 24; $i++) $hourly[$i] = ['h' => $i, 's' => 0, 'f' => 0];
    foreach ($hourRows as $r) { $hourly[(int)$r['h']] = ['h' => (int)$r['h'], 's' => (int)$r['s'], 'f' => (int)$r['f']]; }
    $hourly = array_values($hourly);

    // ---- 每日趋势 ----
    $daily = db()->query("SELECT DATE(l.created_at) d, SUM(l.success=1) s, SUM(l.success=0) f
        FROM login_history l WHERE 1=1 {$rangeSql} GROUP BY d ORDER BY d")->fetchAll();

    // ---- 新 IP 登录（该账号历史首次出现的 IP，首次出现落在所选范围内） ----
    $newIpWindow = $range === '24h' ? '24 HOUR' : ($range === '7d' ? '7 DAY' : '30 DAY');
    $newIpLogins = db()->query("SELECT l.user_id, MAX(l.username) username, l.ipv4_address ip,
        MIN(l.created_at) first_seen, MAX(l.created_at) last_seen, COUNT(*) cnt
        FROM login_history l WHERE l.success=1 AND l.ipv4_address IS NOT NULL AND l.ipv4_address != ''
        GROUP BY l.user_id, l.ipv4_address
        HAVING first_seen >= (NOW() - INTERVAL {$newIpWindow})
        ORDER BY first_seen DESC LIMIT 30")->fetchAll();

    // ---- 被封禁账号 / IP 黑名单 ----
    $bannedCount  = (int)db()->query("SELECT COUNT(*) FROM users WHERE banned=1")->fetchColumn();
    $blockedCount = (int)db()->query("SELECT COUNT(*) FROM blocked_ips")->fetchColumn();
    $bannedUsers  = db()->query("SELECT id, username, ban_reason, created_at FROM users WHERE banned=1 ORDER BY id")->fetchAll();
    $blockedIps   = db()->query("SELECT id, ip, reason, created_by, created_at FROM blocked_ips ORDER BY id DESC LIMIT 100")->fetchAll();

    // ---- 安全事件（按范围） ----
    $evRange = $range === 'all' ? '' : " AND created_at >= (NOW() - INTERVAL {$map[$range]})";
    $eventCount = (int)db()->query("SELECT COUNT(*) FROM security_events WHERE 1=1 {$evRange}")->fetchColumn();
    $events = db()->query("SELECT * FROM security_events WHERE 1=1 {$evRange} ORDER BY id DESC LIMIT 50")->fetchAll();

    // ---- 登录明细（分页 + 筛选） ----
    $page = max(1, (int)($_GET['detail_page'] ?? 1));
    $per  = min(100, max(10, (int)($_GET['detail_per'] ?? 20)));
    $cntStmt = db()->prepare("SELECT COUNT(*) FROM login_history l {$detailWhere}");
    $cntStmt->execute($detailParams);
    $detailTotal = (int)$cntStmt->fetchColumn();
    $off = ($page - 1) * $per;
    $detStmt = db()->prepare("SELECT l.* FROM login_history l {$detailWhere} ORDER BY l.id DESC LIMIT {$off}, {$per}");
    $detStmt->execute($detailParams);
    $detail = $detStmt->fetchAll();

    // ---- 风险评分构成 ----
    $breakdown = [];
    if ($failN >= 20)      $breakdown[] = ['label' => $rangeLabel . '失败登录 ≥ 20 次', 'score' => 3];
    elseif ($failN >= 5)   $breakdown[] = ['label' => $rangeLabel . '失败登录 ≥ 5 次', 'score' => 1];
    if ($suspectIpCount)   $breakdown[] = ['label' => '可疑 IP（失败≥3次）：' . $suspectIpCount . ' 个', 'score' => 2];
    $heavy = count(array_filter($multiAccount, function ($r) { return (int)$r['accounts'] >= 3; }));
    if ($heavy)            $breakdown[] = ['label' => '同指纹 ≥ 3 账号：' . $heavy . ' 组', 'score' => 1];
    if ($eventCount)       $breakdown[] = ['label' => '存在安全事件：' . $eventCount . ' 条', 'score' => 1];
    if ($bannedCount)      $breakdown[] = ['label' => '存在被封禁账号：' . $bannedCount . ' 个', 'score' => 1];
    $score = 0;
    foreach ($breakdown as $b) $score += $b['score'];
    $riskLevel = $score >= 5 ? 'high' : ($score >= 2 ? 'medium' : 'low');

    jsonOutput([
        'range'             => $range,
        'summary'           => [
            'range' => $range, 'range_label' => $rangeLabel,
            'total' => (int)($s['total'] ?? 0), 'success' => (int)($s['success_cnt'] ?? 0),
            'fail' => $failN, 'ips' => (int)($s['ips'] ?? 0), 'users' => (int)($s['users'] ?? 0),
            'suspect_ip' => $suspectIpCount, 'banned' => $bannedCount, 'blocked' => $blockedCount,
            'events' => $eventCount, 'risk_level' => $riskLevel, 'risk_score' => $score,
        ],
        'risk_breakdown'    => $breakdown,
        'hourly'            => $hourly,
        'daily'             => $daily,
        'user_profiles'     => $profiles,
        'new_ip_logins'     => $newIpLogins,
        'multi_account'     => $multiAccount,
        'multi_ip'          => $multiIp,
        'multi_ip_accounts' => $multiIpAccounts,
        'failures'          => $failures,
        'fail_reasons'      => $failReasons,
        'ip_risk'           => $ipRisk,
        'banned_users'      => $bannedUsers,
        'blocked_ips'       => $blockedIps,
        'events'            => $events,
        'detail'            => $detail,
        'detail_total'      => $detailTotal,
        'detail_page'       => $page,
        'detail_pages'      => (int)ceil($detailTotal / max(1, $per)),
        'detail_per'        => $per,
        'filter_users'      => db()->query("SELECT id, username FROM users ORDER BY id")->fetchAll(),
        'filters'           => ['f_user' => $fUser, 'f_result' => $fResult, 'f_kw' => $fKw, 'f_ip' => $fIp],
        'recent'            => db()->query("SELECT * FROM login_history ORDER BY created_at DESC LIMIT 50")->fetchAll(),
    ]);
}

/** 导出安全审计 CSV（沿用当前筛选条件，最多 2 万条） */
function handleExportSecurityCsv() {
    requirePermission('viewSecurity');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: ' . contentDisposition('security_login_' . date('Ymd_His') . '.csv'));
    [$where, $params] = securityDetailFilters();
    $stmt = db()->prepare("SELECT l.* FROM login_history l {$where} ORDER BY l.id DESC LIMIT 20000");
    $stmt->execute($params);
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', '时间', '用户ID', '用户名', '登录方式', '结果', '失败原因', 'IPv4', 'IPv6', '浏览器', '指纹']);
    while ($r = $stmt->fetch()) {
        fputcsv($out, array_map('csvSafe', [
            $r['id'], $r['created_at'], $r['user_id'], $r['username'], $r['login_type'],
            ((int)$r['success'] === 1 ? '成功' : '失败'),
            $r['fail_reason'] ?? '', $r['ipv4_address'] ?? '', $r['ipv6_address'] ?? '',
            $r['browser_info'] ?? '', $r['fingerprint'] ?? '',
        ]));
    }
    fclose($out);
    exit;
}

// ==================== IP 黑名单管理 ====================
function handleBlockedIp(string $method) {
    requirePermission('viewSecurity');
    $user = currentUser();

    if ($method === 'GET') {
        $rows = db()->query("SELECT id, ip, reason, created_by, created_at FROM blocked_ips ORDER BY id DESC LIMIT 200")->fetchAll();
        jsonOutput(['blocked_ips' => $rows]);
    }

    if ($method === 'POST') {
        requireCsrfToken();
        $input  = jsonInput();
        $ip     = trim($input['ip'] ?? '');
        $reason = mb_substr(trim($input['reason'] ?? ''), 0, 200);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) jsonOutput(['error' => 'IP 格式无效'], 400);
        if ($ip === '127.0.0.1' || $ip === '::1') jsonOutput(['error' => '不能封禁本机回环地址'], 400);

        [$self4, $self6] = getClientIPs();
        if ($ip === $self4 || $ip === $self6) jsonOutput(['error' => '不能封禁当前访问所使用的 IP'], 400);

        db()->prepare("INSERT INTO blocked_ips (ip, reason, created_by) VALUES (:ip, :r, :u)
            ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by)")
            ->execute([':ip' => $ip, ':r' => $reason ?: null, ':u' => $user['id']]);

        addLog($user['id'], $user['username'], 'block_ip', 'system', null, ['ip' => $ip, 'reason' => $reason]);
        securityLog('ip_blocked', ['ip' => $ip, 'reason' => $reason]);
        jsonOutput(['ok' => true]);
    }

    if ($method === 'DELETE') {
        requireCsrfToken();
        $input = jsonInput();
        $id = intval($_GET['id'] ?? $input['id'] ?? 0);
        $ip = trim($input['ip'] ?? '');
        if ($id > 0) {
            db()->prepare("DELETE FROM blocked_ips WHERE id=:id")->execute([':id' => $id]);
        } elseif ($ip !== '') {
            db()->prepare("DELETE FROM blocked_ips WHERE ip=:ip")->execute([':ip' => $ip]);
        } else {
            jsonOutput(['error' => '无效参数'], 400);
        }
        addLog($user['id'], $user['username'], 'unblock_ip', 'system', null, ['id' => $id, 'ip' => $ip]);
        jsonOutput(['ok' => true]);
    }

    jsonOutput(['error' => '不支持的方法'], 405);
}

// ==================== 回收站 ====================
function handleRecycleBin(string $method) {
    requirePermission('deleteTransaction');

    switch ($method) {
        case 'GET':
            $rows = db()->query(
                "SELECT t.*, u.username as recorder_name 
                 FROM transactions t LEFT JOIN users u ON t.recorded_by=u.id 
                 WHERE t.deleted_at IS NOT NULL 
                 ORDER BY t.deleted_at DESC LIMIT 100"
            )->fetchAll();
            jsonOutput(['items' => $rows]);
            break;

        case 'PUT':
            requireCsrfToken();
            $input = jsonInput();
            $id = intval($_GET['id'] ?? $input['id'] ?? 0);
            $ids = $input['ids'] ?? null;
            if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
            if (!empty($ids)) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($v){ return $v>0; })));
                if (empty($ids)) jsonOutput(['error' => '无效 ID'], 400);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $upd = db()->prepare("UPDATE transactions SET deleted_at=NULL WHERE id IN ($ph) AND deleted_at IS NOT NULL");
                $upd->execute($ids);
                $n = $upd->rowCount();
                $user = currentUser();
                addLog($user['id'], $user['username'], 'batch_restore', 'transaction', null, ['count' => $n]);
                jsonOutput(['ok' => true, 'restored' => $n]);
            }
            if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);

            $chk = db()->prepare("SELECT id FROM transactions WHERE id=:id AND deleted_at IS NOT NULL");
            $chk->execute([':id' => $id]);
            if (!$chk->fetch()) jsonOutput(['error' => '记录不存在或不在回收站中'], 404);

            db()->prepare("UPDATE transactions SET deleted_at=NULL WHERE id=:id")
                ->execute([':id' => $id]);

            $user = currentUser();
            addLog($user['id'], $user['username'], 'restore_transaction', 'transaction', $id);
            jsonOutput(['ok' => true]);
            break;

        case 'DELETE':
            requireCsrfToken();
            $input = jsonInput();
            $id = intval($_GET['id'] ?? $input['id'] ?? 0);
            $ids = $input['ids'] ?? null;
            if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
            if (($input['all'] ?? false) || ($_GET['all'] ?? '') === '1') {
                $delAll = db()->query("DELETE FROM transactions WHERE deleted_at IS NOT NULL");
                $n = $delAll->rowCount();
                $user = currentUser();
                addLog($user['id'], $user['username'], 'clear_recycle_bin', 'transaction', null, ['count' => $n]);
                jsonOutput(['ok' => true, 'deleted' => $n]);
            }
            if (!empty($ids)) {
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function($v){ return $v>0; })));
                if (empty($ids)) jsonOutput(['error' => '无效 ID'], 400);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $delBatch = db()->prepare("DELETE FROM transactions WHERE id IN ($ph) AND deleted_at IS NOT NULL");
                $delBatch->execute($ids);
                $n = $delBatch->rowCount();
                $user = currentUser();
                addLog($user['id'], $user['username'], 'batch_permanent_delete', 'transaction', null, ['count' => $n]);
                jsonOutput(['ok' => true, 'deleted' => $n]);
            }
            if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);

            $oldStmt = db()->prepare("SELECT * FROM transactions WHERE id=:id AND deleted_at IS NOT NULL");
            $oldStmt->execute([':id' => $id]);
            $old = $oldStmt->fetch();
            if (!$old) jsonOutput(['error' => '记录不存在或不在回收站中'], 404);

            db()->prepare("DELETE FROM transactions WHERE id=:id")
                ->execute([':id' => $id]);

            $user = currentUser();
            addLog($user['id'], $user['username'], 'permanent_delete', 'transaction', $id, $old);
            jsonOutput(['ok' => true]);
            break;

        default:
            jsonOutput(['error' => '不支持的方法'], 405);
    }
}
