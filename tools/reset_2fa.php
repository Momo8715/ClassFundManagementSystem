<?php
/**
 * 两步验证恢复脚本（CLI）
 *
 * 用法：
 *   php tools/reset_2fa.php --list
 *   php tools/reset_2fa.php --user=admin            # 清除指定账号的 2FA
 *   php tools/reset_2fa.php --disable-policy        # 关闭“管理员必须启用 2FA”策略
 *   php tools/reset_2fa.php --user=admin --disable-policy
 *
 * 仅在服务器命令行运行；会写入操作日志（操作者记为 cli）。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--') !== 0) continue;
    $a = substr($a, 2);
    $pos = strpos($a, '=');
    if ($pos === false) $args[$a] = true;
    else $args[substr($a, 0, $pos)] = substr($a, $pos + 1);
}

function out(string $s): void { echo $s . PHP_EOL; }

try {
    $db = db();
    if (isset($args['list']) || (empty($args['user']) && empty($args['disable-policy']))) {
        $policy = (string)getMeta('require_2fa', '0') === '1';
        $until = getMeta('require_2fa_grace_until', '');
        out('2FA 强制策略：' . ($policy ? '启用' : '关闭') . ($until ? '，宽限至 ' . $until : ''));
        $rows = $db->query("SELECT id, username, roles, totp_enabled FROM users ORDER BY id")->fetchAll();
        out('账号列表：');
        foreach ($rows as $r) {
            $roles = json_decode((string)$r['roles'], true) ?: [];
            $priv = in_array('admin', $roles, true) || in_array('head_teacher', $roles, true);
            out(sprintf('  #%d %s  2FA=%s%s', $r['id'], $r['username'], !empty($r['totp_enabled']) ? 'on' : 'off', $priv ? '  [管理员/班主任]' : ''));
        }
        if (empty($args['user']) && empty($args['disable-policy'])) {
            out('');
            out('示例：php tools/reset_2fa.php --user=admin --disable-policy');
        }
    }

    if (!empty($args['user'])) {
        $u = (string)$args['user'];
        $st = $db->prepare("SELECT id, username FROM users WHERE username=:u LIMIT 1");
        $st->execute([':u' => $u]);
        $row = $st->fetch();
        if (!$row) { fwrite(STDERR, "用户不存在：{$u}\n"); exit(2); }
        $db->prepare("UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_backup=NULL, totp_last_step=NULL WHERE id=:id")
            ->execute([':id' => (int)$row['id']]);
        try { addLog(0, 'cli', 'reset_2fa_cli', 'user', (int)$row['id'], ['username' => $u]); } catch (\Throwable $e) {}
        out('已清除 ' . $u . ' 的两步验证。');
    }

    if (!empty($args['disable-policy'])) {
        setMeta('require_2fa', '0');
        setMeta('require_2fa_grace_until', '');
        try { addLog(0, 'cli', 'disable_2fa_policy_cli', 'system', null, []); } catch (\Throwable $e) {}
        out('已关闭管理员 2FA 强制策略。');
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '执行失败：' . $e->getMessage() . PHP_EOL);
    exit(1);
}
