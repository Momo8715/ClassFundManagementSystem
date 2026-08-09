<?php
/**
 * 班级班费管理系统 - 收支记录模块
 * 查询 / 添加 / 编辑 / 删除（软删除）/ 回收站
 */

// ==================== 收支记录 CRUD ====================
function handleTransactions(string $method) {
    requirePermission('viewTransactions');

    switch ($method) {
        case 'GET':
            handleTransactionsGet();
            break;
        case 'POST':
            handleTransactionsPost();
            break;
        case 'PUT':
            handleTransactionsPut();
            break;
        case 'DELETE':
            handleTransactionsDelete();
            break;
        default:
            jsonOutput(['error' => '不支持的方法'], 405);
    }
}

/** GET - 分页查询收支记录 */
function handleTransactionsGet() {
    $type   = $_GET['type'] ?? '';
    $month  = $_GET['month'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $page   = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(100, max(10, intval($_GET['per_page'] ?? 50)));

    $sql = "SELECT t.*, u.username as recorder_name FROM transactions t LEFT JOIN users u ON t.recorded_by=u.id WHERE t.deleted_at IS NULL";
    $countSql = "SELECT COUNT(*) FROM transactions t WHERE t.deleted_at IS NULL";
    $params = [];

    if ($type && in_array($type, ['income', 'expense'])) {
        $where = " AND t.type = :type";
        $sql .= $where;
        $countSql .= $where;
        $params[':type'] = $type;
    }
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $where = " AND DATE_FORMAT(t.date, '%Y-%m') = :month";
        $sql .= $where;
        $countSql .= $where;
        $params[':month'] = $month;
    }
    if ($search) {
        $where = " AND (t.description LIKE :s OR t.category LIKE :s2)";
        $sql .= $where;
        $countSql .= $where;
        $params[':s'] = "%{$search}%";
        $params[':s2'] = "%{$search}%";
    }

    // 总数
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    // 分页数据
    $offset = ($page - 1) * $perPage;
    $sql .= " ORDER BY t.date DESC, t.id DESC LIMIT {$offset}, {$perPage}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonOutput([
        'transactions' => $rows,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => (int)ceil($total / $perPage),
    ]);
}

/** POST - 添加收支记录 */
function handleTransactionsPost() {
    requirePermission('addTransaction');
    requireCsrfToken();

    $input = jsonInput();
    $type       = $input['type'] ?? '';
    $subCat     = trim($input['sub_category'] ?? '');
    $sourceInfo = trim($input['source_info'] ?? '');
    $amount     = sanitizeAmount($input['amount'] ?? 0);
    $date       = $input['date'] ?? '';
    $desc       = trim($input['description'] ?? '');
    $cat        = trim($input['category'] ?? '其他');
    $imagePath  = trim($input['image_path'] ?? '');
    $payerIds   = $input['payer_ids'] ?? null;
    $expAmt     = $input['expected_amount'] ?? null;

    if (!in_array($type, ['income', 'expense'])) jsonOutput(['error' => '类型无效'], 400);
    if ($amount <= 0) jsonOutput(['error' => '金额必须大于 0'], 400);
    if (!isValidDate($date)) jsonOutput(['error' => '日期格式无效'], 400);
    if (empty($desc)) jsonOutput(['error' => '请填写描述'], 400);

    // 处理 expected_amount
    if ($expAmt !== null && $expAmt !== '') {
        $expAmt = sanitizeAmount($expAmt);
    } else {
        $expAmt = null;
    }

    // 处理 payer_ids
    if ($payerIds === 'all') {
        // 保持 'all'
    } elseif (is_array($payerIds) && !empty($payerIds)) {
        $payerIds = json_encode(array_map('intval', $payerIds));
    } elseif (is_string($payerIds) && !empty($payerIds) && $payerIds !== 'all') {
        // 尝试解析 JSON 字符串
        $decoded = json_decode($payerIds, true);
        if (is_array($decoded)) {
            $payerIds = json_encode(array_map('intval', $decoded));
        } else {
            $payerIds = null;
        }
    } else {
        $payerIds = null;
    }

    $user = currentUser();
    $stmt = db()->prepare("INSERT INTO transactions (type, sub_category, source_info, amount, expected_amount, date, description, payer_ids, category, image_path, recorded_by) VALUES (:t, :sc, :si, :a, :ea, :d, :desc, :pids, :cat, :img, :rb)");
    $stmt->execute([
        ':t'    => $type,
        ':sc'   => $subCat ?: null,
        ':si'   => ($subCat === '其他来源') ? $sourceInfo : null,
        ':a'    => $amount,
        ':ea'   => $expAmt,
        ':d'    => $date,
        ':desc' => $desc,
        ':pids' => $payerIds,
        ':cat'  => $cat,
        ':img'  => $imagePath ?: null,
        ':rb'   => $user['id']
    ]);
    $newId = (int)db()->lastInsertId();

    addLog($user['id'], $user['username'], 'create_transaction', 'transaction', $newId, [
        'type' => $type, 'amount' => $amount, 'date' => $date, 'description' => $desc
    ]);

    jsonOutput(['ok' => true, 'id' => $newId], 201);
}

/** PUT - 编辑收支记录 */
function handleTransactionsPut() {
    requirePermission('editTransaction');
    requireCsrfToken();

    try {
        $input = jsonInput();
        $id = intval($_GET['id'] ?? $input['id'] ?? 0);
        if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);

        // 获取旧记录
        $oldStmt = db()->prepare("SELECT * FROM transactions WHERE id=:id AND deleted_at IS NULL");
        $oldStmt->execute([':id' => $id]);
        $old = $oldStmt->fetch();
        if (!$old) jsonOutput(['error' => '记录不存在'], 404);

        // 验证输入
        $newType = $input['type'] ?? $old['type'];
        if (!in_array($newType, ['income', 'expense'])) jsonOutput(['error' => '类型无效'], 400);

        $newDate = $input['date'] ?? $old['date'];
        if (!isValidDate($newDate)) jsonOutput(['error' => '日期格式无效'], 400);

        $newAmount = isset($input['amount']) ? sanitizeAmount($input['amount']) : $old['amount'];
        if ($newAmount <= 0) jsonOutput(['error' => '金额必须大于 0'], 400);

        $newDesc = isset($input['description']) ? trim($input['description']) : $old['description'];
        if (empty($newDesc)) jsonOutput(['error' => '请填写描述'], 400);

        $newSubCat = array_key_exists('sub_category', $input) ? (trim($input['sub_category']) ?: null) : $old['sub_category'];
        $newSrcInfo = array_key_exists('source_info', $input) ? (trim($input['source_info']) ?: null) : $old['source_info'];
        $newCat = trim($input['category'] ?? $old['category']);
        $newImg = array_key_exists('image_path', $input) ? (trim($input['image_path']) ?: null) : $old['image_path'];

        // 处理 expected_amount（修复：之前 PUT 丢失此字段）
        if (array_key_exists('expected_amount', $input)) {
            $val = $input['expected_amount'];
            $newExpAmt = ($val !== null && $val !== '' && $val !== 'null') ? sanitizeAmount($val) : null;
        } else {
            $newExpAmt = $old['expected_amount'];
        }

        // 处理 payer_ids
        if (array_key_exists('payer_ids', $input)) {
            $pids = $input['payer_ids'];
            if ($pids === 'all') {
                $newPayerIds = 'all';
            } elseif (is_array($pids) && !empty($pids)) {
                $newPayerIds = json_encode(array_map('intval', $pids));
            } elseif (is_string($pids) && !empty($pids) && $pids !== 'all') {
                $decoded = json_decode($pids, true);
                if (is_array($decoded)) {
                    $newPayerIds = json_encode(array_map('intval', $decoded));
                } else {
                    $newPayerIds = null;
                }
            } else {
                $newPayerIds = null;
            }
        } else {
            $newPayerIds = $old['payer_ids'];
        }

        $stmt = db()->prepare("UPDATE transactions SET type=:t, sub_category=:sc, source_info=:si, amount=:a, expected_amount=:ea, date=:d, description=:desc, payer_ids=:pids, category=:cat, image_path=:img WHERE id=:id");
        $stmt->execute([
            ':t'    => $newType,
            ':sc'   => $newSubCat,
            ':si'   => $newSrcInfo,
            ':a'    => $newAmount,
            ':ea'   => $newExpAmt,
            ':d'    => $newDate,
            ':desc' => $newDesc,
            ':pids' => $newPayerIds,
            ':cat'  => $newCat,
            ':img'  => $newImg,
            ':id'   => $id,
        ]);

        $user = currentUser();
        addLog($user['id'], $user['username'], 'update_transaction', 'transaction', $id);

        jsonOutput(['ok' => true]);
    } catch (\Exception $e) {
        securityLog('update_transaction_error', ['id' => $id ?? 0, 'error' => $e->getMessage()]);
        jsonOutput(['error' => '保存失败：' . $e->getMessage()], 500);
    }
}

/** DELETE - 软删除收支记录 */
function handleTransactionsDelete() {
    requirePermission('deleteTransaction');
    requireCsrfToken();

    $input = jsonInput();
    $id = intval($_GET['id'] ?? $input['id'] ?? 0);
    if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);

    $stmt = db()->prepare("SELECT * FROM transactions WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $id]);
    $old = $stmt->fetch();
    if (!$old) jsonOutput(['error' => '记录不存在'], 404);

    // 软删除
    db()->prepare("UPDATE transactions SET deleted_at=NOW() WHERE id=:id")->execute([':id' => $id]);

    $user = currentUser();
    addLog($user['id'], $user['username'], 'delete_transaction', 'transaction', $id, $old);

    jsonOutput(['ok' => true]);
}

// ==================== 仪表盘 ====================
function handleDashboard() {
    requirePermission('viewTransactions');

    $income = db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income' AND deleted_at IS NULL")->fetchColumn();
    $expense = db()->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense' AND deleted_at IS NULL")->fetchColumn();
    $expectedTotal = db()->query("SELECT COALESCE(SUM(expected_amount),0) FROM transactions WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL")->fetchColumn();
    $incomeCount = db()->query("SELECT COUNT(*) FROM transactions WHERE type='income' AND deleted_at IS NULL")->fetchColumn();
    $expenseCount = db()->query("SELECT COUNT(*) FROM transactions WHERE type='expense' AND deleted_at IS NULL")->fetchColumn();
    $userCount = db()->query("SELECT COUNT(*) FROM users")->fetchColumn();

    $recent = db()->query("SELECT t.*, u.username as recorder_name FROM transactions t LEFT JOIN users u ON t.recorded_by=u.id WHERE t.deleted_at IS NULL ORDER BY t.date DESC, t.id DESC LIMIT 8")->fetchAll();

    jsonOutput([
        'summary' => [
            'totalIncome'   => (float)$income,
            'totalExpense'  => (float)$expense,
            'balance'       => round((float)($income - $expense), 2),
            'expectedTotal' => (float)$expectedTotal,
            'incomeCount'   => (int)$incomeCount,
            'expenseCount'  => (int)$expenseCount,
            'userCount'     => (int)$userCount,
        ],
        'recent' => $recent,
    ]);
}
