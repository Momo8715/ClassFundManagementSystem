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
            $sc   = trim($r['sub_category'] ?? '');
            $si   = trim($r['source_info'] ?? '');
            $amt  = sanitizeAmount($r['amount'] ?? 0);
            $date = $r['date'] ?? '';
            $desc = trim($r['description'] ?? '');
            $cat  = trim($r['category'] ?? ($type === 'income' ? '其他收入' : '其他支出'));

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
