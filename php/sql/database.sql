-- ============================================================================
-- fastcrand — قاعدة البيانات الكاملة في ملف واحد (الجداول + البيانات الافتراضية)
-- ============================================================================
-- طريقة الاستعمال (استضافة عادية):
--   1) أنشئ قاعدة بيانات فارغة + مستخدماً لها من لوحة الاستضافة (الترميز utf8mb4).
--   2) phpMyAdmin ← اختر القاعدة ← Import (استيراد) ← اختر هذا الملف ← Go.
--   3) اكتب بيانات القاعدة في core/config.php (DB_HOST / DB_NAME / DB_USER / DB_PASS).
--
-- سطر الأوامر:   mysql -u USER -p DBNAME < sql/database.sql
--
-- • الملف آمن عند التكرار (CREATE TABLE IF NOT EXISTS + INSERT IGNORE): لا يمسح شيئاً ولا يكرر البيانات.
-- • لا يحتوي أي مستخدم: يُنشأ حساب الأدمن تلقائياً أول زيارة من ADMIN_EMAIL و ADMIN_PASSWORD في core/config.php.
-- • المتطلبات: MySQL >= 8.0.13  أو  MariaDB >= 10.2.1
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- الجداول (26 جدولاً — مطابقة لمخطط app.py الأصلي مع أعمدة داخلية __fc_* لثبات ترتيب الصفوف)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL DEFAULT '',
  `verified` INT DEFAULT 0,
  `verify_token` VARCHAR(255) NULL,
  `google_id` VARCHAR(255) NULL,
  `avatar` LONGTEXT DEFAULT (''),
  `is_admin` INT DEFAULT 0,
  `balance` DOUBLE DEFAULT 0,
  `banned` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `username` VARCHAR(255) NULL,
  `last_daily_bonus` DOUBLE DEFAULT 0,
  `last_seen` DOUBLE DEFAULT 0,
  `last_ai_nudge` DOUBLE DEFAULT 0,
  `telegram_chat_id` VARCHAR(64) DEFAULT '',
  `api_key` VARCHAR(255) DEFAULT '',
  `public_id` VARCHAR(64) DEFAULT '',
  `totp_secret` VARCHAR(255) NULL,
  `totp_enabled` INT NOT NULL DEFAULT 0,
  `totp_recovery` LONGTEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_api_key` (`api_key`),
  KEY `idx_users_public_id` (`public_id`),
  KEY `idx_users_google_id` (`google_id`),
  KEY `idx_users_verify_token` (`verify_token`),
  KEY `idx_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `pending_email_changes` (
  `user_id` INT NOT NULL,
  `new_email` VARCHAR(255) NOT NULL,
  `code` VARCHAR(32) NOT NULL,
  `attempts` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `order_id` VARCHAR(190) NULL,
  `service` VARCHAR(190) NULL,
  `service_name` VARCHAR(1024) DEFAULT '',
  `link` LONGTEXT NULL,
  `quantity` BIGINT NULL,
  `charge` VARCHAR(64) DEFAULT '0',
  `status` VARCHAR(64) DEFAULT 'Pending',
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `provider_id` INT DEFAULT 0,
  `comments` LONGTEXT DEFAULT (''),
  `site_charge` DOUBLE DEFAULT 0,
  `refunded` INT DEFAULT 0,
  `refill_days` VARCHAR(255) DEFAULT '',
  `last_refill_at` DOUBLE DEFAULT 0,
  `refill_count` INT DEFAULT 0,
  `display_id` VARCHAR(64) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_orders_user` (`user_id`, `created_at` DESC),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_display_id` (`display_id`),
  KEY `idx_orders_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `payment_methods` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `icon` VARCHAR(255) DEFAULT 'fa-solid fa-credit-card',
  `number` VARCHAR(512) DEFAULT '',
  `exchange_rate` VARCHAR(64) DEFAULT '',
  `currency` VARCHAR(32) DEFAULT 'IQD',
  `note` LONGTEXT DEFAULT (''),
  `bot_link` VARCHAR(1024) DEFAULT '',
  `active` INT DEFAULT 1,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `method_type` VARCHAR(32) DEFAULT 'manual',
  `method_extras` LONGTEXT DEFAULT ('{}'),
  `sort_order` INT DEFAULT 0,
  `image` LONGTEXT DEFAULT (''),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `subject` VARCHAR(1024) DEFAULT '',
  `category` VARCHAR(255) DEFAULT 'عام',
  `status` VARCHAR(32) DEFAULT 'open',
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  KEY `idx_tk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `ticket_messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ticket_id` INT NULL,
  `sender` VARCHAR(32) DEFAULT 'user',
  `msg_type` VARCHAR(32) DEFAULT 'text',
  `text` LONGTEXT DEFAULT (''),
  `voice_url` VARCHAR(1024) DEFAULT '',
  `duration` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  KEY `idx_tm_ticket` (`ticket_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `recharge_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `amount` DOUBLE DEFAULT 0,
  `method` VARCHAR(255) DEFAULT '',
  `status` VARCHAR(32) DEFAULT 'pending',
  `receipt` LONGTEXT DEFAULT (''),
  `ext_ref` VARCHAR(255) DEFAULT '',
  `auto` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `__fc_ext_uq` VARCHAR(255) GENERATED ALWAYS AS (NULLIF(`ext_ref`, '')) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_recharge_ext_ref` (`__fc_ext_uq`),
  KEY `idx_rr_user` (`user_id`),
  KEY `idx_rr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `asiacell_flows` (
  `user_id` INT NOT NULL,
  `data` LONGTEXT NOT NULL,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `title` VARCHAR(500) DEFAULT '',
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `updated_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  KEY `idx_ai_conv_user` (`user_id`, `updated_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `conversation_id` INT NULL,
  `role` VARCHAR(32) DEFAULT 'user',
  `content` LONGTEXT DEFAULT (''),
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  KEY `idx_ai_msg_conv` (`conversation_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` VARCHAR(64) NOT NULL,
  `user_id` INT NULL,
  `type` VARCHAR(32) DEFAULT 'system',
  `title` VARCHAR(512) DEFAULT '',
  `text` LONGTEXT DEFAULT (''),
  `icon` VARCHAR(64) DEFAULT 'bell',
  `is_read` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `goto` VARCHAR(512) DEFAULT '',
  `__fc_seq` BIGINT NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`, `created_at`),
  UNIQUE KEY `uq_fc_seq` (`__fc_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `referrals` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `code` VARCHAR(64) NULL,
  `referred_by` INT DEFAULT NULL,
  `ref_count` INT DEFAULT 0,
  `earnings` DOUBLE DEFAULT 0,
  `pending` DOUBLE DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_referrals_code` (`code`),
  UNIQUE KEY `uq_referrals_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `referral_history` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `referrer_id` INT NULL,
  `amount` DOUBLE DEFAULT 0,
  `from_order` VARCHAR(190) DEFAULT '',
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  KEY `idx_rh_ref` (`referrer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id` VARCHAR(64) NOT NULL,
  `text` LONGTEXT DEFAULT (''),
  `type` VARCHAR(32) DEFAULT 'info',
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `__fc_seq` BIGINT NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fc_seq` (`__fc_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `custom_services` (
  `id` VARCHAR(64) NOT NULL,
  `name` VARCHAR(512) NOT NULL,
  `type` VARCHAR(32) DEFAULT 'social',
  `desc_text` LONGTEXT DEFAULT (''),
  `icon` VARCHAR(255) DEFAULT '',
  `color` VARCHAR(64) DEFAULT '#4A90FF',
  `image` LONGTEXT DEFAULT (''),
  `rate` DOUBLE DEFAULT 0,
  `min_qty` BIGINT DEFAULT 100,
  `max_qty` BIGINT DEFAULT 100000,
  `packages` LONGTEXT DEFAULT ('[]'),
  `active` INT DEFAULT 1,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `__fc_seq` BIGINT NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fc_seq` (`__fc_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `custom_orders` (
  `id` VARCHAR(64) NOT NULL,
  `user_id` INT NULL,
  `service_id` VARCHAR(64) NULL,
  `service_name` VARCHAR(512) DEFAULT '',
  `type` VARCHAR(32) DEFAULT 'social',
  `package` LONGTEXT DEFAULT (''),
  `link` LONGTEXT DEFAULT (''),
  `quantity` BIGINT DEFAULT 0,
  `comments` LONGTEXT DEFAULT (''),
  `cost` DOUBLE DEFAULT 0,
  `status` VARCHAR(32) DEFAULT 'pending',
  `refunded` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `ticket_id` INT NULL,
  `__fc_seq` BIGINT NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  KEY `idx_co_user` (`user_id`),
  UNIQUE KEY `uq_fc_seq` (`__fc_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- جلسات الأجهزة (تسجيل الدخول): تُعرض في «حسابي ← الأجهزة والجلسات» وتُنهى فردياً. ليست جزءاً من النسخ الاحتياطي.
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `sid` VARCHAR(64) NOT NULL,
  `ip` VARCHAR(64) DEFAULT '',
  `ua` VARCHAR(255) DEFAULT '',
  `device` VARCHAR(120) DEFAULT '',
  `created_at` DOUBLE DEFAULT 0,
  `last_seen` DOUBLE DEFAULT 0,
  `revoked` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_us_sid` (`sid`),
  KEY `idx_us_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `favorites` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `service_id` VARCHAR(190) NOT NULL,
  `service_name` VARCHAR(512) DEFAULT '',
  `platform` VARCHAR(64) DEFAULT '',
  `category` VARCHAR(512) DEFAULT '',
  `rate` VARCHAR(64) DEFAULT '0',
  `min_qty` BIGINT DEFAULT 0,
  `max_qty` BIGINT DEFAULT 0,
  `custom` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_fav_user_svc` (`user_id`, `service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `service_filters` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `filter_type` VARCHAR(64) NOT NULL,
  `filter_key` VARCHAR(500) NOT NULL,
  `platform` VARCHAR(64) DEFAULT '',
  `hidden` INT DEFAULT 0,
  `profit_pct` DOUBLE DEFAULT NULL,
  `prov_id` INT DEFAULT 0,
  `custom_name` VARCHAR(1024) DEFAULT '',
  `custom_category` VARCHAR(1024) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_filters_filter_type_filter_key_platform` (`filter_type`, `filter_key`, `platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `site_settings` (
  `key` VARCHAR(190) NOT NULL,
  `value` LONGTEXT DEFAULT (''),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id` VARCHAR(64) NOT NULL,
  `code` VARCHAR(190) NOT NULL,
  `amount` DOUBLE DEFAULT 0,
  `max_uses` INT DEFAULT 1,
  `used` INT DEFAULT 0,
  `expires_at` VARCHAR(64) DEFAULT '',
  `active` INT DEFAULT 1,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `__fc_seq` BIGINT NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupons_code` (`code`),
  UNIQUE KEY `uq_fc_seq` (`__fc_seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `coupon_uses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `coupon_id` VARCHAR(64) NULL,
  `user_id` INT NULL,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_uses_coupon_id_user_id` (`coupon_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `reviews` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `user_name` VARCHAR(255) DEFAULT '',
  `stars` INT DEFAULT 5,
  `text` LONGTEXT DEFAULT (''),
  `approved` INT DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `providers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `api_url` VARCHAR(1024) DEFAULT '',
  `api_key` VARCHAR(512) DEFAULT '',
  `active` INT DEFAULT 1,
  `is_default` INT DEFAULT 0,
  `balance` DOUBLE DEFAULT 0,
  `balance_updated` DOUBLE DEFAULT 0,
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  `show_in_store` INT DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `levels` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `name_en` VARCHAR(255) NOT NULL,
  `min_spent` DOUBLE DEFAULT 0,
  `discount` DOUBLE DEFAULT 0,
  `color` VARCHAR(64) DEFAULT '#78716c',
  `icon` VARCHAR(255) DEFAULT 'fa-solid fa-seedling',
  `benefits` LONGTEXT DEFAULT (''),
  `active` INT DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `master_key_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `endpoint` VARCHAR(512) DEFAULT '',
  `method` VARCHAR(16) DEFAULT '',
  `ip` VARCHAR(64) DEFAULT '',
  `created_at` DOUBLE DEFAULT (UNIX_TIMESTAMP()),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;


-- ----------------------------------------------------------------------------
-- البيانات الافتراضية: المستويات، طرق الدفع الثابتة (معطّلة)، المزوّد الافتراضي
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (1, 'برونزي', 'BRONZE', 0, 0, '#78716c', 'fa-solid fa-seedling', 'بداية الرحلة', 1, 0);
INSERT IGNORE INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (2, 'فضي', 'SILVER', 50, 3, '#94a3b8', 'fa-solid fa-shield-halved', 'أولوية بالدعم الفني\nخصم 3%', 1, 1);
INSERT IGNORE INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (3, 'ذهبي', 'GOLD', 200, 5, '#f59e0b', 'fa-solid fa-crown', 'خصم 5%\nعروض حصرية\nدعم سريع', 1, 2);
INSERT IGNORE INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (4, 'VIP', 'VIP', 1000, 8, '#6366f1', 'fa-solid fa-gem', 'خصم 8%\nمدير حساب خاص\nأولوية قصوى\nإشعارات أولاً', 1, 3);
INSERT IGNORE INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (5, 'ملكي', 'ROYAL', 5000, 12, '#ec4899', 'fa-solid fa-chess-king', 'خصم 12%\nدعم 24/7\nخدمات مجانية شهرية', 1, 4);
INSERT IGNORE INTO `levels` (`id`,`name`,`name_en`,`min_spent`,`discount`,`color`,`icon`,`benefits`,`active`,`sort_order`) VALUES (6, 'أسطوري', 'LEGEND', 25000, 15, '#ef4444', 'fa-solid fa-dragon', 'خصم 15%\nكل المزايا\nتسعيرة خاصة\nAPI مخصص', 1, 5);

INSERT IGNORE INTO `payment_methods` (`id`,`name`,`icon`,`number`,`exchange_rate`,`currency`,`note`,`bot_link`,`active`,`method_type`,`method_extras`,`sort_order`,`image`) VALUES (1, 'Binance Pay', 'fa-brands fa-bitcoin', '', '', 'USD', '', '', 0, 'binance', '{"api_key": "", "api_secret": ""}', 1, '');
INSERT IGNORE INTO `payment_methods` (`id`,`name`,`icon`,`number`,`exchange_rate`,`currency`,`note`,`bot_link`,`active`,`method_type`,`method_extras`,`sort_order`,`image`) VALUES (2, 'آسياسيل', 'fa-solid fa-mobile-screen', '', '', 'IQD', 'تحويل رصيد آسياسيل مباشر وتلقائي', '', 0, 'asiacell', '{"max_transfer": 10000}', 2, '');
INSERT IGNORE INTO `payment_methods` (`id`,`name`,`icon`,`number`,`exchange_rate`,`currency`,`note`,`bot_link`,`active`,`method_type`,`method_extras`,`sort_order`,`image`) VALUES (3, 'نجوم تليجرام', 'fa-brands fa-telegram', '', '', 'USD', 'دفع تلقائي عبر بوت تليجرام بنجوم Telegram Stars', '', 0, 'telegram_stars', '{"bot_token": "", "bot_username": "", "admin_chat_id": "", "rate_usd_per_100": 1.5}', 3, '');

INSERT IGNORE INTO `providers` (`id`,`name`,`api_url`,`api_key`,`active`,`is_default`,`balance`,`balance_updated`,`show_in_store`) VALUES (1, 'المزود الرئيسي', 'https://smmparty.com/api/v2', '1c0218b705ad1d031562ab810b11552e', 1, 1, 0, 0, 1);
