<?php
/**
 * 班级班费管理系统 - 学期汇总报表模块
 * 按学期汇总收支、分类占比、月度趋势、班费收缴情况
 */

function handleReportSemester() {
    requirePermission('viewTransactions');

    // 优先使用用户自定义学期（semesters 表），保证报表与学期管理数据一致
    $sid = intval($_GET['semester_id'] ?? 0);
    if ($sid > 0) {
        $stmt = db()->prepare("SELECT * FROM semesters WHERE id=:id");
        $stmt->execute([':id' => $sid]);
        $sem = $stmt->fetch();
        if (!$sem) jsonOutput(['error' => '学期不存在'], 404);
        $start = $sem['start_date'];
        $end = $sem['end_date'];
        $year = (int)substr($start, 0, 4);
        $semester = 'custom';
        $label = $sem['name'];
        $custom = true;
    } else {
        // 回退：内置固定学期范围（可被自定义 start/end 覆盖）
        $year = intval($_GET['year'] ?? date('Y'));
        // 限制年份合理范围，防止非法值产生无效日期
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');
        $semester = in_array($_GET['semester'] ?? '', ['spring', 'autumn']) ? $_GET['semester'] : 'spring';

        if ($semester === 'autumn') {
            $start = "{$year}-09-01";
            $end = ($year + 1) . '-02-28';
        } else {
            $start = "{$year}-03-01";
            $end = "{$year}-08-31";
        }
        if (!empty($_GET['start']) && isValidDate($_GET['start'])) $start = $_GET['start'];
        if (!empty($_GET['end']) && isValidDate($_GET['end'])) $end = $_GET['end'];

        $custom = !empty($_GET['start']) || !empty($_GET['end']);
        $label = $semester === 'autumn' ? "{$year} 秋季学期" : "{$year} 春季学期";
        if ($custom) $label = "{$start} ~ {$end}";
    }

    // 期初余额（学期开始前累计净额）
    $begin = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0)
        FROM transactions WHERE deleted_at IS NULL AND date < :s");
    $begin->execute([':s' => $start]);
    $beginBalance = round((float)$begin->fetchColumn(), 2);

    // 期内收支汇总
    $sum = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense,
        COALESCE(SUM(CASE WHEN type='income' THEN 1 ELSE 0 END),0) AS income_count,
        COALESCE(SUM(CASE WHEN type='expense' THEN 1 ELSE 0 END),0) AS expense_count
        FROM transactions WHERE deleted_at IS NULL AND date BETWEEN :s AND :e");
    $sum->execute([':s' => $start, ':e' => $end]);
    $row = $sum->fetch();

    $totalIncome  = round((float)$row['income'], 2);
    $totalExpense = round((float)$row['expense'], 2);
    $balance      = round($beginBalance + $totalIncome - $totalExpense, 2);

    // 班费收缴情况
    $pay = db()->prepare("SELECT
        COALESCE(SUM(expected_amount),0) AS expected,
        COALESCE(SUM(amount),0) AS collected,
        COUNT(*) AS rounds
        FROM transactions
        WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL AND date BETWEEN :s AND :e");
    $pay->execute([':s' => $start, ':e' => $end]);
    $payRow = $pay->fetch();
    $expectedAmount  = round((float)$payRow['expected'], 2);
    $collectedAmount = round((float)$payRow['collected'], 2);
    $collectRate = $expectedAmount > 0 ? round($collectedAmount / $expectedAmount * 100, 1) : 0;

    // 分类汇总
    $cat = db()->prepare("SELECT type, category, COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
        FROM transactions WHERE deleted_at IS NULL AND date BETWEEN :s AND :e
        GROUP BY type, category ORDER BY total DESC");
    $cat->execute([':s' => $start, ':e' => $end]);
    $byCategory = $cat->fetchAll();
    foreach ($byCategory as &$c) { $c['total'] = round((float)$c['total'], 2); }

    // 月度趋势
    $mon = db()->prepare("SELECT DATE_FORMAT(date,'%Y-%m') AS month,
        COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
        FROM transactions WHERE deleted_at IS NULL AND date BETWEEN :s AND :e
        GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY month");
    $mon->execute([':s' => $start, ':e' => $end]);
    $byMonth = $mon->fetchAll();
    foreach ($byMonth as &$m) {
        $m['income']  = round((float)$m['income'], 2);
        $m['expense'] = round((float)$m['expense'], 2);
        $m['net']     = round((float)$m['income'] - (float)$m['expense'], 2);
    }

    jsonOutput([
        'period'  => [
            'start' => $start, 'end' => $end, 'label' => $label,
            'year' => $year, 'semester' => $semester, 'custom' => $custom,
        ],
        'summary' => [
            'begin_balance'   => $beginBalance,
            'total_income'    => $totalIncome,
            'total_expense'   => $totalExpense,
            'balance'         => $balance,
            'income_count'    => (int)$row['income_count'],
            'expense_count'   => (int)$row['expense_count'],
            'expected_amount' => $expectedAmount,
            'collected_amount'=> $collectedAmount,
            'collect_rate'    => $collectRate,
            'rounds'          => (int)$payRow['rounds'],
        ],
        'by_category' => $byCategory,
        'by_month'    => $byMonth,
    ]);
}

// ==================== 学期管理 ====================

/** 计算某日期前的累计余额 */
function balanceBefore(string $date): float {
    $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE deleted_at IS NULL AND date < :d");
    $stmt->execute([':d' => $date]);
    return round((float)$stmt->fetchColumn(), 2);
}

function handleSemesters(string $method) {
    switch ($method) {
        case 'GET':
            requirePermission('viewTransactions');
            $rows = db()->query("SELECT * FROM semesters ORDER BY start_date DESC, id DESC")->fetchAll();
            foreach ($rows as &$s) {
                $s['begin_balance'] = balanceBefore($s['start_date']);
                // 期末余额 = 期末日期（含）前的累计余额
                $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM transactions WHERE deleted_at IS NULL AND date <= :d");
                $stmt->execute([':d' => $s['end_date']]);
                $s['end_balance'] = round((float)$stmt->fetchColumn(), 2);
            }
            jsonOutput(['semesters' => $rows]);
            break;

        case 'POST':
            requirePermission('manageAllAccounts');
            requireCsrfToken();
            $input = jsonInput();
            $action = $input['action'] ?? '';

            if ($action === 'create') {
                $name = trim($input['name'] ?? '');
                $start = $input['start_date'] ?? '';
                $end = $input['end_date'] ?? '';
                if (empty($name)) jsonOutput(['error' => '请填写学期名称'], 400);
                if (!isValidDate($start) || !isValidDate($end)) jsonOutput(['error' => '日期格式无效'], 400);
                if ($start > $end) jsonOutput(['error' => '开始日期不能晚于结束日期'], 400);

                db()->prepare("INSERT INTO semesters (name, start_date, end_date) VALUES (:n, :s, :e)")
                    ->execute([':n' => $name, ':s' => $start, ':e' => $end]);
                $user = currentUser();
                addLog($user['id'], $user['username'], 'create_semester', 'semester', (int)db()->lastInsertId(), ['name' => $name]);
                jsonOutput(['ok' => true]);
            }

            if ($action === 'archive') {
                $id = intval($input['id'] ?? 0);
                if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);
                db()->prepare("UPDATE semesters SET status='archived' WHERE id=:id AND status='active'")->execute([':id' => $id]);
                $user = currentUser();
                addLog($user['id'], $user['username'], 'archive_semester', 'semester', $id);
                jsonOutput(['ok' => true]);
            }

            jsonOutput(['error' => '不支持的操作'], 400);
            break;

        default:
            jsonOutput(['error' => '不支持的方法'], 405);
    }
}
