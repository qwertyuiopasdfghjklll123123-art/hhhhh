-- قاعدة بيانات واصل (PHP + MySQL) - مقابلة لجداول init_db() بـ webapp.py (Flask/SQLite).
-- آمن عند إعادة الاستيراد: كل جملة CREATE TABLE IF NOT EXISTS.
-- لا يحتوي أي حساب مستخدم - حساب الأدمن يُنشأ تلقائياً من core/config.php بأول زيارة.

CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(190) UNIQUE,
    phone VARCHAR(32) UNIQUE,
    name VARCHAR(190),
    password_hash VARCHAR(255),
    is_admin TINYINT NOT NULL DEFAULT 0,
    plan_active TINYINT NOT NULL DEFAULT 0,
    trial_ends_at VARCHAR(32),
    trial_ended_notified TINYINT NOT NULL DEFAULT 0,
    plan_ends_at VARCHAR(32),
    created_at VARCHAR(32) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS ai_settings (
    id INT PRIMARY KEY,
    api_key TEXT,
    knowledge_base TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    plan_name VARCHAR(190) NOT NULL,
    amount_iqd INT NOT NULL,
    reference VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at VARCHAR(32) NOT NULL,
    INDEX idx_payments_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS wa_accounts (
    id VARCHAR(32) PRIMARY KEY,
    owner INT NOT NULL,
    name VARCHAR(190),
    auto_reply_enabled TINYINT NOT NULL DEFAULT 0,
    auto_reply_ai_enabled TINYINT NOT NULL DEFAULT 0,
    auto_reply_rules TEXT NOT NULL,
    otp_sender TINYINT NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL DEFAULT '',
    INDEX idx_wa_accounts_owner (owner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS site_settings (
    id INT PRIMARY KEY,
    port INT,
    domain VARCHAR(190) DEFAULT '',
    logo TEXT,
    site_name VARCHAR(190) DEFAULT '',
    default_country_code VARCHAR(8) DEFAULT '964',
    whatsapp_subscribe_number VARCHAR(32) DEFAULT '',
    whatsapp_support_number VARCHAR(32) DEFAULT '',
    plan_price_iqd INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS subscribers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    owner INT NOT NULL,
    name VARCHAR(190) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    subscribed_at VARCHAR(32) NOT NULL,
    duration_days INT NOT NULL,
    reminder_sent TINYINT NOT NULL DEFAULT 0,
    expired_sent TINYINT NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL DEFAULT '',
    INDEX idx_subscribers_owner (owner)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS subscription_settings (
    owner INT PRIMARY KEY,
    default_duration_days INT NOT NULL DEFAULT 30,
    reminder_days_before INT NOT NULL DEFAULT 3,
    reminder_message TEXT NOT NULL,
    expired_message TEXT NOT NULL,
    start_message TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- رموز تحقق واتساب المعلّقة (جديد بالنسخة PHP: webapp.py يحفظها بالذاكرة فقط لأن عمليته
-- تبقى شغالة دائماً؛ كل طلب PHP قصير العمر فيحتاج جدولاً يشاركه كل الطلبات)
CREATE TABLE IF NOT EXISTS otp_codes (
    phone VARCHAR(32) PRIMARY KEY,
    code VARCHAR(10) NOT NULL,
    expires_at BIGINT NOT NULL,
    attempts INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS api_keys (
    id INT PRIMARY KEY AUTO_INCREMENT,
    owner INT NOT NULL,
    account_id VARCHAR(32) NOT NULL,
    label VARCHAR(60) NOT NULL DEFAULT '',
    key_hash VARCHAR(64) NOT NULL,
    key_prefix VARCHAR(16) NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT '',
    last_used_at VARCHAR(32),
    revoked TINYINT NOT NULL DEFAULT 0,
    INDEX idx_api_keys_owner (owner),
    INDEX idx_api_keys_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
