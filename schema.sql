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
  `roster_id`  INT DEFAULT NULL COMMENT '绑定的花名册学生ID',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首次登录需改密',
  `totp_secret` VARCHAR(64) DEFAULT NULL COMMENT 'TOTP 密钥(Base32)',
  `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用两步验证',
  `totp_backup` TEXT DEFAULT NULL COMMENT '备用码哈希(JSON数组)',
  `totp_last_step` BIGINT DEFAULT NULL COMMENT '最近通过的TOTP时间步',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_roster` (`roster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 收支记录表
CREATE TABLE IF NOT EXISTS `transactions` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `type`            ENUM('income','expense') NOT NULL COMMENT '收入/支出',
  `sub_category`    VARCHAR(50)  DEFAULT NULL COMMENT '子分类: 班费收缴/其他来源(收入), 日常支出/活动支出/采购/其他(支出)',
  `source_info`     VARCHAR(500) DEFAULT NULL COMMENT '其他来源时的具体来源信息',
  `amount`          DECIMAL(10,2) NOT NULL,
  `expected_amount` DECIMAL(10,2) DEFAULT NULL COMMENT '预缴总金额',
  `per_person`      DECIMAL(10,2) DEFAULT NULL COMMENT '每人应缴(班费收缴轮次；总额按各轮相加)',
  `date`            DATE NOT NULL,
  `description`     VARCHAR(500) NOT NULL DEFAULT '',
  `payer_ids`       TEXT DEFAULT NULL COMMENT '缴费学生ID(JSON数组或all)',
  `exempt_ids`      TEXT DEFAULT NULL COMMENT '单次免缴学生ID(JSON数组)',
  `semester_id`     INT DEFAULT NULL COMMENT '所属学期ID',
  `import_batch`    VARCHAR(32) DEFAULT NULL COMMENT '导入批次号',
  `category`        VARCHAR(100) NOT NULL DEFAULT '其他',
  `image_path`      VARCHAR(500) DEFAULT NULL COMMENT '凭证图片路径',
  `images`          TEXT DEFAULT NULL COMMENT '多图凭证(JSON数组)',
  `image_ids`       TEXT DEFAULT NULL COMMENT '凭证图片ID(JSON数组，存tx_images.id，v1.5.6)',
  `recorded_by`     INT NOT NULL,
  `deleted_at`      TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间',
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_type` (`type`),
  KEY `idx_date` (`date`),
  KEY `idx_recorded_by` (`recorded_by`),
  KEY `idx_semester` (`semester_id`),
  KEY `idx_import_batch` (`import_batch`)
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
  `sort_key`   VARCHAR(512) DEFAULT NULL COMMENT '拼音排序键（Hex，字典序=拼音序）',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_sort` (`sort_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='班级学生名单';

-- 学期管理
CREATE TABLE IF NOT EXISTS `semesters` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(50) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date`   DATE NOT NULL,
  `status`     ENUM('active','archived') NOT NULL DEFAULT 'active',
  `opening_balance` DECIMAL(10,2) DEFAULT NULL COMMENT '冻结的期初余额',
  `closing_balance` DECIMAL(10,2) DEFAULT NULL COMMENT '冻结的期末余额',
  `total_income` DECIMAL(10,2) DEFAULT NULL,
  `total_expense` DECIMAL(10,2) DEFAULT NULL,
  `tx_count` INT DEFAULT NULL,
  `pay_expected` DECIMAL(10,2) DEFAULT NULL,
  `pay_collected` DECIMAL(10,2) DEFAULT NULL,
  `pay_rate` DECIMAL(5,1) DEFAULT NULL,
  `snapshot_json` MEDIUMTEXT DEFAULT NULL COMMENT '归档快照(分类/月度)',
  `snapshot_dirty` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '归档后数据有变动',
  `archived_at` DATETIME DEFAULT NULL,
  `archived_by` INT DEFAULT NULL,
  `carried_from` INT DEFAULT NULL COMMENT '从哪个学期结转',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status` (`status`),
  KEY `idx_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='学期管理';

-- 系统元数据（key-value，用于记录 schema 版本，跳过重复迁移）
CREATE TABLE IF NOT EXISTS `system_meta` (
  `meta_key`   VARCHAR(50) PRIMARY KEY,
  `meta_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统元数据';

-- 凭证图片库（v1.5.6：原图+缩略图二进制存数据库，列表优先加载缩略图）
CREATE TABLE IF NOT EXISTS `tx_images` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` INT NOT NULL DEFAULT 0 COMMENT '关联收支记录ID，0=未关联(上传中)',
  `seq`            INT NOT NULL DEFAULT 0 COMMENT '展示顺序',
  `mime`           VARCHAR(50) NOT NULL DEFAULT 'image/jpeg',
  `thumb`          MEDIUMBLOB NOT NULL COMMENT '缩略图(最长边400px,JPEG)',
  `full`           MEDIUMBLOB NOT NULL COMMENT '原图(旧数据兼容,新图存文件)',
  `full_path`      VARCHAR(500) NULL COMMENT '原图文件路径(新方案:uploads/receipts/)',
  `sha256`         CHAR(64) DEFAULT NULL COMMENT '文件/内容校验',
  `byte_size`      INT UNSIGNED DEFAULT NULL COMMENT '原图字节数',
  `px_width`       INT UNSIGNED DEFAULT NULL,
  `px_height`      INT UNSIGNED DEFAULT NULL,
  `web`            MEDIUMBLOB NULL COMMENT 'Web压缩版(原文件丢失时回退)',
  `web_mime`       VARCHAR(50) DEFAULT NULL COMMENT 'Web压缩版MIME',
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_tx` (`transaction_id`),
  KEY `idx_sha` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='凭证图片(数据库存储)';

-- 数据体检「已知问题」忽略记录
CREATE TABLE IF NOT EXISTS `health_ack` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `item_key`   VARCHAR(191) NOT NULL COMMENT 'code:target',
  `code`       VARCHAR(64) NOT NULL,
  `target`     VARCHAR(191) NOT NULL DEFAULT '',
  `reason`     VARCHAR(255) DEFAULT NULL,
  `created_by` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uk_item` (`item_key`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='数据体检忽略项';

-- 备份执行记录
CREATE TABLE IF NOT EXISTS `backup_runs` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `filename`     VARCHAR(191) NOT NULL,
  `file_size`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `db_tables`    INT NOT NULL DEFAULT 0,
  `uploads_count` INT NOT NULL DEFAULT 0,
  `sha256`       CHAR(64) DEFAULT NULL,
  `status`       VARCHAR(20) NOT NULL DEFAULT 'success',
  `error`        VARCHAR(500) DEFAULT NULL,
  `trigger_type` VARCHAR(50) NOT NULL DEFAULT 'manual',
  `created_by`   INT NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_filename` (`filename`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='备份执行记录';


-- 机器人远端绑定（v1.18）
CREATE TABLE IF NOT EXISTS `bot_bindings` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `channel`     VARCHAR(20) NOT NULL DEFAULT 'wechat' COMMENT '插件渠道',
  `remote_user` VARCHAR(191) NOT NULL COMMENT '远端用户唯一标识',
  `student_id`  INT NOT NULL DEFAULT 0,
  `name`        VARCHAR(50) DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_channel_user` (`channel`, `remote_user`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='机器人远端绑定';

-- 机器人通知出站队列（v1.18）
CREATE TABLE IF NOT EXISTS `bot_notifications` (
  `id`           BIGINT AUTO_INCREMENT PRIMARY KEY,
  `event`        VARCHAR(50) NOT NULL,
  `dedupe_key`   VARCHAR(191) DEFAULT NULL,
  `student_id`   INT NOT NULL DEFAULT 0,
  `student_name` VARCHAR(50) DEFAULT NULL,
  `title`        VARCHAR(191) NOT NULL DEFAULT '',
  `content`      TEXT NOT NULL,
  `payload`      LONGTEXT DEFAULT NULL,
  `scope`        VARCHAR(20) NOT NULL DEFAULT 'group',
  `status`       VARCHAR(20) NOT NULL DEFAULT 'pending',
  `push_status`  VARCHAR(20) NOT NULL DEFAULT 'pending',
  `attempts`     INT NOT NULL DEFAULT 0,
  `last_error`   VARCHAR(500) DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `acked_at`     DATETIME DEFAULT NULL,
  `pushed_at`    DATETIME DEFAULT NULL,
  UNIQUE KEY `uk_dedupe` (`dedupe_key`),
  KEY `idx_plugin_poll` (`status`, `id`),
  KEY `idx_push` (`push_status`, `id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='机器人通知队列';

-- 登录 IP 黑名单（命中后 API 层直接拒绝）
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `ip`         VARCHAR(45)  NOT NULL COMMENT 'IPv4/IPv6',
  `reason`     VARCHAR(200) DEFAULT NULL COMMENT '封禁原因',
  `created_by` INT DEFAULT NULL COMMENT '操作管理员ID',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录 IP 黑名单';

-- 安全事件日志（CSRF 失败/非法上传/被拦截 IP 等）
CREATE TABLE IF NOT EXISTS `security_events` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `event`      VARCHAR(100) NOT NULL COMMENT '事件类型',
  `detail`     VARCHAR(500) DEFAULT NULL COMMENT '事件详情(JSON/文本)',
  `ipv4`       VARCHAR(45)  DEFAULT NULL,
  `ipv6`       VARCHAR(45)  DEFAULT NULL,
  `username`   VARCHAR(50)  DEFAULT NULL COMMENT '触发时的登录用户名(如有)',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_created` (`created_at`),
  KEY `idx_event` (`event`),
  KEY `idx_ip` (`ipv4`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='安全事件日志';

-- 支付订单（在线缴费）
CREATE TABLE IF NOT EXISTS `payment_orders` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `order_no`     VARCHAR(40) NOT NULL COMMENT '商户订单号',
  `student_id`   INT NOT NULL DEFAULT 0 COMMENT '花名册学生ID，0=不指定',
  `student_name` VARCHAR(50) DEFAULT NULL,
  `round_id`     INT NOT NULL DEFAULT 0 COMMENT '缴费轮次(transactions.id)，0=通用',
  `amount`       DECIMAL(10,2) NOT NULL,
  `channel`      VARCHAR(20) DEFAULT NULL COMMENT 'alipay/wxpay/qqpay',
  `channel_id`   VARCHAR(32) DEFAULT NULL COMMENT '支付通道ID',
  `status`       VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending/paid/closed',
  `trade_no`     VARCHAR(64) DEFAULT NULL COMMENT '平台订单号',
  `settle_mode`  VARCHAR(10) NOT NULL DEFAULT 'auto' COMMENT 'auto/manual',
  `settled`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已核销',
  `pay_url`      VARCHAR(1000) DEFAULT NULL,
  `notify_raw`   TEXT,
  `created_by`   INT NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `paid_at`      DATETIME DEFAULT NULL,
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_status` (`status`),
  KEY `idx_student` (`student_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='支付订单';

-- 在线缴费核销记录
CREATE TABLE IF NOT EXISTS `fee_payments` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `order_no`     VARCHAR(40) NOT NULL,
  `student_id`   INT NOT NULL DEFAULT 0,
  `student_name` VARCHAR(50) DEFAULT NULL,
  `round_id`     INT NOT NULL DEFAULT 0,
  `amount`       DECIMAL(10,2) NOT NULL,
  `channel`      VARCHAR(20) DEFAULT NULL,
  `trade_no`     VARCHAR(64) DEFAULT NULL,
  `tx_id`        INT DEFAULT NULL COMMENT '对应收支记录ID',
  `paid_at`      DATETIME DEFAULT NULL,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_student` (`student_id`),
  KEY `idx_round` (`round_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='在线缴费核销记录';

-- 导入批次（整批撤销/追溯）
CREATE TABLE IF NOT EXISTS `import_batches` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `batch_no`     VARCHAR(32) NOT NULL,
  `source`       VARCHAR(20) NOT NULL DEFAULT 'xlsx',
  `filename`     VARCHAR(255) DEFAULT NULL,
  `row_count`    INT NOT NULL DEFAULT 0,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `created_by`   INT NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `undone_at`    DATETIME DEFAULT NULL,
  `note`         VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY `uk_batch_no` (`batch_no`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导入批次';

-- QQ 官方机器人绑定（openid -> 学生，用于 @机器人 查询个人班费）
CREATE TABLE IF NOT EXISTS `qq_bindings` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `bind_key`   VARCHAR(191) NOT NULL COMMENT 'group:<group_openid>:<member_openid> / c2c:<openid> / channel:<channel_id>:<user_id>',
  `student_id` INT NOT NULL DEFAULT 0,
  `role`       VARCHAR(20) NOT NULL DEFAULT 'student' COMMENT 'student=学生 / admin=管理员',
  `user_id`    INT NOT NULL DEFAULT 0 COMMENT '管理员对应的系统用户ID',
  `scope`      VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'group/c2c/channel',
  `target`     VARCHAR(191) NOT NULL DEFAULT '' COMMENT '群 openid / 频道 id',
  `openid`     VARCHAR(191) NOT NULL DEFAULT '' COMMENT '成员/用户 openid',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_bind_key` (`bind_key`),
  KEY `idx_student` (`student_id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QQ机器人绑定';
