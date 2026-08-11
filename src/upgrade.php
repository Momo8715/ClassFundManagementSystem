<?php
/**
 * 班级班费管理系统 - 远程升级模块
 */
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
    $remoteUrl = 'https://raw.githubusercontent.com/Momo8715/ClassFundManagementSystem/main/version.json';
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
function doUpgrade() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();

    set_time_limit(120);

    // 先从远程获取版本信息和下载地址
    $remoteUrl = 'https://raw.githubusercontent.com/Momo8715/ClassFundManagementSystem/main/version.json';
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

    $zipUrl = $remote['url'] ?? '';
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
        if ($content === false || file_put_contents($destPath, $content) === false) {
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

        // 跳过排除的顶级目录
        $relPath = str_replace(realpath(__DIR__ . '/..') . '/', '', $srcPath);
        $skip = false;
        foreach ($exclude as $ex) {
            if (strpos($relPath, $ex . '/') === 0 || $relPath === $ex) {
                $skip = true; break;
            }
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
