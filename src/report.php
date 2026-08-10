<?php
/**
 * 班级班费管理系统 - 学期汇总报表模块
 * 按学期汇总收支、分类占比、月度趋势、班费收缴情况
 */

function handleReportSemester() {
    requirePermission('viewTransactions');

    $year = intval($_GET['year'] ?? date('Y'));
    $semester = in_array($_GET['semester'] ?? '', ['spring', 'autumn']) ? $_GET['semester'] : 'spring';

    // 学期默认日期范围（可被自定义 start/end 覆盖）
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
