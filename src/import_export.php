<?php
/**
 * 班级班费管理系统 - 导入导出模块
 * CSV 导入、xlsx 导入、xlsx 导出、图片上传
 */

// ==================== 批量导入（JSON） ====================
function handleImport() {
    requirePermission('importData');
    requireCsrfToken();

    $input = jsonInput();
    $records = $input['records'] ?? [];
    if (empty($records)) jsonOutput(['error' => '没有可导入的数据'], 400);

    $user = currentUser();
    $stmt = db()->prepare("INSERT INTO transactions (type, sub_category, source_info, amount, date, description, category, recorded_by) VALUES (:t, :sc, :si, :a, :d, :desc, :cat, :rb)");
    $count = 0;
    $errors = [];

    db()->beginTransaction();
    try {
        foreach ($records as $i => $r) {
            $type = ($r['type'] ?? '') === 'expense' ? 'expense' : 'income';
            $sc   = mb_substr(trim($r['sub_category'] ?? ''), 0, 50);
            $si   = mb_substr(trim($r['source_info'] ?? ''), 0, 500);
            $amt  = sanitizeAmount($r['amount'] ?? 0);
            $date = $r['date'] ?? '';
            $desc = mb_substr(trim($r['description'] ?? ''), 0, 500);
            $cat  = mb_substr(trim($r['category'] ?? ($type === 'income' ? '其他收入' : '其他支出')), 0, 100);

            if ($amt <= 0 || !isValidDate($date)) {
                $errors[] = "第 " . ($i + 1) . " 行数据无效";
                continue;
            }
            $stmt->execute([
                ':t'    => $type,
                ':sc'   => $sc ?: null,
                ':si'   => ($sc === '其他来源') ? $si : null,
                ':a'    => $amt,
                ':d'    => $date,
                ':desc' => $desc,
                ':cat'  => $cat,
                ':rb'   => $user['id']
            ]);
            $count++;
        }
        db()->commit();
    } catch (\Exception $e) {
        db()->rollBack();
        securityLog('import_error', ['error' => $e->getMessage()]);
        jsonOutput(['error' => '导入失败：' . $e->getMessage()], 500);
    }

    addLog($user['id'], $user['username'], 'import', 'transaction', null, [
        'count' => $count, 'errors' => $errors
    ]);

    jsonOutput(['ok' => true, 'imported' => $count, 'errors' => $errors]);
}

// ==================== 图片上传 ====================
function handleUploadImage() {
    requirePermission('addTransaction');
    requireCsrfToken();

    if (empty($_FILES['image'])) {
        jsonOutput(['error' => '没有上传文件'], 400);
    }

    $file = $_FILES['image'];

    // 使用真实 MIME 检测（修复：之前仅检查扩展名）
    $error = validateImageUpload($file);
    if ($error !== null) {
        securityLog('invalid_image_upload', ['error' => $error, 'filename' => $file['name'] ?? 'unknown']);
        jsonOutput(['error' => $error], 400);
    }

    // 通过 MIME 确定安全扩展名
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $ext = safeExtension($mime);

    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $newName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $newName)) {
        jsonOutput(['error' => '保存失败，请检查 uploads 目录权限'], 500);
    }

    jsonOutput(['ok' => true, 'path' => 'uploads/' . $newName]);
}

// ==================== 下载模板 ====================
function handleDownloadTemplate() {
    requirePermission('importData');
    outputSimpleXlsx('班费导入模板', '导入模板',
        ['类型', '子分类', '来源信息', '金额', '日期', '描述', '分类'],
        [['收入', '班费收缴', '', '5000', '2025-03-01', '（示例）开学收取班费', '班费']]
    );
    exit;
}

// ==================== xlsx 上传解析 ====================
function handleUploadXlsx() {
    requirePermission('importData');

    if (empty($_FILES['xlsx'])) {
        jsonOutput(['error' => '请选择 xlsx 文件'], 400);
    }
    if (!class_exists('ZipArchive')) {
        jsonOutput(['error' => '服务器未安装 PHP Zip 扩展，请在宝塔 PHP 设置中启用'], 500);
    }

    $tmp = $_FILES['xlsx']['tmp_name'];
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        jsonOutput(['error' => '无法读取文件，请确保是 xlsx 格式'], 400);
    }

    // 解析 SharedStrings
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
    if (!$sheet) {
        $zip->close();
        jsonOutput(['error' => '工作表为空'], 400);
    }
    $xml = simplexml_load_string($sheet);
    $zip->close();
    if (!$xml || !isset($xml->sheetData->row)) {
        jsonOutput(['error' => '工作表无数据'], 400);
    }

    // 逐行解析
    $rows = [];
    $firstRow = true;
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $v = isset($c->v) ? (string)$c->v : '';
            if ((string)$c['t'] === 's' && isset($strings[(int)$v])) $v = $strings[(int)$v];
            $cells[] = trim($v);
        }
        if (empty($cells)) continue;

        // 跳过表头行
        if ($firstRow) {
            $firstRow = false;
            if (stripos($cells[0] ?? '', '类型') !== false || stripos($cells[0] ?? '', 'type') !== false) continue;
        }

        $typeStr = $cells[0] ?? '';
        $type = stripos($typeStr, '收入') !== false ? 'income' : (stripos($typeStr, '支出') !== false ? 'expense' : null);
        if (!$type) continue;

        $subCat = '';
        $srcInfo = '';
        $amount = 0;
        $date = '';
        $desc = '';
        $cat = '';

        // 智能检测格式：第2列是数字 = 旧格式，否则 = 新格式
        if (isset($cells[1]) && is_numeric($cells[1]) && floatval($cells[1]) > 0) {
            $amount = floatval($cells[1]);
            $date = $cells[2] ?? '';
            $desc = $cells[3] ?? '';
            $cat = $cells[4] ?? '';
        } else {
            $subCat  = $cells[1] ?? '';
            $srcInfo = $cells[2] ?? '';
            $amount  = floatval($cells[3] ?? 0);
            $date    = $cells[4] ?? '';
            $desc    = $cells[5] ?? '';
            $cat     = $cells[6] ?? '';
        }

        if ($amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        // 跳过示例行
        if (stripos($desc, '示例') !== false && $amount === 5000.0) continue;
        $desc = str_replace(['（示例）', '(示例)'], '', $desc);

        $rows[] = [
            'type'        => $type,
            'sub_category' => $subCat,
            'source_info' => $srcInfo,
            'amount'      => $amount,
            'date'        => $date,
            'description' => trim($desc),
            'category'    => $cat ?: ($type === 'income' ? '其他收入' : '其他支出'),
        ];
    }

    $doImport = ($_POST['do_import'] ?? '') === '1';
    if ($doImport && !empty($rows)) {
        requireCsrfToken();
        $user = currentUser();
        $stmt = db()->prepare("INSERT INTO transactions (type, sub_category, source_info, amount, date, description, category, recorded_by) VALUES (:t, :sc, :si, :a, :d, :desc, :cat, :rb)");
        $count = 0;
        db()->beginTransaction();
        try {
            foreach ($rows as $r) {
                $stmt->execute([
                    ':t' => $r['type'], ':sc' => $r['sub_category'] ?: null,
                    ':si' => $r['source_info'] ?: null, ':a' => $r['amount'],
                    ':d' => $r['date'], ':desc' => $r['description'],
                    ':cat' => $r['category'], ':rb' => $user['id']
                ]);
                $count++;
            }
            db()->commit();
        } catch (\Exception $e) {
            db()->rollBack();
            jsonOutput(['error' => '导入失败：' . $e->getMessage()], 500);
        }
        addLog($user['id'], $user['username'], 'import_xlsx', 'transaction', null, ['count' => $count]);
        jsonOutput(['ok' => true, 'imported' => $count, 'preview' => $rows]);
    }

    jsonOutput(['ok' => true, 'preview' => $rows, 'count' => count($rows)]);
}

// ==================== 导出收支明细 ====================
function handleExport() {
    requirePermission('viewTransactions');

    ob_clean();

    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo   = $_GET['date_to'] ?? '';

    $sql = "SELECT t.*, u.username as recorder_name FROM transactions t LEFT JOIN users u ON t.recorded_by=u.id WHERE t.deleted_at IS NULL";
    $params = [];

    if ($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $sql .= " AND t.date >= :from";
        $params[':from'] = $dateFrom;
    }
    if ($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $sql .= " AND t.date <= :to";
        $params[':to'] = $dateTo;
    }
    $sql .= " ORDER BY t.date ASC, t.id ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $totalIn = 0;
    $totalOut = 0;
    foreach ($rows as $r) {
        if ($r['type'] === 'income') $totalIn += $r['amount'];
        else $totalOut += $r['amount'];
    }

    $filename = '班费明细';
    if ($dateFrom) $filename .= '_' . $dateFrom;
    if ($dateTo)   $filename .= '_' . $dateTo;

    $exportRows = [
        ['班费收支明细', '', '', '', '', '', '', '', ''],
        ['总收入', number_format($totalIn, 2, '.', ''), '', '', '', '', '', '', ''],
        ['总支出', number_format($totalOut, 2, '.', ''), '', '', '', '', '', '', ''],
        ['结余', number_format($totalIn - $totalOut, 2, '.', ''), '', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', ''],
    ];
    foreach ($rows as $i => $r) {
        $exportRows[] = [
            $i + 1,
            $r['date'],
            $r['type'] === 'income' ? '收入' : '支出',
            $r['sub_category'] ?? '',
            $r['source_info'] ?? '',
            number_format($r['amount'], 2, '.', ''),
            $r['description'],
            $r['category'],
            $r['recorder_name'] ?? ''
        ];
    }

    outputSimpleXlsx(
        $filename,
        '收支明细',
        ['序号', '日期', '类型', '子分类', '来源信息', '金额', '描述', '分类', '记录人'],
        $exportRows
    );
    exit;
}

// ==================== 收据导出 ====================

/** 人民币金额大写转换 */
function amountToChinese(float $amount): string {
    $digits = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
    $units = ['','拾','佰','仟'];
    $bigUnits = ['','万','亿'];
    $amount = round($amount, 2);
    $intPart = (int)floor($amount);
    $decPart = (int)round(($amount - $intPart) * 100);
    if ($amount == 0) return '零元整';
    $intStr = (string)$intPart;
    $len = strlen($intStr);
    $result = '';
    $zeroFlag = false;
    $unitPos = 0;
    for ($i = 0; $i < $len; $i++) {
        $digit = (int)$intStr[$i];
        $pos = $len - 1 - $i;
        if ($digit === 0) {
            $zeroFlag = true;
            if ($pos % 4 === 0 && $pos > 0) { $result .= $bigUnits[(int)($pos / 4)]; $zeroFlag = false; }
        } else {
            if ($zeroFlag) { $result .= '零'; $zeroFlag = false; }
            $result .= $digits[$digit] . $units[$pos % 4] . (($pos % 4 === 0 && $pos > 0) ? $bigUnits[(int)($pos / 4)] : '');
        }
    }
    $result .= '元';
    if ($decPart === 0) {
        $result .= '整';
    } else {
        $jiao = (int)($decPart / 10); $fen = $decPart % 10;
        if ($jiao > 0) $result .= $digits[$jiao] . '角';
        elseif ($fen > 0) $result .= '零';
        if ($fen > 0) $result .= $digits[$fen] . '分';
    }
    return $result;
}

/** 生成打印友好收据页（浏览器打印/另存 PDF） */
function handleReceipt() {
    requirePermission('viewTransactions');

    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) jsonOutput(['error' => '无效 ID'], 400);

    $stmt = db()->prepare("SELECT t.*, u.username AS recorder_name FROM transactions t LEFT JOIN users u ON t.recorded_by=u.id WHERE t.id=:id AND t.deleted_at IS NULL");
    $stmt->execute([':id' => $id]);
    $tx = $stmt->fetch();
    if (!$tx) jsonOutput(['error' => '记录不存在'], 404);

    $isIncome = $tx['type'] === 'income';
    $title = $isIncome ? '收 费 收 据' : '支 出 凭 证';
    $amountCN = amountToChinese((float)$tx['amount']);

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>' . $title . '</title>
    <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:"SimSun","Songti SC",serif; background:#f0f0f0; }
    .page { width:210mm; min-height:140mm; margin:20px auto; background:#fff; padding:24mm 20mm; box-shadow:0 2px 12px rgba(0,0,0,.15); position:relative; }
    h1 { text-align:center; font-size:26px; letter-spacing:8px; margin-bottom:18px; font-weight:700; }
    .no { text-align:right; font-size:13px; margin-bottom:14px; color:#333; }
    .no b { font-size:15px; }
    table { width:100%; border-collapse:collapse; margin:8px 0 16px; }
    td { border:1px solid #333; padding:10px 12px; font-size:15px; line-height:1.7; }
    td.label { width:92px; background:#fafafa; text-align:center; font-weight:600; white-space:nowrap; }
    .amount { font-size:19px; font-weight:700; letter-spacing:1px; }
    .cn { font-size:14px; }
    .footer { margin-top:26px; display:flex; justify-content:space-between; font-size:14px; }
    .footer .box { text-align:center; }
    .footer .line { width:120px; border-bottom:1px solid #333; height:22px; }
    .footer .cap { margin-top:4px; font-size:12px; color:#555; }
    .print-btn { display:block; margin:16px auto 40px; padding:10px 36px; font-size:15px; cursor:pointer; }
    @media print { body { background:#fff; } .page { box-shadow:none; margin:0; width:auto; min-height:auto; } .print-btn { display:none; } }
    </style></head><body>
    <div class="page">
        <h1>' . $title . '</h1>
        <div class="no">编号：<b>CF-' . str_pad((string)$tx['id'], 6, '0', STR_PAD_LEFT) . '</b></div>
        <table>
            <tr><td class="label">日期</td><td>' . htmlspecialchars($tx['date']) . '</td><td class="label">类型</td><td>' . ($isIncome ? '收入（缴款）' : '支出') . '</td></tr>
            <tr><td class="label">金额（大写）</td><td colspan="3" class="cn">' . htmlspecialchars($amountCN) . '</td></tr>
            <tr><td class="label">金额（小写）</td><td colspan="3" class="amount">¥ ' . number_format((float)$tx['amount'], 2, '.', ',') . '</td></tr>
            <tr><td class="label">事由</td><td colspan="3">' . htmlspecialchars($tx['description']) . '</td></tr>
            <tr><td class="label">分类</td><td>' . htmlspecialchars($tx['category']) . ($tx['sub_category'] ? ' / ' . htmlspecialchars($tx['sub_category']) : '') . '</td><td class="label">经办人</td><td>' . htmlspecialchars($tx['recorder_name'] ?? '') . '</td></tr>
        </table>
        <div class="footer">
            <div class="box"><div class="line"></div><div class="cap">缴款 / 经手人</div></div>
            <div class="box"><div class="line"></div><div class="cap">财务委员</div></div>
            <div class="box"><div class="line"></div><div class="cap">班主任</div></div>
        </div>
    </div>
    <button class="print-btn" onclick="window.print()">🖨️ 打印 / 另存为 PDF</button>
    </body></html>';
    exit;
}

// ==================== xlsx 生成器 ====================
function outputSimpleXlsx(string $filename, string $sheetName, array $headers, array $rows) {
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) return false;

    $strings = [];
    $smap = [];
    $addStr = function ($v) use (&$strings, &$smap) {
        $v = (string)$v;
        if (!isset($smap[$v])) {
            $smap[$v] = count($strings);
            $strings[] = $v;
        }
        return $smap[$v];
    };

    $rowsXML = '<row>';
    foreach ($headers as $h) $rowsXML .= '<c t="s"><v>' . $addStr($h) . '</v></c>';
    $rowsXML .= '</row>';
    foreach ($rows as $cells) {
        $rowsXML .= '<row>';
        foreach ($cells as $c) $rowsXML .= '<c t="s"><v>' . $addStr($c) . '</v></c>';
        $rowsXML .= '</row>';
    }

    $ssXML = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) $ssXML .= '<si><t>' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</t></si>';
    $ssXML .= '</sst>';

    $sheetXML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rowsXML . '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . htmlspecialchars($sheetName, ENT_QUOTES) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXML);
    $zip->addFromString('xl/sharedStrings.xml', $ssXML);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache');
    readfile($tmpFile);
    unlink($tmpFile);
    return true;
}

// ==================== 带样式 xlsx 生成器（列宽/合并/边框/图表） ====================

/**
 * 单元格样式索引：
 * 0 默认 | 1 标题(16pt加粗居中) | 2 副标题(10pt灰居中) | 3 分组标题(加粗浅灰底)
 * 4 表头(加粗蓝底边框居中) | 5 数据(边框) | 6 加粗数据(边框) | 7 金额(边框右对齐)
 */
function xlsxStylesXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<fonts count="5">' .
    '<font><sz val="11"/><name val="宋体"/></font>' .
    '<font><b/><sz val="16"/><name val="宋体"/></font>' .
    '<font><sz val="10"/><color rgb="FF808080"/><name val="宋体"/></font>' .
    '<font><b/><sz val="11"/><name val="宋体"/></font>' .
    '<font><b/><sz val="10"/><name val="宋体"/></font>' .
    '</fonts>' .
    '<fills count="4">' .
    '<fill><patternFill patternType="none"/></fill>' .
    '<fill><patternFill patternType="gray125"/></fill>' .
    '<fill><patternFill patternType="solid"><fgColor rgb="FFDCE6F1"/></patternFill></fill>' .
    '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill>' .
    '</fills>' .
    '<borders count="2">' .
    '<border><left/><right/><top/><bottom/><diagonal/></border>' .
    '<border><left style="thin"><color rgb="FF808080"/></left><right style="thin"><color rgb="FF808080"/></right><top style="thin"><color rgb="FF808080"/></top><bottom style="thin"><color rgb="FF808080"/></bottom><diagonal/></border>' .
    '</borders>' .
    '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
    '<cellXfs count="8">' .
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
    '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center" vertical="center"/></xf>' .
    '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="center"/></xf>' .
    '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment horizontal="left"/></xf>' .
    '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>' .
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="center"/></xf>' .
    '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="center"/></xf>' .
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>' .
    '</cellXfs>' .
    '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
    '</styleSheet>';
}

/**
 * 生成带样式的 xlsx 文件并输出下载。
 *
 * @param string $filename 下载文件名（不含扩展名）
 * @param string $sheetName 工作表名
 * @param array  $colWidths 列宽数组，如 [28, 18, 18, 22]
 * @param array  $rows      行数组；每行元素为：
 *                          - string：普通文本（默认样式）
 *                          - ['v'=>文本, 's'=>样式索引, 'm'=>向右合并的额外列数]
 * @param array|null $chart 图表配置（可选）：
 *                          ['title'=>标题, 'from'=>[col,row], 'to'=>[col,row],
 *                           'series'=>[['name'=>, 'catRef'=>, 'valRef'=>, 'cats'=>[], 'vals'=>[]], ...]]
 */
function outputStyledXlsx(string $filename, string $sheetName, array $colWidths, array $rows, ?array $chart = null): bool {
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) return false;

    // ---- 工作表 XML ----
    $hasChart = $chart !== null;
    $colsXml = '<cols>';
    foreach ($colWidths as $i => $w) $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $colsXml .= '</cols>';

    $rowsXml = '';
    $merges = [];
    foreach ($rows as $rIdx => $row) {
        $rowNum = $rIdx + 1;
        $rowsXml .= '<row r="' . $rowNum . '">';
        $colIdx = 0;
        foreach ($row as $cell) {
            $colNum = $colIdx + 1;
            $ref = chr(64 + $colNum) . $rowNum;
            if (is_array($cell)) {
                $val = (string)($cell['v'] ?? '');
                $style = (int)($cell['s'] ?? 0);
                $span = (int)($cell['m'] ?? 0);
                if ($span > 0) {
                    $endCol = chr(64 + $colNum + $span);
                    $merges[] = $ref . ':' . $endCol . $rowNum;
                }
            } else {
                $val = (string)$cell;
                $style = 0;
                $span = 0;
            }
            $val = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $rowsXml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $val . '</t></is></c>';
            $colIdx += 1 + $span;
        }
        $rowsXml .= '</row>';
    }
    $mergeXml = empty($merges) ? '' : '<mergeCells count="' . count($merges) . '">' . implode('', array_map(function ($m) { return '<mergeCell ref="' . $m . '"/>'; }, $merges)) . '</mergeCells>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        $colsXml . '<sheetData>' . $rowsXml . '</sheetData>' . $mergeXml .
        ($hasChart ? '<drawing r:id="rId1"/>' : '') .
        '<pageMargins left="0.5" right="0.5" top="0.7" bottom="0.7" header="0.3" footer="0.3"/>' .
        '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>' .
        '</worksheet>';

    // ---- 图表 XML（柱状图） ----
    $chartXml = '';
    if ($hasChart) {
        $serXml = '';
        foreach ($chart['series'] as $si => $s) {
            $cats = $s['cats'] ?? [];
            $vals = $s['vals'] ?? [];
            $catPts = '';
            foreach ($cats as $ci => $cv) $catPts .= '<c:pt idx="' . $ci . '"><c:v>' . htmlspecialchars((string)$cv, ENT_XML1) . '</c:v></c:pt>';
            $valPts = '';
            foreach ($vals as $vi => $vv) $valPts .= '<c:pt idx="' . $vi . '"><c:v>' . $vv . '</c:v></c:pt>';
            $serXml .= '<c:ser><c:idx val="' . $si . '"/><c:order val="' . $si . '"/>' .
                '<c:tx><c:strRef><c:f>' . $sheetName . '!' . $s['nameRef'] . '</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>' . htmlspecialchars((string)$s['name'], ENT_XML1) . '</c:v></c:pt></c:strCache></c:strRef></c:tx>' .
                '<c:cat><c:strRef><c:f>' . $sheetName . '!' . $s['catRef'] . '</c:f><c:strCache><c:ptCount val="' . count($cats) . '"/>' . $catPts . '</c:strCache></c:strRef></c:cat>' .
                '<c:val><c:numRef><c:f>' . $sheetName . '!' . $s['valRef'] . '</c:f><c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="' . count($vals) . '"/>' . $valPts . '</c:numCache></c:numRef></c:val>' .
                '</c:ser>';
        }
        $chartXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<c:chart>' .
            '<c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="12" b="1"/></a:pPr><a:r><a:rPr lang="zh-CN" sz="12" b="1"/><a:t>' . htmlspecialchars((string)($chart['title'] ?? '图表'), ENT_XML1) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>' .
            '<c:autoTitleDeleted val="0"/>' .
            '<c:plotArea><c:layout/>' .
            '<c:barChart><c:barDir val="col"/><c:grouping val="clustered"/><c:varyColors val="0"/>' . $serXml .
            '<c:axId val="123456789"/><c:axId val="987654321"/></c:barChart>' .
            '<c:catAx><c:axId val="123456789"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/><c:crossAx val="987654321"/></c:catAx>' .
            '<c:valAx><c:axId val="987654321"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:crossAx val="123456789"/></c:valAx>' .
            '</c:plotArea>' .
            '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>' .
            '<c:plotVisOnly val="1"/>' .
            '</c:chart></c:chartSpace>';
    }

    // ---- 绘图 XML ----
    $drawingXml = '';
    if ($hasChart) {
        $from = $chart['from'];
        $to = $chart['to'];
        $drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">' .
            '<xdr:twoCellAnchor editAs="oneCell">' .
            '<xdr:from><xdr:col>' . $from[0] . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $from[1] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>' .
            '<xdr:to><xdr:col>' . $to[0] . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $to[1] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>' .
            '<xdr:graphicFrame macro="">' .
            '<xdr:nvGraphicFramePr><xdr:cNvPr id="2" name="Chart 1"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>' .
            '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>' .
            '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">' .
            '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId1"/>' .
            '</a:graphicData></a:graphic>' .
            '</xdr:graphicFrame><xdr:clientData/>' .
            '</xdr:twoCellAnchor></xdr:wsDr>';
    }

    // ---- 打包 ----
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        ($hasChart ? '<Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '') .
        '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_QUOTES) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->addFromString('xl/styles.xml', xlsxStylesXml());
    if ($hasChart) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>' .
            '</Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', $drawingXml);
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/>' .
            '</Relationships>');
        $zip->addFromString('xl/charts/chart1.xml', $chartXml);
    }
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache');
    readfile($tmpFile);
    unlink($tmpFile);
    return true;
}
