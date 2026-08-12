-- ============================================
-- 班级班费管理系统 - MySQL 数据库结构
-- 宝塔面板：在数据库管理 → SQL 框中粘贴执行
-- 或直接访问 install.php 自动建表
-- ============================================

-- 用户表
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `username`   VARCHAR(50)  NOT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `roles`      VARCHAR(255) NOT NULL DEFAULT '["student"]' COMMENT 'JSON数组',
  `banned`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否封禁',
  `ban_reason` VARCHAR(500) DEFAULT NULL COMMENT '封禁理由',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 收支记录表
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `type`            ENUM('income','expense') NOT NULL COMMENT '收入/支出',
  `sub_category`    VARCHAR(50)  DEFAULT NULL COMMENT '子分类: 班费收缴/其他来源(收入), 日常支出/活动支出/采购/其他(支出)',
  `source_info`     VARCHAR(500) DEFAULT NULL COMMENT '其他来源时的具体来源信息',
  `amount`          DECIMAL(10,2) NOT NULL,
  `expected_amount` DECIMAL(10,2) DEFAULT NULL COMMENT '预缴总金额',
  `date`            DATE NOT NULL,
  `description`     VARCHAR(500) NOT NULL DEFAULT '',
  `payer_ids`       TEXT DEFAULT NULL COMMENT '缴费学生ID(JSON数组或all)',
  `category`        VARCHAR(100) NOT NULL DEFAULT '其他',
  `image_path`      VARCHAR(500) DEFAULT NULL COMMENT '凭证图片路径',
  `images`          TEXT DEFAULT NULL COMMENT '多图凭证(JSON数组)',
  `recorded_by`     INT NOT NULL,
  `deleted_at`      TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_type` (`type`),
  KEY `idx_date` (`date`),
  KEY `idx_recorded_by` (`recorded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 操作日志表（不可变 — 应用程序永不执行UPDATE/DELETE）
CREATE TABLE IF NOT EXISTS `operation_logs` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`       INT NOT NULL,
  `username`      VARCHAR(50) NOT NULL,
  `action`        VARCHAR(100) NOT NULL COMMENT '操作类型',
  `target_type`   VARCHAR(50) NOT NULL COMMENT '操作对象: transaction/user/system',
  `target_id`     INT DEFAULT NULL,
  `details`       TEXT COMMENT 'JSON详细描述',
  `ip_address`    VARCHAR(45) DEFAULT NULL COMMENT 'IPv4地址',
  `ipv6_address`  VARCHAR(45) DEFAULT NULL COMMENT 'IPv6地址',
  `user_agent`    TEXT COMMENT '浏览器User-Agent',
  `browser_info`  VARCHAR(500) DEFAULT NULL COMMENT '解析后的浏览器信息(操作系统/浏览器/版本)',
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_id` (`user_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='操作日志（不可删除、不可修改）';

-- 登录历史表（独立记录每次登录尝试，成功/失败均记录）
CREATE TABLE IF NOT EXISTS `login_history` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT DEFAULT 0,
  `username`     VARCHAR(50) NOT NULL,
  `login_type`   VARCHAR(20) NOT NULL DEFAULT 'password' COMMENT 'password/guest',
  `success`      TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=成功 0=失败',
  `ipv4_address` VARCHAR(45) DEFAULT NULL,
  `ipv6_address` VARCHAR(45) DEFAULT NULL,
  `user_agent`   TEXT,
  `browser_info` VARCHAR(500) DEFAULT NULL COMMENT '浏览器+操作系统信息',
  `fingerprint`  VARCHAR(64) DEFAULT NULL COMMENT '浏览器指纹',
  `fail_reason`  VARCHAR(200) DEFAULT NULL COMMENT '失败原因',
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_id` (`user_id`),
  KEY `idx_success` (`success`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='登录历史（可用于安全审计）';

-- 班级花名册
CREATE TABLE IF NOT EXISTS `class_roster` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(50) NOT NULL,
  `exempt`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否免缴',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='班级学生名单';

-- 学期管理
CREATE TABLE IF NOT EXISTS `semesters` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(50) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date`   DATE NOT NULL,
  `status`     ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='学期管理';

-- 系统元数据（key-value，用于记录 schema 版本，跳过重复迁移）
CREATE TABLE IF NOT EXISTS `system_meta` (
  `meta_key`   VARCHAR(50) PRIMARY KEY,
  `meta_value` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统元数据';
