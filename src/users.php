<?php
/**
 * 班级班费管理系统 - 用户管理模块
 * 查询 / 添加 / 编辑 / 删除 / 封禁
 */

function handleUsers(string $method) {
    switch ($method) {
        case 'GET':
            handleUsersGet();
            break;
        case 'POST':
            handleUsersPost();
            break;
        case 'PUT':
            handleUsersPut();
            break;
        case 'DELETE':
            handleUsersDelete();
            break;
        default:
            jsonOutput(['error' => '不支持的方法'], 405);
    }
}

/** 正规化 roles：JSON 字符串 → PHP 数组（兼容双重编码脏数据） */
function normalizeRoles($roles): array {
    if (is_string($roles)) {
        $decoded = json_decode($roles, true);
        if (is_array($decoded)) return $decoded;
        // 双重编码回退："[\"admin\"]" → ["admin"] (string) → 再解一层
        if (is_string($decoded)) {
            $d2 = json_decode($decoded, true);
            if (is_array($d2)) return $d2;
        }
    }
    if (is_array($roles)) return $roles;
    return ['student'];
}

function handleUsersGet() {
    requireLogin();
    if (!hasPermission('manageStudents') && !hasPermission('manageAllAccounts')) {
        requirePermission('manageStudents');
    }
    try {
        $users = db()->query("SELECT id, username, roles, banned, ban_reason, created_at FROM users ORDER BY id")->fetchAll();
        foreach ($users as &$u) {
            $u['roles'] = sortRolesByPriority(normalizeRoles($u['roles']));
            $u['highest_role'] = getHighestRole($u['roles']);
        }
        jsonOutput(['users' => $users]);
    } catch (\Throwable $e) {
        error_log('[班费系统] GET users 异常: ' . $e->getMessage());
        jsonOutput(['error' => '查询失败，请稍后重试'], 500);
    }
}

function handleUsersPost() {
    requireLogin();
    if (!hasPermission('manageAllAccounts') && !hasPermission('manageStudents')) {
        requirePermission('manageStudents');
    }

    requireCsrfToken();

    $input    = jsonInput();
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? $input['pwd'] ?? '123456';
    $roles    = normalizeRoles($input['roles'] ?? ['student']);

    if (empty($username)) jsonOutput(['error' => '请输入姓名'], 400);
    if (mb_strlen($username) > 50) jsonOutput(['error' => '姓名不能超过 50 个字符'], 400);
    // 防止创建空密码账户
    if (strlen($password) < 4) jsonOutput(['error' => '密码至少 4 位'], 400);

    // 角色白名单过滤，剔除未知角色
    $roles = array_values(array_intersect($roles, ['head_teacher', 'admin', 'monitor', 'vice_monitor', 'finance', 'student']));
    if (empty($roles)) $roles = ['student'];

    // 非管理员不能创建管理员角色
    if (!hasPermission('manageAllAccounts')) {
        $roles = array_values(array_diff($roles, ['head_teacher', 'admin']));
        if (empty($roles)) $roles = ['student'];
    }

    // 检查用户名唯一性
    $existing = db()->prepare("SELECT id FROM users WHERE username = :u");
    $existing->execute([':u' => $username]);
    if ($existing->fetch()) {
        jsonOutput(['error' => '该姓名已存在'], 409);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare("INSERT INTO users (username, password, roles) VALUES (:u, :p, :r)");
    $stmt->execute([':u' => $username, ':p' => $hash, ':r' => json_encode($roles, JSON_UNESCAPED_UNICODE)]);
    $newId = (int)db()->lastInsertId();

    $user = currentUser();
    addLog($user['id'], $user['username'], 'create_user', 'user', $newId, [
        'username' => $username, 'roles' => $roles
    ]);

    jsonOutput(['ok' => true, 'id' => $newId], 201);
}

function handleUsersPut() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();

    $input    = jsonInput();
    $id       = intval($_GET['id'] ?? $input['id'] ?? 0);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? $input['pwd'] ?? null;
    // 仅当显式提供了 roles 字段时才更新角色，否则保留原值（避免误清空）
    $hasRoles = array_key_exists('roles', $input) && $input['roles'] !== null && $input['roles'] !== '';
    $roles    = $hasRoles ? normalizeRoles($input['roles']) : null;
    if ($hasRoles) {
        // 角色白名单过滤，剔除未知角色
        $roles = array_values(array_intersect($roles, ['head_teacher', 'admin', 'monitor', 'vice_monitor', 'finance', 'student']));
        if (empty($roles)) $roles = ['student'];
    }

    if ($id <= 0 || empty($username)) jsonOutput(['error' => '无效参数'], 400);
    if (mb_strlen($username) > 50) jsonOutput(['error' => '姓名不能超过 50 个字符'], 400);

    // 不能降级自己的管理员权限
    if ($id == currentUser()['id'] && $hasRoles && !in_array('admin', $roles)) {
        jsonOutput(['error' => '不能移除自己的管理员权限'], 400);
    }

    if ($password && strlen($password) > 0) {
        if (strlen($password) < 4) jsonOutput(['error' => '密码至少 4 位'], 400);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hasRoles) {
            $stmt = db()->prepare("UPDATE users SET username=:u, password=:p, roles=:r WHERE id=:id");
            $stmt->execute([':u' => $username, ':p' => $hash, ':r' => json_encode($roles), ':id' => $id]);
        } else {
            $stmt = db()->prepare("UPDATE users SET username=:u, password=:p WHERE id=:id");
            $stmt->execute([':u' => $username, ':p' => $hash, ':id' => $id]);
        }
    } else {
        if ($hasRoles) {
            $stmt = db()->prepare("UPDATE users SET username=:u, roles=:r WHERE id=:id");
            $stmt->execute([':u' => $username, ':r' => json_encode($roles), ':id' => $id]);
        } else {
            $stmt = db()->prepare("UPDATE users SET username=:u WHERE id=:id");
            $stmt->execute([':u' => $username, ':id' => $id]);
        }
    }

    $user = currentUser();
    addLog($user['id'], $user['username'], 'update_user', 'user', $id, [
        'username' => $username, 'roles' => $roles
    ]);

    jsonOutput(['ok' => true]);
}

function handleUsersDelete() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();

    $input = jsonInput();
    $id = intval($_GET['id'] ?? $input['id'] ?? 0);
    if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);
    if ($id == currentUser()['id']) jsonOutput(['error' => '不能删除自己'], 400);

    $stmt = db()->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $old = $stmt->fetch();
    if (!$old) jsonOutput(['error' => '用户不存在'], 404);

    db()->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);

    $user = currentUser();
    addLog($user['id'], $user['username'], 'delete_user', 'user', $id, [
        'username' => $old['username']
    ]);

    jsonOutput(['ok' => true]);
}

// ==================== 封禁 / 解封用户 ====================
function handleBanUser() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();

    $input = jsonInput();
    $uid = intval($input['user_id'] ?? 0);
    if ($uid <= 0) jsonOutput(['error' => '无效用户 ID'], 400);
    if ($uid == currentUser()['id']) jsonOutput(['error' => '不能封禁自己'], 400);

    $ban = intval($input['ban'] ?? 1);
    $reason = trim($input['reason'] ?? '');

    if ($ban && empty($reason)) jsonOutput(['error' => '请填写封禁理由'], 400);

    if ($ban) {
        db()->prepare("UPDATE users SET banned=1, ban_reason=:r WHERE id=:id")
            ->execute([':r' => $reason, ':id' => $uid]);
    } else {
        db()->prepare("UPDATE users SET banned=0, ban_reason=NULL WHERE id=:id")
            ->execute([':id' => $uid]);
    }

    $user = currentUser();
    addLog($user['id'], $user['username'], $ban ? 'ban_user' : 'unban_user', 'user', $uid, ['reason' => $reason]);

    jsonOutput(['ok' => true]);
}

// ==================== 班级信息 ====================
function handleClassInfo(string $method) {
    startSession();

    if ($method === 'GET') {
        requireLogin();
        $info = [
            'name'     => $_SESSION['class_name'] ?? '',
            'semester' => $_SESSION['class_semester'] ?? '',
        ];
        jsonOutput(['classInfo' => $info]);
    }

    if ($method === 'PUT') {
        requirePermission('manageAllAccounts');
        requireCsrfToken();
        $input = jsonInput();
        $_SESSION['class_name']     = trim($input['name'] ?? '');
        $_SESSION['class_semester'] = trim($input['semester'] ?? '');
        jsonOutput(['ok' => true]);
    }
}
