<?php
/**
 * 学生个人班费 / 订单 / 电子收据
 */

function myFeeStudent(): array {
    $sid = currentStudentId();
    if ($sid <= 0) jsonOutput(['error' => '当前账号未绑定花名册学生，请联系班委绑定'], 403);
    $st = feeStudentStatus($sid);
    $rows = db()->query("SELECT id, date, description, per_person, amount, expected_amount, payer_ids, exempt_ids
        FROM transactions WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL
        ORDER BY date ASC, id ASC")->fetchAll();
    $rounds = [];
    foreach ($rows as $r) {
        $rid = (int)$r['id'];
        $pr = $st['per_round'][$rid] ?? null;
        if ($pr === null) continue;
        $rounds[] = [
            'id' => $rid,
            'date' => (string)$r['date'],
            'description' => (string)$r['description'],
            'per_person' => round(feeRoundPerPerson($r), 2),
            'due' => round((float)($pr['due'] ?? 0), 2),
            'paid' => round((float)($pr['paid'] ?? 0), 2),
            'exempt' => !empty($pr['exempt']),
        ];
    }
    $orders = [];
    try {
        $q = db()->prepare("SELECT order_no, student_name, round_id, amount, channel, status, settled, created_at, paid_at
            FROM payment_orders WHERE student_id=:sid ORDER BY id DESC LIMIT 30");
        $q->execute([':sid' => $sid]);
        $orders = $q->fetchAll();
    } catch (Throwable $e) { /* 订单表不存在时不影响明细 */ }
    jsonOutput([
        'student' => ['id' => $sid, 'name' => (string)($st['name'] ?? '')],
        'fee' => ['due' => (float)$st['due'], 'paid' => (float)$st['paid'], 'outstanding' => (float)$st['outstanding'], 'exempt' => !empty($st['exempt'])],
        'rounds' => $rounds,
        'orders' => $orders,
        'receipt_url' => 'api.php?action=my_receipt',
        'pay_url' => payBaseUrl() . '/?pay=' . $sid . '#page-pay',
    ]);
}

function handleMyFee() {
    requireLogin();
    if (!empty($_SESSION['is_guest'])) jsonOutput(['error' => '访客请使用在线缴费页'], 403);
    myFeeStudent();
}

function handleMyOrders() {
    requireLogin();
    if (!empty($_SESSION['is_guest'])) jsonOutput(['error' => '访客请使用在线缴费页'], 403);
    $sid = currentStudentId();
    if ($sid <= 0) jsonOutput(['error' => '当前账号未绑定花名册学生'], 403);
    $stmt = db()->prepare("SELECT order_no, student_name, round_id, amount, channel, status, settled, created_at, paid_at
        FROM payment_orders WHERE student_id=:sid ORDER BY id DESC LIMIT 50");
    $stmt->execute([':sid' => $sid]);
    jsonOutput(['orders' => $stmt->fetchAll()]);
}

function handleMyReceipt() {
    requireLogin();
    if (!empty($_SESSION['is_guest'])) { http_response_code(403); echo '访客无收据'; exit; }
    $sid = currentStudentId();
    if ($sid <= 0) { http_response_code(403); echo '当前账号未绑定花名册学生'; exit; }
    $st = feeStudentStatus($sid);
    $name = (string)($st['name'] ?? '');
    $rows = [];
    try {
        $q = db()->prepare("SELECT order_no, student_name, round_id, amount, channel, paid_at, created_at
            FROM fee_payments WHERE student_id=:sid AND amount > 0 ORDER BY id ASC");
        $q->execute([':sid' => $sid]);
        $rows = $q->fetchAll();
    } catch (Throwable $e) {}
    $site = trim((string)getPayConfig()['sitename']) ?: SITE_NAME;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    ?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>班费电子收据</title>
<style>body{font:14px/1.7 system-ui,"Microsoft YaHei",sans-serif;max-width:720px;margin:40px auto;padding:0 20px;color:#1f2937}h1{font-size:20px;text-align:center}.muted{color:#6b7280;font-size:12px}table{width:100%;border-collapse:collapse;margin:18px 0}th,td{border-bottom:1px solid #e5e7eb;padding:9px 6px;text-align:left}th{color:#6b7280;font-weight:500}.sum{font-size:22px;font-weight:700;color:#059669}@media print{button{display:none}}</style>
</head><body>
<h1>班费电子收据</h1>
<p class="muted" style="text-align:center"><?php echo htmlspecialchars($site); ?> · <?php echo htmlspecialchars(date('Y-m-d H:i')); ?></p>
<p>学生：<b><?php echo htmlspecialchars($name); ?></b></p>
<p>应缴：¥<?php echo number_format((float)$st['due'],2,'.',''); ?>　已缴：¥<?php echo number_format((float)$st['paid'],2,'.',''); ?>　待缴：¥<?php echo number_format((float)$st['outstanding'],2,'.',''); ?></p>
<table><thead><tr><th>时间</th><th>订单号</th><th>金额</th><th>方式</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="4" class="muted">暂无缴费记录</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><?php echo htmlspecialchars((string)($r['paid_at'] ?: $r['created_at'])); ?></td><td><?php echo htmlspecialchars((string)$r['order_no']); ?></td><td>¥<?php echo number_format((float)$r['amount'],2,'.',''); ?></td><td><?php echo htmlspecialchars((string)($r['channel'] ?: '-')); ?></td></tr><?php endforeach; ?>
</tbody></table>
<p class="sum">待缴合计：¥<?php echo number_format((float)$st['outstanding'],2,'.',''); ?></p>
<p class="muted">本收据由班费系统生成，仅用于个人核对。</p>
<button onclick="window.print()">打印 / 保存 PDF</button>
</body></html>
<?php
    exit;
}
