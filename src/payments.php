<?php
/**
 * 班级班费管理系统 - 花名册 & 缴费追踪模块
 */

// ==================== 花名册管理 ====================
function handleRoster(string $method) {
    switch ($method) {
        case 'GET':
            requirePermission('viewPayments');
            $rows = db()->query("SELECT * FROM class_roster ORDER BY id")->fetchAll();
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

            $stmt = db()->prepare("INSERT IGNORE INTO class_roster (name) VALUES (:n)");
            $count = 0;
            foreach ($names as $n) {
                if (!is_string($n)) continue; // 跳过非字符串元素，防止 trim() 报错
                $n = trim($n);
                if ($n && mb_strlen($n) <= 50) {
                    $stmt->execute([':n' => $n]);
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
        $stmt = db()->prepare("INSERT IGNORE INTO class_roster (name) VALUES (:n)");
        $count = 0;
        foreach ($names as $n) { $stmt->execute([':n' => $n]); $count += $stmt->rowCount(); }
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
            $roster = db()->query("SELECT * FROM class_roster ORDER BY id")->fetchAll();
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

    $roster = db()->query("SELECT * FROM class_roster ORDER BY id")->fetchAll();
    $txs = db()->query("SELECT * FROM transactions WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL ORDER BY date DESC")->fetchAll();

    // 构建学生缴费状态
    $ps = [];
    foreach ($roster as $s) {
        $ps[$s['id']] = ['name' => $s['name'], 'paid' => 0.0, 'exempt' => $s['exempt']];
    }

    $totalCollected = 0.0;
    $nonExempt = array_filter($roster, function ($s) { return !$s['exempt']; });
    $nonExemptCount = count($nonExempt);

    // 获取每人应缴金额（system_meta 全局配置 → 交易推算 → 0）
    $perPerson = round((float)getMeta('per_person', '0'), 2);
    if ($perPerson <= 0 && $nonExemptCount > 0) {
        // 回退1：从最新班费收缴交易的 expected_amount 推算
        $stmt = db()->query("SELECT expected_amount FROM transactions WHERE type='income' AND sub_category='班费收缴' AND expected_amount IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $last = $stmt->fetch();
        if ($last) {
            $perPerson = round(floatval($last['expected_amount']) / $nonExemptCount, 2);
        }
    }
    if ($perPerson <= 0 && $nonExemptCount > 0) {
        // 回退2：payer_ids='all' 的交易金额 / 人数
        $stmt = db()->query("SELECT amount FROM transactions WHERE type='income' AND sub_category='班费收缴' AND payer_ids='all' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $last = $stmt->fetch();
        if ($last) {
            $perPerson = round(floatval($last['amount']) / $nonExemptCount, 2);
        }
    }
    if ($perPerson <= 0 && $nonExemptCount > 0) {
        // 回退3：任意班费收缴交易 → 金额 / 缴费人数
        $stmt = db()->query("SELECT amount, payer_ids FROM transactions WHERE type='income' AND sub_category='班费收缴' AND payer_ids IS NOT NULL AND payer_ids != '' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $last = $stmt->fetch();
        if ($last) {
            $pids = json_decode($last['payer_ids'], true) ?: [];
            $payerCount = count($pids);
            if ($payerCount > 0) {
                $perPerson = round(floatval($last['amount']) / $payerCount, 2);
            }
        }
    }
    // 推算结果同步到全局配置，避免下次重复推算
    if ($perPerson > 0 && getMeta('per_person') === '') {
        setMeta('per_person', (string)$perPerson);
    }
    $totalExpected = round($perPerson * $nonExemptCount, 2);

    $rounds = [];
    foreach ($txs as $tx) {
        $totalCollected += $tx['amount'];
        $pids = json_decode($tx['payer_ids'] ?? '', true) ?: [];
        $roundPaid = [];
        $roundUnpaid = [];

        if ($tx['payer_ids'] === 'all') {
            if ($nonExemptCount > 0) {
                $perPersonThisRound = round($tx['amount'] / $nonExemptCount, 2);
                foreach ($roster as $s) {
                    if ($s['exempt']) continue;
                    $ps[$s['id']]['paid'] += $perPersonThisRound;
                    $roundPaid[] = ['name' => $s['name'], 'amount' => $perPersonThisRound];
                }
            }
            foreach ($roster as $s) {
                if ($s['exempt']) continue;
                $found = false;
                foreach ($roundPaid as $rp) if ($rp['name'] === $s['name']) $found = true;
                if (!$found) $roundUnpaid[] = ['name' => $s['name'], 'amount' => 0];
            }
        } elseif (!empty($pids)) {
            $perPersonThisRound = round($tx['amount'] / count($pids), 2);
            foreach ($pids as $pid) {
                if (isset($ps[$pid])) {
                    $ps[$pid]['paid'] += $perPersonThisRound;
                    $roundPaid[] = ['name' => $ps[$pid]['name'], 'amount' => $perPersonThisRound];
                }
            }
            foreach ($nonExempt as $s) {
                if (!in_array($s['id'], $pids)) $roundUnpaid[] = ['name' => $s['name'], 'amount' => 0];
            }
        } else {
            foreach ($nonExempt as $s) $roundUnpaid[] = ['name' => $s['name'], 'amount' => 0];
        }

        $rounds[] = [
            'id'            => (int)$tx['id'],
            'date'          => $tx['date'],
            'description'   => $tx['description'],
            'amount'        => round((float)$tx['amount'], 2),
            'paid_list'     => $roundPaid,
            'unpaid_list'   => $roundUnpaid,
            'paid_count'    => count($roundPaid),
            'unpaid_count'  => count($roundUnpaid),
        ];
    }

    // 分类已缴/未缴
    $paid = [];
    $unpaid = [];
    foreach ($ps as $sid => $info) {
        if ($info['exempt']) continue;
        if ($perPerson > 0 && $info['paid'] >= $perPerson * 0.99) {
            $paid[] = $info;
        } elseif ($perPerson > 0) {
            $unpaid[] = $info;
        } elseif ($info['paid'] > 0) {
            $paid[] = $info;
        } else {
            $unpaid[] = $info;
        }
    }

    jsonOutput([
        'roster_count'    => count($roster),
        'total_collected' => round($totalCollected, 2),
        'total_expected'  => $totalExpected,
        'per_person'      => $perPerson,
        'paid_count'      => count($paid),
        'unpaid_count'    => count($unpaid),
        'paid_list'       => $paid,
        'unpaid_list'     => $unpaid,
        'roster'          => $roster,
        'rounds'          => $rounds,
    ]);
}

/** 导出欠费名单 */
function handleExportUnpaid() {
    requirePermission('viewPayments');

    $roster = db()->query("SELECT * FROM class_roster ORDER BY id")->fetchAll();
    $ps = [];
    foreach ($roster as $s) $ps[$s['id']] = ['name' => $s['name'], 'paid' => 0.0, 'exempt' => $s['exempt']];

    $nonExempt = array_filter($roster, function ($s) { return !$s['exempt']; });
    $nonExemptCount = count($nonExempt);

    $txs = db()->query("SELECT * FROM transactions WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL ORDER BY date DESC")->fetchAll();
    foreach ($txs as $tx) {
        $pids = json_decode($tx['payer_ids'] ?? '', true) ?: [];
        if ($tx['payer_ids'] === 'all') {
            // 与 handlePayments 一致：排除免缴学生后平分
            if ($nonExemptCount > 0) {
                $each = round($tx['amount'] / $nonExemptCount, 2);
                foreach ($roster as $s) {
                    if ($s['exempt']) continue;
                    $ps[$s['id']]['paid'] += $each;
                }
            }
        } elseif (!empty($pids)) {
            $each = round($tx['amount'] / count($pids), 2);
            foreach ($pids as $pid) if (isset($ps[$pid])) $ps[$pid]['paid'] += $each;
        }
    }

    // 与缴费页 handlePayments 的判定保持一致：未缴清（含部分缴费）也应列入欠费名单
    $perPerson = round((float)getMeta('per_person', '0'), 2);
    if ($perPerson <= 0 && $nonExemptCount > 0) {
        // 回退：从最新班费收缴交易的 expected_amount 推算每人应缴
        $stmt = db()->query("SELECT expected_amount FROM transactions WHERE type='income' AND sub_category='班费收缴' AND expected_amount IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $last = $stmt->fetch();
        if ($last) $perPerson = round(floatval($last['expected_amount']) / $nonExemptCount, 2);
    }

    $rows = [];
    foreach ($ps as $info) {
        if ($info['exempt']) continue; // 跳过免缴学生
        if ($perPerson > 0) {
            // 已缴清阈值与 handlePayments 一致（0.99 容差），未缴清（含部分缴费）列入名单
            if ($info['paid'] < $perPerson * 0.99) $rows[] = [$info['name'], '未缴清', number_format($info['paid'], 2, '.', '')];
        } elseif ($info['paid'] <= 0) {
            $rows[] = [$info['name'], '未缴纳', '0'];
        }
    }
    if (empty($rows)) $rows[] = ['全部已缴纳', '', ''];

    outputSimpleXlsx('欠费名单', '欠费名单', ['姓名', '状态', '已缴金额'], $rows);
    exit;
}
