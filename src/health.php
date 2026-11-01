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

        $rows = db()->query("SELECT id, full_path, sha256,
            OCTET_LENGTH(web) AS web_len, OCTET_LENGTH(`full`) AS full_len
            FROM tx_images WHERE full_path IS NOT NULL AND full_path<>'' LIMIT 500")->fetchAll();
        $recoverable = [];
        $lost = [];
        $checksumBad = [];
        foreach ($rows as $r) {
            $rel = (string)$r['full_path'];
            $abs = receiptSafePath($rel);
            if (!$abs) continue;
            if (is_file($abs)) {
                if (!empty($r['sha256'])) {
                    $h = @hash_file('sha256', $abs);
                    if ($h !== false && !hash_equals((string)$r['sha256'], (string)$h)) $checksumBad[] = (int)$r['id'];
                }
                continue;
            }
            if ((int)$r['web_len'] > 0 || (int)$r['full_len'] > 0) $recoverable[] = (int)$r['id'];
            else $lost[] = (int)$r['id'];
        }
        if (!empty($recoverable)) {
            $items[] = healthIssue('image_file_missing_recoverable', '凭证原图文件缺失（可回退）', 'warn',
                count($recoverable) . ' 张原图文件不存在，但数据库里还有 Web 版/旧原图，可清掉失效路径并回退显示',
                ['ids' => $recoverable, 'count' => count($recoverable)], 'repair_image_paths');
        }
        if (!empty($lost)) {
            $items[] = healthIssue('image_file_missing_lost', '凭证原图已丢失（仅缩略图）', 'warn',
                count($lost) . ' 张原图文件不存在，且数据库没有 Web 版/旧原图，只能保留缩略图；可从旧备份恢复，或标记为仅缩略图',
                ['ids' => $lost, 'count' => count($lost)], 'mark_thumbnail_only');
        }
        if (!empty($checksumBad)) {
            $items[] = healthIssue('image_checksum_mismatch', '凭证文件校验不一致', 'warn',
                count($checksumBad) . ' 张凭证文件的 sha256 与入库记录不一致，可能被替换或损坏',
                ['ids' => $checksumBad, 'count' => count($checksumBad)]);
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
        $policy = twofaPolicy();
        $admins = db()->query("SELECT id, username, roles FROM users
            WHERE (roles LIKE '%admin%' OR roles LIKE '%head_teacher%') AND (totp_enabled IS NULL OR totp_enabled=0)
            LIMIT 20")->fetchAll();
        foreach ($admins as $u) {
            if ($policy['enabled'] && $policy['expired']) {
                $items[] = healthIssue('admin_no_2fa_policy', '管理员未启用两步验证（策略已到期）', 'error',
                    $u['username'] . ' 未启用 2FA，系统策略宽限期已过，该账号的其他接口已被限制',
                    ['user_id' => (int)$u['id']]);
            } elseif ($policy['enabled']) {
                $items[] = healthIssue('admin_no_2fa_policy', '管理员未启用两步验证（策略宽限期内）', 'warn',
                    $u['username'] . ' 需在 ' . $policy['grace_until'] . ' 前启用 2FA',
                    ['user_id' => (int)$u['id']]);
            } else {
                $items[] = healthIssue('admin_no_2fa', '管理员未启用两步验证', 'warn',
                    $u['username'] . ' 具备较高权限，建议在「账号安全」中启用两步验证',
                    ['user_id' => (int)$u['id']]);
            }
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

function healthCheckBackup(): array {
    $items = [];
    try {
        $files = backupListFiles();
        if (empty($files)) {
            $items[] = healthIssue('backup_none', '还没有数据备份', 'error',
                '数据库与 uploads 尚未备份；迁移、误删或磁盘故障时无法恢复', ['count' => 0], 'run_backup');
        } else {
            $last = $files[0];
            $ts = strtotime((string)($last['mtime'] ?? '')) ?: 0;
            $ageH = $ts > 0 ? (int)floor((time() - $ts) / 3600) : 999;
            if ($last['status'] !== 'ok') {
                $items[] = healthIssue('backup_invalid', '最近备份包损坏', 'error',
                    '备份 ' . $last['filename'] . ' 的 manifest 无法读取，请重新备份', ['file' => $last['filename']], 'run_backup');
            } elseif ($ageH > 48) {
                $items[] = healthIssue('backup_stale', '数据备份已过期', 'warn',
                    '最近备份在 ' . $ageH . ' 小时前（' . $last['mtime'] . '），建议至少每天一次', ['file' => $last['filename']], 'run_backup');
            }
        }
        $r = backupRetention();
        if ($r < 7) {
            $items[] = healthIssue('backup_retention_low', '备份保留数量偏少', 'info',
                '当前只保留 ' . $r . ' 份，建议 7-30 份（配置管理 → 数据备份）', ['retention' => $r]);
        }
    } catch (\Throwable $e) {
        $items[] = healthIssue('backup_check_failed', '备份检查失败', 'warn', $e->getMessage());
    }
    return $items;
}

// ==================== 接口 ====================

function healthAckTarget(array $it): string {
    $m = $it['meta'] ?? [];
    if (!is_array($m)) return '';
    foreach (['semester_id', 'tx_id', 'order_id', 'order_no', 'fee_payment_id', 'user_id', 'a', 'b'] as $k) {
        if (isset($m[$k]) && is_scalar($m[$k])) return $k . ':' . $m[$k];
    }
    if (isset($m['ids']) && is_array($m['ids']) && !empty($m['ids'])) return 'ids:' . implode(',', array_map('intval', $m['ids']));
    return '';
}

function healthAckKey(array $it): string {
    return mb_substr((string)($it['code'] ?? 'unknown') . ':' . healthAckTarget($it), 0, 191);
}

function healthAckMap(): array {
    $out = [];
    try {
        foreach (db()->query("SELECT item_key FROM health_ack WHERE expires_at IS NULL OR expires_at > NOW()")->fetchAll(PDO::FETCH_COLUMN) as $k) {
            $out[(string)$k] = true;
        }
    } catch (\Exception $e) { /* 表缺失 */ }
    return $out;
}

function healthBuildSections(): array {
    return [
        healthSection('semester', '学期与结转', healthCheckSemesters()),
        healthSection('fee', '班费轮次与缴费', healthCheckFeeRounds()),
        healthSection('payment', '在线支付与核销', healthCheckPayments()),
        healthSection('backup', '备份与恢复', healthCheckBackup()),
        healthSection('image', '凭证图片', healthCheckImages()),
        healthSection('data', '账目与账号绑定', healthCheckData()),
        healthSection('account', '账号安全', healthCheckAccounts()),
    ];
}

/** 给 item 加 key，并按 health_ack 过滤；返回 [sections, ignored] */
function healthApplyAcks(array $sections): array {
    $acks = healthAckMap();
    $ignored = 0;
    foreach ($sections as &$sec) {
        $kept = [];
        foreach ($sec['items'] as &$it) {
            $it['key'] = healthAckKey($it);
            if (isset($acks[$it['key']])) { $ignored++; continue; }
            $kept[] = $it;
        }
        unset($it);
        $sec['items'] = $kept;
        $sec['count'] = count($kept);
    }
    unset($sec);
    return [$sections, $ignored];
}

function healthIssueSummary(array $sections): array {
    $summary = ['error' => 0, 'warn' => 0, 'info' => 0, 'total' => 0];
    foreach ($sections as $sec) {
        foreach ($sec['items'] as $it) {
            $sev = (string)($it['severity'] ?? 'info');
            if (!isset($summary[$sev])) $summary[$sev] = 0;
            $summary[$sev]++;
            $summary['total']++;
        }
    }
    return $summary;
}

function handleHealthCheck() {
    requirePermission('viewHealth');
    $sections = healthBuildSections();
    [$sections, $ignored] = healthApplyAcks($sections);
    $summary = healthIssueSummary($sections);
    jsonOutput([
        'checked_at' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'ignored' => $ignored,
        'sections' => $sections,
        'fixes' => [
            'recompute_snapshot' => '重新结账归档学期',
            'backfill_semester' => '回填学期归属',
            'backfill_per_person' => '回填每人应缴',
            'settle_paid_orders' => '核销已支付订单',
            'close_stale_orders' => '关闭超时未支付订单',
            'cleanup_orphan_images' => '清理孤儿凭证',
            'repair_image_paths' => '修复凭证路径',
            'mark_thumbnail_only' => '标记为仅缩略图',
            'repair_roster_bindings' => '修复花名册绑定',
            'run_backup' => '立即备份',
        ],
    ]);
}

function handleHealthAck() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();
    $input = jsonInput();
    $key = mb_substr(trim((string)($input['item_key'] ?? '')), 0, 191);
    if ($key === '') jsonOutput(['error' => '缺少 item_key'], 400);
    $reason = mb_substr(trim((string)($input['reason'] ?? '')), 0, 255);
    $days = (int)($input['days'] ?? 30);
    if ($days < 1 || $days > 365) $days = 30;
    $expires = date('Y-m-d H:i:s', time() + $days * 86400);
    $user = currentUser();
    try {
        db()->prepare("INSERT INTO health_ack (item_key, code, target, reason, created_by, expires_at)
            VALUES (:k, :c, :t, :r, :u, :e)
            ON DUPLICATE KEY UPDATE reason=VALUES(reason), created_by=VALUES(created_by), expires_at=VALUES(expires_at), created_at=NOW()")
            ->execute([
                ':k' => $key,
                ':c' => mb_substr((string)($input['code'] ?? ''), 0, 64),
                ':t' => mb_substr((string)($input['target'] ?? ''), 0, 191),
                ':r' => $reason ?: null,
                ':u' => (int)$user['id'],
                ':e' => $expires,
            ]);
    } catch (\Exception $e) {
        jsonOutput(['error' => '保存忽略记录失败：' . $e->getMessage()], 500);
    }
    addLog((int)$user['id'], (string)$user['username'], 'health_ack', 'system', null, ['key' => $key, 'days' => $days, 'reason' => $reason]);
    jsonOutput(['ok' => true, 'expires_at' => $expires]);
}

function handleHealthUnack() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();
    $input = jsonInput();
    if (!empty($input['all'])) {
        db()->exec("DELETE FROM health_ack");
        jsonOutput(['ok' => true, 'all' => true]);
    }
    $key = mb_substr(trim((string)($input['item_key'] ?? '')), 0, 191);
    if ($key === '') jsonOutput(['error' => '缺少 item_key'], 400);
    db()->prepare("DELETE FROM health_ack WHERE item_key=:k")->execute([':k' => $key]);
    jsonOutput(['ok' => true]);
}

/** 修复动作预演：只统计，不改数据 */
function healthFixPlan(string $action): array {
    $plan = ['action' => $action];
    if ($action === 'recompute_snapshot') {
        $plan['semesters'] = (int)db()->query("SELECT COUNT(*) FROM semesters WHERE status='archived'")->fetchColumn();
    } elseif ($action === 'backfill_semester') {
        $plan['transactions'] = (int)db()->query("SELECT COUNT(*) FROM transactions t
            WHERE t.deleted_at IS NULL AND (t.semester_id IS NULL OR t.semester_id=0)
            AND EXISTS (SELECT 1 FROM semesters s WHERE t.date BETWEEN s.start_date AND s.end_date)")->fetchColumn();
    } elseif ($action === 'backfill_per_person') {
        $plan['rounds'] = (int)db()->query("SELECT COUNT(*) FROM transactions
            WHERE sub_category='班费收缴' AND (per_person IS NULL OR per_person<=0)")->fetchColumn();
    } elseif ($action === 'settle_paid_orders') {
        $r = db()->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM payment_orders WHERE status='paid' AND settled=0")->fetch();
        $plan['orders'] = (int)($r['c'] ?? 0);
        $plan['amount'] = round((float)($r['a'] ?? 0), 2);
    } elseif ($action === 'close_stale_orders') {
        $plan['orders'] = (int)db()->query("SELECT COUNT(*) FROM payment_orders WHERE status='pending' AND created_at < (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
    } elseif ($action === 'cleanup_orphan_images') {
        $plan['images'] = (int)db()->query("SELECT COUNT(*) FROM tx_images ti
            LEFT JOIN transactions t ON t.id=ti.transaction_id
            WHERE (ti.transaction_id=0 AND ti.created_at < (NOW() - INTERVAL 24 HOUR))
               OR (ti.transaction_id>0 AND t.id IS NULL)")->fetchColumn();
    } elseif ($action === 'repair_image_paths' || $action === 'mark_thumbnail_only') {
        $rows = db()->query("SELECT id, full_path, OCTET_LENGTH(web) web_len, OCTET_LENGTH(`full`) full_len
            FROM tx_images WHERE full_path IS NOT NULL AND full_path<>'' LIMIT 2000")->fetchAll();
        $n = 0;
        foreach ($rows as $r) {
            $abs = receiptSafePath((string)$r['full_path']);
            if (!$abs || is_file($abs)) continue;
            $hasFallback = ((int)$r['web_len'] > 0 || (int)$r['full_len'] > 0);
            if ($action === 'repair_image_paths' && $hasFallback) $n++;
            if ($action === 'mark_thumbnail_only' && !$hasFallback) $n++;
        }
        $plan['images'] = $n;
    } elseif ($action === 'run_backup') {
        $plan['note'] = '创建数据库 + uploads 全量备份';
    } elseif ($action === 'repair_roster_bindings') {
        $plan['invalid'] = (int)db()->query("SELECT COUNT(*) FROM users u LEFT JOIN class_roster r ON r.id=u.roster_id
            WHERE u.roster_id IS NOT NULL AND u.roster_id>0 AND r.id IS NULL")->fetchColumn();
        $plan['unbound'] = (int)db()->query("SELECT COUNT(*) FROM users WHERE (roster_id IS NULL OR roster_id=0) AND roles LIKE '%student%'")->fetchColumn();
    } else {
        throw new \InvalidArgumentException('不支持的修复动作');
    }
    return $plan;
}

function handleHealthFix() {
    requirePermission('manageAllAccounts');
    requireCsrfToken();
    $input = jsonInput();
    $action = (string)($input['action'] ?? '');
    $user = currentUser();
    $result = ['action' => $action];

    try {
        $plan = healthFixPlan($action);
    } catch (\Throwable $e) {
        jsonOutput(['error' => $e->getMessage()], 400);
    }
    if (!empty($input['dry_run'])) {
        jsonOutput(['ok' => true, 'dry_run' => true, 'plan' => $plan]);
    }

    // 破坏性修复前自动整站备份（含 uploads），备份失败则中止
    $destructive = ['cleanup_orphan_images', 'repair_image_paths', 'mark_thumbnail_only', 'settle_paid_orders', 'repair_roster_bindings'];
    if (in_array($action, $destructive, true) && function_exists('backupCreate')) {
        try {
            $b = backupCreate((int)$user['id'], 'pre_health_' . $action);
            $result['backup'] = $b['filename'];
        } catch (\Throwable $e) {
            jsonOutput(['error' => '修复前自动备份失败，已中止：' . $e->getMessage()], 500);
        }
    }

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
            $rows = db()->query("SELECT id, full_path, OCTET_LENGTH(web) AS web_len, OCTET_LENGTH(`full`) AS full_len
                FROM tx_images WHERE full_path IS NOT NULL AND full_path<>'' LIMIT 1000")->fetchAll();
            $upd = db()->prepare("UPDATE tx_images SET full_path=NULL WHERE id=:id");
            $n = 0;
            foreach ($rows as $r) {
                $abs = receiptSafePath((string)$r['full_path']);
                if (!$abs) continue;
                if (!is_file($abs) && ((int)$r['full_len'] > 0 || (int)$r['web_len'] > 0)) {
                    $upd->execute([':id' => (int)$r['id']]); $n++;
                }
            }
            $result['count'] = $n;
        } elseif ($action === 'mark_thumbnail_only') {
            // 原图已彻底丢失：清掉失效路径，避免健康检查一直提示；查看时回退缩略图
            $rows = db()->query("SELECT id, full_path, OCTET_LENGTH(web) AS web_len, OCTET_LENGTH(`full`) AS full_len
                FROM tx_images WHERE full_path IS NOT NULL AND full_path<>'' LIMIT 1000")->fetchAll();
            $upd = db()->prepare("UPDATE tx_images SET full_path=NULL WHERE id=:id");
            $n = 0;
            foreach ($rows as $r) {
                $abs = receiptSafePath((string)$r['full_path']);
                if (!$abs) continue;
                if (!is_file($abs) && (int)$r['full_len'] <= 0 && (int)$r['web_len'] <= 0) {
                    $upd->execute([':id' => (int)$r['id']]); $n++;
                }
            }
            $result['count'] = $n;
        } elseif ($action === 'run_backup') {
            $b = backupCreate((int)$user['id'], 'health_fix');
            $result['backup'] = $b;
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
