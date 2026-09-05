<?php
/**
 * 班级班费管理系统 - 远程升级模块
 */

/** 国内可访问的 GitHub 代理前缀（ghfast.top 已在服务器实测可用；GitHub 直连国内不通） */
function githubProxy(string $url): string {
    if (strpos($url, 'https://github.com/') === 0 || strpos($url, 'https://raw.githubusercontent.com/') === 0) {
        return 'https://ghfast.top/' . $url;
    }
    return $url;
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
    $remoteUrl = githubProxy('https://raw.githubusercontent.com/Momo8715/ClassFundManagementSystem/main/version.json');
    $local = json_decode(file_get_contents(__DIR__ . '/../version.json'), true);
    $currentVersion = $local['version'] ?? '0.0.0';

    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($remoteUrl, false, $ctx);
    if (!$json) {
        jsonOutput(['error' => '无法连接远程更新服务器'], 503);
    }

    $remote = json_decode($json, true);
    if (!$remote || empty($remote['version'])) {
        jsonOutput(['error' => '远程版本信息无效'], 500);
    }

    $hasUpdate = version_compare($remote['version'], $currentVersion, '>');
    jsonOutput([
        'current'  => $currentVersion,
        'remote'   => $remote['version'],
        'hasUpdate' => $hasUpdate,
        'notes'    => $remote['notes'] ?? '',
        'url'      => $remote['url'] ?? '',
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

    // 先从远程获取版本信息和下载地址
    $remoteUrl = githubProxy('https://raw.githubusercontent.com/Momo8715/ClassFundManagementSystem/main/version.json');
    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $json = @file_get_contents($remoteUrl, false, $ctx);
    if (!$json) jsonOutput(['error' => '无法连接远程更新服务器'], 503);

    $remote = json_decode($json, true);
    if (!$remote || empty($remote['version'])) jsonOutput(['error' => '远程版本信息无效'], 500);

    // 版本比较：只允许升级到更高版本
    $local = json_decode(file_get_contents(__DIR__ . '/../version.json'), true);
    $currentVersion = $local['version'] ?? '0.0.0';
    if (!version_compare($remote['version'], $currentVersion, '>')) {
        jsonOutput(['error' => '当前已是最新版本（' . $currentVersion . '），无需升级'], 400);
    }

    $zipUrl = githubProxy($remote['url'] ?? '');
    if (empty($zipUrl)) jsonOutput(['error' => '远程未配置升级包地址'], 400);

    // 下载 ZIP
    $ctx = stream_context_create(['http' => ['timeout' => 120]]);
    $zipData = @file_get_contents($zipUrl, false, $ctx);
    if (!$zipData) jsonOutput(['error' => '下载升级包失败'], 500);

    $tmpZip = sys_get_temp_dir() . '/classfund_upgrade_' . time() . '.zip';
    file_put_contents($tmpZip, $zipData);

    // 验证 ZIP
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        unlink($tmpZip);
        jsonOutput(['error' => '升级包损坏，无法打开'], 500);
    }

    // 备份当前文件（排除 uploads、backup_、.reasonix）
    $backupDir = __DIR__ . '/../backup_' . date('Ymd_His');
    if (!mkdir($backupDir, 0755, true)) {
        $zip->close(); unlink($tmpZip);
        jsonOutput(['error' => '无法创建备份目录'], 500);
    }

    $rootDir = realpath(__DIR__ . '/..');
    $exclude = ['uploads', '.reasonix', 'backup_', '.git', 'db_config.json'];
    $backupCount = backupFiles($rootDir, $backupDir, $exclude);

    // 解压覆盖
    $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $name = $entry['name'];

        // 跳过目录和排除的文件
        if (substr($name, -1) === '/') continue;
        if (strpos($name, '..') !== false) continue; // 路径穿越防护

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

    $user = currentUser();
    addLog($user['id'], $user['username'], 'upgrade', 'system', null, [
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
