<?php
/**
 * 班级班费管理系统 - 数据库 + uploads 备份 / 恢复（v1.13）
 *
 * 备份包结构（单个 zip，默认放在站点根目录 backup_YYYYmmdd_HHMMSS_data.zip）：
 *   manifest.json        版本/时间/表数量/文件数量/统计
 *   db/schema.json       SHOW CREATE TABLE 的建表语句
 *   db/<table>.jsonl     每行一条记录；二进制列以 {"__b64":"..."} 保存
 *   uploads/...          凭证原图（保持相对路径）
 *
 * 设计取舍：不使用 mysqldump（宝塔常禁用 exec），用 PDO 生成可回放的 JSONL；
 * 恢复时按 schema.json 重建表再逐行插入，避免解析 SQL 字符串里的分号。
 */

function backupRoot(): string {
    return dirname(__DIR__);
}

function backupEnsureToken(): string {
    $t = getMeta('cron_token');
    if (strlen($t) < 32) {
        $t = bin2hex(random_bytes(24));
        setMeta('cron_token', $t);
    }
    return $t;
}

function backupTokenValid(string $token): bool {
    if ($token === '' || strlen($token) < 32) return false;
    return hash_equals(backupEnsureToken(), $token);
}

function backupRetention(): int {
    $n = (int)getMeta('backup_retention', '14');
    return ($n >= 1 && $n <= 90) ? $n : 14;
}

function backupCronUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_PROTOCOL'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
    return ($https ? 'https://' : 'http://') . $host . '/api.php?action=backup_cron&token=' . backupEnsureToken();
}

function backupCronAllUrl(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
    return ($https ? 'https://' : 'http://') . $host . '/cron.php?token=' . backupEnsureToken() . '&task=all';
}

function backupRemoveDir(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) @rmdir($f->getPathname()); else @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function backupTableNames(): array {
    $rows = db()->query("SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    return array_values($rows);
}

function backupColumns(string $table): array {
    $cols = [];
    $stmt = db()->query("SHOW COLUMNS FROM `" . $table . "`");
    while ($c = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[(string)$c['Field']] = strtolower((string)$c['Type']);
    }
    return $cols;
}

function backupIsBinaryColumn(string $type): bool {
    return (bool)preg_match('/blob|binary/i', $type);
}

function backupEncodeValue($value, bool $binary) {
    if ($value === null) return null;
    $s = (string)$value;
    if ($binary || !preg_match('//u', $s)) return ['__b64' => base64_encode($s)];
    return $s;
}

/** 读取备份包的 manifest（失败返回 null） */
function backupReadManifest(string $path): ?array {
    if (!is_file($path) || filesize($path) <= 0) return null;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $raw = $zip->getFromName('manifest.json');
    $zip->close();
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/** 列出备份文件 */
function backupListFiles(): array {
    $root = backupRoot();
    $files = glob($root . '/backup_*_data.zip') ?: [];
    $runs = [];
    try {
        foreach (db()->query("SELECT filename, sha256, status, error, trigger_type, created_at FROM backup_runs ORDER BY id DESC LIMIT 200")->fetchAll() as $r) {
            $runs[(string)$r['filename']] = $r;
        }
    } catch (\Exception $e) { /* 表缺失 */ }

    $out = [];
    foreach ($files as $path) {
        $name = basename($path);
        $manifest = backupReadManifest($path);
        $out[] = [
            'filename' => $name,
            'size' => (int)filesize($path),
            'mtime' => date('Y-m-d H:i:s', (int)filemtime($path)),
            'manifest' => $manifest,
            'sha256' => $runs[$name]['sha256'] ?? null,
            'status' => $manifest ? 'ok' : 'invalid',
            'trigger_type' => $runs[$name]['trigger_type'] ?? ($manifest['trigger'] ?? 'manual'),
            'error' => $runs[$name]['error'] ?? null,
        ];
    }
    usort($out, function ($a, $b) { return strcmp((string)$b['mtime'], (string)$a['mtime']); });
    return $out;
}

function backupRecord(string $filename, int $size, int $tables, int $uploads, ?string $sha, string $status, string $error, string $trigger, int $userId): void {
    try {
        db()->prepare("INSERT INTO backup_runs
            (filename, file_size, db_tables, uploads_count, sha256, status, error, trigger_type, created_by)
            VALUES (:f, :s, :t, :u, :h, :st, :e, :tr, :c)
            ON DUPLICATE KEY UPDATE file_size=VALUES(file_size), db_tables=VALUES(db_tables),
            uploads_count=VALUES(uploads_count), sha256=VALUES(sha256), status=VALUES(status),
            error=VALUES(error), trigger_type=VALUES(trigger_type), created_by=VALUES(created_by)")
            ->execute([
                ':f' => $filename, ':s' => $size, ':t' => $tables, ':u' => $uploads,
                ':h' => $sha, ':st' => $status, ':e' => mb_substr($error, 0, 500),
                ':tr' => mb_substr($trigger, 0, 50), ':c' => $userId,
            ]);
    } catch (\Exception $e) { /* 记录失败不影响备份 */ }
}

function backupPrune(): int {
    $files = glob(backupRoot() . '/backup_*_data.zip') ?: [];
    if (count($files) <= backupRetention()) return 0;
    usort($files, function ($a, $b) { return filemtime($b) <=> filemtime($a); });
    $deleted = 0;
    foreach (array_slice($files, backupRetention()) as $f) {
        if (@unlink($f)) $deleted++;
    }
    return $deleted;
}

/**
 * 创建备份，返回 [filename,size,sha256,tables,uploads]。
 * @throws RuntimeException
 */
function backupCreate(int $userId = 0, string $trigger = 'manual'): array {
    @set_time_limit(300);
    $root = backupRoot();
    $tmp = sys_get_temp_dir() . '/cf_backup_' . bin2hex(random_bytes(6));
    if (!mkdir($tmp . '/db', 0755, true)) throw new RuntimeException('无法创建临时目录');
    $zipPath = '';
    $filename = '';
    try {
        $tables = backupTableNames();
        $schema = [];
        $tableStats = [];
        $dbBytes = 0;
        foreach ($tables as $table) {
            $row = db()->query("SHOW CREATE TABLE `" . $table . "`")->fetch(PDO::FETCH_ASSOC);
            if (!$row) continue;
            $ddl = $row['Create Table'] ?? (array_values($row)[1] ?? '');
            if ($ddl === '') continue;
            $schema[$table] = $ddl;
            $cols = backupColumns($table);
            $fp = fopen($tmp . '/db/' . $table . '.jsonl', 'wb');
            if (!$fp) throw new RuntimeException('无法写入临时备份文件');
            $count = 0;
            $stmt = db()->query("SELECT * FROM `" . $table . "`");
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $enc = [];
                foreach ($r as $k => $v) $enc[$k] = backupEncodeValue($v, backupIsBinaryColumn($cols[$k] ?? ''));
                fwrite($fp, json_encode($enc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                $count++;
            }
            fclose($fp);
            $dbBytes += (int)@filesize($tmp . '/db/' . $table . '.jsonl');
            $tableStats[$table] = $count;
        }
        $schemaJson = json_encode(['tables' => $schema, 'generated_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($tmp . '/db/schema.json', $schemaJson);

        $uploadsCount = 0;
        $uploadsBytes = 0;
        $uploadRoot = $root . '/uploads';
        if (is_dir($uploadRoot)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $uploadsCount++;
                $uploadsBytes += (int)$file->getSize();
            }
        }

        $filename = 'backup_' . date('Ymd_His') . '_data.zip';
        $zipPath = $root . '/' . $filename;
        if (file_exists($zipPath)) $filename = 'backup_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '_data.zip';
        $zipPath = $root . '/' . $filename;
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建备份 zip');
        $zip->addFromString('db/schema.json', $schemaJson);
        foreach ($tableStats as $table => $count) {
            $zip->addFile($tmp . '/db/' . $table . '.jsonl', 'db/' . $table . '.jsonl');
        }
        if (is_dir($uploadRoot)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $zip->addFile($file->getPathname(), $rel);
            }
        }
        $version = 'unknown';
        $vf = $root . '/version.json';
        if (is_file($vf)) { $v = json_decode((string)file_get_contents($vf), true); if (is_array($v) && !empty($v['version'])) $version = (string)$v['version']; }
        $manifest = [
            'app' => 'ClassFund',
            'version' => $version,
            'schema_version' => getMeta('schema_version'),
            'created_at' => date('c'),
            'trigger' => $trigger,
            'created_by' => $userId,
            'table_count' => count($tableStats),
            'tables' => $tableStats,
            'uploads_count' => $uploadsCount,
            'uploads_bytes' => $uploadsBytes,
            'db_bytes' => $dbBytes,
            'php_version' => PHP_VERSION,
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();
        @chmod($zipPath, 0600);
        $size = (int)filesize($zipPath);
        $sha = hash_file('sha256', $zipPath);
        backupRecord($filename, $size, count($tableStats), $uploadsCount, $sha, 'success', '', $trigger, $userId);
        backupPrune();
        backupRemoveDir($tmp);
        return ['filename' => $filename, 'size' => $size, 'sha256' => $sha, 'tables' => count($tableStats), 'uploads' => $uploadsCount];
    } catch (\Throwable $e) {
        if ($zipPath !== '' && is_file($zipPath)) @unlink($zipPath);
        backupRemoveDir($tmp);
        $safe = $filename !== '' ? $filename : 'failed_' . date('Ymd_His');
        backupRecord($safe, 0, 0, 0, null, 'failed', $e->getMessage(), $trigger, $userId);
        throw new RuntimeException($e->getMessage());
    }
}

/** 校验备份文件名，返回绝对路径或 null */
function backupResolveFile(string $filename): ?string {
    $filename = basename(trim($filename));
    if (!preg_match('/^backup_\d{8}_\d{6}(_[a-f0-9]{1,8})?_data\.zip$/', $filename)) return null;
    $path = backupRoot() . '/' . $filename;
    return is_file($path) ? $path : null;
}

/** 恢复备份（full：重建表 + 覆盖同名 uploads 文件） */
function backupRestore(string $path, int $userId): array {
    @set_time_limit(600);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('无法打开备份包');
    $manifestRaw = $zip->getFromName('manifest.json');
    $schemaRaw = $zip->getFromName('db/schema.json');
    if ($manifestRaw === false || $schemaRaw === false) { $zip->close(); throw new RuntimeException('备份包缺少 manifest/schema'); }
    $schema = json_decode($schemaRaw, true);
    $tables = is_array($schema['tables'] ?? null) ? $schema['tables'] : [];
    if (empty($tables)) { $zip->close(); throw new RuntimeException('备份包没有表结构'); }

    // 体积/数量上限，避免 zip 炸弹
    if ($zip->numFiles > 10000) { $zip->close(); throw new RuntimeException('备份包文件数量异常'); }
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        $total += (int)($st['size'] ?? 0);
        if ($total > 500 * 1024 * 1024) { $zip->close(); throw new RuntimeException('备份包解压体积异常'); }
    }

    $safe = backupCreate($userId, 'pre_restore');
    $safety = $safe['filename'];
    try {
        $db = db();
        $db->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach ($tables as $table => $ddl) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) throw new RuntimeException('非法表名');
            $db->exec("DROP TABLE IF EXISTS `" . $table . "`");
            $db->exec($ddl);
        }
        foreach ($tables as $table => $ddl) {
            $raw = $zip->getFromName('db/' . $table . '.jsonl');
            if ($raw === false || $raw === '') continue;
            $lines = explode("\n", $raw);
            $cols = null;
            $insert = null;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $row = json_decode($line, true);
                if (!is_array($row)) continue;
                if ($cols === null) {
                    $cols = array_keys($row);
                    $ph = implode(',', array_fill(0, count($cols), '?'));
                    $quoted = implode(',', array_map(function ($c) { return "`" . $c . "`"; }, $cols));
                    $insert = $db->prepare("INSERT INTO `" . $table . "` (" . $quoted . ") VALUES (" . $ph . ")");
                }
                $i = 1;
                foreach ($cols as $c) {
                    $v = $row[$c] ?? null;
                    if (is_array($v) && array_key_exists('__b64', $v)) {
                        $insert->bindValue($i++, base64_decode((string)$v['__b64']), PDO::PARAM_LOB);
                    } elseif ($v === null) {
                        $insert->bindValue($i++, null, PDO::PARAM_NULL);
                    } else {
                        $insert->bindValue($i++, (string)$v, PDO::PARAM_STR);
                    }
                }
                $insert->execute();
            }
        }
        $db->exec("SET FOREIGN_KEY_CHECKS=1");

        // 恢复 uploads（只覆盖同名文件，不删除备份中没有的文件）
        $uploads = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string)($st['name'] ?? ''));
            if (substr($name, -1) === '/') continue;
            if (strpos($name, 'uploads/') !== 0) continue;
            if (strpos($name, '..') !== false || $name[0] === '/') continue;
            if (preg_match('/\.(php|phtml|phar|php[0-9]?)$/i', $name)) continue;
            $data = $zip->getFromIndex($i);
            if ($data === false) continue;
            $dest = backupRoot() . '/' . $name;
            $dir = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (@file_put_contents($dest, $data) !== false) { @chmod($dest, 0644); $uploads++; }
        }
        $zip->close();
        // 备份可能来自旧版本：补齐后续新增列/表
        try { autoMigrate(); } catch (\Throwable $e) { /* 迁移失败不回滚已恢复的数据 */ }
        addLog($userId, currentUser()['username'] ?? 'system', 'backup_restore', 'system', null, [
            'file' => basename($path), 'safety_backup' => $safety, 'uploads' => $uploads,
        ]);
        return ['safety_backup' => $safety, 'tables' => count($tables), 'uploads' => $uploads];
    } catch (\Throwable $e) {
        $zip->close();
        throw new RuntimeException('恢复中断：' . $e->getMessage() . '。恢复前备份：' . $safety);
    }
}

// ==================== 接口 ====================
function handleBackupList() {
    requirePermission('viewSecurity');
    $files = backupListFiles();
    $last = $files[0] ?? null;
    jsonOutput([
        'files' => $files,
        'last' => $last,
        'retention' => backupRetention(),
        'cron_url' => backupCronUrl(),
        'cron_all_url' => backupCronAllUrl(),
        'backup_dir' => '站点根目录 backup_*_data.zip（nginx 已按 backup_ 禁止直接访问）',
    ]);
}

function handleBackupCreate() {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $user = currentUser();
    $res = backupCreate((int)$user['id'], 'manual');
    addLog((int)$user['id'], (string)$user['username'], 'backup_create', 'system', null, $res);
    jsonOutput(['ok' => true, 'backup' => $res]);
}

function handleBackupCron() {
    $token = (string)($_GET['token'] ?? '');
    if (!backupTokenValid($token)) { http_response_code(403); echo json_encode(['error' => 'token invalid']); exit; }
    $res = backupCreate(0, 'cron');
    jsonOutput(['ok' => true, 'backup' => $res]);
}

function handleBackupDownload() {
    requirePermission('viewSecurity');
    $file = (string)($_GET['file'] ?? '');
    $path = backupResolveFile($file);
    if (!$path) { http_response_code(404); echo '备份不存在'; exit; }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function handleBackupRestore() {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $input = jsonInput();
    $file = (string)($input['file'] ?? '');
    if (($input['confirm'] ?? '') !== 'RESTORE') jsonOutput(['error' => '请输入 RESTORE 确认恢复（会覆盖当前数据库）'], 400);
    $path = backupResolveFile($file);
    if (!$path) jsonOutput(['error' => '备份不存在'], 404);
    $user = currentUser();
    try {
        $res = backupRestore($path, (int)$user['id']);
        jsonOutput(['ok' => true, 'result' => $res]);
    } catch (\Throwable $e) {
        addLog((int)$user['id'], (string)$user['username'], 'backup_restore_failed', 'system', null, ['file' => basename($path), 'error' => $e->getMessage()]);
        jsonOutput(['error' => $e->getMessage()], 500);
    }
}

function handleBackupDelete() {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $input = jsonInput();
    $path = backupResolveFile((string)($input['file'] ?? ''));
    if (!$path) jsonOutput(['error' => '备份不存在'], 404);
    $name = basename($path);
    if (!@unlink($path)) jsonOutput(['error' => '删除失败'], 500);
    $user = currentUser();
    addLog((int)$user['id'], (string)$user['username'], 'backup_delete', 'system', null, ['file' => $name]);
    jsonOutput(['ok' => true]);
}

function handleBackupRetentionSave() {
    requirePermission('viewSecurity');
    requireCsrfToken();
    $input = jsonInput();
    $n = (int)($input['retention'] ?? 14);
    if ($n < 1 || $n > 90) jsonOutput(['error' => '保留数量需在 1-90 之间'], 400);
    setMeta('backup_retention', (string)$n);
    jsonOutput(['ok' => true, 'retention' => $n]);
}
