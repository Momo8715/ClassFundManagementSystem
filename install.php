<?php
/**
 * 班级班费管理系统 - 安装向导
 * 上传即用：自动检测环境，引导完成数据库配置、建表、创建管理员
 * 部署完成后建议删除此文件
 */
require_once __DIR__ . '/config.php';

// 已安装保护：db_config.json 存在即禁止重装（不依赖数据库可用性，防止被未认证重装接管）
if (file_exists(__DIR__ . '/db_config.json')) {
    header('Location: index.php');
    exit;
}

$step    = intval($_POST['step'] ?? $_GET['step'] ?? 0);
$error   = '';
$msg     = '';
$envPass = true;

// ---- 环境检测 ----
$phpVersion    = PHP_VERSION;
$phpOk         = version_compare($phpVersion, '8.0', '>=');
$pdoOk         = extension_loaded('pdo');
$mysqlOk       = extension_loaded('pdo_mysql');
$jsonOk        = extension_loaded('json');
$sessionOk     = true; // 内置

// ---- Step 0: 环境检查 + 数据库配置 ----
if ($step === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 用户提交了数据库配置
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbCharset = 'utf8mb4';

    if (empty($dbName) || empty($dbUser)) {
        $error = '请填写数据库名和用户名';
    } else {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset={$dbCharset}";
            $testPdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $testPdo->query("SELECT 1");

            // 连接成功，暂存配置到 session，进入建表步骤
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['install_db'] = [
                'DB_HOST' => $dbHost, 'DB_PORT' => $dbPort, 'DB_NAME' => $dbName,
                'DB_USER' => $dbUser, 'DB_PASS' => $dbPass, 'DB_CHARSET' => $dbCharset,
            ];
            $step = 1; // 跳转到建表
        } catch (Exception $e) {
            error_log('[班费系统] 数据库连接失败: ' . $e->getMessage());
            $error = '数据库连接失败，请检查：① 用户名/密码是否与宝塔「数据库」页面一致；② 该用户在 MySQL 中是否存在（不存在请新建）；③ 数据库名是否已创建。';
        }
    }
}

// ---- 获取当前有效的数据库连接（安装过程中使用 session 暂存） ----
function getInstallDb(): PDO {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $cfg = $_SESSION['install_db'] ?? null;
    if (!$cfg) throw new Exception('数据库配置丢失，请返回第一步重新填写');
    $dsn = 'mysql:host=' . $cfg['DB_HOST'] . ';port=' . $cfg['DB_PORT'] . ';dbname=' . $cfg['DB_NAME'] . ';charset=' . $cfg['DB_CHARSET'];
    return new PDO($dsn, $cfg['DB_USER'], $cfg['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** 确保所有数据表存在（安全幂等，已存在则跳过） */
function ensureTablesExist(PDO $db): int {
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if (!$sql) throw new Exception('无法读取 schema.sql');

    // 按分号拆分 SQL 语句，过滤空行和注释
    $statements = [];
    $current = '';
    $lines = explode("\n", $sql);
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // 跳过注释行
        if ($trimmed === '' || str_starts_with($trimmed, '--')) continue;
        $current .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $stripped = trim($current);
            if (!empty($stripped)) {
                $statements[] = rtrim($stripped, ';');
            }
            $current = '';
        }
    }
    // 最后一条可能没有分号
    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    $count = 0;
    foreach ($statements as $stmt) {
        // 只执行 CREATE TABLE 语句
        if (preg_match('/^CREATE\s+TABLE/i', $stmt)) {
            $db->exec($stmt);
            $count++;
        }
    }
    return $count;
}

// ---- Step 1: 建表 ----
$forceReinstall = ($_POST['force_reinstall'] ?? '') === '1';

if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getInstallDb();

        $already = $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;

        if ($already && $forceReinstall) {
            // 全新安装：先删旧表
            $db->exec("DROP TABLE IF EXISTS login_history");
            $db->exec("DROP TABLE IF EXISTS operation_logs");
            $db->exec("DROP TABLE IF EXISTS transactions");
            $db->exec("DROP TABLE IF EXISTS users");
            $already = false;
        }

        if ($already) {
            $msg = '⚠️ 数据表已存在，可直接进入系统。如需全新安装（清除旧数据），请点击下方按钮。';
            // 不跳步骤，让用户选择
        } else {
            $count = ensureTablesExist($db);
            $msg = '✅ 数据表创建成功！共 ' . $count . ' 张表。';
            $step = 2;
        }
    } catch (Exception $e) {
        error_log('[班费系统] 建表失败: ' . $e->getMessage());
        $error = '建表失败，请检查数据库权限后重试，详见服务器错误日志';
    }
}

// ---- Step 2: 创建管理员 + 保存配置 ----
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminName   = trim($_POST['admin_name'] ?? '');
    $adminPass   = trim($_POST['admin_pass'] ?? '');
    $studentNames = trim($_POST['student_names'] ?? '');

    if (empty($adminName) || empty($adminPass)) {
        $error = '请填写管理员姓名和密码';
    } else {
        try {
            $db = getInstallDb();

            // 防御：确保表存在（防止 session 丢失等异常情况）
            $tableExists = $db->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
            if (!$tableExists) {
                ensureTablesExist($db);
            }

            // 创建管理员
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (username, password, roles) VALUES (:u, :p, :r)");
            $stmt->execute([':u' => $adminName, ':p' => $hash, ':r' => '["head_teacher","admin"]']);
            $adminId = (int)$db->lastInsertId();

            // 导入学生
            $names = preg_split('/[\r\n]+/', $studentNames);
            foreach ($names as $name) {
                $name = trim($name);
                if (empty($name)) continue;
                $stmt->execute([':u' => $name, ':p' => password_hash('123456', PASSWORD_BCRYPT), ':r' => '["student"]']);
            }

            // 初始结余
            $initBalance = round(floatval($_POST['init_balance'] ?? 0), 2);
            if ($initBalance > 0) {
                $stmt2 = $db->prepare("INSERT INTO transactions (type, amount, date, description, category, recorded_by) VALUES ('income', :a, CURDATE(), '初始结余', '班费', :rb)");
                $stmt2->execute([':a' => $initBalance, ':rb' => $adminId]);
            }

            // === 写入 db_config.json（上传即用的关键） ===
            $cfg = $_SESSION['install_db'] ?? [];
            if (!empty($cfg)) {
                $written = file_put_contents(__DIR__ . '/db_config.json', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                if ($written === false) {
                    throw new Exception('无法写入 db_config.json，请检查网站根目录写入权限');
                }
                if (!@chmod(__DIR__ . '/db_config.json', 0600)) {
                    error_log('[班费系统] db_config.json chmod 0600 失败');
                }
            }

            // 清理 session
            unset($_SESSION['install_db']);

            $step = 3;
            $msg = '🎉 部署完成！管理员 ' . $adminName . ' 已创建。点击下方按钮进入系统。';

            // 如果需要重新部署，会走 GET 请求回到 step 0
        } catch (Exception $e) {
            error_log('[班费系统] 安装失败: ' . $e->getMessage());
            $error = str_contains($e->getMessage(), 'db_config.json')
                ? $e->getMessage()
                : '创建账户失败，请重试，详见服务器错误日志';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>部署向导 - 班级班费管理系统</title>
<style>
:root { --primary:#4f6ef7;--primary-light:#eef1ff;--success:#22c55e;--danger:#ef4444;--bg:#f5f6fa;--card:#fff;--text:#1e293b;--text-secondary:#64748b;--border:#e2e8f0;--radius:12px;--shadow:0 1px 3px rgba(0,0,0,.06);--shadow-lg:0 10px 25px rgba(0,0,0,.08); }
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Microsoft YaHei',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
.container{width:660px;max-width:94vw}
.card{background:var(--card);border-radius:16px;padding:36px 32px;box-shadow:var(--shadow-lg);margin-bottom:20px}
.card h1{font-size:22px;color:var(--primary);margin-bottom:4px}
.card h2{font-size:17px;margin-bottom:14px}
.card .subtitle{color:var(--text-secondary);font-size:13px;margin-bottom:20px}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:13px;font-weight:600;margin-bottom:5px}
.form-group input,.form-group textarea,.form-group select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:7px;font-size:14px;font-family:inherit}
.form-group textarea{resize:vertical;min-height:70px}
.form-row{display:grid;grid-template-columns:2fr 1fr;gap:10px}
.btn{padding:10px 24px;border-radius:8px;font-size:14px;font-weight:600;border:none;cursor:pointer;transition:opacity .2s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:var(--primary);color:#fff}
.btn-success{background:var(--success);color:#fff}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--text)}
.btn:hover{opacity:.85}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:14px;font-size:13px;line-height:1.5}
.alert-error{background:#fef2f2;color:var(--danger);border:1px solid #fecaca}
.alert-success{background:#f0fdf4;color:var(--success);border:1px solid #bbf7d0}
.alert-info{background:var(--primary-light);color:var(--primary);border:1px solid #c7d2fe}
.env-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
.env-item{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f8fafc;border-radius:6px;font-size:12px}
.env-icon{font-size:16px}
small{color:var(--text-secondary);font-size:11px;display:block;margin-top:3px}
.step-indicator{display:flex;gap:6px;margin-bottom:18px}
.step-indicator .si{flex:1;text-align:center;font-size:11px;color:var(--text-secondary);padding:6px 0;border-bottom:3px solid var(--border)}
.step-indicator .si.active{color:var(--primary);border-color:var(--primary);font-weight:600}
.step-indicator .si.done{color:var(--success);border-color:var(--success)}
@media(max-width:640px){
  body{align-items:flex-start;padding:0}
  .container{max-width:100vw}
  .card{border-radius:0;padding:24px 16px;margin-bottom:0;min-height:100vh}
  .card h1{font-size:19px}
  .card h2{font-size:15px}
  .form-row{grid-template-columns:1fr}
  .env-grid{grid-template-columns:1fr}
  .step-indicator{gap:2px}
  .step-indicator .si{font-size:9px;padding:4px 0}
  .btn{width:100%;justify-content:center}
}
</style>
</head>
<body>
<div class="container">
<div class="card">
<h1>📒 班级班费管理系统</h1>
<p class="subtitle">上传即用 · 自动检测环境 · 首次部署向导</p>

<?php if ($error): ?>
<div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- 步骤指示器 -->
<div class="step-indicator">
  <div class="si <?= $step===0?'active':($step>0?'done':'') ?>">① 环境 & 数据库</div>
  <div class="si <?= $step===1?'active':($step>1?'done':'') ?>">② 创建数据表</div>
  <div class="si <?= $step>=2?'active':'' ?>">③ 管理员 & 完成</div>
</div>

<?php if ($step === 0): ?>
  <!-- === 环境检测 === -->
  <h2>🔍 环境检测</h2>
  <div class="env-grid">
    <div class="env-item"><span class="env-icon"><?= $phpOk?'✅':'❌' ?></span> PHP <?= htmlspecialchars($phpVersion) ?> <?= $phpOk?'':'（需要 ≥ 8.0）' ?></div>
    <div class="env-item"><span class="env-icon"><?= $pdoOk?'✅':'❌' ?></span> PDO 扩展 <?= $pdoOk?'':'（未启用）' ?></div>
    <div class="env-item"><span class="env-icon"><?= $mysqlOk?'✅':'❌' ?></span> pdo_mysql <?= $mysqlOk?'':'（未启用）' ?></div>
    <div class="env-item"><span class="env-icon"><?= $jsonOk?'✅':'❌' ?></span> JSON 扩展 <?= $jsonOk?'':'（未启用）' ?></div>
  </div>

  <?php if (!$phpOk || !$pdoOk || !$mysqlOk): ?>
    <div class="alert alert-error">环境不满足要求，请在宝塔面板 → 软件商店 → PHP设置中启用所需扩展。</div>
  <?php else: ?>
    <div class="alert alert-info">✅ 环境检测通过。请在下方填写数据库信息（需先在宝塔面板创建数据库）。</div>

    <!-- === 数据库配置表单 === -->
    <h2>🗄️ 数据库配置</h2>
    <form method="post">
      <input type="hidden" name="step" value="0">
      <div class="form-row">
        <div class="form-group"><label>主机地址</label><input type="text" name="db_host" value="localhost" placeholder="localhost 或数据库服务器 IP"><small style="color:var(--text-secondary)">默认 localhost（同服务器）；若数据库在其他服务器，填对应 IP 地址</small></div>
        <div class="form-group"><label>端口</label><input type="text" name="db_port" value="3306"></div>
      </div>
      <div class="form-group"><label>数据库名 *</label><input type="text" name="db_name" placeholder="宝塔创建的数据库名，如 class_fund" required></div>
      <div class="form-row">
        <div class="form-group"><label>用户名 *</label><input type="text" name="db_user" placeholder="数据库用户名" required><small style="color:var(--danger)">⚠️ 填宝塔「数据库」页面里的用户名（纯英文数字），不是管理员姓名</small></div>
        <div class="form-group"><label>密码</label><input type="password" name="db_pass" placeholder="数据库密码"></div>
      </div>
      <small>💡 在宝塔面板 → 数据库 页面可查看以上信息。配置将自动保存，无需手动编辑文件。</small>
      <div style="margin-top:16px;text-align:right">
        <button type="submit" class="btn btn-primary">测试连接 → 下一步</button>
      </div>
    </form>
  <?php endif; ?>

<?php elseif ($step === 1): ?>
  <!-- === 建表 === -->
  <h2>📦 创建数据表</h2>
  <?php if (str_contains($msg, '数据表已存在')): ?>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px;">数据库已有数据，可选择全新安装（清除旧数据）或直接进入系统。</p>
    <form method="post" style="display:inline-block">
      <input type="hidden" name="step" value="1">
      <input type="hidden" name="force_reinstall" value="1">
      <button type="submit" class="btn btn-danger" onclick="return confirm('⚠️ 此操作将清空所有旧数据，确定继续？')">🗑️ 全新安装（清除旧数据）</button>
    </form>
    <a href="index.php" class="btn btn-primary" style="margin-left:8px;text-decoration:none">跳过，进入系统</a>
  <?php elseif (!$msg): ?>
    <form method="post">
      <input type="hidden" name="step" value="1">
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px;">将在数据库中创建 users、transactions、operation_logs、login_history 四张表。</p>
      <button type="submit" class="btn btn-primary">执行建表</button>
    </form>
  <?php else: ?>
    <!-- 建表成功，显示下一步表单 -->
    <form method="post">
      <input type="hidden" name="step" value="2">
      <h2>👤 创建管理员</h2>
      <div class="form-group"><label>管理员姓名 *</label><input type="text" name="admin_name" placeholder="例如: 王老师" required></div>
      <div class="form-group"><label>登录密码 *</label><input type="password" name="admin_pass" placeholder="设置管理员密码" required></div>
      <div class="form-group"><label>初始结余 / 元（可选，填入已有班费金额）</label><input type="number" name="init_balance" step="0.01" value="0" placeholder="0.00"></div>
      <div class="form-group"><label>快速导入学生（每行一人，可选）</label><textarea name="student_names" placeholder="张三&#10;李四&#10;王五&#10;"></textarea><small>默认密码 123456，后续可在系统中修改</small></div>
      <div style="text-align:right"><button type="submit" class="btn btn-success">✅ 完成部署</button></div>
    </form>
  <?php endif; ?>

<?php elseif ($step === 2): ?>
  <!-- === 管理员表单 === -->
  <h2>👤 创建管理员</h2>
  <form method="post">
    <input type="hidden" name="step" value="2">
    <div class="form-group"><label>管理员姓名 *</label><input type="text" name="admin_name" placeholder="例如: 王老师" required></div>
    <div class="form-group"><label>登录密码 *</label><input type="password" name="admin_pass" placeholder="设置管理员密码" required></div>
    <div class="form-group"><label>初始结余 / 元（可选）</label><input type="number" name="init_balance" step="0.01" value="0" placeholder="0.00"></div>
    <div class="form-group"><label>快速导入学生（每行一人，可选）</label><textarea name="student_names" placeholder="张三&#10;李四&#10;王五&#10;"></textarea><small>默认密码 123456</small></div>
    <div style="text-align:right"><button type="submit" class="btn btn-success">✅ 完成部署</button></div>
  </form>

<?php elseif ($step === 3): ?>
  <!-- === 完成 === -->
  <div style="text-align:center;padding:10px 0;">
    <a href="index.php" class="btn btn-primary" style="display:inline-block;text-decoration:none;font-size:15px;padding:12px 32px;">→ 进入系统</a>
    <p style="font-size:14px;color:var(--text-secondary);margin-top:12px;">
      📁 数据库配置已保存至 <code>db_config.json</code><br>
      下次访问直接进入系统，无需重复配置。
    </p>
  </div>
<?php endif; ?>
</div>

<p style="text-align:center;font-size:11px;color:var(--text-secondary);">
  ⚠️ 部署完成后建议删除 <code>install.php</code> 以保证安全
</p>
</div>
</body>
</html>
