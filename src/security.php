<?php
/**
 * 班级班费管理系统 - 安全分析模块
 */

function handleSecurityAnalysis() {
    requirePermission('viewSecurity');

    // 同指纹多账号检测
    $multiAccount = db()->query(
        "SELECT fingerprint, COUNT(DISTINCT user_id) as accounts, GROUP_CONCAT(DISTINCT username) as names, COUNT(*) as logins 
         FROM login_history 
         WHERE fingerprint IS NOT NULL AND fingerprint != '' AND success=1 
         GROUP BY fingerprint HAVING accounts > 1 
         ORDER BY accounts DESC LIMIT 30"
    )->fetchAll();

    // 同账号多 IP 检测
    $multiIp = db()->query(
        "SELECT user_id, username, COUNT(DISTINCT ipv4_address) as ip_count, 
                GROUP_CONCAT(DISTINCT ipv4_address) as ips, COUNT(*) as logins 
         FROM login_history 
         WHERE success=1 
         GROUP BY user_id, username HAVING ip_count > 1 
         ORDER BY ip_count DESC LIMIT 30"
    )->fetchAll();

    // 登录失败统计
    $failures = db()->query(
        "SELECT username, COUNT(*) as attempts, MAX(created_at) as last_attempt 
         FROM login_history 
         WHERE success=0 
         GROUP BY username 
         ORDER BY attempts DESC LIMIT 30"
    )->fetchAll();

    // 最近登录记录
    $recent = db()->query(
        "SELECT * FROM login_history ORDER BY created_at DESC LIMIT 50"
    )->fetchAll();

    jsonOutput([
        'multi_account' => $multiAccount,
        'multi_ip'      => $multiIp,
        'failures'      => $failures,
        'recent'        => $recent,
    ]);
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
            if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);

            // 仅允许恢复已软删除（在回收站中）的记录
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
