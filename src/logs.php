<?php
/**
 * 班级班费管理系统 - 操作日志模块（不可变）
 */

function handleLogs() {
    requirePermission('viewLogs');

    $page    = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(50, max(10, intval($_GET['per_page'] ?? 20)));
    $userId  = intval($_GET['user_id'] ?? 0);
    $action  = $_GET['log_action'] ?? '';

    $sql  = "SELECT * FROM operation_logs WHERE 1=1";
    $params = [];

    if ($userId > 0) {
        $sql .= " AND user_id = :uid";
        $params[':uid'] = $userId;
    }
    if ($action) {
        $sql .= " AND action = :action";
        $params[':action'] = $action;
    }

    // 总数
    $countStmt = db()->prepare("SELECT COUNT(*) FROM ($sql) AS t");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // 使用 LIMIT 占位符参数化
    $offset = ($page - 1) * $perPage;
    $sql .= " ORDER BY created_at DESC LIMIT {$offset}, {$perPage}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // 获取用户列表供筛选
    $users = db()->query("SELECT id, username FROM users ORDER BY id")->fetchAll();

    jsonOutput([
        'logs'        => $logs,
        'users'       => $users,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}
