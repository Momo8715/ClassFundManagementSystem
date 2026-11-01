<?php
/**
 * 班级班费管理系统 - 认证模块
 * 登录 / 访客登录 / 注销 / 修改密码 / 当前用户
 */

// ==================== 登录 ====================
function handleLogin() {
    $input = jsonInput();
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? $input['pwd'] ?? '';

    // 速率限制：每 IP 每分钟最多 5 次登录尝试（先于任何校验，空请求同样消耗配额，防止探测）
    requireRateLimit('login', 5, 60);

    if (empty($username) || empty($password)) {
        addLoginHistory(0, $username ?: '(空)', 'password', false, '用户名或密码为空', $input['fingerprint'] ?? '');
        jsonOutput(['error' => '请输入用户名和密码'], 400);
    }

    try {
        $stmt = db()->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();
    } catch (\Exception $e) {
        securityLog('login_db_error', ['username' => $username, 'error' => $e->getMessage()]);
        jsonOutput(['error' => '系统错误，请重试'], 500);
    }

    if (!$user || !password_verify($password, $user['password'])) {
        addLoginHistory(0, $username, 'password', false, '用户名或密码错误', $input['fingerprint'] ?? '');
        jsonOutput(['error' => '用户名或密码错误'], 401);
    }

    // 检查是否被封禁
    if ($user['banned'] ?? 0) {
        $reason = $user['ban_reason'] ?? '无';
        addLoginHistory($user['id'], $user['username'], 'password', false, '账号已被封禁：' . $reason, $input['fingerprint'] ?? '');
        jsonOutput(['error' => '账号已被管理员限制登录。理由：' . $reason], 403);
    }

    // 两步验证：先校验密码，再进入第二步（不在此时建立完整登录态）
    if (!empty($user['totp_enabled'])) {
        // 老客户端没有 2FA 逻辑：返回明确提示，避免前端拿到空 user 报错
        if ((string)($input['client_2fa'] ?? '') !== '1') {
            jsonOutput(['error' => '该账号已启用两步验证，请刷新页面（Ctrl+F5）后重新登录', 'need_refresh' => true], 401);
        }
        startSession();
        regenerateSession();
        $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
        $_SESSION['2fa_pending_time'] = time();
        $_SESSION['2fa_pending_challenge'] = bin2hex(random_bytes(16));
        $_SESSION['2fa_pending_attempts'] = 0;
        jsonOutput([
            'ok' => false,
            'need_2fa' => true,
            'challenge' => $_SESSION['2fa_pending_challenge'],
            'username' => $user['username'],
            'expires_in' => TWOFA_PENDING_TTL,
        ]);
    }

    startSession();
    // 防止会话固定攻击：登录成功后重新生成 session ID
    regenerateSession();

    $payload = twofaApplyLoginSession($user);

    try { addLoginHistory((int)$user['id'], $user['username'], 'password', true, '', $input['fingerprint'] ?? ''); } catch (\Exception $e) {}
    try { addLog((int)$user['id'], $user['username'], 'login', 'system', null, '用户登录'); } catch (\Exception $e) {}

    jsonOutput(['ok' => true, 'user' => $payload]);
}

// ==================== 访客登录 ====================
function handleGuestLogin() {
    // 访客登录无需凭据，但仍需限流：每次都会写入登录历史与操作日志，防止被刷爆
    requireRateLimit('guest_login', 30, 60);
    $input = jsonInput();
    startSession();
    // 防止会话固定攻击：访客登录同样重新生成 session ID
    regenerateSession();

    $_SESSION['user_id']   = 0;
    $_SESSION['username']  = '同学（访客）';
    $_SESSION['roles']     = ['student'];
    $_SESSION['is_guest']  = true;
    $_SESSION['roster_id'] = 0;
    $_SESSION['student_name'] = '';
    $_SESSION['totp_enabled'] = false;
    $_SESSION['must_change_password'] = false;

    generateCsrfToken();

    addLoginHistory(0, '同学（访客）', 'guest', true, '', $input['fingerprint'] ?? '');
    addLog(0, '同学（访客）', 'guest_login', 'system', null, '访客以同学身份进入');

    jsonOutput([
        'ok'   => true,
        'user' => [
            'id'         => 0,
            'username'   => '同学（访客）',
            'roles'      => ['student'],
            'is_guest'   => true,
            'csrf_token' => $_SESSION['csrf_token'],
        ]
    ]);
}

// ==================== 注销 ====================
function handleLogout() {
    startSession();
    requireCsrfToken();
    if (isset($_SESSION['user_id'])) {
        addLog($_SESSION['user_id'], $_SESSION['username'], 'logout', 'system', null, '用户登出');
    }
    session_destroy();
    jsonOutput(['ok' => true]);
}

// ==================== 修改密码 ====================
function handleChangePassword() {
    requireLogin();
    requireCsrfToken();

    $user = currentUser();
    if ($user['id'] === 0) jsonOutput(['error' => '访客不能修改密码'], 403);

    $input = jsonInput();
    $oldPass = $input['old_password'] ?? $input['old_pwd'] ?? '';
    $newPass = $input['new_password'] ?? $input['new_pwd'] ?? '';

    if (empty($oldPass) || empty($newPass)) jsonOutput(['error' => '请填写旧密码和新密码'], 400);
    if (strlen($newPass) < 4) jsonOutput(['error' => '新密码至少 4 位'], 400);
    if ($oldPass === $newPass) jsonOutput(['error' => '新密码不能与旧密码相同'], 400);

    $stmt = db()->prepare("SELECT password FROM users WHERE id=:id");
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($oldPass, $row['password'])) jsonOutput(['error' => '旧密码错误'], 401);

    $hash = password_hash($newPass, PASSWORD_BCRYPT);
    db()->prepare("UPDATE users SET password=:p, must_change_password=0 WHERE id=:id")->execute([':p' => $hash, ':id' => $user['id']]);
    $_SESSION['must_change_password'] = false;
    addLog($user['id'], $user['username'], 'change_password', 'user', $user['id']);

    jsonOutput(['ok' => true]);
}

// ==================== 当前用户信息 ====================
function handleMe() {
    requireLogin();
    $user = currentUser();

    // 访客用户
    if ($user['id'] === 0 || ($_SESSION['is_guest'] ?? false)) {
        jsonOutput([
            'user' => [
                'id'           => 0,
                'username'     => $user['username'],
                'roles'        => ['student'],
                'highest_role' => 'student',
                'is_guest'     => true,
                'created_at'   => null,
                'roster_id'    => 0,
                'student_name' => '',
                'totp_enabled' => false,
                'must_change_password' => false,
                'must_enable_2fa' => false,
                'csrf_token'   => $_SESSION['csrf_token'] ?? '',
            ]
        ]);
    }

    $stmt = db()->prepare("SELECT username, roles, created_at, roster_id, must_change_password, totp_enabled FROM users WHERE id = :id");
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonOutput(['error' => '用户不存在'], 404);
    }

    // 同步会话里的账号安全状态（管理员重置密码/开启 2FA 后无需重新登录即可反映）
    $_SESSION['roster_id'] = (int)($row['roster_id'] ?? 0);
    $_SESSION['must_change_password'] = !empty($row['must_change_password']);
    $_SESSION['totp_enabled'] = !empty($row['totp_enabled']);
    $_SESSION['must_enable_2fa'] = twofaNeedsSetup(sortRolesByPriority(normalizeRoles($row['roles'])), !empty($row['totp_enabled']));
    $studentName = '';
    if ((int)($_SESSION['roster_id']) > 0) {
        $sn = db()->prepare("SELECT name FROM class_roster WHERE id=:id");
        $sn->execute([':id' => (int)$_SESSION['roster_id']]);
        $studentName = (string)$sn->fetchColumn();
        $_SESSION['student_name'] = $studentName;
    }

    $roles = sortRolesByPriority(normalizeRoles($row['roles']));
    jsonOutput([
        'user' => [
            'id'           => $user['id'],
            'username'     => $row['username'],
            'roles'        => $roles,
            'highest_role' => getHighestRole($roles),
            'created_at'   => $row['created_at'],
            'roster_id'    => (int)($_SESSION['roster_id'] ?? 0),
            'student_name' => $studentName,
            'totp_enabled' => !empty($row['totp_enabled']),
            'must_change_password' => !empty($row['must_change_password']),
            'must_enable_2fa' => !empty($_SESSION['must_enable_2fa']),
            'csrf_token'   => $_SESSION['csrf_token'] ?? '',
        ]
    ]);
}
