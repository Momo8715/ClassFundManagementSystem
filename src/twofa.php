<?php
/**
 * 班级班费管理系统 - 两步验证（TOTP）接口与登录挑战
 */
require_once __DIR__ . '/totp.php';

const TWOFA_PENDING_TTL = 300;      // 密码通过后，等待验证码的有效期（秒）
const TWOFA_MAX_ATTEMPTS = 5;

/** 管理员 2FA 策略状态 */
function twofaPolicy(): array {
    $enabled = getMeta('require_2fa', '0') === '1';
    $until = trim(getMeta('require_2fa_grace_until', ''));
    $expired = $enabled && $until !== '' && strtotime($until) !== false && strtotime($until) <= time();
    return ['enabled' => $enabled, 'grace_until' => $until, 'expired' => $expired];
}

function twofaIsPrivileged(array $roles): bool {
    return in_array('admin', $roles, true) || in_array('head_teacher', $roles, true);
}

function twofaNeedsSetup(array $roles, bool $totpEnabled): bool {
    $p = twofaPolicy();
    return $p['enabled'] && $p['expired'] && twofaIsPrivileged($roles) && !$totpEnabled;
}

/** 读取用户两步验证相关字段 */
function twofaUser(int $id): ?array {
    $stmt = db()->prepare("SELECT id, username, password, roles, roster_id, must_change_password, banned, ban_reason,
        totp_secret, totp_enabled, totp_backup, totp_last_step
        FROM users WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function twofaBackupHashes(array $user): array {
    $raw = (string)($user['totp_backup'] ?? '');
    if ($raw === '') return [];
    $arr = json_decode($raw, true);
    if (!is_array($arr)) return [];
    return array_values(array_filter($arr, function ($h) { return is_string($h) && $h !== ''; }));
}

/**
 * 校验第二因子（TOTP 或备用码）。
 * 返回 ok/type/step/index/remaining（剩余备用码哈希）。
 */
function twofaCheckSecondFactor(array $user, string $code): array {
    $code = trim($code);
    $hashes = twofaBackupHashes($user);
    $secret = (string)($user['totp_secret'] ?? '');
    $lastStep = $user['totp_last_step'] !== null ? (int)$user['totp_last_step'] : null;

    if ($secret !== '' && preg_match('/^\d{6}$/', $code)) {
        $r = totpVerify($secret, $code, 1, $lastStep);
        if ($r['ok']) return ['ok' => true, 'type' => 'totp', 'step' => (int)$r['step'], 'index' => -1, 'remaining' => $hashes];
    }
    $idx = totpVerifyBackupCode($code, $hashes);
    if ($idx !== null) {
        array_splice($hashes, $idx, 1);
        return ['ok' => true, 'type' => 'backup', 'step' => 0, 'index' => $idx, 'remaining' => $hashes];
    }
    return ['ok' => false, 'type' => '', 'step' => 0, 'index' => -1, 'remaining' => $hashes];
}

/** 登录成功后统一写会话（密码登录与两步验证登录共用） */
function twofaApplyLoginSession(array $user): array {
    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['username']  = (string)$user['username'];
    $_SESSION['roles']     = normalizeRoles($user['roles']);
    $_SESSION['is_guest']  = false;
    $_SESSION['roster_id'] = (int)($user['roster_id'] ?? 0);
    $_SESSION['totp_enabled'] = !empty($user['totp_enabled']);
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $_SESSION['must_enable_2fa'] = twofaNeedsSetup($_SESSION['roles'], !empty($user['totp_enabled']));
    $_SESSION['ban_checked_at'] = time();
    $_SESSION['banned'] = false;
    unset($_SESSION['student_name']);
    generateCsrfToken();

    // 懒绑定：用户名与花名册姓名一致时自动绑定
    $rid = (int)($_SESSION['roster_id'] ?? 0);
    if ($rid <= 0) {
        try {
            $st = db()->prepare("SELECT id, name FROM class_roster WHERE name=:n LIMIT 1");
            $st->execute([':n' => (string)$user['username']]);
            $row = $st->fetch();
            if ($row) {
                $rid = (int)$row['id'];
                $_SESSION['roster_id'] = $rid;
                $_SESSION['student_name'] = (string)$row['name'];
                db()->prepare("UPDATE users SET roster_id=:r WHERE id=:u")->execute([':r' => $rid, ':u' => (int)$user['id']]);
            }
        } catch (\Exception $e) { /* 忽略 */ }
    } else {
        try {
            $st = db()->prepare("SELECT name FROM class_roster WHERE id=:id");
            $st->execute([':id' => $rid]);
            $_SESSION['student_name'] = (string)$st->fetchColumn();
        } catch (\Exception $e) { /* 忽略 */ }
    }

    return [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'roles' => $_SESSION['roles'],
        'roster_id' => (int)($_SESSION['roster_id'] ?? 0),
        'student_name' => (string)($_SESSION['student_name'] ?? ''),
        'totp_enabled' => !empty($user['totp_enabled']),
        'must_change_password' => !empty($user['must_change_password']),
        'csrf_token' => $_SESSION['csrf_token'],
    ];
}

/** 登录第二步：校验 TOTP / 备用码并完成登录 */
function handleLogin2FA() {
    requireRateLimit('login_2fa', 6, 60);
    startSession();
    $pendingId = (int)($_SESSION['2fa_pending_user_id'] ?? 0);
    $pendingAt = (int)($_SESSION['2fa_pending_time'] ?? 0);
    $challenge = (string)($_SESSION['2fa_pending_challenge'] ?? '');
    $attempts = (int)($_SESSION['2fa_pending_attempts'] ?? 0);
    $input = jsonInput();
    $given = (string)($input['challenge'] ?? '');
    $code = (string)($input['code'] ?? '');

    if ($pendingId <= 0 || $pendingAt <= 0 || $challenge === '' || !hash_equals($challenge, $given)) {
        jsonOutput(['error' => '验证会话已失效，请重新登录'], 401);
    }
    if (time() - $pendingAt > TWOFA_PENDING_TTL) {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_challenge'], $_SESSION['2fa_pending_attempts']);
        jsonOutput(['error' => '验证码已过期，请重新登录'], 401);
    }
    if ($attempts >= TWOFA_MAX_ATTEMPTS) {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_challenge'], $_SESSION['2fa_pending_attempts']);
        jsonOutput(['error' => '验证失败次数过多，请重新登录'], 429);
    }

    $user = twofaUser($pendingId);
    if (!$user || empty($user['totp_enabled'])) {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_challenge'], $_SESSION['2fa_pending_attempts']);
        jsonOutput(['error' => '账号状态异常，请重新登录'], 401);
    }
    if (!empty($user['banned'])) {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_challenge'], $_SESSION['2fa_pending_attempts']);
        addLoginHistory((int)$user['id'], (string)$user['username'], 'password', false, '账号已被封禁', (string)($input['fingerprint'] ?? ''));
        jsonOutput(['error' => '账号已被管理员限制登录。理由：' . ($user['ban_reason'] ?? '无')], 403);
    }

    $check = twofaCheckSecondFactor($user, $code);
    if (!$check['ok']) {
        $_SESSION['2fa_pending_attempts'] = $attempts + 1;
        addLoginHistory((int)$user['id'], (string)$user['username'], 'password', false, '两步验证失败', (string)($input['fingerprint'] ?? ''));
        jsonOutput(['error' => '验证码不正确或已使用'], 401);
    }

    // 校验通过：一次性写入，防重放
    if ($check['type'] === 'totp') {
        db()->prepare("UPDATE users SET totp_last_step=:s WHERE id=:id")->execute([':s' => (int)$check['step'], ':id' => (int)$user['id']]);
    } else {
        $hashes = $check['remaining'];
        db()->prepare("UPDATE users SET totp_backup=:b WHERE id=:id")->execute([':b' => json_encode(array_values($hashes)), ':id' => (int)$user['id']]);
    }
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_pending_challenge'], $_SESSION['2fa_pending_attempts']);

    regenerateSession();
    $payload = twofaApplyLoginSession($user);
    try { addLoginHistory((int)$user['id'], (string)$user['username'], 'password', true, '', (string)($input['fingerprint'] ?? '')); } catch (\Exception $e) {}
    try { addLog((int)$user['id'], (string)$user['username'], 'login_2fa', 'system', null, ['type' => $check['type']]); } catch (\Exception $e) {}
    jsonOutput(['ok' => true, 'user' => $payload]);
}

/** 两步验证设置 / 关闭 / 重生成备用码 */
function handleTwofaSetup() {
    requireLogin();
    $user = currentUser();
    if ((int)$user['id'] <= 0) jsonOutput(['error' => '访客不能设置两步验证'], 403);
    $input = jsonInput();
    $op = (string)($input['op'] ?? 'status');
    $uid = (int)$user['id'];
    $row = twofaUser($uid);
    if (!$row) jsonOutput(['error' => '用户不存在'], 404);

    if ($op === 'status') {
        $hashes = twofaBackupHashes($row);
        jsonOutput([
            'enabled' => !empty($row['totp_enabled']),
            'remaining_backup_codes' => count($hashes),
            'account' => (string)$row['username'],
            'issuer' => SITE_NAME,
            'policy' => twofaPolicy(),
            'policy_applies' => twofaIsPrivileged(normalizeRoles($row['roles'])),
            'must_enable' => !empty($_SESSION['must_enable_2fa']),
        ]);
    }

    if ($op === 'start') {
        requireCsrfToken();
        requireRateLimit('2fa_setup', 10, 60);
        if (!empty($row['totp_enabled'])) jsonOutput(['error' => '两步验证已启用，如需更换请先关闭'], 400);
        $secret = totpSecret(20);
        $_SESSION['totp_setup_secret'] = $secret;
        $_SESSION['totp_setup_at'] = time();
        jsonOutput([
            'ok' => true,
            'secret' => $secret,
            'uri' => totpUri($secret, (string)$row['username'], SITE_NAME),
            'account' => (string)$row['username'],
            'issuer' => SITE_NAME,
            'digits' => 6,
            'period' => 30,
        ]);
    }

    if ($op === 'confirm') {
        requireCsrfToken();
        requireRateLimit('2fa_verify', 10, 60);
        $secret = (string)($_SESSION['totp_setup_secret'] ?? '');
        $at = (int)($_SESSION['totp_setup_at'] ?? 0);
        if ($secret === '' || $at <= 0 || time() - $at > 600) jsonOutput(['error' => '设置会话已过期，请重新点击「启用」'], 400);
        $check = totpVerify($secret, (string)($input['code'] ?? ''), 1, null);
        if (!$check['ok']) jsonOutput(['error' => '验证码不正确，请确认手机时间准确后重试'], 400);
        $backup = totpGenerateBackupCodes(8);
        db()->prepare("UPDATE users SET totp_secret=:s, totp_enabled=1, totp_backup=:b, totp_last_step=:st WHERE id=:id")
            ->execute([':s' => $secret, ':b' => json_encode($backup['hashes']), ':st' => (int)$check['step'], ':id' => $uid]);
        unset($_SESSION['totp_setup_secret'], $_SESSION['totp_setup_at']);
        $_SESSION['totp_enabled'] = true;
        $_SESSION['must_enable_2fa'] = false;
        addLog($uid, (string)$row['username'], '2fa_enable', 'user', $uid);
        jsonOutput(['ok' => true, 'enabled' => true, 'backup_codes' => $backup['codes']]);
    }

    if ($op === 'disable' || $op === 'backup') {
        requireCsrfToken();
        requireRateLimit('2fa_verify', 10, 60);
        $password = (string)($input['password'] ?? '');
        if ($password === '' || !password_verify($password, (string)$row['password'])) {
            jsonOutput(['error' => '当前密码不正确'], 401);
        }
        if (empty($row['totp_enabled'])) jsonOutput(['error' => '尚未启用两步验证'], 400);
        $check = twofaCheckSecondFactor($row, (string)($input['code'] ?? ''));
        if (!$check['ok']) jsonOutput(['error' => '验证码或备用码不正确'], 401);

        if ($op === 'disable') {
            $policy = twofaPolicy();
            if ($policy['enabled'] && twofaIsPrivileged(normalizeRoles($row['roles']))) {
                jsonOutput(['error' => '系统要求管理员开启两步验证，不能关闭；如需更换设备请使用备用码重新生成'], 400);
            }
            db()->prepare("UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_backup=NULL, totp_last_step=NULL WHERE id=:id")
                ->execute([':id' => $uid]);
            $_SESSION['totp_enabled'] = false;
            addLog($uid, (string)$row['username'], '2fa_disable', 'user', $uid);
            jsonOutput(['ok' => true, 'enabled' => false]);
        }

        $backup = totpGenerateBackupCodes(8);
        db()->prepare("UPDATE users SET totp_backup=:b WHERE id=:id")->execute([':b' => json_encode($backup['hashes']), ':id' => $uid]);
        addLog($uid, (string)$row['username'], '2fa_backup_regenerate', 'user', $uid);
        jsonOutput(['ok' => true, 'backup_codes' => $backup['codes']]);
    }

    jsonOutput(['error' => '不支持的操作'], 400);
}


/** 管理员 2FA 强制策略（仅 admin 可改） */
function handleTwofaPolicy(string $method) {
    requirePermission('viewSecurity');
    if ($method === 'GET') {
        $p = twofaPolicy();
        $users = db()->query("SELECT id, username, roles, totp_enabled FROM users
            WHERE (roles LIKE '%admin%' OR roles LIKE '%head_teacher%')
            ORDER BY id")->fetchAll();
        $without = [];
        foreach ($users as $u) {
            $roles = normalizeRoles($u['roles']);
            if (twofaIsPrivileged($roles) && empty($u['totp_enabled'])) $without[] = ['id' => (int)$u['id'], 'username' => $u['username']];
        }
        jsonOutput(['policy' => $p, 'privileged_without_2fa' => $without, 'count' => count($without)]);
    }
    if ($method === 'POST') {
        if (!empty($_SESSION['must_enable_2fa'])) jsonOutput(['error' => '请先启用两步验证，再修改策略'], 403);
        requireCsrfToken();
        $input = jsonInput();
        $enabled = !empty($input['enabled']) && $input['enabled'] !== '0' && $input['enabled'] !== 'false';
        $days = (int)($input['grace_days'] ?? 7);
        if ($days < 0 || $days > 90) jsonOutput(['error' => '宽限期需在 0-90 天之间'], 400);
        $old = twofaPolicy();
        if (!$enabled) {
            setMeta('require_2fa', '0');
            setMeta('require_2fa_grace_until', '');
        } else {
            setMeta('require_2fa', '1');
            if (!$old['enabled'] || !empty($input['reset_grace'])) {
                setMeta('require_2fa_grace_until', date('Y-m-d H:i:s', time() + $days * 86400));
            } elseif ($old['grace_until'] === '') {
                setMeta('require_2fa_grace_until', date('Y-m-d H:i:s', time() + $days * 86400));
            }
            setMeta('require_2fa_set_at', date('Y-m-d H:i:s'));
        }
        // 立即刷新当前用户的 must_enable 状态
        $u = currentUser();
        if ($u && (int)$u['id'] > 0) {
            $_SESSION['must_enable_2fa'] = twofaNeedsSetup($u['roles'], !empty($_SESSION['totp_enabled']));
        }
        addLog((int)($u['id'] ?? 0), (string)($u['username'] ?? 'system'), 'twofa_policy_save', 'system', null, [
            'enabled' => $enabled, 'days' => $days, 'grace_until' => getMeta('require_2fa_grace_until'),
        ]);
        jsonOutput(['ok' => true, 'policy' => twofaPolicy(), 'must_enable' => !empty($_SESSION['must_enable_2fa'])]);
    }
    jsonOutput(['error' => '不支持的方法'], 405);
}
