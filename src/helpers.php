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

/** 递归编码数组中的所有字符串值 */
function escapeHtmlArray(array $data): array {
    $result = [];
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            $result[$key] = escapeHtml($value);
        } elseif (is_array($value)) {
            $result[$key] = escapeHtmlArray($value);
        } else {
            $result[$key] = $value;
        }
    }
    return $result;
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
    $input = jsonInput();
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
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

    // 通过文件内容检测真实类型（而非信任扩展名）
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

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

/** 记录安全事件到 error_log（不依赖数据库） */
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
}

