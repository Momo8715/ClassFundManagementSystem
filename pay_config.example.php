<?php
/**
 * 支付通道配置模板（易支付 / 彩虹易支付协议）
 * 使用方法：复制为 pay_config.php 并填写，然后 enabled 改为 true
 *   cp pay_config.example.php pay_config.php
 * ⚠️ pay_config.php 已在 .gitignore 中，不会被提交，请勿把密钥写入代码或数据库。
 */
return [
    // 总开关：配置好网关/pid/key 后再改为 true
    'enabled'      => false,

    // 网关地址（不带末尾斜杠），例如 https://pay.example.com
    'gateway'      => 'https://pay.example.com',

    // 商户信息（易支付后台获取）
    'pid'          => '1000',
    'key'          => 'YOUR_MERCHANT_KEY',

    // 签名方式：MD5（易支付默认；部分平台支持 RSA，暂仅实现 MD5）
    'sign_type'    => 'MD5',

    // 默认支付方式：alipay / wxpay / qqpay
    'default_type' => 'alipay',

    // 核销模式：auto=支付成功自动核销；manual=仅记账为已支付，待班委手动确认核销
    'settle_mode'  => 'auto',

    // 回调地址（留空则自动使用 https://当前域名/api.php?action=pay_notify | pay_return）
    'notify_url'   => '',
    'return_url'   => '',

    // 单笔金额限制
    'min_amount'   => 0.01,
    'max_amount'   => 2000.00,

    // 页面/订单展示的站点名
    'sitename'     => '班级班费管理系统',
];
