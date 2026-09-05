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

/** 导出学期报表为 xlsx（带样式/列宽/框线/明细/图表，A4竖版打印） */
function handleExportReport() {
    requirePermission('viewTransactions');

    $d = buildReportData($_GET);
    $p = $d['period'];
    $s = $d['summary'];

    $nCat = count($d['by_category']);
    $nMon = count($d['by_month']);

    // 期内收支明细（详细账目）
    $detStmt = db()->prepare("SELECT t.date, t.type, t.amount, t.description, t.category, u.username AS recorder
        FROM transactions t LEFT JOIN users u ON t.recorded_by=u.id
        WHERE t.deleted_at IS NULL AND t.date BETWEEN :s AND :e ORDER BY t.date, t.id");
    $detStmt->execute([':s' => $p['start'], ':e' => $p['end']]);
    $details = $detStmt->fetchAll();
    $nDet = count($details);

    // ---- 构建报表行（样式：1标题 2副标题 3分组 4表头 5数据 6加粗 7金额） ----
    $rows = [];
    $rows[] = [['v' => $p['label'] . ' - 收支汇总报表', 's' => 8, 'm' => 5]];
    $rows[] = [['v' => '统计区间：' . $p['start'] . ' ~ ' . $p['end'] . '    生成时间：' . date('Y-m-d H:i'), 's' => 2, 'm' => 5]];
    $rows[] = ['', '', '', '', '', ''];

    $rows[] = ['期初余额', ['v' => number_format($s['begin_balance'], 2, '.', ''), 's' => 7], '元', '', '', ''];
    $rows[] = ['总收入', ['v' => number_format($s['total_income'], 2, '.', ''), 's' => 7], '元', $s['income_count'] . ' 笔', '', ''];
    $rows[] = ['总支出', ['v' => number_format($s['total_expense'], 2, '.', ''), 's' => 7], '元', $s['expense_count'] . ' 笔', '', ''];
    $rows[] = ['期末结余', ['v' => number_format($s['balance'], 2, '.', ''), 's' => 6], '元', $s['balance'] >= 0 ? '正常' : '超支', '', ''];
    $rows[] = ['班费收缴', ['v' => '实收 ' . number_format($s['collected_amount'], 2, '.', '') . ' 元', 's' => 5], '应收 ' . number_format($s['expected_amount'], 2, '.', '') . ' 元', '收缴率 ' . $s['collect_rate'] . '%（' . $s['rounds'] . ' 轮）', '', ''];

    $rows[] = ['', '', '', '', '', ''];
    $rows[] = [['v' => '一、分类明细', 's' => 3, 'm' => 5]];
    $rows[] = [['v' => '分类', 's' => 4], ['v' => '金额（元）', 's' => 4], ['v' => '笔数', 's' => 4], ['v' => '类型', 's' => 4], ['v' => '', 's' => 4], ['v' => '', 's' => 4]];
    if ($nCat > 0) {
        foreach ($d['by_category'] as $c) {
            $rows[] = [$c['category'], ['v' => number_format($c['total'], 2, '.', ''), 's' => 7], ['v' => $c['cnt'], 's' => 5], $c['type'] === 'income' ? '收入' : '支出', '', ''];
        }
    } else {
        $rows[] = ['（本学期暂无收支记录）', '', '', '', '', ''];
    }

    $rows[] = ['', '', '', '', '', ''];
    $rows[] = [['v' => '二、月度明细', 's' => 3, 'm' => 5]];
    $rows[] = [['v' => '月份', 's' => 4], ['v' => '收入（元）', 's' => 4], ['v' => '支出（元）', 's' => 4], ['v' => '净额（元）', 's' => 4], ['v' => '', 's' => 4], ['v' => '', 's' => 4]];
    if ($nMon > 0) {
        foreach ($d['by_month'] as $m) {
            $rows[] = [$m['month'], ['v' => number_format($m['income'], 2, '.', ''), 's' => 7], ['v' => number_format($m['expense'], 2, '.', ''), 's' => 7], ['v' => number_format($m['net'], 2, '.', ''), 's' => 6], '', ''];
        }
    } else {
        $rows[] = ['（本学期暂无月度数据）', '', '', '', '', ''];
    }

    $rows[] = ['', '', '', '', '', ''];
    $rows[] = [['v' => '三、收支明细（共 ' . $nDet . ' 笔）', 's' => 3, 'm' => 5]];
    $rows[] = [['v' => '日期', 's' => 4], ['v' => '类型', 's' => 4], ['v' => '金额（元）', 's' => 4], ['v' => '描述', 's' => 4], ['v' => '分类', 's' => 4], ['v' => '记录人', 's' => 4]];
    if ($nDet > 0) {
        foreach ($details as $tx) {
            $rows[] = [
                $tx['date'],
                $tx['type'] === 'income' ? '收入' : '支出',
                ['v' => number_format((float)$tx['amount'], 2, '.', ''), 's' => 7],
                $tx['description'],
                $tx['category'],
                $tx['recorder'] ?? '',
            ];
        }
    } else {
        $rows[] = ['（本学期暂无收支记录）', '', '', '', '', ''];
    }

    // ---- 图表配置（月度收支柱状图） ----
    $chart = null;
    if ($nMon > 0) {
        // 行号（1-based）：分类区 n 行、月度区 n 行、明细区 n 行之后为图表
        $monHeaderRow = 14 + max($nCat, 1);       // 月度表头行
        $monDataRow   = $monHeaderRow + 1;         // 月度数据首行
        $monEndRow    = $monDataRow + $nMon - 1;
        $chartTitleRow = $monEndRow + 2 + 3 + max($nDet, 1) + 1; // 明细区(空1+分组1+表头1+数据n+空1) 之后
        // 更精确：明细分组行 = $monEndRow+2（空）→ 明细表头 = +3 → 明细数据到 +3+max(nDet,1) → 图表分组 = +3+max(nDet,1)+1
        $chartTitleRow = $monEndRow + 2 + 3 + max($nDet, 1) + 1;

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
        $rows[] = ['', '', '', '', '', ''];
        $rows[] = [['v' => '四、月度收支图表', 's' => 3, 'm' => 5]];
    }

    outputStyledXlsx('学期报表_' . date('Ymd_His'), '学期报表', [12, 8, 12, 32, 10, 10], $rows, $chart);
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

// ==================== PDF 学期报表（v1.7） ====================
/** api.php?action=export_report_pdf 导出学期报表 PDF（含汇总/分类/明细，A4） */
function handleExportReportPdf() {
    requirePermission('viewTransactions');

    $d = buildReportData($_GET);
    $p = $d['period'];
    $s = $d['summary'];

    // 明细账目
    $detStmt = db()->prepare("SELECT t.date, t.type, t.amount, t.description, t.category, u.username AS recorder
        FROM transactions t LEFT JOIN users u ON t.recorded_by=u.id
        WHERE t.deleted_at IS NULL AND t.date BETWEEN :s AND :e ORDER BY t.date, t.id");
    $detStmt->execute([':s' => $p['start'], ':e' => $p['end']]);
    $details = $detStmt->fetchAll();

    require_once __DIR__ . '/fpdf.php';

    // ---- 自定义 PDF 类（页脚） ----
    class ClassFundPdf extends FPDF {
        protected $footerNote = '';
        public function setFooterNote($n) { $this->footerNote = $n; }
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', '', 8);
            $this->SetTextColor(150);
            $this->Cell(0, 10, $this->footerNote, 0, 0, 'C');
            $this->Cell(0, 10, '第 ' . $this->PageNo() . ' 页', 0, 0, 'R');
        }
        public function sectionTitle($t) {
            $this->SetFont('helvetica', 'B', 12);
            $this->SetTextColor(99, 102, 241);
            $this->Cell(0, 8, $t, 0, 1);
            $this->SetTextColor(0);
            $this->Ln(1);
        }
        public function summaryRow($label, $value, $color = null) {
            if ($color) $this->SetTextColor($color[0], $color[1], $color[2]);
            $this->SetFont('helvetica', 'B', 11);
            $this->Cell(50, 7, $label, 0, 0);
            $this->SetFont('helvetica', 'B', 11);
            $this->Cell(0, 7, $value, 0, 1);
            $this->SetTextColor(0);
        }
    }

    $pdf = new ClassFundPdf();
    $pdf->SetTitle($p['label'] . ' - 收支汇总报表');
    $pdf->AddPage();

    // 标题
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, iconv('UTF-8', 'UTF-8//IGNORE', '班级班费管理系统'), 0, 1, 'C');
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 9, iconv('UTF-8', 'UTF-8//IGNORE', $p['label'] . ' 收支汇总报表'), 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(120);
    $pdf->Cell(0, 6, '统计期间：' . $p['start'] . ' ~ ' . $p['end'] . '    生成时间：' . date('Y-m-d H:i'), 0, 1, 'C');
    $pdf->SetTextColor(0);
    $pdf->Ln(4);

    // 汇总卡片（表格形式）
    $pdf->sectionTitle('📊 收支汇总');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetFillColor(245, 246, 255);
    $pdf->Cell(63, 8, '期初余额：¥' . number_format($s['begin_balance'], 2), 1, 0, 'C', true);
    $pdf->Cell(63, 8, '总收入：¥' . number_format($s['total_income'], 2), 1, 0, 'C', true);
    $pdf->Cell(63, 8, '总支出：¥' . number_format($s['total_expense'], 2), 1, 1, 'C', true);
    $pdf->SetFont('helvetica', 'B', 11);
    $balColor = $s['balance'] >= 0 ? [16, 185, 129] : [244, 63, 94];
    $pdf->SetTextColor($balColor[0], $balColor[1], $balColor[2]);
    $pdf->Cell(63, 9, '结余：¥' . number_format($s['balance'], 2), 1, 0, 'C', true);
    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(63, 9, '收支笔数：' . $s['income_count'] . ' 收 / ' . $s['expense_count'] . ' 支', 1, 0, 'C', true);
    $pdf->Cell(63, 9, '收缴率：' . $s['collect_rate'] . '%', 1, 1, 'C', true);
    $pdf->Ln(4);

    // 分类汇总
    $pdf->sectionTitle('📁 分类汇总');
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(230, 232, 255);
    $pdf->Cell(80, 7, '分类', 1, 0, 'C', true);
    $pdf->Cell(25, 7, '类型', 1, 0, 'C', true);
    $pdf->Cell(40, 7, '笔数', 1, 0, 'C', true);
    $pdf->Cell(45, 7, '金额', 1, 1, 'C', true);
    $pdf->SetFont('helvetica', '', 9);
    foreach ($d['by_category'] as $i => $c) {
        $pdf->Cell(80, 6, iconv('UTF-8', 'UTF-8//IGNORE', $c['category']), 1);
        $pdf->Cell(25, 6, $c['type'] === 'income' ? '收入' : '支出', 1, 0, 'C');
        $pdf->Cell(40, 6, (string)$c['cnt'], 1, 0, 'C');
        $pdf->Cell(45, 6, '¥' . number_format($c['total'], 2), 1, 1, 'R');
    }
    $pdf->Ln(4);

    // 月度趋势
    if (!empty($d['by_month'])) {
        $pdf->sectionTitle('📅 月度趋势');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(230, 232, 255);
        $pdf->Cell(50, 7, '月份', 1, 0, 'C', true);
        $pdf->Cell(50, 7, '收入', 1, 0, 'C', true);
        $pdf->Cell(50, 7, '支出', 1, 0, 'C', true);
        $pdf->Cell(40, 7, '净额', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', '', 9);
        foreach ($d['by_month'] as $m) {
            $net = $m['net'];
            $pdf->Cell(50, 6, $m['month'], 1, 0, 'C');
            $pdf->Cell(50, 6, '¥' . number_format($m['income'], 2), 1, 0, 'R');
            $pdf->Cell(50, 6, '¥' . number_format($m['expense'], 2), 1, 0, 'R');
            $pdf->Cell(40, 6, ($net >= 0 ? '+' : '') . '¥' . number_format($net, 2), 1, 1, 'R');
        }
        $pdf->Ln(4);
    }

    // 收支明细
    $pdf->sectionTitle('📋 收支明细（' . count($details) . ' 笔）');
    if (empty($details)) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 8, '该期间暂无收支记录', 0, 1, 'C');
    } else {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(230, 232, 255);
        $pdf->Cell(22, 6, '日期', 1, 0, 'C', true);
        $pdf->Cell(15, 6, '类型', 1, 0, 'C', true);
        $pdf->Cell(30, 6, '金额', 1, 0, 'C', true);
        $pdf->Cell(75, 6, '描述', 1, 0, 'C', true);
        $pdf->Cell(30, 6, '分类', 1, 0, 'C', true);
        $pdf->Cell(18, 6, '记录人', 1, 1, 'C', true);
        $pdf->SetFont('helvetica', '', 8);
        foreach ($details as $i => $t) {
            // 换页保护
            if ($pdf->GetY() > 265) $pdf->AddPage();
            $pdf->Cell(22, 6, $t['date'], 1, 0, 'C');
            $pdf->Cell(15, 6, $t['type'] === 'income' ? '收' : '支', 1, 0, 'C');
            $pdf->Cell(30, 6, '¥' . number_format($t['amount'], 2), 1, 0, 'R');
            $pdf->Cell(75, 6, iconv('UTF-8', 'UTF-8//IGNORE', mb_substr($t['description'], 0, 22)), 1);
            $pdf->Cell(30, 6, iconv('UTF-8', 'UTF-8//IGNORE', mb_substr($t['category'], 0, 8)), 1);
            $pdf->Cell(18, 6, iconv('UTF-8', 'UTF-8//IGNORE', mb_substr($t['recorder'] ?? '', 0, 5)), 1, 1, 'C');
        }
    }

    $pdf->setFooterNote('班级班费管理系统 - ' . $p['label']);
    $pdf->Output('D', '收支报表_' . $p['label'] . '_' . date('Ymd') . '.pdf');
    exit;
}
