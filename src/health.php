<?php
/**
 * 班级班费管理系统 - 数据体检 / 对账中心
 *
 * 只读检查 + 少量安全的修复动作。检查项覆盖：
 * 学期与归档快照、班费轮次金额、在线缴费归属、凭证图片、重复/异常账目、账号绑定。
 */

function healthIssue(string $code, string $title, string $severity, string $detail = '', array $meta = [], string $fix = ''): array {
    return ['code' => $code, 'title' => $title, 'severity' => $severity, 'detail' => $detail, 'meta' => $meta, 'fix' => $fix];
}

function healthSection(string $key, string $title, array $items): array {
    $rank = ['info' => 0, 'warn' => 1, 'error' => 2];
    $severity = 'info';
    foreach ($items as $it) {
        $s = (string)($it['severity'] ?? 'info');
        if (($rank[$s] ?? 0) > ($rank[$severity] ?? 0)) $severity = $s;
    }
    return ['key' => $key, 'title' => $title, 'severity' => $severity, 'items' => $items, 'count' => count($items)];
}

// ==================== 各检查组 ====================

function healthCheckSemesters(): array {
    $items = [];
    try {
        $active = db()->query("SELECT id, name FROM semesters WHERE status='active' ORDER BY start_date")->fetchAll();
        if (count($active) > 1) {
            $items[] = healthIssue('semester_multi_active', '存在多个进行中的学期', 'warn',
                '同时进行中的学期会让新流水归属不确定：' . implode('、', array_column($active, 'name')),
                ['ids' => array_column($active, 'id')]);
        }

        $overlaps = db()->query("SELECT a.id aid, a.name an, a.start_date as_, a.end_date ae,
            b.id bid, b.name bn, b.start_date bs, b.end_date be
            FROM semesters a JOIN semesters b ON a.id < b.id
            AND a.start_date <= b.end_date AND a.end_date >= b.start_date
            ORDER BY a.start_date LIMIT 20")->fetchAll();
        foreach ($overlaps as $o) {
            $items[] = healthIssue('semester_overlap', '学期日期重叠', 'error',
                '「' . $o['an'] . '」(' . $o['as_'] . '~' . $o['ae'] . ') 与 「' . $o['bn'] . '」(' . $o['bs'] . '~' . $o['be'] . ') 重叠',
                ['a' => (int)$o['aid'], 'b' => (int)$o['bid']]);
        }

        $archived = db()->query("SELECT * FROM semesters WHERE status='archived' ORDER BY start_date DESC")->fetchAll();
        foreach ($archived as $sem) {
            $liveEnd = semesterBalanceThrough((string)$sem['end_date']);
            $stored = $sem['closing_balance'] !== null ? round((float)$sem['closing_balance'], 2) : null;
            if ($stored === null) {
                $items[] = healthIssue('semester_no_snapshot', '归档学期缺少结账快照', 'warn',
                    '「' . $sem['name'] . '」没有冻结期末余额，历史报表会随数据变化；建议重新结账',
                    ['semester_id' => (int)$sem['id']], 'recompute_snapshot');
            } elseif (!empty($sem['snapshot_dirty']) || abs($liveEnd - $stored) > 0.009) {
                $items[] = healthIssue('semester_stale', '归档学期快照已过期', 'error',
                    '「' . $sem['name'] . '」归档后数据发生变动（快照 ¥' . number_format($stored, 2) . '，当前 ¥' . number_format($liveEnd, 2) . '）；请重新结账',
                    ['semester_id' => (int)$sem['id'], 'stored' => $stored, 'live' => $liveEnd], 'recompute_snapshot');
            }
        }

        $missing = db()->query("SELECT COUNT(*) FROM transactions t
            WHERE t.deleted_at IS NULL AND (t.semester_id IS NULL OR t.semester_id=0)
            AND EXISTS (SELECT 1 FROM semesters s WHERE t.date BETWEEN s.start_date AND s.end_date)")->fetchColumn();
        if ((int)$missing > 0) {
            $items[] = healthIssue('tx_no_semester', '有流水未归入学期', 'warn',
                (int)$missing . ' 条流水的日期落在学期内，但没有学期归属（会影响按学期统计）',
                ['count' => (int)$missing], 'backfill_semester');
        }

        $mismatch = db()->query("SELECT t.id, t.date, s.name FROM transactions t
            JOIN semesters s ON s.id=t.semester_id
            WHERE t.deleted_at IS NULL AND (t.date < s.start_date OR t.date > s.end_date)
            LIMIT 20")->fetchAll();
        foreach ($mismatch as $m) {
            $items[] = healthIssue('tx_semester_mismatch', '流水学期归属与日期不符', 'warn',
                '记录 #' . $m['id'] . '（' . $m['date'] . '）被归入学期「' . $m['name'] . '」',
                ['tx_id' => (int)$m['id']], 'backfill_semester');
        }
    } catch (\Exception $e) {
        $items[] = healthIssue('semester_check_failed', '学期检查失败', 'warn', $e->getMessage());
    }
    return $items;
}

function healthCheckFeeRounds(): array {
    $items = [];
    try {
        $rounds = db()->query("SELECT * FROM transactions
            WHERE type='income' AND sub_category='班费收缴' AND deleted_at IS NULL
            ORDER BY date DESC, id DESC LIMIT 300")->fetchAll();
        $roster = feeRoster();
        $eligible = feeEligibleCount($roster);
        $missingPp = 0;
        foreach ($rounds as $tx) {
            $pp = feeRoundPerPerson($tx, $eligible);
            if ($pp <= 0) {
                $missingPp++;
                $items[] = healthIssue('round_no_per_person', '轮次缺少每人应缴', 'warn',
                    '轮次 #' . $tx['id'] . '「' . $tx['description'] . '」没有可用的每人应缴金额',
                    ['tx_id' => (int)$tx['id']], 'backfill_per_person');
                continue;
            }
            $detail = feeRoundDetail($tx, $roster, $eligible);
            $diff = round((float)$tx['amount'] - (float)$detail['paid_total'], 2);
            if (abs($diff) > 0.011) {
                $items[] = healthIssue('round_amount_mismatch', '轮次实收与缴费名单不一致', 'warn',
                    '轮次 #' . $tx['id'] . '「' . $tx['description'] . '」账面 ¥' . number_format((float)$tx['amount'], 2)
                    . '，按名单应为 ¥' . number_format((float)$detail['paid_total'], 2) . '（差 ' . ($diff > 0 ? '+' : '') . number_format($diff, 2) . '）',
                    ['tx_id' => (int)$tx['id'], 'amount' => (float)$tx['amount'], 'expected' => (float)$detail['paid_total']]);
            }
        }

        $statuses = feeAllStatuses($roster);
        $over = 0;
        foreach ($statuses as $st) {
            if (!empty($st['exempt'])) continue;
            if ((float)$st['due'] > 0 && (float)$st['paid'] > (float)$st['due'] + 0.01) {
                $over++;
                if ($over <= 20) {
                    $items[] = healthIssue('student_overpaid', '学生已缴超过应缴', 'info',
                        $st['name'] . '：应缴 ¥' . number_format((float)$st['due'], 2) . '，已缴 ¥' . number_format((float)$st['paid'], 2),
                        ['student_id' => (int)$st['id']]);
                }
            }
        }
        if ($over > 20) {
            $items[] = healthIssue('student_overpaid_more', '更多学生已缴超过应缴', 'info', '另有 ' . ($over - 20) . ' 人');
        }

        $online = 0.0;
        foreach (db()->query(payOnlineUnattributedSql())->fetchAll() as $r) $online += (float)$r['v'];
        if ($online > 0.009) {
            $items[] = healthIssue('online_unattributed', '在线缴费未并入轮次', 'info',
                '共有 ¥' . number_format($online, 2) . ' 未归属到具体轮次（常见于部分缴费/超额缴费），可在「支付订单」核对',
                ['amount' => round($online, 2)]);
        }
    } catch (\Exception $e) {
        $items[] = healthIssue('fee_check_failed', '缴费检查失败', 'warn', $e->getMessage());
    }
    return $items;
}

function healthCheckPayments(): array {
    $items = [];
    try {
        $paidUnsettled = db()->query("SELECT id, order_no, student_name, amount FROM payment_orders
            WHERE status='paid' AND settled=0 ORDER BY id DESC LIMIT 50")->fetchAll();
        foreach ($paidUnsettled as $o) {
            $items[] = healthIssue('order_paid_unsettled', '已支付订单未核销', 'error',
                '订单 ' . $o['order_no'] . ($o['student_name'] ? '（' . $o['student_name'] . '）' : '') . '：¥' . number_format((float)$o['amount'], 2),
                ['order_id' => (int)$o['id'], 'order_no' => $o['order_no']], 'settle_paid_orders');
        }

        $stale = db()->query("SELECT COUNT(*) FROM payment_orders WHERE status='pending' AND created_at < (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
        if ((int)$stale > 0) {
            $items[] = healthIssue('order_stale_pending', '长时间未支付的订单', 'info',
                (int)$stale . ' 笔待支付订单已超过 24 小时，可批量关闭', ['count' => (int)$stale], 'close_stale_orders');
        }

        $orphan = db()->query("SELECT fp.id, fp.order_no, fp.amount, fp.tx_id FROM fee_payments fp
            LEFT JOIN payment_orders po ON po.order_no=fp.order_no
            WHERE po.id IS NULL LIMIT 20")->fetchAll();
        foreach ($orphan as $o) {
            $items[] = healthIssue('fee_payment_no_order', '核销记录找不到订单', 'warn',
                '核销 ' . $o['order_no'] . '：¥' . number_format((float)$o['amount'], 2),
                ['fee_payment_id' => (int)$o['id']]);
        }

        $deleted = db()->query("SELECT fp.id, fp.order_no, fp.amount, fp.tx_id FROM fee_payments fp
            JOIN transactions t ON t.id=fp.tx_id
            WHERE t.deleted_at IS NOT NULL LIMIT 20")->fetchAll();
        foreach ($deleted as $o) {
            $items[] = healthIssue('fee_payment_deleted_tx', '核销记录指向已删除账目', 'warn',
                '核销 ' . $o['order_no'] . ' 指向已进回收站的记录 #' . $o['tx_id'],
                ['fee_payment_id' => (int)$o['id']]);
        }
    } catch (\Exception $e) {
        $items[] = healthIssue('payment_check_failed', '支付检查失败', 'warn', $e->getMessage());
    }
    return $items;
}

function healthCheckImages(): array {
    $items = [];
    try {
        $orphan = (int)db()->query("SELECT COUNT(*) FROM tx_images WHERE transaction_id=0 AND created_at < (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
        if ($orphan > 0) {
            $items[] = healthIssue('image_orphan', '未关联的凭证图片', 'info',
                $orphan . ' 张图片上传后超过 24 小时仍未关联收支，可清理',
                ['count' => $orphan], 'cleanup_orphan_images');
        }
        $ghost = (int)db()->query("SELECT COUNT(*) FROM tx_images ti
            LEFT JOIN transactions t ON t.id=ti.transaction_id
            WHERE ti.transaction_id>0 AND t.id IS NULL")->fetchColumn();
        if ($ghost > 0) {
            $items[] = healthIssue('image_ghost', '凭证图片指向不存在的账目', 'warn',
                $ghost . ' 张图片关联的收支记录已不存在，可清理',
                ['count' => $ghost], 'cleanup_orphan_images');
        }

        $rows = db()->query("SELECT id, full_path FROM tx_images WHERE full_path IS NOT NULL AND full_path<>'' LIMIT 500")->fetchAll();
        $missing = 0;
        foreach ($rows as $r) {
            $rel = (string)$r['full_path'];
            if ($rel === '' || strpos($rel, '..') !== false) continue;
            if (!is_file(dirname(__DIR__) . '/' . $rel)) $missing++;
        }
        if ($missing > 0) {
            $items[] = healthIssue('image_file_missing', '凭证原图文件缺失', 'warn',
                $missing . ' 张凭证的原图文件不存在（缩略图仍可查看）；可尝试回退到数据库原图',
                ['count' => $missing], 'repair_image_paths');
        }
    } catch (\Exception $e) {
        $items[] = healthIssue('image_check_failed', '凭证检查失败', 'warn', $e->getMessage());
    }
    return $items;
}

function healthCheckData(): array {
    $items = [];
    try {
        $badAmount = db()->query("SELECT id, date, amount, description FROM transactions
            WHERE deleted_at IS NULL AND (amount < 0 OR amount > 10000000 OR (amount=0 AND sub_category<>'班费收缴'))
            ORDER BY id DESC LIMIT 20")->fetchAll();
        foreach ($badAmount as $t) {
            $items[] = healthIssue('tx_bad_amount', '收支金额异常', 'error',
                '记录 #' . $t['id'] . '（' . $t['date'] . '）金额 ¥' . number_format((float)$t['amount'], 2) . '：' . mb_substr((string)$t['description'], 0, 40),
                ['tx_id' => (int)$t['id']]);
        }

        $badDate = db()->query("SELECT id, date, description FROM transactions
            WHERE deleted_at IS NULL AND (date < '2000-01-01' OR date > DATE_ADD(CURDATE(), INTERVAL 365 DAY))
            ORDER BY id DESC LIMIT 20")->fetchAll();
        foreach ($badDate as $t) {
            $items[] = healthIssue('tx_bad_date', '收支日期异常', 'warn',
                '记录 #' . $t['id'] . ' 日期为 ' . $t['date'] . '：' . mb_substr((string)$t['description'], 0, 40),
                ['tx_id' => (int)$t['id']]);
        }

        $dups = db()->query("SELECT date, type, amount, description, COUNT(*) c, GROUP_CONCAT(id ORDER BY id) ids
            FROM transactions WHERE deleted_at IS NULL
            GROUP BY date, type, amount, description HAVING c>1 ORDER BY c DESC LIMIT 20")->fetchAll();
        foreach ($dups as $d) {
            $items[] = healthIssue('tx_duplicate', '疑似重复的收支记录', 'info',
                $d['date'] . ' ' . ($d['type'] === 'income' ? '收入' : '支出') . ' ¥' . number_format((float)$d['amount'], 2)
                . '「' . mb_substr((string)$d['description'], 0, 30) . '」共 ' . $d['c'] . ' 条（ID ' . $d['ids'] . '）',
                ['ids' => array_map('intval', explode(',', (string)$d['ids']))]);
        }

        $badRoster = (int)db()->query("SELECT COUNT(*) FROM users u LEFT JOIN class_roster r ON r.id=u.roster_id
            WHERE u.roster_id IS NOT NULL AND u.roster_id>0 AND r.id IS NULL")->fetchColumn();
        if ($badRoster > 0) {
            $items[] = healthIssue('user_roster_invalid', '用户绑定了不存在的花名册学生', 'warn',
                $badRoster . ' 个账号的 roster_id 已失效，可解绑后按姓名自动重新绑定',
                ['count' => $badRoster], 'repair_roster_bindings');
        }
        $unbound = (int)db()->query("SELECT COUNT(*) FROM users WHERE (roster_id IS NULL OR roster_id=0) AND roles LIKE '%student%'")->fetchColumn();
        if ($unbound > 0) {
            $items[] = healthIssue('user_unbound', '学生账号未绑定花名册', 'info',
                $unbound . ' 个学生账号未绑定花名册；姓名与花名册一致时会自动绑定，否则在线缴费只能由班委代操作',
                ['count' => $unbound], 'repair_roster_bindings');
        }
    } catch (\Exception $e) {
        $items[] = healthIssue('data_check_failed', '数据检查失败', 'warn', $e->getMessage());
    }
    return $items;
}

function healthCheckAccounts(): array {
    $items = [];
    try {
        $must = (int)db()->query("SELECT COUNT(*) FROM users WHERE must_change_password=1")->fetchColumn();
        if ($must > 0) {
            $items[] = healthIssue('user_must_change_password', '账号仍使用初始密码', 'info',
                $must . ' 个账号尚未完成首次改密（登录后会被强制要求修改）', ['count' => $must]);
        }
        $admins = db()->query("SELECT id, username, roles FROM users
            WHERE (roles LIKE '%admin%' OR roles LIKE '%head_teacher%') AND (totp_enabled IS NULL OR totp_enabled=0)
            LIMIT 20")->fetchAll();
        foreach ($admins as $u) {
            $items[] = healthIssue('admin_no_2fa', '管理员未启用两步验证', 'warn',
                $u['username'] . ' 具备较高权限，建议在「账号安全」中启用两步验证',
                ['user_id' => (int)$u['id']]);
        }
        $banned = (int)db()->query("SELECT COUNT(*) FROM users WHERE banned=1")->fetchColumn();
        if ($banned > 0) {
            $items[] = healthIssue('user_banned', '存在被封禁账号', 'info', $banned . ' 个账号当前被封禁', ['count' => $banned]);
        }
    } catch (\Exception $e) {
        $items[] = healthIssue('account_check_failed', '账号检查失败', 'warn', $e->getMessage());
    }
    return $items;
}

// ==================== 接口 ====================

function handleHealthCheck() {
    requirePermission('viewHealth');
    $sections = [
        healthSection('semester', '学期与结转', healthCheckSemesters()),
        healthSection('fee', '班费轮次与缴费', healthCheckFeeRounds()),
        healthSection('payment', '在线支付与核销', healthCheckPayments()),
        healthSection('image', '凭证图片', healthCheckImages()),
        healthSection('data', '账目与账号绑定', healthCheckData()),
        healthSection('account', '账号安全', healthCheckAccounts()),
    ];
    $summary = ['error' => 0, 'warn' => 0, 'info' => 0, 'total' => 0];
    foreach ($sections as $sec) {
        foreach ($sec['items'] as $it) {
            $s = (string)($it['severity'] ?? 'info');
            if (!isset($summary[$s])) $summary[$s] = 0;
            $summary[$s]++;
            $summary['total']++;
        }
    }
    jsonOutput([
        'checked_at' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'sections' => $sections,
        'fixes' => [
            'recompute_snapshot' => '重新结账归档学期',
            'backfill_semester' => '回填学期归属',
            'backfill_per_person' => '回填每人应缴',
            'settle_paid_orders' => '核销已支付订单',
            'close_stale_orders' => '关闭超时未支付订单',
            'cleanup_orphan_images' => '清理孤儿凭证',
            'repair_image_paths' => '修复凭证路径',
            'repair_roster_bindings' => '修复花名册绑定',
        ],
    ]);
}

function handleHealthFix() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();
    $input = jsonInput();
    $action = (string)($input['action'] ?? '');
    $user = currentUser();
    $result = ['action' => $action];

    try {
        if ($action === 'recompute_snapshot') {
            $rows = db()->query("SELECT id FROM semesters WHERE status='archived'")->fetchAll();
            $n = 0;
            foreach ($rows as $r) { semesterApplySnapshot((int)$r['id'], (int)$user['id'], true); $n++; }
            $result['count'] = $n;
        } elseif ($action === 'backfill_semester') {
            $stmt = db()->exec("UPDATE transactions t JOIN semesters s
                ON t.date BETWEEN s.start_date AND s.end_date
                SET t.semester_id=s.id
                WHERE t.semester_id IS NULL OR t.semester_id=0");
            $result['count'] = (int)$stmt;
        } elseif ($action === 'backfill_per_person') {
            $rows = db()->query("SELECT id, description, amount, expected_amount, payer_ids FROM transactions
                WHERE sub_category='班费收缴' AND (per_person IS NULL OR per_person<=0)")->fetchAll();
            $eligible = (int)db()->query("SELECT COUNT(*) FROM class_roster WHERE exempt=0")->fetchColumn();
            $upd = db()->prepare("UPDATE transactions SET per_person=:p WHERE id=:id");
            $n = 0;
            foreach ($rows as $r) {
                $pp = 0.0;
                if (preg_match('/每人\s*([0-9]+(?:\.[0-9]+)?)/u', (string)$r['description'], $m)) $pp = round((float)$m[1], 2);
                if ($pp <= 0) { $e = (float)$r['expected_amount']; if ($e > 0 && $eligible > 0) $pp = round($e / $eligible, 2); }
                if ($pp <= 0) { $pids = json_decode($r['payer_ids'] ?? '', true) ?: []; if (is_array($pids) && count($pids) > 0) $pp = round(((float)$r['amount']) / count($pids), 2); }
                if ($pp > 0) { $upd->execute([':p' => $pp, ':id' => (int)$r['id']]); $n++; }
            }
            $result['count'] = $n;
        } elseif ($action === 'settle_paid_orders') {
            $orders = db()->query("SELECT * FROM payment_orders WHERE status='paid' AND settled=0 ORDER BY id ASC LIMIT 100")->fetchAll();
            $ok = 0; $fail = 0;
            foreach ($orders as $o) {
                if (paySettle($o, (string)$o['trade_no'])) $ok++; else $fail++;
            }
            $result['settled'] = $ok; $result['failed'] = $fail;
        } elseif ($action === 'close_stale_orders') {
            $n = db()->exec("UPDATE payment_orders SET status='closed' WHERE status='pending' AND created_at < (NOW() - INTERVAL 24 HOUR)");
            $result['count'] = (int)$n;
        } elseif ($action === 'cleanup_orphan_images') {
            $rows = db()->query("SELECT ti.id, ti.full_path FROM tx_images ti
                LEFT JOIN transactions t ON t.id=ti.transaction_id
                WHERE (ti.transaction_id=0 AND ti.created_at < (NOW() - INTERVAL 24 HOUR))
                   OR (ti.transaction_id>0 AND t.id IS NULL)")->fetchAll();
            $files = 0;
            foreach ($rows as $r) {
                $rel = (string)($r['full_path'] ?? '');
                if ($rel !== '' && strpos($rel, 'uploads/') === 0 && strpos($rel, '..') === false) {
                    $abs = dirname(__DIR__) . '/' . $rel;
                    if (is_file($abs) && @unlink($abs)) $files++;
                }
            }
            $n = 0;
            if ($rows) {
                $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $del = db()->prepare("DELETE FROM tx_images WHERE id IN ($ph)");
                $del->execute($ids);
                $n = $del->rowCount();
            }
            $result['images'] = $n; $result['files'] = $files;
        } elseif ($action === 'repair_image_paths') {
            $rows = db()->query("SELECT id, full_path, OCTET_LENGTH(`full`) AS full_len FROM tx_images WHERE full_path IS NOT NULL AND full_path<>'' LIMIT 1000")->fetchAll();
            $upd = db()->prepare("UPDATE tx_images SET full_path=NULL WHERE id=:id");
            $n = 0;
            foreach ($rows as $r) {
                $rel = (string)$r['full_path'];
                if ($rel === '' || strpos($rel, '..') !== false) continue;
                if (!is_file(dirname(__DIR__) . '/' . $rel) && (int)$r['full_len'] > 0) { $upd->execute([':id' => (int)$r['id']]); $n++; }
            }
            $result['count'] = $n;
        } elseif ($action === 'repair_roster_bindings') {
            $bad = db()->exec("UPDATE users u LEFT JOIN class_roster r ON r.id=u.roster_id
                SET u.roster_id=NULL WHERE u.roster_id>0 AND r.id IS NULL");
            $bind = db()->exec("UPDATE users u JOIN class_roster r ON u.username=r.name
                SET u.roster_id=r.id WHERE u.roster_id IS NULL OR u.roster_id=0");
            $result['unbound'] = (int)$bad; $result['bound'] = (int)$bind;
        } else {
            jsonOutput(['error' => '不支持的修复动作'], 400);
        }
    } catch (\Exception $e) {
        addLog((int)$user['id'], (string)$user['username'], 'health_fix_failed', 'system', null, ['action' => $action, 'error' => $e->getMessage()]);
        jsonOutput(['error' => '修复失败：' . $e->getMessage()], 500);
    }

    addLog((int)$user['id'], (string)$user['username'], 'health_fix', 'system', null, $result);
    jsonOutput(['ok' => true, 'result' => $result]);
}
