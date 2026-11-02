-- نفّذ هذا الملف مرة واحدة في phpMyAdmin إن كان موقعك مُنصَّباً من نسخة سابقة بدون
-- جدول import_progress (شريط تقدّم الاستيراد الحي في لوحة التحكم)
CREATE TABLE IF NOT EXISTS import_progress (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    current_step INT UNSIGNED NOT NULL DEFAULT 0,
    total_steps INT UNSIGNED NOT NULL DEFAULT 0,
    label VARCHAR(255) DEFAULT NULL,
    status ENUM('idle','running','done','error') NOT NULL DEFAULT 'idle',
    message TEXT DEFAULT NULL,
    started_at DATETIME DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
