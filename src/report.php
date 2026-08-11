<?php
/**
 * 班级班费管理系统 - 学期汇总报表模块
 * 按学期汇总收支、分类占比、月度趋势、班费收缴情况
 */

/** 构建报表数据（供页面展示与 Excel 导出共用） */
function buildReportData(array $params): array {
    // 优先使用用户自定义学期（semesters 表），保证报表与学期管理数据一致
    $sid = intval($params['semester_id'] ?? 0);
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
        $year = intval($params['year'] ?? date('Y'));
        // 限制年份合理范围，防止非法值产生无效日期
        if ($year < 2000 || $year > 2100) $year = (int)date('Y');
        $semester = in_array($params['semester'] ?? '', ['spring', 'autumn']) ? $params['semester'] : 'spring';

        if ($semester === 'autumn') {
            $start = "{$year}-09-01";
            $end = ($year + 1) . '-02-28';
        } else {
            $start = "{$year}-03-01";
            $end = "{$year}-08-31";
        }
        if (!empty($params['start']) && isValidDate($params['start'])) $start = $params['start'];
        if (!empty($params['end']) && isValidDate($params['end'])) $end = $params['end'];

        $custom = !empty($params['start']) || !empty($params['end']);
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

    return [
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
    ];
}

function handleReportSemester() {
    requirePermission('viewTransactions');
    jsonOutput(buildReportData($_GET));
}

/** 导出学期报表为 xlsx（带样式/列宽/框线/图表，便于打印） */
function handleExportReport() {
    requirePermission('viewTransactions');

    $d = buildReportData($_GET);
    $p = $d['period'];
    $s = $d['summary'];

    $nCat = count($d['by_category']);
    $nMon = count($d['by_month']);

    // ---- 构建报表行（样式：1标题 2副标题 3分组 4表头 5数据 6加粗 7金额） ----
    $rows = [];
    $rows[] = [['v' => $p['label'] . ' - 收支汇总报表', 's' => 1, 'm' => 3]];
    $rows[] = [['v' => '统计区间：' . $p['start'] . ' ~ ' . $p['end'], 's' => 2, 'm' => 3]];
    $rows[] = ['', '', '', ''];

    $rows[] = ['期初余额', ['v' => number_format($s['begin_balance'], 2, '.', ''), 's' => 7], '元', ''];
    $rows[] = ['总收入', ['v' => number_format($s['total_income'], 2, '.', ''), 's' => 7], $s['income_count'] . ' 笔', ''];
    $rows[] = ['总支出', ['v' => number_format($s['total_expense'], 2, '.', ''), 's' => 7], $s['expense_count'] . ' 笔', ''];
    $rows[] = ['期末结余', ['v' => number_format($s['balance'], 2, '.', ''), 's' => 6], $s['balance'] >= 0 ? '正常' : '超支', ''];
    $rows[] = ['班费收缴', ['v' => '实收 ' . number_format($s['collected_amount'], 2, '.', '') . ' 元', 's' => 5], '应收 ' . number_format($s['expected_amount'], 2, '.', '') . ' 元', '收缴率 ' . $s['collect_rate'] . '%（' . $s['rounds'] . ' 轮）'];

    $rows[] = ['', '', '', ''];
    $rows[] = [['v' => '一、分类明细', 's' => 3, 'm' => 3]];
    $rows[] = [['v' => '分类', 's' => 4], ['v' => '金额（元）', 's' => 4], ['v' => '笔数', 's' => 4], ['v' => '类型', 's' => 4]];
    if ($nCat > 0) {
        foreach ($d['by_category'] as $c) {
            $rows[] = [$c['category'], ['v' => number_format($c['total'], 2, '.', ''), 's' => 7], ['v' => $c['cnt'], 's' => 5], $c['type'] === 'income' ? '收入' : '支出'];
        }
    } else {
        $rows[] = ['（本学期暂无收支记录）', '', '', ''];
    }

    $rows[] = ['', '', '', ''];
    $rows[] = [['v' => '二、月度明细', 's' => 3, 'm' => 3]];
    $rows[] = [['v' => '月份', 's' => 4], ['v' => '收入（元）', 's' => 4], ['v' => '支出（元）', 's' => 4], ['v' => '净额（元）', 's' => 4]];
    if ($nMon > 0) {
        foreach ($d['by_month'] as $m) {
            $rows[] = [$m['month'], ['v' => number_format($m['income'], 2, '.', ''), 's' => 7], ['v' => number_format($m['expense'], 2, '.', ''), 's' => 7], ['v' => number_format($m['net'], 2, '.', ''), 's' => 6]];
        }
    } else {
        $rows[] = ['（本学期暂无月度数据）', '', '', ''];
    }

    // ---- 图表配置（月度收支柱状图） ----
    $chart = null;
    if ($nMon > 0) {
        // 行号（1-based）：分类数据 n 行后为月度区
        $monHeaderRow = 11 + max($nCat, 1);      // 月度表头行
        $monDataRow   = $monHeaderRow + 1;        // 月度数据首行
        $monEndRow    = $monDataRow + $nMon - 1;  // 月度数据末行
        $chartTitleRow = $monEndRow + 2;          // 图表分组标题行

        $chart = [
            'title'  => '月度收支对比',
            'from'   => [0, $chartTitleRow],       // 0-based 行
            'to'     => [5, $chartTitleRow + 14],
            'series' => [
                [
                    'name'    => '收入',
                    'nameRef' => '$B$' . $monHeaderRow,
                    'catRef'  => '$A$' . $monDataRow . ':$A$' . $monEndRow,
                    'valRef'  => '$B$' . $monDataRow . ':$B$' . $monEndRow,
                    'cats'    => array_column($d['by_month'], 'month'),
                    'vals'    => array_map(function ($m) { return $m['income']; }, $d['by_month']),
                ],
                [
                    'name'    => '支出',
                    'nameRef' => '$C$' . $monHeaderRow,
                    'catRef'  => '$A$' . $monDataRow . ':$A$' . $monEndRow,
                    'valRef'  => '$C$' . $monDataRow . ':$C$' . $monEndRow,
                    'cats'    => array_column($d['by_month'], 'month'),
                    'vals'    => array_map(function ($m) { return $m['expense']; }, $d['by_month']),
                ],
            ],
        ];
        // 图表分组标题行
        $rows[] = ['', '', '', ''];
        $rows[] = [['v' => '三、月度收支图表', 's' => 3, 'm' => 3]];
    }

    outputStyledXlsx('学期报表_' . date('Ymd_His'), '学期报表', [30, 18, 18, 22], $rows, $chart);
    exit;
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
