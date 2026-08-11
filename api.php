<?php
/**
 * 班级班费管理系统 - API 路由器
 * 所有请求入口，分发到对应模块处理
 */
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/config.php';

// 未安装时返回友好提示
if (!isDbInstalled()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['error' => '系统未安装，请先访问 install.php 完成部署'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 自动迁移数据库
autoMigrate();

// 加载所有模块
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/transactions.php';
require_once __DIR__ . '/src/users.php';
require_once __DIR__ . '/src/payments.php';
require_once __DIR__ . '/src/logs.php';
require_once __DIR__ . '/src/security.php';
require_once __DIR__ . '/src/import_export.php';
require_once __DIR__ . '/src/report.php';
require_once __DIR__ . '/src/upgrade.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 写操作仅允许 POST/PUT/DELETE，禁止通过 GET 触发（防止预取/缓存意外副作用）
$writeActions = [
    'transactions' => ['POST', 'PUT', 'DELETE'],
    'users' => ['POST', 'PUT', 'DELETE'],
    'roster' => ['POST', 'DELETE'],
    'semesters' => ['POST'],
    'recycle_bin' => ['PUT', 'DELETE'],
    'expected_payment' => ['POST'],
    'classInfo' => ['PUT'],
    'guest_login' => ['POST'],
    'logout' => ['POST'],
    'change_password' => ['POST'],
    'transactions_batch' => ['POST'],
    'import' => ['POST'],
    'upload_image' => ['POST'],
    'upload_xlsx' => ['POST'],
    'roster_xlsx' => ['POST'],
    'ban_user' => ['POST'],
    'do_upgrade' => ['POST'],
];
if (isset($writeActions[$action]) && !in_array($method, $writeActions[$action], true)) {
    jsonOutput(['error' => '不支持的方法'], 405);
}

// 路由分发
switch ($action) {
    // ========== 认证 ==========
    case 'login':
        handleLogin();
        break;
    case 'guest_login':
        handleGuestLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'me':
        handleMe();
        break;
    case 'change_password':
        handleChangePassword();
        break;

    // ========== 仪表盘 & 报表 ==========
    case 'dashboard':
        handleDashboard();
        break;
    case 'report_semester':
        handleReportSemester();
        break;
    case 'export_report':
        handleExportReport();
        break;
    case 'semesters':
        handleSemesters($method);
        break;

    // ========== 收支记录 ==========
    case 'transactions':
        handleTransactions($method);
        break;
    case 'transactions_batch':
        handleTransactionsBatchDelete();
        break;

    // ========== 导入导出 ==========
    case 'import':
        handleImport();
        break;
    case 'upload_image':
        handleUploadImage();
        break;
    case 'download_template':
        handleDownloadTemplate();
        break;
    case 'upload_xlsx':
        handleUploadXlsx();
        break;
    case 'export':
        handleExport();
        break;
    case 'receipt':
        handleReceipt();
        break;
    case 'export_unpaid':
        handleExportUnpaid();
        break;

    // ========== 花名册 & 缴费追踪 ==========
    case 'roster':
        handleRoster($method);
        break;
    case 'roster_template':
        handleRosterTemplate();
        break;
    case 'roster_xlsx':
        handleRosterXlsx();
        break;
    case 'payments':
        handlePayments();
        break;
    case 'expected_payment':
        handleExpectedPayment($method);
        break;

    // ========== 用户管理 ==========
    case 'users':
        handleUsers($method);
        break;

    // ========== 操作日志 ==========
    case 'logs':
        handleLogs();
        break;
    case 'export_logs':
        handleExportLogs();
        break;

    // ========== 安全 & 回收站 ==========
    case 'classInfo':
        handleClassInfo($method);
        break;
    case 'security_analysis':
        handleSecurityAnalysis();
        break;
    case 'recycle_bin':
        handleRecycleBin($method);
        break;
    case 'ban_user':
        handleBanUser();
        break;

    // ========== 远程升级 ==========
    case 'check_update':
        handleUpgrade('GET');
        break;
    case 'do_upgrade':
        handleUpgrade('POST');
        break;

    default:
        jsonOutput(['error' => '未知操作'], 404);
}
