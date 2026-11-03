<?php
/**
 * 班级班费管理系统 - API 路由器
 * 所有请求入口，分发到对应模块处理
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once __DIR__ . '/config.php';

// API 响应一律不缓存（保证数据实时性），配合 Cloudflare 缓存规则
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 未安装时返回友好提示
if (!isDbInstalled()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo json_encode(['error' => '系统未安装，请先访问 install.php 完成部署'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 自动迁移数据库
autoMigrate();

// IP 黑名单拦截（命中则直接 403；支付回调/同步返回由支付平台服务器发起，需放行）
if (!in_array($_GET['action'] ?? '', ['pay_notify', 'pay_return', 'qq_webhook'], true)) {
    requireNotBlockedIp();
}

// 加载所有模块（顺序：基础/账本/学期 → 业务 → 支付 → 体检）
require_once __DIR__ . '/src/totp.php';
require_once __DIR__ . '/src/twofa.php';
require_once __DIR__ . '/src/fee_ledger.php';
require_once __DIR__ . '/src/bot_notify.php';
require_once __DIR__ . '/src/semesters.php';
require_once __DIR__ . '/src/auth.php';
require_once __DIR__ . '/src/transactions.php';
require_once __DIR__ . '/src/users.php';
require_once __DIR__ . '/src/payments.php';
require_once __DIR__ . '/src/logs.php';
require_once __DIR__ . '/src/security.php';
require_once __DIR__ . '/src/import_export.php';
require_once __DIR__ . '/src/import_batches.php';
require_once __DIR__ . '/src/backup.php';
require_once __DIR__ . '/src/report.php';
require_once __DIR__ . '/src/pay.php';
require_once __DIR__ . '/src/health.php';
require_once __DIR__ . '/src/upgrade.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 每个 action 允许的 HTTP 方法（只读接口允许 GET；纯写操作仅允许 POST/PUT/DELETE）
$actionMethods = [
    'transactions' => ['GET', 'POST', 'PUT', 'DELETE'],
    'users' => ['GET', 'POST', 'PUT', 'DELETE'],
    'roster' => ['GET', 'POST', 'DELETE'],
    'semesters' => ['GET', 'POST'],
    'recycle_bin' => ['GET', 'PUT', 'DELETE'],
    'expected_payment' => ['GET', 'POST'],
    'classInfo' => ['GET', 'PUT'],
    'login' => ['POST'],
    'login_2fa' => ['POST'],
    'guest_login' => ['POST'],
    'logout' => ['POST'],
    'twofa_setup' => ['POST'],
    'twofa_policy' => ['GET', 'POST'],
    'change_password' => ['POST'],
    'transactions_batch' => ['POST'],
    'import' => ['POST'],
    'import_batches' => ['GET'],
    'import_undo' => ['POST'],
    'upload_image' => ['POST'],
    'upload_xlsx' => ['POST'],
    'roster_xlsx' => ['POST'],
    'ban_user' => ['POST'],
    'blocked_ip' => ['GET', 'POST', 'DELETE'],
    'export_security_csv' => ['GET'],
    'health_check' => ['GET'],
    'health_fix' => ['POST'],
    'health_ack' => ['POST'],
    'health_unack' => ['POST'],
    'backup_list' => ['GET'],
    'backup_create' => ['POST'],
    'backup_cron' => ['GET'],
    'backup_download' => ['GET'],
    'backup_restore' => ['POST'],
    'backup_delete' => ['POST'],
    'backup_retention' => ['POST'],
    'bot_notifications' => ['GET'],
    'bot_notification_retry' => ['POST'],
    'bot_token_rotate' => ['POST'],
    'bot_push_toggle' => ['POST'],
    'pay_meta' => ['GET'],
    'pay_create' => ['POST'],
    'pay_public_meta' => ['GET'],
    'pay_public_create' => ['POST'],
    'pay_config_get' => ['GET'],
    'pay_config_save' => ['POST'],
    'pay_reminders' => ['GET'],
    'pay_reminder_cfg_get' => ['GET'],
    'pay_reminder_cfg_save' => ['POST'],
    'pay_reminder_send' => ['POST'],
    'pay_reminder_push_text' => ['POST'],
    'qq_webhook' => ['POST'],
    'qq_admin_code' => ['POST'],
    'pay_notify' => ['GET', 'POST'],
    'pay_return' => ['GET'],
    'pay_orders' => ['GET'],
    'pay_confirm' => ['POST'],
    'pay_cancel' => ['POST'],
    'do_upgrade' => ['POST'],
];
if (isset($actionMethods[$action]) && !in_array($method, $actionMethods[$action], true)) {
    jsonOutput(['error' => '不支持的方法'], 405);
}

// 路由分发
switch ($action) {
    // ========== 认证 ==========
    case 'login':
        handleLogin();
        break;
    case 'login_2fa':
        handleLogin2FA();
        break;
    case 'twofa_setup':
        handleTwofaSetup();
        break;
    case 'twofa_policy':
        handleTwofaPolicy($method);
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
    case 'export_report_pdf':
        handleExportReportPdf();
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
    case 'import_batches':
        handleImportBatches();
        break;
    case 'import_undo':
        handleImportUndo();
        break;
    case 'upload_image':
        handleUploadImage();
        break;
    case 'tx_image':
        handleTxImage();
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
    case 'blocked_ip':
        handleBlockedIp($method);
        break;
    case 'export_security_csv':
        handleExportSecurityCsv();
        break;
    case 'health_check':
        handleHealthCheck();
        break;
    case 'health_fix':
        handleHealthFix();
        break;
    case 'health_ack':
        handleHealthAck();
        break;
    case 'health_unack':
        handleHealthUnack();
        break;
    case 'backup_list':
        handleBackupList();
        break;
    case 'backup_create':
        handleBackupCreate();
        break;
    case 'backup_cron':
        handleBackupCron();
        break;
    case 'backup_download':
        handleBackupDownload();
        break;
    case 'backup_restore':
        handleBackupRestore();
        break;
    case 'backup_delete':
        handleBackupDelete();
        break;
    case 'backup_retention':
        handleBackupRetentionSave();
        break;
    case 'bot_notifications':
        handleBotNotifications();
        break;
    case 'bot_notification_retry':
        handleBotNotificationRetry();
        break;
    case 'bot_token_rotate':
        handleBotTokenRotate();
        break;
    case 'bot_push_toggle':
        handleBotPushToggle();
        break;

    // ========== 在线支付 ==========
    case 'pay_meta':
        handlePayMeta();
        break;
    case 'pay_create':
        handlePayCreate();
        break;
    case 'pay_public_meta':
        handlePayPublicMeta();
        break;
    case 'pay_public_create':
        handlePayCreate(true);
        break;
    case 'pay_notify':
        handlePayNotify();
        break;
    case 'pay_return':
        handlePayReturn();
        break;
    case 'pay_orders':
        handlePayOrders();
        break;
    case 'pay_confirm':
        handlePayConfirm();
        break;
    case 'pay_cancel':
        handlePayCancel();
        break;
    case 'pay_config_get':
        handlePayConfigGet();
        break;
    case 'pay_config_save':
        handlePayConfigSave();
        break;

    // ========== 催缴通知 ==========
    case 'pay_reminders':
        handlePayReminders();
        break;
    case 'pay_reminder_cfg_get':
        handlePayReminderCfgGet();
        break;
    case 'pay_reminder_cfg_save':
        handlePayReminderCfgSave();
        break;
    case 'pay_reminder_send':
        handlePayReminderSend();
        break;
    case 'pay_reminder_push_text':
        handlePayReminderPushText();
        break;
    case 'qq_webhook':
        handleQqWebhook();
        break;
    case 'qq_admin_code':
        handleQqAdminCode();
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
