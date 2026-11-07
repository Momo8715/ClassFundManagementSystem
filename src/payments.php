<?php
/**
 * 班级班费管理系统 - 花名册 & 缴费追踪模块
 *
 * 缴费口径统一由 src/fee_ledger.php 提供，本文件只负责花名册维护、
 * 预期缴费设置与接口输出，避免再复制一套计算逻辑。
 */

// ==================== 花名册管理 ====================
function handleRoster(string $method) {
    switch ($method) {
        case 'GET':
            requirePermission('viewPayments');
            $rows = db()->query("SELECT id, name, exempt, created_at FROM class_roster ORDER BY sort_key, id")->fetchAll();
            jsonOutput(['roster' => $rows]);
            break;

        case 'POST':
            requirePermission('manageRoster');
            requireCsrfToken();
            $input = jsonInput();
            $names = $input['names'] ?? [];
            // URL-encoded 传输会将数组序列化为 JSON 字符串，需还原
            if (is_string($names)) {
                $decoded = json_decode($names, true);
                if (is_array($decoded)) $names = $decoded;
                else $names = [$names];
            }
            if (empty($names)) jsonOutput(['error' => '请提供学生姓名列表'], 400);

            $stmt = db()->prepare("INSERT IGNORE INTO class_roster (name, sort_key) VALUES (:n, :k)");
            $count = 0;
            foreach ($names as $n) {
                if (!is_string($n)) continue; // 跳过非字符串元素，防止 trim() 报错
                $n = trim($n);
                if ($n && mb_strlen($n) <= 50) {
                    $stmt->execute([':n' => $n, ':k' => rosterSortKey($n)]);
                    $count += $stmt->rowCount();
                }
            }

            $user = currentUser();
            addLog($user['id'], $user['username'], 'upload_roster', 'roster', null, ['count' => $count]);
            jsonOutput(['ok' => true, 'imported' => $count]);
            break;

        case 'DELETE':
            requirePermission('deleteRoster');
            requireCsrfToken();
            $input = jsonInput();
            $id = intval($_GET['id'] ?? $input['id'] ?? 0);
            if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);

            $oldStmt = db()->prepare("SELECT name FROM class_roster WHERE id=:id");
            $oldStmt->execute([':id' => $id]);
            $old = $oldStmt->fetch();
            if (!$old) jsonOutput(['error' => '学生不存在'], 404);

            db()->prepare("DELETE FROM class_roster WHERE id=:id")->execute([':id' => $id]);

            $user = currentUser();
            addLog($user['id'], $user['username'], 'delete_roster', 'roster', $id, $old);
            jsonOutput(['ok' => true]);
            break;

        default:
            jsonOutput(['error' => '不支持的方法'], 405);
    }
}

/** 下载花名册模板 */
function handleRosterTemplate() {
    requirePermission('manageRoster');
    outputSimpleXlsx('学生名单模板', '花名册', ['姓名'], [['张三'], ['李四'], ['王五']]);
    exit;
}

/** xlsx 上传花名册 */
function handleRosterXlsx() {
    requirePermission('manageRoster');
    requireCsrfToken();

    if (empty($_FILES['xlsx'])) jsonOutput(['error' => '请选择 xlsx 文件'], 400);
    if (!class_exists('ZipArchive')) jsonOutput(['error' => '服务器未安装 PHP Zip 扩展'], 500);

    $tmp = $_FILES['xlsx']['tmp_name'];
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) jsonOutput(['error' => '无法读取文件'], 400);

    $strings = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss) {
        $xml = simplexml_load_string($ss);
        if ($xml) foreach ($xml->si as $si) {
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            elseif (isset($si->r)) foreach ($si->r as $r) $t .= (string)$r->t;
            $strings[] = $t;
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheet) jsonOutput(['error' => '工作表为空'], 400);

    $xml = simplexml_load_string($sheet);
    if (!$xml || !isset($xml->sheetData->row)) jsonOutput(['error' => '工作表无数据'], 400);

    $names = [];
    $firstRow = true;
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $v = isset($c->v) ? (string)$c->v : '';
            if ((string)$c['t'] === 's' && isset($strings[(int)$v])) $v = $strings[(int)$v];
            $cells[] = trim($v);
        }
        if ($firstRow) { $firstRow = false; continue; }
        $name = $cells[0] ?? '';
        if ($name && mb_strlen($name) <= 50) $names[] = $name;
    }

    if (empty($names)) jsonOutput(['error' => '未解析到有效姓名'], 400);

    $doImport = ($_POST['do_import'] ?? '') === '1';
    if ($doImport) {
        requireCsrfToken();
        $stmt = db()->prepare("INSERT IGNORE INTO class_roster (name, sort_key) VALUES (:n, :k)");
        $count = 0;
        foreach ($names as $n) { $stmt->execute([':n' => $n, ':k' => rosterSortKey($n)]); $count += $stmt->rowCount(); }
        $user = currentUser();
        addLog($user['id'], $user['username'], 'upload_roster_xlsx', 'roster', null, ['count' => $count]);
        jsonOutput(['ok' => true, 'imported' => $count, 'preview' => $names]);
    }

    jsonOutput(['ok' => true, 'preview' => $names, 'count' => count($names)]);
}

// ==================== 预期缴费管理 ====================
function handleExpectedPayment(string $method) {
    requirePermission('viewPayments');

    switch ($method) {
        case 'GET':
            $roster = db()->query("SELECT id, name, exempt, created_at FROM class_roster ORDER BY sort_key, id")->fetchAll();
            $pp = getMeta('per_person');
            jsonOutput([
                'roster'     => $roster,
                'per_person' => $pp !== '' ? (float)$pp : null
            ]);
            break;

        case 'POST':
            requirePermission('manageRoster');
            requireCsrfToken();

            $input = jsonInput();

            if (isset($input['per_person'])) {
                // 班级全局配置：存入 system_meta，所有登录用户共享同一份
                setMeta('per_person', (string)sanitizeAmount($input['per_person']));
            }
            if (isset($input['exempt_id'])) {
                db()->prepare("UPDATE class_roster SET exempt=:e WHERE id=:id")
                    ->execute([
                        ':e'  => intval($input['exempt'] ?? 1),
                        ':id' => intval($input['exempt_id'])
                    ]);
            }
            // 批量设置免缴
            if (isset($input['exempt_ids'])) {
                $ids = $input['exempt_ids'];
                if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
                $ids = array_values(array_filter(array_map('intval', (array)$ids), function ($v) { return $v > 0; }));
                if (!empty($ids)) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    db()->prepare("UPDATE class_roster SET exempt=:e WHERE id IN ($ph)")
                        ->execute(array_merge([intval($input['exempt'] ?? 1)], $ids));
                }
            }
            jsonOutput(['ok' => true]);
            break;

        default:
            jsonOutput(['error' => '不支持的方法'], 405);
    }
}

// ==================== 缴费情况总览 ====================
function handlePayments() {
    requirePermission('viewPayments');
    jsonOutput(feeSummary());
}

/** 导出欠费名单 */
function handleExportUnpaid() {
    requirePermission('viewPayments');
    [$headers, $rows] = feeUnpaidExportRows();
    outputSimpleXlsx('欠费名单_' . date('Ymd_His'), '欠费名单', $headers, $rows);
    exit;
}

// ==================== 线下缴费 / 退款 ====================
function handleOfflinePayment() {
    requirePermission('manageRoster');
    requireCsrfToken();
    $in = jsonInput();
    $sid = (int)($in['student_id'] ?? 0);
    $amount = round((float)($in['amount'] ?? 0), 2);
    $roundId = (int)($in['round_id'] ?? 0);
    if ($sid <= 0 && trim((string)($in['name'] ?? '')) !== '') {
        $q = db()->prepare("SELECT id FROM class_roster WHERE name=:n LIMIT 1");
        $q->execute([':n' => trim((string)$in['name'])]);
        $sid = (int)$q->fetchColumn();
    }
    $method = mb_substr(trim((string)($in['method'] ?? 'cash')), 0, 30);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 200);
    if ($sid <= 0 || $amount <= 0) jsonOutput(['error' => '请选择学生并填写正确金额'], 400);
    $st = db()->prepare("SELECT id, name FROM class_roster WHERE id=:id");
    $st->execute([':id' => $sid]);
    $row = $st->fetch();
    if (!$row) jsonOutput(['error' => '学生不存在'], 404);
    $orderNo = 'OFF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    db()->prepare("INSERT INTO fee_payments (order_no, student_id, student_name, round_id, amount, channel, trade_no, paid_at)
        VALUES (:o, :sid, :sn, :rid, :a, :ch, :t, NOW())")
        ->execute([':o' => $orderNo, ':sid' => $sid, ':sn' => $row['name'], ':rid' => $roundId,
            ':a' => $amount, ':ch' => 'offline:' . ($method ?: 'cash'), ':t' => $note]);
    $attr = payAttributeToRounds($sid, $roundId, $amount);
    $u = currentUser();
    addLog((int)$u['id'], (string)$u['username'], 'offline_payment', 'payment', $sid, ['amount' => $amount, 'order_no' => $orderNo, 'method' => $method]);
    jsonOutput(['ok' => true, 'order_no' => $orderNo, 'amount' => $amount, 'name' => $row['name'], 'merged' => $attr['merged'], 'rest' => $attr['rest']]);
}

function handleRefund() {
    requirePermission('manageRoster');
    requireCsrfToken();
    $in = jsonInput();
    $sid = (int)($in['student_id'] ?? 0);
    $amount = round((float)($in['amount'] ?? 0), 2);
    $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 200);
    if ($sid <= 0 && trim((string)($in['name'] ?? '')) !== '') {
        $q = db()->prepare("SELECT id FROM class_roster WHERE name=:n LIMIT 1");
        $q->execute([':n' => trim((string)$in['name'])]);
        $sid = (int)$q->fetchColumn();
    }
    if ($sid <= 0 || $amount <= 0) jsonOutput(['error' => '请选择学生并填写退款金额'], 400);
    $st = db()->prepare("SELECT id, name FROM class_roster WHERE id=:id");
    $st->execute([':id' => $sid]);
    $row = $st->fetch();
    if (!$row) jsonOutput(['error' => '学生不存在'], 404);
    $orderNo = 'REF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
    db()->prepare("INSERT INTO fee_payments (order_no, student_id, student_name, round_id, amount, channel, trade_no, paid_at)
        VALUES (:o, :sid, :sn, 0, :a, 'refund', :t, NOW())")
        ->execute([':o' => $orderNo, ':sid' => $sid, ':sn' => $row['name'], ':a' => -abs($amount), ':t' => $reason]);
    $uid = (int)(currentUser()['id'] ?? 0);
    addLog($uid, (string)(currentUser()['username'] ?? ''), 'refund', 'payment', $sid, ['amount' => $amount, 'order_no' => $orderNo, 'reason' => $reason]);
    jsonOutput(['ok' => true, 'order_no' => $orderNo, 'amount' => $amount, 'name' => $row['name']]);
}
