<?php
/**
 * ملف إعدادات قاعدة البيانات — عدّل القيم تحت حسب بيانات MySQL الحقيقية
 * على سيرفرك، ثم شغّل install.php مرة واحدة لاستيراد الجدول.
 *
 * تنبيه أمني: لا ترفع هذا الملف لمستودع عام بعد وضع كلمة مرور حقيقية فيه.
 */

define('DB_HOST', 'localhost');       // عنوان سيرفر MySQL
define('DB_NAME', 'fastcrand');       // اسم قاعدة البيانات
define('DB_USER', 'fastcrand_user');  // اسم المستخدم
define('DB_PASS', 'CHANGE_ME');       // كلمة المرور
define('DB_CHARSET', 'utf8mb4');

// مسار ملف الشيما (يستخدمه install.php فقط)
define('SCHEMA_SQL_PATH', __DIR__ . '/sql/schema.sql');

// مفتاح جلسات التطبيق — غيّره لقيمة عشوائية خاصة بك
define('APP_SECRET', 'change_this_to_a_random_secret_string');

// رابط API مزود SMM الافتراضي (يمكن أيضاً تعديله من جدول providers بعد التثبيت)
define('DEFAULT_SMM_API_URL', 'https://smmparty.com/api/v2');
