<?php
/**
 * إعدادات الموقع — انسخ هذا الملف إلى config.php وعدّل القيم.
 * (أو شغّل install.php وسيكتبه لك بعد إدخال بيانات MySQL.)
 *
 * كل الثوابت المكتوبة هنا كانت ثوابت ببداية app.py في نسخة بايثون (نفس القيم الافتراضية).
 */

// ===== MySQL =====
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'fastcrand');
define('DB_USER', 'fastcrand_user');
define('DB_PASS', 'CHANGE_ME');

// ===== الموقع =====
define('SECRET_KEY', 'fc_s3cr3t_k3y_2026_fastcrand_permanent'); // يوقّع كوكي الجلسة — غيّره لقيمة سرّية خاصة بك
define('DOMAIN', 'fastcrand.com');

// مجلد البيانات المكتوبة (صور/صوتيات/نسخ احتياطية/كاش/سجلات) — يجب أن يكون قابلاً للكتابة من PHP
define('DATA_DIR', dirname(__DIR__) . '/data');

// ===== مزوّد SMM الافتراضي (يُزرع بجدول providers عند أول تثبيت) =====
define('SMM_API_URL', 'https://smmparty.com/api/v2');
define('SMM_API_KEY', '1c0218b705ad1d031562ab810b11552e');

// ===== بريد SMTP الافتراضي (يمكن تغييره من لوحة الإدارة) =====
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'noreply@' . DOMAIN);
define('SMTP_PASS', 'YOUR_EMAIL_PASSWORD');

// ===== تسجيل الدخول بجوجل (يمكن تغييره من لوحة الإدارة) =====
define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');
