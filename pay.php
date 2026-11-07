<?php
// 兼容旧链接：统一回到原应用的在线缴费页，不再单独展示缴费页
$sid = (int)($_GET['pay'] ?? $_GET['student'] ?? 0);
$target = '/' . ($sid > 0 ? '?pay=' . $sid . '#page-pay' : '#page-pay');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ' . $target, true, 302);
exit;
