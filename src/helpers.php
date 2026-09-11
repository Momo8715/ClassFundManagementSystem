<?php
/**
 * 班级班费管理系统 - 工具函数库
 * XSS 防护、CSRF 令牌、速率限制、安全工具
 */

// ========== XSS / 输出编码 ==========

/** HTML 实体编码，防止存储型 XSS */
function escapeHtml(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ========== CSRF 保护 ==========

/** 生成 CSRF 令牌并存入 session */
function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** 验证 CSRF 令牌 */
function validateCsrfToken(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/** 要求 CSRF 令牌，无效则拒绝请求 */
function requireCsrfToken(): void {
    // 只从请求体或请求头读取 token，不接受 URL 参数（防止 token 经 Referer/日志泄露）
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($token)) {
        // PUT/DELETE 等请求体需手动解析
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (!is_array($data)) { parse_str($raw, $data); }
            $token = $data['csrf_token'] ?? '';
        }
    }
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF 令牌无效或缺失，请刷新页面后重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== 速率限制 ==========

/** 简单的 IP + Action 速率限制（基于文件存储） */
function rateLimit(string $action, int $maxAttempts = 10, int $windowSeconds = 60): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $key = 'ratelimit_' . $action . '_' . str_replace([':', '.'], '_', $ip);
    $file = sys_get_temp_dir() . '/' . $key . '.json';

    $now = time();

    // 使用排他锁包裹读取-修改-写入，消除 TOCTOU 竞态条件
    $fp = @fopen($file, 'c+');
    if (!$fp) return true; // 无法打开锁文件时放行，避免锁死
    if (!flock($fp, LOCK_EX)) { fclose($fp); return true; }

    $data = ['attempts' => 0, 'reset_at' => $now + $windowSeconds];
    $raw = stream_get_contents($fp);
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        // 校验结构，防止损坏/异常数据导致错误
        if (is_array($decoded) && isset($decoded['attempts']) && isset($decoded['reset_at'])) {
            $data = $decoded;
        }
    }

    if ($now > $data['reset_at']) {
        // 窗口过期，重置
        $data = ['attempts' => 0, 'reset_at' => $now + $windowSeconds];
    }

    $data['attempts']++;
    $allowed = $data['attempts'] <= $maxAttempts;

    // 原子写入
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $allowed;
}

/** 强制速率限制检查，超出则拒绝 */
function requireRateLimit(string $action, int $maxAttempts = 10, int $windowSeconds = 60): void {
    if (!rateLimit($action, $maxAttempts, $windowSeconds)) {
        http_response_code(429);
        echo json_encode([
            'error' => '操作过于频繁，请 ' . ceil($windowSeconds / 60) . ' 分钟后再试',
            'retry_after' => $windowSeconds
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ========== 输入验证 ==========

/** 验证日期格式 YYYY-MM-DD */
function isValidDate(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $parts = explode('-', $date);
    return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
}

/** 验证并清洗金额 */
function sanitizeAmount($value): float {
    $amount = floatval($value);
    return max(0, round($amount, 2));
}

/** 验证图片上传（检查 MIME 类型 + 文件签名） */
function validateImageUpload(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if ($file['error'] === UPLOAD_ERR_NO_FILE) return '没有上传文件';
        return '文件上传失败（错误码：' . $file['error'] . '）';
    }

    // 大小限制 5MB
    if ($file['size'] > 5 * 1024 * 1024) {
        return '图片不能超过 5MB';
    }

    // 通过文件内容检测真实类型（而非信任扩展名）；fileinfo 扩展缺失时回退 getimagesize
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
    if (empty($mime)) {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) return '文件不是有效的图片';
        $mime = $info['mime'];
    }

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    if (!in_array($mime, $allowedMimes, true)) {
        return '不支持的文件类型（检测到：' . $mime . '），仅允许 jpg/png/gif/webp/bmp';
    }

    // 额外验证：用 getimagesize 确认是真实图片
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return '文件不是有效的图片';
    }

    return null; // 验证通过
}

/** 获取安全的文件扩展名 */
function safeExtension(string $mime): string {
    $map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/bmp'  => 'bmp',
    ];
    return $map[$mime] ?? 'bin';
}

// ========== 安全日志 ==========

/** 记录安全事件：写入 error_log，并尽力同步到 security_events 表（供安全面板展示） */
function securityLog(string $event, array $context = []): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $log = sprintf(
        "[SECURITY] %s | IP: %s | %s | %s",
        date('Y-m-d H:i:s'),
        $ip,
        $event,
        json_encode($context, JSON_UNESCAPED_UNICODE)
    );
    error_log($log);

    // 落库（最佳努力）：每请求最多 5 条，同事件+同 IP 60 秒内去重，避免被刷爆
    static $writes = 0;
    if ($writes >= 5) return;
    $writes++;
    try {
        [$ipv4, $ipv6] = function_exists('getClientIPs') ? getClientIPs() : [null, null];
        $detail = json_encode($context, JSON_UNESCAPED_UNICODE);
        $detail = mb_substr(is_string($detail) ? $detail : '', 0, 500);
        $dup = db()->prepare("SELECT 1 FROM security_events WHERE event=:e AND ipv4 <=> :ip AND created_at > (NOW() - INTERVAL 60 SECOND) LIMIT 1");
        $dup->execute([':e' => $event, ':ip' => $ipv4]);
        if ($dup->fetchColumn()) return;
        db()->prepare("INSERT INTO security_events (event, detail, ipv4, ipv6, username, created_at)
            VALUES (:e, :d, :i4, :i6, :u, NOW())")->execute([
            ':e'  => mb_substr($event, 0, 100),
            ':d'  => $detail,
            ':i4' => $ipv4,
            ':i6' => $ipv6,
            ':u'  => isset($_SESSION['username']) ? mb_substr((string)$_SESSION['username'], 0, 50) : null,
        ]);
    } catch (\Exception $e) {
        // 落库失败不影响主流程
    }
}

// ========== 导出辅助 ==========

/** CSV 公式注入防护：以 = + - @ 或制表符/回车开头的单元格前置单引号，防止 Excel 执行公式 */
function csvSafe($value): string {
    $v = (string)$value;
    if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) return "'" . $v;
    return $v;
}

/** 生成兼容中文文件名的 Content-Disposition（RFC 6266 / RFC 5987） */
function contentDisposition(string $filename): string {
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename);
    $ascii = str_replace(['"', '\\'], '', (string)$ascii);
    return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
}

