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

/** 用户名与花名册姓名完全一致时自动绑定 */
function bindRosterByName(int $userId, string $username): int {
    try {
        $st = db()->prepare("SELECT id FROM class_roster WHERE name=:n LIMIT 1");
        $st->execute([':n' => $username]);
        $rid = (int)$st->fetchColumn();
        if ($rid > 0) {
            db()->prepare("UPDATE users SET roster_id=:r WHERE id=:id")->execute([':r' => $rid, ':id' => $userId]);
            if ((int)($_SESSION['user_id'] ?? 0) === $userId) $_SESSION['roster_id'] = $rid;
        }
        return $rid;
    } catch (\Exception $e) {
        return 0;
    }
}

function handleUsersGet() {
    requireLogin();
    if (!hasPermission('manageStudents') && !hasPermission('manageAllAccounts')) {
        requirePermission('manageStudents');
    }
    try {
        $users = db()->query("SELECT u.id, u.username, u.roles, u.banned, u.ban_reason, u.created_at,
            u.roster_id, u.must_change_password, u.totp_enabled, r.name AS roster_name
            FROM users u LEFT JOIN class_roster r ON r.id=u.roster_id")->fetchAll();
        foreach ($users as &$u) {
            $u['roles'] = sortRolesByPriority(normalizeRoles($u['roles']));
            $u['highest_role'] = getHighestRole($u['roles']);
        }
        unset($u); // 释放引用，防止后续操作污染数组
        // 按最高角色优先级排序（班主任→管理员→班长→副班长→财务→同学），同角色按 id
        $priority = json_decode(ROLE_PRIORITY, true);
        usort($users, function ($a, $b) use ($priority) {
            $pa = $priority[$a['highest_role']] ?? 99;
            $pb = $priority[$b['highest_role']] ?? 99;
            if ($pa !== $pb) return $pa <=> $pb;
            return $a['id'] <=> $b['id'];
        });
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
    $password = (string)($input['password'] ?? $input['pwd'] ?? '');
    $roles    = normalizeRoles($input['roles'] ?? ['student']);
    $generated = false;

    if (empty($username)) jsonOutput(['error' => '请输入姓名'], 400);
    if (mb_strlen($username) > 50) jsonOutput(['error' => '姓名不能超过 50 个字符'], 400);
    // v1.12：不传密码时随机生成，避免所有学生共用 123456
    if ($password === '') {
        $password = bin2hex(random_bytes(4));
        $generated = true;
    }
    // 防止创建空密码账户
    if (strlen($password) < 4) jsonOutput(['error' => '密码至少 4 位'], 400);

    // 角色白名单过滤，剔除未知角色
    $roles = array_values(array_intersect($roles, ['head_teacher', 'admin', 'monitor', 'vice_monitor', 'finance', 'student']));
    if (empty($roles)) $roles = ['student'];

    // 非管理员（班主任/管理员）只能创建普通「同学」账号，
    // 防止班长/副班长越权任命班长、副班长、财务委员等高权限角色
    if (!hasPermission('manageAllAccounts')) {
        $roles = array_values(array_intersect($roles, ['student']));
        if (empty($roles)) $roles = ['student'];
    }

    // 检查用户名唯一性
    $existing = db()->prepare("SELECT id FROM users WHERE username = :u");
    $existing->execute([':u' => $username]);
    if ($existing->fetch()) {
        jsonOutput(['error' => '该姓名已存在'], 409);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare("INSERT INTO users (username, password, roles, must_change_password) VALUES (:u, :p, :r, 1)");
    $stmt->execute([':u' => $username, ':p' => $hash, ':r' => json_encode($roles, JSON_UNESCAPED_UNICODE)]);
    $newId = (int)db()->lastInsertId();
    bindRosterByName($newId, $username);

    $user = currentUser();
    addLog($user['id'], $user['username'], 'create_user', 'user', $newId, [
        'username' => $username, 'roles' => $roles, 'generated_password' => $generated
    ]);

    jsonOutput(['ok' => true, 'id' => $newId, 'generated_password' => $generated ? $password : null], 201);
}

function handleUsersPut() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();

    $input    = jsonInput();
    $id       = intval($_GET['id'] ?? $input['id'] ?? 0);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? $input['pwd'] ?? null;
    // roster_id：显式传 0/null 表示解绑；不传则保持不变
    $hasRoster = array_key_exists('roster_id', $input);
    $newRosterId = null;
    if ($hasRoster) {
        $rid = intval($input['roster_id'] ?? 0);
        if ($rid > 0) {
            $chk = db()->prepare("SELECT id FROM class_roster WHERE id=:id");
            $chk->execute([':id' => $rid]);
            if (!$chk->fetch()) jsonOutput(['error' => '花名册学生不存在'], 400);
        }
        $newRosterId = $rid > 0 ? $rid : null;
    }
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

    // 用户名唯一性检查（排除自身），防止改名冲突触发数据库唯一约束异常
    $dup = db()->prepare("SELECT id FROM users WHERE username = :u AND id <> :id");
    $dup->execute([':u' => $username, ':id' => $id]);
    if ($dup->fetch()) jsonOutput(['error' => '该姓名已被其他用户使用'], 409);

    // 不能降级自己的管理员权限
    if ($id == currentUser()['id'] && $hasRoles && !in_array('admin', $roles)) {
        jsonOutput(['error' => '不能移除自己的管理员权限'], 400);
    }

    $sets = ['username=:u'];
    $params = [':u' => $username, ':id' => $id];
    if ($password !== null && (string)$password !== '') {
        if (strlen($password) < 4) jsonOutput(['error' => '密码至少 4 位'], 400);
        $sets[] = 'password=:p';
        $params[':p'] = password_hash($password, PASSWORD_BCRYPT);
        // 管理员重置密码后，用户下次登录必须改密
        $sets[] = 'must_change_password=1';
    }
    if ($hasRoles) {
        $sets[] = 'roles=:r';
        $params[':r'] = json_encode($roles, JSON_UNESCAPED_UNICODE);
    }
    if ($hasRoster) {
        $sets[] = 'roster_id=:rid';
        $params[':rid'] = $newRosterId;
    }
    $stmt = db()->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id=:id");
    $stmt->execute($params);
    $me = currentUser();
    if ($me && (int)$me['id'] === $id) {
        if ($password !== null && (string)$password !== '') $_SESSION['must_change_password'] = true;
        if ($hasRoster) $_SESSION['roster_id'] = (int)($newRosterId ?? 0);
    }

    $user = currentUser();
    addLog($user['id'], $user['username'], 'update_user', 'user', $id, [
        'username' => $username, 'roles' => $roles, 'roster_id' => $newRosterId, 'password_reset' => ($password !== null && (string)$password !== '')
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
    if ($method === 'GET') {
        requireLogin();
        // 班级信息为班级全局配置，存 system_meta 表，所有用户共享
        $info = [
            'name'     => getMeta('class_name'),
            'semester' => getMeta('class_semester'),
        ];
        jsonOutput(['classInfo' => $info]);
    }

    if ($method === 'PUT') {
        requirePermission('manageAllAccounts');
        requireCsrfToken();
        $input = jsonInput();
        setMeta('class_name', trim($input['name'] ?? ''));
        setMeta('class_semester', trim($input['semester'] ?? ''));
        jsonOutput(['ok' => true]);
    }
}
