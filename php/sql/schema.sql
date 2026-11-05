-- ============================================================
-- fastcrand — MySQL schema (منقول من نسخة Flask/SQLite الأصلية app.py)
-- يحتوي كل الجداول (25 جدول) بشكلها النهائي الحالي + بيانات أولية أساسية.
-- استورده عبر php/install.php أو يدوياً:
--   mysql -u USER -p DBNAME < schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- المستخدمون
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL DEFAULT '',
  verified TINYINT(1) NOT NULL DEFAULT 0,
  verify_token VARCHAR(190) DEFAULT NULL,
  google_id VARCHAR(190) DEFAULT NULL,
  avatar VARCHAR(255) NOT NULL DEFAULT '',
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  balance DECIMAL(14,4) NOT NULL DEFAULT 0,
  banned TINYINT(1) NOT NULL DEFAULT 0,
  username VARCHAR(100) DEFAULT NULL,
  last_daily_bonus BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_ai_nudge BIGINT UNSIGNED NOT NULL DEFAULT 0,
  telegram_chat_id VARCHAR(64) NOT NULL DEFAULT '',
  api_key VARCHAR(128) NOT NULL DEFAULT '',
  public_id VARCHAR(64) NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pending_email_changes (
  user_id INT UNSIGNED PRIMARY KEY,
  new_email VARCHAR(190) NOT NULL,
  code VARCHAR(20) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- الطلبات والمزودين والخدمات
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  api_url VARCHAR(255) NOT NULL DEFAULT '',
  api_key VARCHAR(190) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  show_in_store TINYINT(1) NOT NULL DEFAULT 0,
  balance DECIMAL(14,4) NOT NULL DEFAULT 0,
  balance_updated BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  order_id VARCHAR(64) DEFAULT NULL,
  provider_id INT UNSIGNED NOT NULL DEFAULT 0,
  service VARCHAR(64) DEFAULT NULL,
  service_name VARCHAR(255) NOT NULL DEFAULT '',
  link VARCHAR(500) DEFAULT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  comments VARCHAR(500) NOT NULL DEFAULT '',
  charge VARCHAR(32) NOT NULL DEFAULT '0',
  site_charge DECIMAL(14,4) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'Pending',
  refunded TINYINT(1) NOT NULL DEFAULT 0,
  refill_days VARCHAR(16) NOT NULL DEFAULT '',
  last_refill_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  refill_count INT UNSIGNED NOT NULL DEFAULT 0,
  display_id VARCHAR(32) NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_orders_user (user_id, created_at),
  KEY idx_orders_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_filters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filter_type VARCHAR(32) NOT NULL,
  filter_key VARCHAR(64) NOT NULL,
  platform VARCHAR(100) NOT NULL DEFAULT '',
  prov_id INT UNSIGNED NOT NULL DEFAULT 0,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  profit_pct DECIMAL(10,4) DEFAULT NULL,
  custom_name VARCHAR(255) NOT NULL DEFAULT '',
  custom_category VARCHAR(190) NOT NULL DEFAULT '',
  UNIQUE KEY uq_filter (filter_type, filter_key, platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- المحفظة والدفع
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_methods (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  icon VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-credit-card',
  number VARCHAR(190) NOT NULL DEFAULT '',
  exchange_rate VARCHAR(32) NOT NULL DEFAULT '',
  currency VARCHAR(10) NOT NULL DEFAULT 'IQD',
  note VARCHAR(500) NOT NULL DEFAULT '',
  bot_link VARCHAR(255) NOT NULL DEFAULT '',
  image VARCHAR(255) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  method_type VARCHAR(32) NOT NULL DEFAULT 'manual',
  method_extras TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recharge_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  method VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  receipt VARCHAR(255) NOT NULL DEFAULT '',
  ext_ref VARCHAR(190) DEFAULT NULL,
  auto TINYINT(1) NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_recharge_ext_ref (ext_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asiacell_flows (
  user_id INT UNSIGNED PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id VARCHAR(64) PRIMARY KEY,
  code VARCHAR(64) NOT NULL,
  amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  max_uses INT NOT NULL DEFAULT 1,
  used INT NOT NULL DEFAULT 0,
  expires_at VARCHAR(32) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_coupon_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupon_uses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id VARCHAR(64) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_coupon_user (coupon_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- الدعم الفني
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(255) NOT NULL DEFAULT '',
  category VARCHAR(100) NOT NULL DEFAULT 'عام',
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  sender VARCHAR(16) NOT NULL DEFAULT 'user',
  msg_type VARCHAR(16) NOT NULL DEFAULT 'text',
  text TEXT NOT NULL,
  voice_url VARCHAR(255) NOT NULL DEFAULT '',
  duration INT NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_ticket_msg (ticket_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- المساعد الذكي (AI)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_ai_conv_user (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'user',
  content MEDIUMTEXT NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_ai_msg_conv (conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- الإشعارات والإعلانات والتقييمات
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
  id VARCHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'system',
  title VARCHAR(255) NOT NULL DEFAULT '',
  text VARCHAR(500) NOT NULL DEFAULT '',
  icon VARCHAR(64) NOT NULL DEFAULT 'bell',
  goto VARCHAR(190) NOT NULL DEFAULT '',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_notif_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id VARCHAR(64) PRIMARY KEY,
  text VARCHAR(500) NOT NULL DEFAULT '',
  type VARCHAR(32) NOT NULL DEFAULT 'info',
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  user_name VARCHAR(190) NOT NULL DEFAULT '',
  stars TINYINT UNSIGNED NOT NULL DEFAULT 5,
  text VARCHAR(1000) NOT NULL DEFAULT '',
  approved TINYINT(1) NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- الإحالة (Referrals)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS referrals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code VARCHAR(32) NOT NULL,
  referred_by INT UNSIGNED DEFAULT NULL,
  ref_count INT NOT NULL DEFAULT 0,
  earnings DECIMAL(14,4) NOT NULL DEFAULT 0,
  pending DECIMAL(14,4) NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_ref_user (user_id),
  UNIQUE KEY uq_ref_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referral_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,4) NOT NULL DEFAULT 0,
  from_order VARCHAR(64) NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- الخدمات المخصّصة (custom services) والمفضّلة
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS custom_services (
  id VARCHAR(64) PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'social',
  desc_text VARCHAR(500) NOT NULL DEFAULT '',
  icon VARCHAR(100) NOT NULL DEFAULT '',
  color VARCHAR(16) NOT NULL DEFAULT '#4A90FF',
  image VARCHAR(255) NOT NULL DEFAULT '',
  rate DECIMAL(14,4) NOT NULL DEFAULT 0,
  min_qty INT NOT NULL DEFAULT 100,
  max_qty INT NOT NULL DEFAULT 100000,
  packages TEXT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_orders (
  id VARCHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  service_id VARCHAR(64) NOT NULL,
  service_name VARCHAR(190) NOT NULL DEFAULT '',
  type VARCHAR(32) NOT NULL DEFAULT 'social',
  package VARCHAR(190) NOT NULL DEFAULT '',
  link VARCHAR(500) NOT NULL DEFAULT '',
  quantity INT NOT NULL DEFAULT 0,
  comments VARCHAR(500) NOT NULL DEFAULT '',
  cost DECIMAL(14,4) NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  refunded TINYINT(1) NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  service_id VARCHAR(64) NOT NULL,
  service_name VARCHAR(190) NOT NULL DEFAULT '',
  platform VARCHAR(100) NOT NULL DEFAULT '',
  category VARCHAR(190) NOT NULL DEFAULT '',
  rate VARCHAR(32) NOT NULL DEFAULT '0',
  min_qty INT NOT NULL DEFAULT 0,
  max_qty INT NOT NULL DEFAULT 0,
  custom TINYINT(1) NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_fav_user_svc (user_id, service_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- الإعدادات والمستويات والسجلات
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS site_settings (
  `key` VARCHAR(100) PRIMARY KEY,
  value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS levels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  name_en VARCHAR(100) NOT NULL,
  min_spent DECIMAL(14,4) NOT NULL DEFAULT 0,
  discount DECIMAL(6,2) NOT NULL DEFAULT 0,
  color VARCHAR(16) NOT NULL DEFAULT '#78716c',
  icon VARCHAR(100) NOT NULL DEFAULT 'fa-solid fa-seedling',
  benefits VARCHAR(500) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS master_key_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  endpoint VARCHAR(255) NOT NULL DEFAULT '',
  method VARCHAR(16) NOT NULL DEFAULT '',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- بيانات أولية (seed) — نفس قيم نسخة app.py الأصلية
-- ============================================================

INSERT INTO levels (name, name_en, min_spent, discount, color, icon, benefits, active, sort_order) VALUES
('برونزي','BRONZE',0,0,'#78716c','fa-solid fa-seedling','بداية الرحلة',1,0),
('فضي','SILVER',50,3,'#94a3b8','fa-solid fa-shield-halved','أولوية بالدعم الفني\nخصم 3%',1,1),
('ذهبي','GOLD',200,5,'#f59e0b','fa-solid fa-crown','خصم 5%\nعروض حصرية\nدعم سريع',1,2),
('VIP','VIP',1000,8,'#6366f1','fa-solid fa-gem','خصم 8%\nمدير حساب خاص\nأولوية قصوى\nإشعارات أولاً',1,3),
('ملكي','ROYAL',5000,12,'#ec4899','fa-solid fa-chess-king','خصم 12%\nدعم 24/7\nخدمات مجانية شهرية',1,4),
('أسطوري','LEGEND',25000,15,'#ef4444','fa-solid fa-dragon','خصم 15%\nكل المزايا\nتسعيرة خاصة\nAPI مخصص',1,5);

-- مزوّد SMM افتراضي — عدّل api_url/api_key من لوحة الإدارة بعد التثبيت
INSERT INTO providers (name, api_url, api_key, active, is_default, show_in_store, created_at) VALUES
('المزود الرئيسي', 'https://smmparty.com/api/v2', 'REPLACE_WITH_REAL_PROVIDER_API_KEY', 1, 1, 1, UNIX_TIMESTAMP());

-- طرق الدفع الثابتة (معطّلة افتراضياً — تُفعَّل وتُعبّأ من لوحة الإدارة)
INSERT INTO payment_methods (name, icon, currency, note, active, sort_order, method_type, method_extras, created_at) VALUES
('Binance Pay', 'fa-brands fa-bitcoin', 'USD', '', 0, 1, 'binance', '{"api_key":"","api_secret":""}', UNIX_TIMESTAMP()),
('آسياسيل', 'fa-solid fa-mobile-screen', 'IQD', 'تحويل رصيد آسياسيل مباشر وتلقائي', 0, 2, 'asiacell', '{"max_transfer":10000}', UNIX_TIMESTAMP()),
('نجوم تليجرام', 'fa-brands fa-telegram', 'USD', 'دفع تلقائي عبر بوت تليجرام بنجوم Telegram Stars', 0, 3, 'telegram_stars', '{"bot_token":"","bot_username":"","admin_chat_id":"","rate_usd_per_100":1.5}', UNIX_TIMESTAMP());

-- مستخدم تجربة + حساب أدمن (غيّر كلمة مرور الأدمن فوراً بعد أول تسجيل دخول!)
-- password = SHA256('123456') / SHA256('2sebvWFr6BYwVBrV')
INSERT INTO users (name, email, password, verified, is_admin, created_at) VALUES
('حمزة', 'test@test.com', SHA2('123456', 256), 1, 0, UNIX_TIMESTAMP()),
('الأدمن', 'admin@admin.com', SHA2('2sebvWFr6BYwVBrV', 256), 1, 1, UNIX_TIMESTAMP());
