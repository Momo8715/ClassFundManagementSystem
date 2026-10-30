<?php
/**
 * 班级班费管理系统 - 学期结转 / 归档冻结
 *
 * 设计要点：
 *  - 归档不给流水额外记「结转收入」，避免总账重复；余额通过 semester.opening_balance 承接。
 *  - 归档时把期初/期末/收支/收缴/分类/月度写成快照，历史报表不再随后续数据漂移。
 *  - 归档学期禁止手工增删改收支与导入；在线缴费等系统入账允许发生，但会把 snapshot_dirty 置 1，
 *    数据体检会提示「重新结账」。
 */

/** 全部学期（按开始日期倒序） */
function semesterAll(): array {
    static $cache = null;
    if ($cache === null) {
        $cache = db()->query("SELECT * FROM semesters ORDER BY start_date DESC, id DESC")->fetchAll();
    }
    return $cache;
}

function semesterById(int $id): ?array {
    // 始终查库：归档/重新结账后需要拿到最新状态（semesterAll 有静态缓存）
    $stmt = db()->prepare("SELECT * FROM semesters WHERE id=:id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 某日期落在哪个学期（优先进行中，其次开始日期更晚的） */
function semesterIdForDate(string $date): int {
    $stmt = db()->prepare("SELECT id FROM semesters
        WHERE start_date <= :d1 AND end_date >= :d2
        ORDER BY CASE WHEN status='active' THEN 0 ELSE 1 END, start_date DESC, id DESC LIMIT 1");
    $stmt->execute([':d1' => $date, ':d2' => $date]);
    return (int)$stmt->fetchColumn();
}

/** 某日期是否落在已归档学期（用于冻结校验） */
function semesterArchivedForDate(string $date): ?array {
    $stmt = db()->prepare("SELECT * FROM semesters
        WHERE start_date <= :d1 AND end_date >= :d2 AND status='archived'
        ORDER BY start_date DESC, id DESC LIMIT 1");
    $stmt->execute([':d1' => $date, ':d2' => $date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 归档学期禁止手工写；命中直接 403 退出 */
function semesterBlockArchivedDate(string $date): void {
    $sem = semesterArchivedForDate($date);
    if ($sem) {
        jsonOutput(['error' => '日期 ' . $date . ' 属于已归档学期「' . $sem['name'] . '」，历史数据已冻结；如需修改请先在「学期管理 → 取消归档」'], 403);
    }
}

/** 标记归档学期快照已过期（系统入账修改了归档期数据时调用） */
function semesterMarkDirty(int $semesterId): void {
    if ($semesterId <= 0) return;
    try {
        db()->prepare("UPDATE semesters SET snapshot_dirty=1 WHERE id=:id AND status='archived'")->execute([':id' => $semesterId]);
    } catch (\Exception $e) { /* 忽略 */ }
}

/** 学期是否重叠（返回冲突学期或 null） */
function semesterOverlap(string $start, string $end, int $excludeId = 0): ?array {
    $stmt = db()->prepare("SELECT * FROM semesters
        WHERE id <> :id AND start_date <= :e AND end_date >= :s
        ORDER BY start_date ASC LIMIT 1");
    $stmt->execute([':id' => $excludeId, ':s' => $start, ':e' => $end]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function semesterBalanceBefore(string $date): float {
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0)
        FROM transactions WHERE deleted_at IS NULL AND date < :d");
    $stmt->execute([':d' => $date]);
    return round((float)$stmt->fetchColumn(), 2);
}

function semesterBalanceThrough(string $date): float {
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0)
        FROM transactions WHERE deleted_at IS NULL AND date <= :d");
    $stmt->execute([':d' => $date]);
    return round((float)$stmt->fetchColumn(), 2);
}

/** 计算某学期的完整快照（不落库） */
function semesterBuildSnapshot(array $sem): array {
    $start = (string)$sem['start_date'];
    $end = (string)$sem['end_date'];

    $opening = semesterBalanceBefore($start);
    $closing = semesterBalanceThrough($end);

    $sum = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense,
        COALESCE(SUM(CASE WHEN type='income' THEN 1 ELSE 0 END),0) AS income_count,
        COALESCE(SUM(CASE WHEN type='expense' THEN 1 ELSE 0 END),0) AS expense_count
        FROM transactions WHERE deleted_at IS NULL AND date BETWEEN :s AND :e");
    $sum->execute([':s' => $start, ':e' => $end]);
    $row = $sum->fetch() ?: ['income' => 0, 'expense' => 0, 'income_count' => 0, 'expense_count' => 0];

    $pay = db()->prepare("SELECT
        COALESCE(SUM(expected_amount),0) AS expected,
        COALESCE(SUM(amount),0) AS collected,
        COUNT(*) AS rounds
        FROM transactions WHERE type='income' AND sub_category='班费收缴'
        AND deleted_at IS NULL AND date BETWEEN :s AND :e");
    $pay->execute([':s' => $start, ':e' => $end]);
    $payRow = $pay->fetch() ?: ['expected' => 0, 'collected' => 0, 'rounds' => 0];

    $cat = db()->prepare("SELECT type, category, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
        FROM transactions WHERE deleted_at IS NULL AND date BETWEEN :s AND :e
        GROUP BY type, category ORDER BY total DESC");
    $cat->execute([':s' => $start, ':e' => $end]);
    $byCategory = $cat->fetchAll();
    foreach ($byCategory as &$c) { $c['total'] = round((float)$c['total'], 2); }
    unset($c);

    $mon = db()->prepare("SELECT DATE_FORMAT(date,'%Y-%m') AS month,
        COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
        FROM transactions WHERE deleted_at IS NULL AND date BETWEEN :s AND :e
        GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY month");
    $mon->execute([':s' => $start, ':e' => $end]);
    $byMonth = $mon->fetchAll();
    foreach ($byMonth as &$m) {
        $m['income'] = round((float)$m['income'], 2);
        $m['expense'] = round((float)$m['expense'], 2);
        $m['net'] = round($m['income'] - $m['expense'], 2);
    }
    unset($m);

    $expected = round((float)$payRow['expected'], 2);
    $collected = round((float)$payRow['collected'], 2);
    $rate = $expected > 0 ? round($collected / $expected * 100, 1) : 0.0;

    return [
        'opening_balance' => $opening,
        'closing_balance' => $closing,
        'total_income'    => round((float)$row['income'], 2),
        'total_expense'   => round((float)$row['expense'], 2),
        'tx_count'        => (int)$row['income_count'] + (int)$row['expense_count'],
        'income_count'    => (int)$row['income_count'],
        'expense_count'   => (int)$row['expense_count'],
        'pay_expected'    => $expected,
        'pay_collected'   => $collected,
        'pay_rate'        => $rate,
        'pay_rounds'      => (int)$payRow['rounds'],
        'roster_count'    => (int)db()->query("SELECT COUNT(*) FROM class_roster")->fetchColumn(),
        'by_category'     => $byCategory,
        'by_month'        => $byMonth,
        'generated_at'    => date('Y-m-d H:i:s'),
        'version'         => 1,
    ];
}

/** 计算并写入学期快照；$archive=true 时同时归档 */
function semesterApplySnapshot(int $id, ?int $by = null, bool $archive = false): ?array {
    $sem = semesterById($id);
    if (!$sem) return null;
    $snap = semesterBuildSnapshot($sem);
    $json = json_encode([
        'income_count' => $snap['income_count'],
        'expense_count' => $snap['expense_count'],
        'pay_rounds' => $snap['pay_rounds'],
        'roster_count' => $snap['roster_count'],
        'by_category' => $snap['by_category'],
        'by_month' => $snap['by_month'],
        'generated_at' => $snap['generated_at'],
        'version' => $snap['version'],
    ], JSON_UNESCAPED_UNICODE);

    $sets = "opening_balance=:ob, closing_balance=:cb, total_income=:ti, total_expense=:te,
        tx_count=:tc, pay_expected=:pe, pay_collected=:pc, pay_rate=:pr, snapshot_json=:sj, snapshot_dirty=0";
    $params = [
        ':ob' => $snap['opening_balance'], ':cb' => $snap['closing_balance'],
        ':ti' => $snap['total_income'], ':te' => $snap['total_expense'],
        ':tc' => $snap['tx_count'], ':pe' => $snap['pay_expected'],
        ':pc' => $snap['pay_collected'], ':pr' => $snap['pay_rate'],
        ':sj' => $json, ':id' => $id,
    ];
    if ($archive) {
        $sets .= ", status='archived', archived_at=NOW(), archived_by=:by";
        $params[':by'] = $by;
    }
    db()->prepare("UPDATE semesters SET {$sets} WHERE id=:id")->execute($params);
    return semesterById($id);
}

/** 把日期区间内尚未归属的流水挂到该学期 */
function semesterAssignRange(int $id, string $start, string $end): int {
    $stmt = db()->prepare("UPDATE transactions SET semester_id=:id
        WHERE date BETWEEN :s AND :e AND (semester_id IS NULL OR semester_id=0)");
    $stmt->execute([':id' => $id, ':s' => $start, ':e' => $end]);
    return $stmt->rowCount();
}

/** 由当前学期创建下一学期并承接期末余额 */
function semesterCreateNext(array $current, array $input, int $userId): array {
    $name = trim((string)($input['next_name'] ?? ''));
    $start = (string)($input['next_start'] ?? '');
    $end = (string)($input['next_end'] ?? '');
    if ($name === '') throw new \InvalidArgumentException('请填写下一学期名称');
    if (mb_strlen($name) > 50) throw new \InvalidArgumentException('学期名称不能超过 50 个字符');
    if (!isValidDate($start) || !isValidDate($end)) throw new \InvalidArgumentException('下一学期日期格式无效');
    if ($start > $end) throw new \InvalidArgumentException('下一学期开始日期不能晚于结束日期');
    if ($start <= (string)$current['end_date']) throw new \InvalidArgumentException('下一学期开始日期必须晚于当前学期结束日期');

    $dup = db()->prepare("SELECT id FROM semesters WHERE name=:n AND id<>:id");
    $dup->execute([':n' => $name, ':id' => (int)$current['id']]);
    if ($dup->fetch()) throw new \InvalidArgumentException('已存在同名学期「' . $name . '」');

    $overlap = semesterOverlap($start, $end, (int)$current['id']);
    if ($overlap) throw new \InvalidArgumentException('日期与已有学期「' . $overlap['name'] . '」重叠');

    $closing = semesterBalanceThrough((string)$current['end_date']);
    db()->prepare("INSERT INTO semesters (name, start_date, end_date, status, opening_balance, carried_from)
        VALUES (:n, :s, :e, 'active', :ob, :cf)")
        ->execute([':n' => $name, ':s' => $start, ':e' => $end, ':ob' => $closing, ':cf' => (int)$current['id']]);
    $id = (int)db()->lastInsertId();
    addLog($userId, currentUser()['username'] ?? '', 'create_semester', 'semester', $id, ['name' => $name, 'carried_from' => (int)$current['id'], 'opening_balance' => $closing]);
    return semesterById($id) ?? [];
}
