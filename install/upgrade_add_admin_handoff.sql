-- نفّذ هذا الملف مرة واحدة في phpMyAdmin إن كان موقعك مُنصَّباً من نسخة سابقة بدون
-- جدول admin_handoff_tokens (رمز الانتقال الآمن من الموقع الرئيسي إلى لوحة التحكم)
CREATE TABLE IF NOT EXISTS admin_handoff_tokens (
    token VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
