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

/** 导出操作日志 CSV */
function handleExportLogs() {
    requirePermission('viewLogs');

    $userId = intval($_GET['user_id'] ?? 0);
    $action = $_GET['log_action'] ?? '';

    $sql  = "SELECT * FROM operation_logs WHERE 1=1";
    $params = [];
    if ($userId > 0) { $sql .= " AND user_id = :uid"; $params[':uid'] = $userId; }
    if ($action)     { $sql .= " AND action = :action"; $params[':action'] = $action; }
    $sql .= " ORDER BY id DESC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // 操作类型中文映射
    $actionMap = [
        'login' => '登录', 'logout' => '登出', 'guest_login' => '访客登录',
        'change_password' => '修改密码',
        'create_transaction' => '添加收支', 'update_transaction' => '编辑收支',
        'delete_transaction' => '删除收支', 'restore_transaction' => '恢复收支',
        'permanent_delete' => '彻底删除',
        'create_user' => '添加用户', 'update_user' => '编辑用户', 'delete_user' => '删除用户',
        'ban_user' => '封禁用户', 'unban_user' => '解封用户',
        'upload_roster' => '录入花名册', 'upload_roster_xlsx' => '导入花名册', 'delete_roster' => '删除花名册',
        'import' => '批量导入', 'import_xlsx' => '导入Excel',
        'upgrade' => '系统升级',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="operation_logs_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM，兼容 Excel
    fputcsv($out, ['ID', '时间', '用户', '操作', '对象', '目标ID', '详情', 'IP', '浏览器']);
    foreach ($logs as $log) {
        $detail = $log['details'] ?? '';
        if ($detail) {
            $d = json_decode($detail, true);
            if (is_array($d)) $detail = json_encode($d, JSON_UNESCAPED_UNICODE);
        }
        fputcsv($out, [
            $log['id'],
            $log['created_at'],
            $log['username'],
            $actionMap[$log['action']] ?? $log['action'],
            $log['target_type'],
            $log['target_id'],
            $detail,
            $log['ip_address'] ?? '',
            $log['browser_info'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}
