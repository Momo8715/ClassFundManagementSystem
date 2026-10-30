<?php
/**
 * 纯函数冒烟测试（不连接数据库）
 * 用法：php tools/smoke.php
 */
require_once __DIR__ . '/../src/helpers.php';
require_once __DIR__ . '/../src/totp.php';
require_once __DIR__ . '/../src/fee_ledger.php';
require_once __DIR__ . '/../src/import_export.php';

$fail = 0;
function ok($cond, string $name, string $extra = ''): void {
    global $fail;
    if ($cond) {
        echo "  ✅ {$name}\n";
    } else {
        $fail++;
        echo "  ❌ {$name}" . ($extra !== '' ? "（{$extra}）" : '') . "\n";
    }
}

echo "[TOTP]\n";
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32("12345678901234567890")
ok(totpBase32Decode($secret) === '12345678901234567890', 'Base32 解码 RFC 测试串');
ok(totpBase32Encode('12345678901234567890') === $secret, 'Base32 编码 RFC 测试串');
ok(totpCode($secret, 1, 6) === '287082', 'RFC 6238 T=1 的 6 位码', totpCode($secret, 1, 6));
ok(totpCode($secret, 1, 8) === '94287082', 'RFC 6238 T=1 的 8 位码', totpCode($secret, 1, 8));
$nowCode = totpCode($secret);
$v = totpVerify($secret, $nowCode, 1, null);
ok($v['ok'] === true, '当前时间码校验通过');
$v2 = totpVerify($secret, '000000', 1, null);
ok($v2['ok'] === false, '错误码校验失败');
$backup = totpGenerateBackupCodes(8);
ok(count($backup['codes']) === 8 && count(array_unique($backup['codes'])) === 8, '生成 8 个不重复备用码');
$idx = totpVerifyBackupCode($backup['codes'][0], $backup['hashes']);
ok($idx === 0, '备用码哈希可校验');
ok(totpVerifyBackupCode('ZZZZZ-ZZZZZ', $backup['hashes']) === null, '无效备用码被拒绝');
ok(strpos(totpUri($secret, '张三', '班级班费'), 'secret=' . $secret) !== false, 'otpauth 链接包含密钥');

echo "[金额大写]\n";
ok(amountToChinese(0) === '零元整', '0 元');
ok(amountToChinese(1234.56) === '壹仟贰佰叁拾肆元伍角陆分', '1234.56', amountToChinese(1234.56));
ok(amountToChinese(100) === '壹佰元整', '100 元', amountToChinese(100));

echo "[费用账本纯函数]\n";
ok(abs(feeRoundPerPerson(['per_person' => '10.00', 'amount' => 0], 5) - 10.0) < 0.001, '显式每人应缴优先');
ok(abs(feeRoundPerPerson(['per_person' => null, 'expected_amount' => '50.00', 'amount' => 0], 5) - 10.0) < 0.001, '应收总额/人数兜底');
ok(abs(feeRoundPerPerson(['per_person' => null, 'expected_amount' => null, 'amount' => '30.00', 'payer_ids' => '[1,2,3]'], 5) - 10.0) < 0.001, '实收/缴费人数兜底');
ok(feeRoundPerPerson(['per_person' => null, 'expected_amount' => null, 'amount' => 0, 'payer_ids' => null], 5) === 0.0, '无金额返回 0');
$parsed = feeParseRound(['id' => 7, 'payer_ids' => 'all', 'exempt_ids' => '[2,3]', 'per_person' => '5.00'], 10);
ok($parsed['all'] === true && $parsed['exids'] === [2, 3] && abs($parsed['pp'] - 5.0) < 0.001, '轮次解析（all + 免缴）');

echo "\n" . ($fail === 0 ? '全部通过 ✅' : "失败 {$fail} 项 ❌") . "\n";
exit($fail === 0 ? 0 : 1);
