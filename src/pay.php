<?php
/**
 * 班级班费管理系统 - 在线支付模块入口（拆分后保持向后兼容）
 * 具体实现见 pay_core.php / pay_reminder.php / qq_bot.php / fee_ledger.php
 */
require_once __DIR__ . '/fee_ledger.php';
require_once __DIR__ . '/pay_core.php';
require_once __DIR__ . '/pay_reminder.php';
require_once __DIR__ . '/qq_bot.php';
