<?php
/**
 * نسخة مرجعية من ملف الإعدادات.
 * لا تستخدم هذا الملف مباشرة — يقوم المثبّت (install/) بإنشاء config/config.php
 * تلقائياً بعد إدخال بيانات الاتصال بقاعدة البيانات عبر واجهة التثبيت.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'database_name');
define('DB_USER', 'database_user');
define('DB_PASS', 'database_password');
define('DB_CHARSET', 'utf8mb4');

// رابط الجذر للنظام بدون سلاش في النهاية، مثال: https://example.com/hr
define('APP_URL', 'http://localhost');

// مفتاح عشوائي يُستخدم لتشفير الجلسات وحماية النماذج (CSRF)
define('APP_SECRET', 'change-this-random-secret');

define('APP_INSTALLED', true);
