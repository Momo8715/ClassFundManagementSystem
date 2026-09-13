<?php
/**
 * 支付通道配置模板（支持易支付 与 V免签 两种类型）
 * 使用方法：复制为 pay_config.php 并填写，然后 enabled 改为 true
 *   cp pay_config.example.php pay_config.php
 * ⚠️ pay_config.php 已在 .gitignore 中，不会被提交，请勿把密钥写入代码或数据库。
 */
return [
    // 总开关
    'enabled'    => false,
    'channels'   => [
        // ── 示例一：易支付 / 彩虹易支付 ─────────────────────────────
        [
            'id'      => 'ch_epay_1',
            'name'    => '易支付主通道',
            'driver'  => 'epay',                       // epay=易支付协议（MD5 签名）
            'gateway' => 'https://pay.example.com',    // 网关地址，不带末尾斜杠
            'pid'     => '1000',                       // 商户ID（V免签不需要）
            'key'     => 'YOUR_MERCHANT_KEY',          // 商户密钥
            'types'   => ['alipay', 'wxpay'],
            'enabled' => false,
        ],
        // ── 示例二：V免签（vmqfox）──────────────────────────────────
        [
            'id'      => 'ch_vmqfox_1',
            'name'    => 'V免签通道',
            'driver'  => 'vmqfox',                     // vmqfox=V免签协议（HMAC 签名）
            'gateway' => 'https://vmqfox.example.com', // V免签服务地址
            'pid'     => '',                           // 不需要
            'key'     => 'YOUR_VMQFOX_COMM_KEY',       // 通讯密钥（32 位）
            'types'   => ['alipay', 'wxpay'],
            'enabled' => false,
        ],
    ],

    'methods'      => [
        ['code' => 'alipay', 'label' => '支付宝'],
        ['code' => 'wxpay',  'label' => '微信支付'],
    ],

    'sign_type'    => 'MD5',
    'default_type' => 'alipay',
    'settle_mode'  => 'auto',   // auto=支付成功自动核销；manual=待班委手动确认
    'notify_url'   => '',       // 留空自动使用 https://当前域名/api.php?action=pay_notify
    'return_url'   => '',
    'min_amount'   => 0.01,
    'max_amount'   => 2000.00,
    'sitename'     => '班级班费管理系统',
];
