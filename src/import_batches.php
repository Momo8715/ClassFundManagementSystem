<?php
/**
 * 班级班费管理系统 - 导入批次（追溯 / 去重 / 整批撤销）
 */

function importBatchNo(): string {
    return 'IMP' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
}

/** 同类型+同日期+同金额+同描述视为疑似重复（导入时跳过） */
function importIsDuplicate(string $type, float $amount, string $date, string $desc): bool {
    try {
        $stmt = db()->prepare("SELECT id FROM transactions
            WHERE deleted_at IS NULL AND type=:t AND amount=:a AND date=:d AND description=:desc LIMIT 1");
        $stmt->execute([':t' => $type, ':a' => $amount, ':d' => $date, ':desc' => $desc]);
        return (bool)$stmt->fetchColumn();
    } catch (\Exception $e) {
        return false;
    }
}

function importRecordBatch(string $batchNo, string $source, ?string $filename, int $rowCount, float $total, int $userId, string $note = ''): void {
    if ($rowCount <= 0) return;
    try {
        $stmt = db()->prepare("INSERT INTO import_batches
            (batch_no, source, filename, row_count, total_amount, created_by, note)
            VALUES (:b, :src, :f, :n, :a, :u, :note)");
        $stmt->execute([
            ':b' => $batchNo, ':src' => $source, ':f' => $filename,
            ':n' => $rowCount, ':a' => round($total, 2), ':u' => $userId,
            ':note' => mb_substr($note, 0, 255),
        ]);
    } catch (\Exception $e) { /* 记录失败不影响导入 */ }
}

/** 导入批次列表（最近 100 条） */
function handleImportBatches() {
    requirePermission('importData');
    $rows = db()->query("SELECT b.*, u.username AS created_by_name,
        (SELECT COUNT(*) FROM transactions t WHERE t.import_batch=b.batch_no AND t.deleted_at IS NULL) AS live_count,
        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.import_batch=b.batch_no AND t.deleted_at IS NULL) AS live_amount
        FROM import_batches b LEFT JOIN users u ON u.id=b.created_by
        ORDER BY b.id DESC LIMIT 100")->fetchAll();
    foreach ($rows as &$r) {
        $r['live_amount'] = round((float)$r['live_amount'], 2);
        $r['total_amount'] = round((float)$r['total_amount'], 2);
    }
    unset($r);
    jsonOutput(['batches' => $rows]);
}

/** 整批撤销（软删除，可在回收站恢复） */
function handleImportUndo() {
    requirePermission('deleteTransaction');
    requireCsrfToken();
    $input = jsonInput();
    $batchNo = trim((string)($input['batch_no'] ?? ''));
    if ($batchNo === '') jsonOutput(['error' => '缺少批次号'], 400);
    $stmt = db()->prepare("SELECT * FROM import_batches WHERE batch_no=:b LIMIT 1");
    $stmt->execute([':b' => $batchNo]);
    $batch = $stmt->fetch();
    if (!$batch) jsonOutput(['error' => '批次不存在'], 404);
    if (!empty($batch['undone_at'])) jsonOutput(['error' => '该批次已撤销'], 400);

    $txs = db()->prepare("SELECT id, date FROM transactions WHERE import_batch=:b AND deleted_at IS NULL");
    $txs->execute([':b' => $batchNo]);
    $rows = $txs->fetchAll();
    if (empty($rows)) {
        db()->prepare("UPDATE import_batches SET undone_at=NOW() WHERE batch_no=:b")->execute([':b' => $batchNo]);
        jsonOutput(['ok' => true, 'deleted' => 0]);
    }
    foreach ($rows as $row) {
        $sem = semesterArchivedForDate((string)$row['date']);
        if ($sem) jsonOutput(['error' => '批次包含已归档学期「' . $sem['name'] . '」的记录，请先取消归档'], 403);
    }
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $del = db()->prepare("UPDATE transactions SET deleted_at=NOW() WHERE id IN ($ph) AND deleted_at IS NULL");
    $del->execute($ids);
    $n = $del->rowCount();
    db()->prepare("UPDATE import_batches SET undone_at=NOW() WHERE batch_no=:b")->execute([':b' => $batchNo]);
    $user = currentUser();
    addLog((int)$user['id'], (string)$user['username'], 'import_undo', 'transaction', null, ['batch_no' => $batchNo, 'count' => $n]);
    jsonOutput(['ok' => true, 'deleted' => $n]);
}
