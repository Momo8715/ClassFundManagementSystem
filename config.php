<?php
/**
 * 班级班费管理系统 - 配置文件
 * 上传即用：首次访问自动跳转 install.php，无需手动编辑
 * 高级用户：也可直接修改下方常量或创建 db_config.json
 */

// 加载工具函数库
require_once __DIR__ . '/src/helpers.php';

// ========== 数据库配置（优先读取 db_config.json，未安装时用默认值） ==========
function loadDbConfig(): array {
    $file = __DIR__ . '/db_config.json';
    if (file_exists($file)) {
        $cfg = json_decode(file_get_contents($file), true);
        if ($cfg && !empty($cfg['DB_HOST'])) {
            return $cfg;
        }
    }
    // fallback: 常量（可直接修改此处，或通过 install.php 自动生成 db_config.json）
    return [
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'DB_NAME' => 'class_fund',
        'DB_USER' => 'class_fund',
        'DB_PASS' => 'your_password',
        'DB_CHARSET' => 'utf8mb4',
    ];
}

// 加载配置
$_db_cfg = loadDbConfig();
define('DB_HOST',   $_db_cfg['DB_HOST']);
define('DB_PORT',   $_db_cfg['DB_PORT']);
define('DB_NAME',   $_db_cfg['DB_NAME']);
define('DB_USER',   $_db_cfg['DB_USER']);
define('DB_PASS',   $_db_cfg['DB_PASS']);
define('DB_CHARSET', $_db_cfg['DB_CHARSET']);

/** 检测数据库是否已配置并可连接（不抛异常） */
function isDbConnected(): bool {
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->query("SELECT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/** 检测数据表是否已创建 */
function isDbInstalled(): bool {
    if (!isDbConnected()) return false;
    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount();
        return $tables > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** 自动数据库迁移——新增字段/表，不丢数据，无需重新部署 */
function autoMigrate(): void {
    if (!isDbConnected()) return;
    try {
        $db = db();

        // ---- schema 版本检查：已是最新则跳过，避免每次请求执行十几条 SHOW COLUMNS/ALTER ----
        $db->exec("CREATE TABLE IF NOT EXISTS system_meta (
            meta_key VARCHAR(50) PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统元数据(key-value)'");
        $currentVersion = $db->query("SELECT meta_value FROM system_meta WHERE meta_key='schema_version'")->fetchColumn();
        if ($currentVersion === SCHEMA_VERSION) return;

        // transactions 表新增字段检测
        try {
            $cols = $db->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            // 表不存在则先创建
            $db->exec("CREATE TABLE IF NOT EXISTS transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type ENUM('income','expense') NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                date DATE NOT NULL,
                description VARCHAR(500) NOT NULL DEFAULT '',
                category VARCHAR(100) NOT NULL DEFAULT '其他',
                recorded_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_type (type),
                KEY idx_date (date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $cols = [];
        }
        if (!in_array('sub_category', $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN sub_category VARCHAR(50) DEFAULT NULL COMMENT '子分类' AFTER type");
        if (!in_array('source_info',  $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN source_info VARCHAR(500) DEFAULT NULL COMMENT '来源信息' AFTER sub_category");
        if (!in_array('image_path',   $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN image_path VARCHAR(500) DEFAULT NULL COMMENT '凭证图片' AFTER category");
        // 回收站：软删除
        if (!in_array('deleted_at',   $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间' AFTER updated_at");
        // 预缴金额（班费收缴）
        if (!in_array('expected_amount', $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN expected_amount DECIMAL(10,2) DEFAULT NULL COMMENT '预缴总金额' AFTER amount");

        // operation_logs 表新增字段检测
        try {
            $logCols = $db->query("SHOW COLUMNS FROM operation_logs")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            // 表不存在则先创建
            $db->exec("CREATE TABLE IF NOT EXISTS operation_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(50) NOT NULL,
                action VARCHAR(100) NOT NULL,
                target_type VARCHAR(50) NOT NULL,
                target_id INT DEFAULT NULL,
                details TEXT,
                ip_address VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_id (user_id),
                KEY idx_action (action),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $logCols = [];
        }
        if (!in_array('ipv6_address', $logCols)) $db->exec("ALTER TABLE operation_logs ADD COLUMN ipv6_address VARCHAR(45) DEFAULT NULL COMMENT 'IPv6地址' AFTER ip_address");
        if (!in_array('user_agent',   $logCols)) $db->exec("ALTER TABLE operation_logs ADD COLUMN user_agent TEXT COMMENT '浏览器UA' AFTER ipv6_address");
        if (!in_array('browser_info', $logCols)) $db->exec("ALTER TABLE operation_logs ADD COLUMN browser_info VARCHAR(500) DEFAULT NULL COMMENT '浏览器信息' AFTER user_agent");

        // login_history 表
        $db->exec("CREATE TABLE IF NOT EXISTS login_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT DEFAULT 0,
            username VARCHAR(50) NOT NULL,
            login_type VARCHAR(20) NOT NULL DEFAULT 'password',
            success TINYINT(1) NOT NULL DEFAULT 1,
            ipv4_address VARCHAR(45) DEFAULT NULL,
            ipv6_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT,
            browser_info VARCHAR(500) DEFAULT NULL,
            fail_reason VARCHAR(200) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_id (user_id),
            KEY idx_success (success),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录历史'");

        // login_history.fingerprint 浏览器指纹
        $lhCols = $db->query("SHOW COLUMNS FROM login_history")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('fingerprint', $lhCols)) $db->exec("ALTER TABLE login_history ADD COLUMN fingerprint VARCHAR(64) DEFAULT NULL COMMENT '浏览器指纹' AFTER browser_info");

        // users 封禁字段
        try {
            $uCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            // 表不存在则先创建
            $db->exec("CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL,
                password VARCHAR(255) NOT NULL,
                roles VARCHAR(255) NOT NULL DEFAULT '[\"student\"]',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $uCols = [];
        }
        if (!in_array('banned', $uCols)) $db->exec("ALTER TABLE users ADD COLUMN banned TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否封禁' AFTER roles");
        if (!in_array('ban_reason', $uCols)) $db->exec("ALTER TABLE users ADD COLUMN ban_reason VARCHAR(500) DEFAULT NULL COMMENT '封禁理由' AFTER banned");

        // class_roster 班级花名册
        $db->exec("CREATE TABLE IF NOT EXISTS class_roster (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='班级学生名单'");

        // class_roster.exempt 豁免缴费
        $rCols = $db->query("SHOW COLUMNS FROM class_roster")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('exempt', $rCols)) $db->exec("ALTER TABLE class_roster ADD COLUMN exempt TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否免缴' AFTER name");

        // transactions.payer_ids 缴费学生
        $cols = $db->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('payer_ids', $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN payer_ids TEXT DEFAULT NULL COMMENT '缴费学生ID(JSON数组或all)' AFTER description");
        // transactions.images 多图凭证
        if (!in_array('images', $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN images TEXT DEFAULT NULL COMMENT '多图凭证(JSON数组)' AFTER image_path");
        // transactions.image_ids 数据库凭证图ID（v1.5.6：凭证图片存库，列表优先加载缩略图）
        if (!in_array('image_ids', $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN image_ids TEXT DEFAULT NULL COMMENT '凭证图片ID(JSON数组，存tx_images.id)' AFTER images");
        // transactions.exempt_ids 单次免缴学生（v1.6：本轮免缴学生ID JSON数组，与永久免缴 exempt 字段独立）
        if (!in_array('exempt_ids', $cols)) $db->exec("ALTER TABLE transactions ADD COLUMN exempt_ids TEXT DEFAULT NULL COMMENT '单次免缴学生ID(JSON数组)' AFTER payer_ids");

        // tx_images 凭证图片库（v1.5.6：原图+缩略图二进制存数据库，不再依赖 uploads/ 文件）
        $db->exec("CREATE TABLE IF NOT EXISTS tx_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL DEFAULT 0 COMMENT '关联收支记录ID，0=未关联(上传中)',
            seq INT NOT NULL DEFAULT 0 COMMENT '展示顺序',
            mime VARCHAR(50) NOT NULL DEFAULT 'image/jpeg',
            thumb MEDIUMBLOB NOT NULL COMMENT '缩略图(最长边400px,JPEG)',
            full MEDIUMBLOB NOT NULL COMMENT '原图',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tx (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='凭证图片(数据库存储)'");

        // semesters 学期表
        $db->exec("CREATE TABLE IF NOT EXISTS semesters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='学期管理'");

        // ---- 全部迁移完成，记录版本号（异常时不会执行到这里，下次请求会重试） ----
        $db->exec("INSERT INTO system_meta (meta_key, meta_value) VALUES ('schema_version', '" . SCHEMA_VERSION . "')
            ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    } catch (Exception $e) {
        error_log('[班费系统] autoMigrate 失败: ' . $e->getMessage());
    }
}

// ========== 系统配置 ==========
define('SITE_NAME', '班级班费管理系统');
define('SESSION_TIMEOUT', 86400);      // 会话超时（秒）
// 数据库结构版本：⚠️ 每次修改下方 autoMigrate() 的迁移逻辑时，必须同步递增此值，
// 否则线上库会跳过新增的迁移。版本一致时每次请求不再执行迁移检查（性能优化）。
define('SCHEMA_VERSION', '1.6');

// ========== 角色定义（按优先级从高到低排列） ==========
define('ROLES', json_encode([
    'head_teacher' => '班主任',
    'admin'        => '管理员',
    'monitor'      => '班长',
    'vice_monitor' => '副班长',
    'finance'      => '财务委员',
    'student'      => '同学'
]));

// 角色优先级权重（数值越小优先级越高，用于多角色取最高权限）
define('ROLE_PRIORITY', json_encode([
    'head_teacher' => 1,
    'admin'        => 2,
    'monitor'      => 3,
    'vice_monitor' => 4,
    'finance'      => 5,
    'student'      => 6,
]));

/** 获取用户的最高优先级角色 */
function getHighestRole(array $roles): string {
    $priority = json_decode(ROLE_PRIORITY, true);
    $best = 'student';
    $bestP = 99;
    foreach ($roles as $r) {
        $p = $priority[$r] ?? 99;
        if ($p < $bestP) { $bestP = $p; $best = $r; }
    }
    return $best;
}

/** 按优先级排序角色（高优先级在前） */
function sortRolesByPriority(array $roles): array {
    $priority = json_decode(ROLE_PRIORITY, true);
    usort($roles, function($a, $b) use ($priority) {
        return ($priority[$a] ?? 99) - ($priority[$b] ?? 99);
    });
    return $roles;
}

// ========== 权限矩阵 ==========
define('PERMISSIONS', json_encode([
    'viewTransactions'  => ['head_teacher','admin','monitor','vice_monitor','finance','student'],
    'addTransaction'    => ['head_teacher','admin','monitor','vice_monitor','finance'],
    'editTransaction'   => ['head_teacher','admin','monitor','vice_monitor','finance'],
    'deleteTransaction' => ['head_teacher','admin'],
    'importData'        => ['head_teacher','admin','monitor','vice_monitor','finance'],
    'manageStudents'    => ['head_teacher','admin','monitor','vice_monitor'],
    'manageAllAccounts' => ['head_teacher','admin'],
    'viewLogs'          => ['head_teacher','admin'],
    'viewSecurity'      => ['admin'],
    'manageRoster'      => ['head_teacher','admin','monitor','vice_monitor'],
    'deleteRoster'      => ['head_teacher','admin'],
    'viewPayments'      => ['head_teacher','admin','monitor','vice_monitor','finance'],
]));

// ========== 数据库连接 ==========
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ========== 系统级配置（system_meta 表，班级全局共享） ==========

/** 读取系统级配置（替代旧版存 session：per_person / class_name / class_semester 等全局值） */
function getMeta(string $key, string $default = ''): string {
    try {
        $stmt = db()->prepare("SELECT meta_value FROM system_meta WHERE meta_key = :k");
        $stmt->execute([':k' => $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (\Exception $e) {
        return $default;
    }
}

/** 写入系统级配置（system_meta 表，所有登录用户共享同一份） */
function setMeta(string $key, string $value): void {
    db()->prepare("INSERT INTO system_meta (meta_key, meta_value) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)")
        ->execute([':k' => $key, ':v' => $value]);
}

// ========== 会话管理 ==========
function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/** 登录后重新生成会话 ID（防止会话固定攻击） */
function regenerateSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $oldCsrf = $_SESSION['csrf_token'] ?? null;
    session_regenerate_id(true);
    if ($oldCsrf !== null) {
        $_SESSION['csrf_token'] = $oldCsrf;
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array {
    startSession();
    if (!isset($_SESSION['user_id'])) return null;
    $roles = $_SESSION['roles'] ?? [];
    return [
        'id'           => $_SESSION['user_id'],
        'username'     => $_SESSION['username'],
        'roles'        => sortRolesByPriority($roles),
        'highest_role' => getHighestRole($roles),
    ];
}

function requireLogin() {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => '请先登录']);
        exit;
    }
    // 封禁检查：被封禁用户的已有会话立即失效（防止封禁形同虚设）
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        try {
            $stmt = db()->prepare("SELECT banned FROM users WHERE id=:id");
            $stmt->execute([':id' => $uid]);
            if ((int)$stmt->fetchColumn() === 1) {
                $_SESSION = [];
                session_destroy();
                http_response_code(403);
                echo json_encode(['error' => '账号已被管理员限制登录']);
                exit;
            }
        } catch (\Exception $e) {
            // 数据库异常时不阻断请求
        }
    }
}

function hasPermission(string $action): bool {
    $user = currentUser();
    if (!$user) return false;
    $perms = json_decode(PERMISSIONS, true);
    $allowed = $perms[$action] ?? [];
    return !empty(array_intersect($user['roles'], $allowed));
}

function requirePermission(string $action) {
    requireLogin();
    if (!hasPermission($action)) {
        http_response_code(403);
        echo json_encode(['error' => '权限不足']);
        exit;
    }
}

// ========== 输入/输出 ==========
function jsonInput(): array {
    // 合并 $_POST, $_GET, php://input（兼容各种请求方式）
    // 修复：$_POST 非空时也合并 $_GET，避免 URL 上的参数被丢弃（body 优先，GET 补充缺失键）
    $data = $_POST;
    if (empty($data)) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];
        // PUT/DELETE 的 URL-encoded body 回退
        if (empty($data) && !empty($raw)) {
            parse_str($raw, $data);
        }
    }
    if (!empty($_GET)) $data = array_merge($_GET, $data);
    return $data;
}

function jsonOutput($data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 安全辅助函数 ==========

/** 获取客户端真实IPv4和IPv6 */
function getClientIPs(): array {
    // 只信任 Cloudflare 的 CF-Connecting-IP（由 CF 覆盖，客户端无法伪造）与 REMOTE_ADDR，
    // 不再信任 X-Forwarded-For / X-Real-IP / Client-IP 等可被直接伪造的头，防止审计污染。
    $sources = [];
    foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $key) {
        $val = $_SERVER[$key] ?? '';
        if ($val) {
            foreach (explode(',', $val) as $ip) {
                $ip = trim($ip);
                if ($ip && !in_array($ip, $sources, true)) $sources[] = $ip;
            }
        }
    }

    // 分离 IPv4 / IPv6
    $ipv4 = '';
    $ipv6 = '';
    foreach ($sources as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if (!$ipv4) $ipv4 = $ip;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (!$ipv6) $ipv6 = $ip;
        }
        if ($ipv4 && $ipv6) break;
    }

    if (!$ipv4 && !$ipv6) $ipv4 = '127.0.0.1';
    return [$ipv4, $ipv6];
}

/** 解析浏览器信息 */
function getBrowserInfo(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (empty($ua)) return '未知';
    $browser = '未知'; $os = '未知';
    if (str_contains($ua, 'Windows NT 10')) $os = 'Windows 10/11';
    elseif (str_contains($ua, 'Windows')) $os = 'Windows';
    elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';
    elseif (str_contains($ua, 'Android')) $os = 'Android';
    elseif (str_contains($ua, 'Linux')) $os = 'Linux';
    elseif (str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')) $os = 'macOS';
    if (str_contains($ua, 'Edg/')) { preg_match('/Edg\/([\d.]+)/', $ua, $m); $browser = 'Edge '.($m[1]??''); }
    elseif (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Edg/')) { preg_match('/Chrome\/([\d.]+)/', $ua, $m); $browser = 'Chrome '.($m[1]??''); }
    elseif (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome/')) { preg_match('/Version\/([\d.]+)/', $ua, $m); $browser = 'Safari '.($m[1]??''); }
    elseif (str_contains($ua, 'Firefox/')) { preg_match('/Firefox\/([\d.]+)/', $ua, $m); $browser = 'Firefox '.($m[1]??''); }
    elseif (str_contains($ua, 'MicroMessenger')) $browser = '微信';
    elseif (str_contains($ua, 'QQ/')) $browser = 'QQ';
    elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera/')) $browser = 'Opera';
    return $os.' / '.$browser;
}

// ========== 操作日志（不可变） ==========
function addLog(int $userId, string $username, string $action, string $targetType, $targetId = null, $details = null) {
    try {
        [$ipv4, $ipv6] = getClientIPs();
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $binfo = getBrowserInfo();
        db()->prepare("INSERT INTO operation_logs (user_id, username, action, target_type, target_id, details, ip_address, ipv6_address, user_agent, browser_info, created_at) 
            VALUES (:uid, :uname, :act, :ttype, :tid, :det, :ip, :ipv6, :ua, :binfo, NOW())")->execute([
            ':uid'   => $userId, ':uname' => $username, ':act' => $action,
            ':ttype' => $targetType, ':tid' => $targetId,
            ':det'   => is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE),
            ':ip'    => $ipv4, ':ipv6' => $ipv6, ':ua' => mb_substr($ua, 0, 500), ':binfo' => $binfo,
        ]);
    } catch (\Exception $e) {
        error_log('[班费系统] addLog 写入失败: ' . $e->getMessage());
    }
}

/** 记录登录历史（每次登录尝试都记录，成功/失败均可审计） */
function addLoginHistory(int $userId, string $username, string $loginType, bool $success, string $failReason = '', string $fingerprint = '') {
    try {
        [$ipv4, $ipv6] = getClientIPs();
        $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $binfo = getBrowserInfo();
        db()->prepare("INSERT INTO login_history (user_id, username, login_type, success, ipv4_address, ipv6_address, user_agent, browser_info, fingerprint, fail_reason, created_at)
            VALUES (:uid, :uname, :lt, :ok, :ip4, :ip6, :ua, :binfo, :fp, :r, NOW())")->execute([
            ':uid'   => $userId, ':uname' => $username, ':lt' => $loginType, ':ok' => $success ? 1 : 0,
            ':ip4'   => $ipv4, ':ip6' => $ipv6, ':ua' => mb_substr($ua, 0, 500), ':binfo' => $binfo,
            ':fp'    => $fingerprint ?: null, ':r' => $failReason,
        ]);
    } catch (\Exception $e) {
        error_log('[班费系统] addLoginHistory 写入失败: ' . $e->getMessage());
    }
}
