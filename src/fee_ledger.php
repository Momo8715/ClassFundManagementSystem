<?php
/**
 * 班级班费管理系统 - 统一费用账本
 *
 * 全站「应缴 / 已缴 / 待缴」只允许从这里计算，避免缴费页、催缴、QQ 机器人、
 * 报表、数据体检各算一套导致口径漂移。
 *
 * 口径（v1.12 起统一）：
 *   应缴 = 各轮「每人应缴」相加（per_person 优先；无显式值时按 应收总额/非免缴人数、
 *          或 实收金额/缴费人数 兜底），单轮免缴（exempt_ids）不计入该轮；
 *          永久免缴学生应缴为 0。
 *   已缴 = 线下该轮缴费名单（payer_ids = all 或含该生）按每人应缴计入
 *          + 未并入轮次的在线核销金额（fee_payments.tx_id 为空或指向非班费轮次）。
 *   待缴 = max(0, 应缴 - 已缴)。
 */

/** 花名册（默认按拼音排序；带 id/name/exempt） */
function feeRoster(): array {
    return db()->query("SELECT id, name, exempt FROM class_roster ORDER BY sort_key, id")->fetchAll();
}

/** 非免缴人数 */
function feeEligibleCount(?array $roster = null): int {
    if ($roster === null) return (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
    $n = 0;
    foreach ($roster as $s) if (empty($s['exempt'])) $n++;
    return $n;
}

/**
 * 某轮班费收缴的「每人应缴」。
 * 显式 per_person 优先；兼容旧数据：应收总额/非免缴人数 → 实收金额/缴费人数。
 */
function feeRoundPerPerson(array $tx, ?int $eligible = null): float {
    if ($eligible === null) $eligible = feeEligibleCount();
    if (isset($tx['per_person']) && $tx['per_person'] !== null && (float)$tx['per_person'] > 0) {
        return round((float)$tx['per_person'], 2);
    }
    $exp = (float)($tx['expected_amount'] ?? 0);
    if ($exp > 0 && $eligible > 0) return round($exp / $eligible, 2);
    $pids = json_decode($tx['payer_ids'] ?? '', true) ?: [];
    if (is_array($pids) && count($pids) > 0) return round((float)$tx['amount'] / count($pids), 2);
    return 0.0;
}

/** 兼容旧函数名（历史调用方较多，保留薄包装） */
function payRoundPerPerson(array $tx, int $eligible = -1): float {
    return feeRoundPerPerson($tx, $eligible < 0 ? null : $eligible);
}

/** 解析一轮的缴费名单 / 免缴名单 */
function feeParseRound(array $tx, int $eligible): array {
    $pids = [];
    if ((string)($tx['payer_ids'] ?? '') !== 'all') {
        $decoded = json_decode($tx['payer_ids'] ?? '', true);
        if (is_array($decoded)) $pids = array_values(array_filter(array_map('intval', $decoded)));
    }
    $exids = json_decode($tx['exempt_ids'] ?? '', true);
    if (!is_array($exids)) $exids = [];
    $exids = array_values(array_filter(array_map('intval', $exids)));
    return [
        'id'    => (int)($tx['id'] ?? 0),
        'pp'    => feeRoundPerPerson($tx, $eligible),
        'all'   => ((string)($tx['payer_ids'] ?? '') === 'all'),
        'pids'  => $pids,
        'exids' => $exids,
    ];
}

/**
 * 单轮明细：已缴 / 未缴 / 本轮免缴。
 * 返回结构兼容历史接口（paid_list / unpaid_list / exempt_list / *_count）。
 */
function feeRoundDetail(array $tx, ?array $roster = null, ?int $eligible = null): array {
    $roster = $roster ?? feeRoster();
    $eligible = $eligible ?? feeEligibleCount($roster);
    $parsed = feeParseRound($tx, $eligible);
    $pp = $parsed['pp'];
    $exids = $parsed['exids'];
    $paid = [];
    $unpaid = [];
    $exempt = [];

    $byId = [];
    foreach ($roster as $s) $byId[(int)$s['id']] = $s;

    foreach ($exids as $sid) {
        if (isset($byId[$sid])) $exempt[] = ['id' => $sid, 'name' => $byId[$sid]['name'], 'amount' => 0];
    }

    if ($parsed['all']) {
        foreach ($roster as $s) {
            if (!empty($s['exempt'])) continue;
            if (in_array((int)$s['id'], $exids, true)) continue;
            $paid[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'amount' => $pp];
        }
    } elseif (!empty($parsed['pids'])) {
        foreach ($parsed['pids'] as $sid) {
            if (isset($byId[$sid])) $paid[] = ['id' => $sid, 'name' => $byId[$sid]['name'], 'amount' => $pp];
        }
        foreach ($roster as $s) {
            $sid = (int)$s['id'];
            if (!empty($s['exempt'])) continue;
            if (in_array($sid, $exids, true)) continue;
            if (!in_array($sid, $parsed['pids'], true)) $unpaid[] = ['id' => $sid, 'name' => $s['name'], 'amount' => 0];
        }
    } else {
        foreach ($roster as $s) {
            $sid = (int)$s['id'];
            if (!empty($s['exempt'])) continue;
            if (in_array($sid, $exids, true)) continue;
            $unpaid[] = ['id' => $sid, 'name' => $s['name'], 'amount' => 0];
        }
    }

    return [
        'id'           => (int)($tx['id'] ?? 0),
        'date'         => (string)($tx['date'] ?? ''),
        'description'  => (string)($tx['description'] ?? ''),
        'amount'       => round((float)($tx['amount'] ?? 0), 2),
        'per_person'   => $pp,
        'paid_list'    => $paid,
        'unpaid_list'  => $unpaid,
        'exempt_list'  => $exempt,
        'paid_count'   => count($paid),
        'unpaid_count' => count($unpaid),
        'exempt_count' => count($exempt),
        'paid_total'   => round(array_sum(array_column($paid, 'amount')), 2),
    ];
}

/** 所有班费收缴轮次（倒序展示用） */
function feeRounds(bool $details = false): array {
    $rows = db()->query("SELECT * FROM transactions
        WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL
        ORDER BY date DESC, id DESC")->fetchAll();
    if (!$details) return $rows;
    $roster = feeRoster();
    $eligible = feeEligibleCount($roster);
    $out = [];
    foreach ($rows as $tx) $out[] = feeRoundDetail($tx, $roster, $eligible);
    return $out;
}

/** 全班每个学生的应缴/已缴/待缴（含逐轮明细） */
function feeAllStatuses(?array $roster = null, ?array $rounds = null): array {
    $roster = $roster ?? feeRoster();
    $eligible = feeEligibleCount($roster);
    if ($rounds === null) {
        $rounds = db()->query("SELECT id, amount, expected_amount, per_person, payer_ids, exempt_ids
            FROM transactions WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL
            ORDER BY date ASC, id ASC")->fetchAll();
    }
    $parsed = [];
    foreach ($rounds as $r) $parsed[] = feeParseRound($r, $eligible);

    $online = [];
    try {
        foreach (db()->query(payOnlineUnattributedSql())->fetchAll() as $r) {
            $sid = (int)$r['student_id'];
            if ($sid > 0) $online[$sid] = round((float)$r['v'], 2);
        }
    } catch (Exception $e) { /* 表缺失时不影响主流程 */ }

    $out = [];
    foreach ($roster as $s) {
        $sid = (int)$s['id'];
        $exempt = !empty($s['exempt']);
        $due = 0.0;
        $paid = $online[$sid] ?? 0.0;
        $perRound = [];
        foreach ($parsed as $p) {
            if (in_array($sid, $p['exids'], true)) {
                $perRound[$p['id']] = ['due' => 0.0, 'paid' => 0.0, 'exempt' => true];
                continue;
            }
            if ($exempt) {
                $perRound[$p['id']] = ['due' => 0.0, 'paid' => 0.0, 'exempt' => true];
                continue;
            }
            $due += $p['pp'];
            $isPaid = $p['all'] || in_array($sid, $p['pids'], true);
            if ($isPaid) $paid += $p['pp'];
            $perRound[$p['id']] = ['due' => round($p['pp'], 2), 'paid' => $isPaid ? round($p['pp'], 2) : 0.0, 'exempt' => false];
        }
        $due = round($due, 2);
        $paid = round($paid, 2);
        $out[$sid] = [
            'id'          => $sid,
            'name'        => $s['name'],
            'exempt'      => $exempt,
            'due'         => $due,
            'paid'        => $paid,
            'outstanding' => round(max(0, $due - $paid), 2),
            'per_round'   => $perRound,
        ];
    }
    return $out;
}

/** 兼容旧函数名 */
function payAllFeeStatus(): array {
    return feeAllStatuses();
}

/** 某个学生的 应缴/已缴/待缴 */
function feeStudentStatus(int $studentId): array {
    $all = feeAllStatuses();
    return $all[$studentId] ?? ['id' => $studentId, 'due' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
}

/** 兼容旧函数名 */
function payStudentStatus(int $studentId): array {
    return feeStudentStatus($studentId);
}

/** 累计「每人应缴」：各轮显式 per_person 相加；无显式值时按旧逻辑兜底 */
function feePerPersonTotal(array $rounds, ?array $roster = null): float {
    $sum = 0.0;
    $has = false;
    foreach ($rounds as $r) {
        if (isset($r['per_person']) && $r['per_person'] !== null && (float)$r['per_person'] > 0) {
            $sum += (float)$r['per_person'];
            $has = true;
        }
    }
    if ($has) return round($sum, 2);

    $pp = round((float)getMeta('per_person', '0'), 2);
    if ($pp > 0) return $pp;

    $roster = $roster ?? feeRoster();
    $nonExempt = feeEligibleCount($roster);
    if ($nonExempt <= 0) return 0.0;

    $last = db()->query("SELECT expected_amount FROM transactions
        WHERE type='income' AND sub_category='班费收缴' AND expected_amount IS NOT NULL AND deleted_at IS NULL
        ORDER BY id DESC LIMIT 1")->fetch();
    if ($last && (float)$last['expected_amount'] > 0) return round((float)$last['expected_amount'] / $nonExempt, 2);

    $last = db()->query("SELECT amount, payer_ids FROM transactions
        WHERE type='income' AND sub_category='班费收缴' AND payer_ids IS NOT NULL AND payer_ids != '' AND deleted_at IS NULL
        ORDER BY id DESC LIMIT 1")->fetch();
    if ($last) {
        if ((string)$last['payer_ids'] === 'all') {
            return round((float)$last['amount'] / $nonExempt, 2);
        }
        $pids = json_decode($last['payer_ids'], true) ?: [];
        if (count($pids) > 0) return round((float)$last['amount'] / count($pids), 2);
    }
    return 0.0;
}

/**
 * 缴费情况总览（缴费页 / 导出欠费名单共用）。
 * 返回字段保持历史接口形状。
 */
function feeSummary(): array {
    $roster = feeRoster();
    $eligible = feeEligibleCount($roster);
    $rounds = db()->query("SELECT * FROM transactions
        WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL
        ORDER BY date DESC, id DESC")->fetchAll();
    // 计算用升序，展示用倒序
    $roundsAsc = array_reverse($rounds);
    $statuses = feeAllStatuses($roster, $roundsAsc);

    $details = [];
    $totalCollected = 0.0;
    foreach ($rounds as $tx) {
        $totalCollected += (float)$tx['amount'];
        $details[] = feeRoundDetail($tx, $roster, $eligible);
    }

    $perPerson = feePerPersonTotal($rounds, $roster);
    $nonExempt = $eligible;
    $totalExpected = round($perPerson * $nonExempt, 2);

    // 最近一轮的单次免缴学生：不计入本轮催缴（历史行为）
    $lastExempt = [];
    if (!empty($rounds)) {
        $decoded = json_decode($rounds[0]['exempt_ids'] ?? '', true);
        if (is_array($decoded)) $lastExempt = array_map('intval', $decoded);
    }

    $paid = [];
    $unpaid = [];
    $onlinePaid = [];
    foreach (db()->query(payOnlineUnattributedSql())->fetchAll() as $fp) {
        $sid = (int)$fp['student_id'];
        if ($sid > 0) $onlinePaid[$sid] = round((float)$fp['v'], 2);
    }
    foreach ($statuses as $sid => $st) {
        if (!empty($st['exempt'])) continue;
        if (in_array($sid, $lastExempt, true)) continue;
        $item = ['id' => $sid, 'name' => $st['name'], 'paid' => $st['paid'], 'exempt' => false];
        if ($perPerson > 0) {
            if ($st['paid'] >= $perPerson * 0.99) $paid[] = $item;
            else $unpaid[] = $item;
        } else {
            if ($st['paid'] > 0) $paid[] = $item;
            else $unpaid[] = $item;
        }
    }

    return [
        'roster_count'    => count($roster),
        'total_collected' => round($totalCollected, 2),
        'total_expected'  => $totalExpected,
        'per_person'      => $perPerson,
        'paid_count'      => count($paid),
        'unpaid_count'    => count($unpaid),
        'paid_list'       => $paid,
        'unpaid_list'     => $unpaid,
        'roster'          => $roster,
        'rounds'          => $details,
        'online_paid'     => $onlinePaid,
        'statuses'        => $statuses,
    ];
}

/**
 * 欠费导出明细（与缴费页同一口径）。
 * 返回 [表头, 行数组]。
 */
function feeUnpaidExportRows(): array {
    $summary = feeSummary();
    $perPerson = (float)$summary['per_person'];
    $last = db()->query("SELECT exempt_ids FROM transactions
        WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL
        ORDER BY date DESC, id DESC LIMIT 1")->fetch();
    $lastExempt = [];
    if ($last) {
        $decoded = json_decode($last['exempt_ids'] ?? '', true);
        if (is_array($decoded)) $lastExempt = array_map('intval', $decoded);
    }

    $rows = [];
    $totalOwe = 0.0;
    $totalPaidOfOwe = 0.0;
    foreach ($summary['statuses'] as $sid => $st) {
        if (!empty($st['exempt'])) continue;
        if (in_array($sid, $lastExempt, true)) continue;
        $paid = round((float)$st['paid'], 2);
        $isOwe = $perPerson > 0 ? $paid < $perPerson * 0.99 : $paid <= 0;
        if (!$isOwe) continue;
        $owe = $perPerson > 0 ? round(max(0, $perPerson - $paid), 2) : 0;
        $rows[] = [
            (string)(count($rows) + 1),
            $st['name'],
            $perPerson > 0 ? number_format($perPerson, 2, '.', '') : '未设置',
            number_format($paid, 2, '.', ''),
            number_format($owe, 2, '.', ''),
            $perPerson > 0 ? '未缴清' : '未缴纳',
        ];
        $totalOwe += $owe;
        $totalPaidOfOwe += $paid;
    }
    if (empty($rows)) {
        $rows[] = ['全部已缴纳', '', '', '', '', ''];
    } else {
        $rows[] = ['合计', '欠费 ' . count($rows) . ' 人', '', '已缴 ¥' . number_format($totalPaidOfOwe, 2, '.', ''), '欠费 ¥' . number_format($totalOwe, 2, '.', ''), ''];
    }
    return [['序号', '姓名', '累计应缴', '已缴金额', '欠费金额', '状态'], $rows];
}
