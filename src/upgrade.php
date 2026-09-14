<?php
/**
 * 班级班费管理系统 - 远程升级模块
 *
 * 安全设计：
 *  - 升级包地址必须落在本仓库 Releases 白名单内（允许 ghfast.top 代理前缀），
 *    防止远端 version.json 被篡改后指向任意地址；
 *  - version.json 若提供 sha256，则强校验升级包完整性；
 *  - 下载与解压均有体积上限，避免内存耗尽 / zip 炸弹。
 */

/** 国内可访问的 GitHub 代理前缀（ghfast.top 已在服务器实测可用；GitHub 直连国内不通） */
function githubProxy(string $url): string {
    if (strpos($url, 'https://github.com/') === 0 || strpos($url, 'https://raw.githubusercontent.com/') === 0) {
        return 'https://ghfast.top/' . $url;
    }
    return $url;
}

/** 读取远程文本，失败返回 null */
function upgradeHttpGet(string $url, int $timeout = 10): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout'        => $timeout,
        'user_agent'     => 'ClassFund-Updater/1.0',
        'follow_location'=> 1,
        'max_redirects'  => 3,
    ]]);
    $data = @file_get_contents($url, false, $ctx);
    return ($data === false || $data === '') ? null : $data;
}

/** 获取远端 version.json：优先 GitHub Contents API（国内可直连、不受代理/CDN 缓存影响），raw 兜底 */
function fetchRemoteVersion(): ?array {
    $api = 'https://api.github.com/repos/Momo8715/ClassFundManagementSystem/contents/version.json?ref=main&t=' . time();
    $ctx = stream_context_create(['http' => [
        'timeout'         => 10,
        'header'          => "Accept: application/vnd.github.raw
User-Agent: ClassFund-Updater/1.0
Cache-Control: no-cache
",
        'follow_location' => 1,
    ]]);
    $txt = @file_get_contents($api, false, $ctx);
    if (is_string($txt) && $txt !== '') {
        $d = json_decode($txt, true);
        if (is_array($d) && !empty($d['version'])) return $d;
    }
    // 兜底：raw.githubusercontent（代理优先，直连兜底）
    $raw = 'https://raw.githubusercontent.com/Momo8715/ClassFundManagementSystem/main/version.json?t=' . time();
    foreach (array_unique([githubProxy($raw), $raw]) as $u) {
        $json = upgradeHttpGet($u, 10);
        if ($json !== null) {
            $d = json_decode($json, true);
            if (is_array($d) && !empty($d['version'])) return $d;
        }
    }
    return null;
}

/** 升级包来源白名单：必须是本仓库 Releases 下的资产（可带 ghfast.top 代理前缀） */
function isAllowedUpgradeUrl(string $url): bool {
    $prefixes = [
        'https://github.com/Momo8715/ClassFundManagementSystem/releases/download/',
        'https://github.com/Momo8715/ClassFundManagementSystem/releases/latest/download/',
    ];
    $cands = [$url];
    if (strpos($url, 'https://ghfast.top/') === 0) {
        $cands[] = substr($url, strlen('https://ghfast.top/'));
    }
    foreach ($cands as $u) {
        foreach ($prefixes as $p) {
            if (strpos($u, $p) === 0) return true;
        }
    }
    return false;
}

/** 下载升级包：代理优先 → 直连兜底；带上限（默认 50MB），校验 PK 头 */
function downloadUpgradeZip(string $url, int $maxBytes = 52428800): ?string {
    foreach (array_unique([githubProxy($url), $url]) as $u) {
        $ctx = stream_context_create(['http' => [
            'timeout'        => 120,
            'user_agent'     => 'ClassFund-Updater/1.0',
            'follow_location'=> 1,
            'max_redirects'  => 3,
        ]]);
        $fp = @fopen($u, 'rb', false, $ctx);
        if (!$fp) continue;
        $data = '';
        $ok = true;
        while (!feof($fp)) {
            $chunk = fread($fp, 262144);
            if ($chunk === false) { $ok = false; break; }
            $data .= $chunk;
            if (strlen($data) > $maxBytes) { $ok = false; break; }
        }
        fclose($fp);
        if ($ok && $data !== '' && substr($data, 0, 2) === 'PK') return $data;
    }
    return null;
}

function handleUpgrade(string $method) {
    requirePermission('viewSecurity');

    switch ($method) {
        case 'GET':
            checkUpdate();
            break;
        case 'POST':
            doUpgrade();
            break;
        default:
            jsonOutput(['error' => '不支持的方法'], 405);
    }
}

/** 检查远程版本 */
function checkUpdate() {
    $local = json_decode(@file_get_contents(__DIR__ . '/../version.json'), true);
    $currentVersion = $local['version'] ?? '0.0.0';

    $remote = fetchRemoteVersion();
    if (!$remote) {
        jsonOutput(['error' => '无法连接远程更新服务器或版本信息无效'], 503);
    }

    $hasUpdate = version_compare($remote['version'], $currentVersion, '>');
    jsonOutput([
        'current'   => $currentVersion,
        'remote'    => $remote['version'],
        'hasUpdate' => $hasUpdate,
        'notes'     => $remote['notes'] ?? '',
        'url'       => $remote['url'] ?? '',
    ]);
}

/** 执行远程升级 */
// ============ 健壮文件写入（兼容属主不一致环境） ============
/**
 * 尽力写入升级文件：
 * 1. 直接写（文件属主=当前用户时成功）
 * 2. 失败 → 尝试 chmod 修正权限后写
 * 3. 仍失败 → 尝试删除旧文件（目录可写即可删除）再写新文件
 * 4. 删除也失败 → 尝试修改属主（如 PHP-FPM 以 root 运行）后写
 */
function writeUpgradeFile(string $path, string $content): bool {
    // 策略1: 直接写
    if (@file_put_contents($path, $content) !== false) return true;

    // 策略2: 目标已存在且不可写 → 尝试 chmod
    if (file_exists($path)) {
        @chmod($path, 0644);
        if (@file_put_contents($path, $content) !== false) return true;
    }

    // 策略3: 删除旧文件后重建（删除仅需目录写权限）
    if (file_exists($path)) {
        @chmod($path, 0666); // 尽力放开
        if (@unlink($path)) {
            if (@file_put_contents($path, $content) !== false) return true;
        }
    }

    // 策略4: 尝试 chown 给当前用户（通常无效，除非 root 运行）
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $user = get_current_user();
        @chown($path, $user);
        if (@file_put_contents($path, $content) !== false) return true;
    }

    // 策略5: 目录不可写时尝试放宽目录
    $dir = dirname($path);
    if (is_dir($dir)) {
        @chmod($dir, 0755);
        if (@file_put_contents($path, $content) !== false) return true;
    }

    return false;
}

function doUpgrade() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();

    // ===== 升级前权限预检：确保能写入站点文件 =====
    $rootDir = realpath(__DIR__ . '/..');
    if (!$rootDir || !is_dir($rootDir)) {
        jsonOutput(['error' => '无法定位站点目录'], 500);
    }
    $probe = $rootDir . '/.upgrade_probe_' . getmypid();
    $dirWritable = @file_put_contents($probe, '1') !== false;
    if (!$dirWritable) {
        @chmod($rootDir, 0755);
        $dirWritable = @file_put_contents($probe, '1') !== false;
    }
    if (file_exists($probe)) @unlink($probe);
    if (!$dirWritable) {
        $phpUser = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'www')
            : 'www';
        jsonOutput([
            'error' => '站点目录不可写，无法升级。请用 SSH 执行修复命令后重试：chown -R ' . $phpUser . ':' . $phpUser . ' ' . escapeshellarg($rootDir),
        ], 403);
    }

    set_time_limit(120);

    // ---- 远端版本（带来源校验） ----
    $remote = fetchRemoteVersion();
    if (!$remote) jsonOutput(['error' => '无法获取远程版本信息（网络不可达或版本信息无效）'], 503);

    $local = json_decode(@file_get_contents(__DIR__ . '/../version.json'), true);
    $currentVersion = $local['version'] ?? '0.0.0';
    if (!version_compare($remote['version'], $currentVersion, '>')) {
        jsonOutput(['error' => '当前已是最新版本（' . $currentVersion . '），无需升级'], 400);
    }

    // ---- 升级包地址白名单校验：只允许本仓库 Releases 资产 ----
    $zipUrl = $remote['url'] ?? '';
    if (!isAllowedUpgradeUrl($zipUrl)) {
        securityLog('upgrade_url_rejected', ['url' => $zipUrl, 'remote' => $remote['version']]);
        jsonOutput(['error' => '升级包地址不在允许范围内，已拒绝（升级源可能被篡改）'], 400);
    }

    // ---- 下载升级包（带上限） ----
    $zipData = downloadUpgradeZip($zipUrl);
    if ($zipData === null) {
        jsonOutput(['error' => '下载升级包失败（网络异常或超出体积上限）'], 500);
    }

    // ---- 完整性校验：version.json 提供 sha256 时必须匹配 ----
    $expectedSha = strtolower(trim((string)($remote['sha256'] ?? '')));
    if ($expectedSha !== '') {
        if (!hash_equals($expectedSha, hash('sha256', $zipData))) {
            securityLog('upgrade_hash_mismatch', ['expected' => $expectedSha, 'remote' => $remote['version']]);
            jsonOutput(['error' => '升级包完整性校验失败，已中止升级'], 400);
        }
    } else {
        error_log('[班费系统] 升级包未提供 sha256，跳过完整性校验');
    }

    $tmpZip = sys_get_temp_dir() . '/classfund_upgrade_' . time() . '.zip';
    file_put_contents($tmpZip, $zipData);

    // 验证 ZIP
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        unlink($tmpZip);
        jsonOutput(['error' => '升级包损坏，无法打开'], 500);
    }
    if ($zip->numFiles > 5000) {
        $zip->close(); unlink($tmpZip);
        jsonOutput(['error' => '升级包文件数量异常，已中止'], 500);
    }

    // 备份当前文件（排除 uploads、backup_、.reasonix）
    $backupDir = __DIR__ . '/../backup_' . date('Ymd_His');
    if (is_dir($backupDir)) $backupDir .= '_' . substr(md5(uniqid('', true)), 0, 4);
    if (!mkdir($backupDir, 0755, true)) {
        $zip->close(); unlink($tmpZip);
        jsonOutput(['error' => '无法创建备份目录'], 500);
    }

    $rootDir = realpath(__DIR__ . '/..');
    $exclude = ['uploads', '.reasonix', 'backup_', '.git', 'db_config.json'];
    $backupCount = backupFiles($rootDir, $backupDir, $exclude);

    // 解压覆盖（路径穿越 / 绝对路径 / 解压体积三重防护）
    $errors = [];
    $totalUncompressed = 0;
    $maxUncompressed = 200 * 1024 * 1024;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $name = $entry['name'];

        // 跳过目录和排除的文件
        if ($name === '' || substr($name, -1) === '/') continue;
        if ($name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name)) continue; // 路径穿越防护

        $totalUncompressed += (int)($entry['size'] ?? 0);
        if ($totalUncompressed > $maxUncompressed) {
            $zip->close(); unlink($tmpZip);
            jsonOutput(['error' => '升级包解压体积异常，已中止'], 500);
        }

        // 跳过排除目录中的文件
        $skip = false;
        foreach ($exclude as $ex) {
            if (strpos($name, $ex . '/') === 0) { $skip = true; break; }
        }
        if ($skip) continue;

        $destPath = $rootDir . '/' . $name;
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        $content = $zip->getFromIndex($i);
        if ($content === false || !writeUpgradeFile($destPath, $content)) {
            $errors[] = $name;
        }
    }

    $zip->close();
    unlink($tmpZip);

    // 以远端 version.json 为准写回本地：升级包内的版本信息可能滞后（CI 先打包、后回写 main），
    // 若不覆盖，升级完成后仍会提示「发现新版本」。
    $finalVersion = [
        'version' => (string)$remote['version'],
        'notes'   => (string)($remote['notes'] ?? ''),
        'url'     => (string)($remote['url'] ?? ''),
        'sha256'  => (string)($remote['sha256'] ?? ''),
    ];
    @file_put_contents(__DIR__ . '/../version.json', json_encode($finalVersion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $user = currentUser();
    addLog($user['id'], $user['username'], 'upgrade', 'system', null, [
        'from' => $currentVersion,
        'to' => $remote['version'],
        'sha256_verified' => $expectedSha !== '',
        'backup' => basename($backupDir),
        'backup_files' => $backupCount,
        'errors' => $errors,
    ]);

    if ($errors) {
        jsonOutput([
            'ok' => true,
            'warning' => '升级完成但有 ' . count($errors) . ' 个文件写入失败',
            'backup' => basename($backupDir),
            'failed' => $errors,
        ]);
    }

    jsonOutput([
        'ok' => true,
        'backup' => basename($backupDir),
        'message' => '升级成功，备份目录: ' . basename($backupDir),
    ]);
}

/** 递归备份文件 */
function backupFiles(string $src, string $dst, array $exclude): int {
    $count = 0;
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;

        // 跳过排除的顶级目录/文件：精确匹配、目录前缀匹配、以及 'backup_' 这类前缀名
        $relPath = str_replace(realpath(__DIR__ . '/..') . '/', '', $srcPath);
        $skip = false;
        foreach ($exclude as $ex) {
            if ($relPath === $ex) { $skip = true; break; }
            if (strpos($relPath, $ex . '/') === 0) { $skip = true; break; }
            // 前缀型排除项（以 '_' 结尾，如 'backup_'）：匹配所有以该前缀开头的路径，
            // 避免旧备份目录被递归复制进新备份导致体积膨胀
            if (str_ends_with($ex, '_') && str_starts_with($relPath, $ex)) { $skip = true; break; }
        }
        if ($skip) continue;

        if (is_dir($srcPath)) {
            if (!is_dir($dstPath)) mkdir($dstPath, 0755, true);
            $count += backupFiles($srcPath, $dstPath, $exclude);
        } else {
            copy($srcPath, $dstPath);
            $count++;
        }
    }
    return $count;
}
