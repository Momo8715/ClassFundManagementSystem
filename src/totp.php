<?php
/**
 * 班级班费管理系统 - TOTP（RFC 6238）与备用码
 *
 * 纯 PHP 实现，不依赖第三方库；用于管理员/班委的两步验证。
 * 备份码使用 SHA-256 哈希存储（码本身 50 bit 随机，足够抵抗离线爆破）。
 */

/** Base32 编码（RFC 4648，无填充） */
function totpBase32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

/** Base32 解码（忽略空格/连字符/填充；非法字符返回空串） */
function totpBase32Decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    if ($b32 === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) return '';
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

/** 生成 Base32 TOTP 密钥（默认 160 bit） */
function totpSecret(int $bytes = 20): string {
    if ($bytes < 10) $bytes = 10;
    if ($bytes > 64) $bytes = 64;
    return totpBase32Encode(random_bytes($bytes));
}

/** 生成指定时间步的 6 位码 */
function totpCode(string $secret, ?int $timeSlice = null, int $digits = 6, int $period = 30): string {
    if ($timeSlice === null) $timeSlice = (int)floor(time() / $period);
    $key = totpBase32Decode($secret);
    if ($key === '') return '';
    $counter = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $counter, $key, true);
    if ($hash === false || strlen($hash) < 20) return '';
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    $mod = (int)pow(10, $digits);
    return str_pad((string)($value % $mod), $digits, '0', STR_PAD_LEFT);
}

/**
 * 校验 TOTP。
 * @return array{ok:bool, step:int}
 */
function totpVerify(string $secret, string $code, int $window = 1, ?int $lastStep = null, int $period = 30): array {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) return ['ok' => false, 'step' => 0];
    $now = (int)floor(time() / $period);
    for ($i = -$window; $i <= $window; $i++) {
        $step = $now + $i;
        if ($step <= 0) continue;
        if ($lastStep !== null && $step <= $lastStep) continue; // 防重放
        $expect = totpCode($secret, $step, 6, $period);
        if ($expect !== '' && hash_equals($expect, $code)) {
            return ['ok' => true, 'step' => $step];
        }
    }
    return ['ok' => false, 'step' => 0];
}

/** otpauth:// 链接（供手动粘贴到验证器；不依赖二维码服务，避免密钥外泄） */
function totpUri(string $secret, string $account, string $issuer): string {
    $label = rawurlencode($issuer . ':' . $account);
    return 'otpauth://totp/' . $label
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/** 生成 n 个备用码（形如 ABCDE-FGHJK），返回明文与哈希 */
function totpGenerateBackupCodes(int $count = 8): array {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 去掉易混淆的 0/O/1/I
    $codes = [];
    $hashes = [];
    for ($n = 0; $n < $count; $n++) {
        $raw = '';
        for ($i = 0; $i < 10; $i++) $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        $codes[] = $code;
        $hashes[] = hash('sha256', totpNormalizeBackupCode($code));
    }
    return ['codes' => $codes, 'hashes' => $hashes];
}

function totpNormalizeBackupCode(string $code): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
}

/** 校验备用码；返回命中的索引，未命中返回 null */
function totpVerifyBackupCode(string $code, array $hashes): ?int {
    $norm = totpNormalizeBackupCode($code);
    if ($norm === '') return null;
    $hash = hash('sha256', $norm);
    foreach (array_values($hashes) as $i => $h) {
        if (is_string($h) && $h !== '' && hash_equals($h, $hash)) return (int)$i;
    }
    return null;
}
