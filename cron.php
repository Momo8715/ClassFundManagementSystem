<?php
/**
 * 班级班费管理系统 - 定时任务入口（v1.13）
 *
 * 用法（由宝塔计划任务 curl 调用）：
 *   cron.php?token=<system_meta.cron_token>&task=backup
 *   cron.php?token=...&task=cleanup
 *   cron.php?token=...&task=health
 *   cron.php?token=...&task=all
 *
 * token 可在「配置管理 → 数据备份」页面复制；请勿泄露。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/totp.php';
require_once __DIR__ . '/src/twofa.php';
require_once __DIR__ . '/src/fee_ledger.php';
require_once __DIR__ . '/src/semesters.php';
require_once __DIR__ . '/src/import_export.php';
require_once __DIR__ . '/src/health.php';
require_once __DIR__ . '/src/bot_notify.php';
require_once __DIR__ . '/src/backup.php';
require_once __DIR__ . '/src/pay.php'; // 复用群机器人通知

autoMigrate();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cronJson($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$token = (string)($_GET['token'] ?? '');
if (!backupTokenValid($token)) cronJson(['error' => 'token invalid'], 403);

$task = strtolower(trim((string)($_GET['task'] ?? 'all')));
if (!in_array($task, ['backup', 'cleanup', 'health', 'bot', 'remind', 'all'], true)) cronJson(['error' => '未知任务'], 400);

set_time_limit(600);
$out = ['ok' => true, 'task' => $task, 'ran_at' => date('Y-m-d H:i:s')];

try {
    if ($task === 'backup' || $task === 'all') {
        $out['backup'] = backupCreate(0, 'cron');
    }
    if ($task === 'cleanup' || $task === 'all') {
        $out['cleanup'] = cleanupOrphanImages(24);
    }
    if ($task === 'bot' || $task === 'all') {
        $out['bot_push'] = botDispatchPending(20);
        $out['bot_pruned'] = botPruneNotifications(30);
    }
    if ($task === 'remind' || $task === 'all') {
        $out['remind'] = reminderAutoSend();
    }
    if ($task === 'health' || $task === 'all') {
        $sections = healthBuildSections();
        [$sections, $ignored] = healthApplyAcks($sections);
        $summary = healthIssueSummary($sections);
        $out['health'] = ['summary' => $summary, 'ignored' => $ignored];
        setMeta('health_last_run', date('c'));
        setMeta('health_last_summary', json_encode(['summary' => $summary, 'ignored' => $ignored, 'checked_at' => date('c')], JSON_UNESCAPED_UNICODE));

        // 只在出现 error 时推送，避免每天刷屏
        if (($summary['error'] ?? 0) > 0) {
            $lines = [];
            foreach ($sections as $sec) {
                foreach ($sec['items'] as $it) {
                    if (($it['severity'] ?? '') !== 'error') continue;
                    $lines[] = '• ' . ($it['title'] ?? '') . '：' . ($it['detail'] ?? '');
                    if (count($lines) >= 10) break 2;
                }
            }
            $text = '【班费系统 · 数据体检】\n发现 ' . $summary['error'] . ' 个错误、' . $summary['warn'] . ' 个警告\n' . implode("\n", $lines)
                . "\n\n请登录后台 → 🩺 数据体检 处理。";
            try {
                $c = reminderConfig();
                if (($c['type'] ?? '') === 'qq') {
                    $res = reminderQqSend($c, $text);
                } elseif (!empty($c['webhook'])) {
                    $res = reminderWebhookPost($c['webhook'], reminderWebhookPayload((string)$c['type'], $text));
                } else {
                    $res = ['ok' => false, 'error' => '未配置通知通道'];
                }
                $out['notify'] = !empty($res['ok']) ? 'sent' : ('fail: ' . ($res['error'] ?? 'unknown'));
            } catch (\Throwable $e) {
                $out['notify'] = 'fail: ' . $e->getMessage();
            }
        }
    }
    cronJson($out);
} catch (\Throwable $e) {
    cronJson(['ok' => false, 'task' => $task, 'error' => $e->getMessage()], 500);
}
