<?php
/**
 * تهيئة التطبيق: الإعدادات + المكتبات + معالجات الأخطاء + تسجيل المسارات.
 * يُستدعى من public/index.php (ويب) ومن cron.php / tools/*.php (سطر الأوامر).
 */
define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

// FC_CONFIG: مسار بديل لملف الإعدادات (لأدوات سطر الأوامر والاختبارات)
$__fc_config = getenv('FC_CONFIG') ?: APP_DIR . '/config.php';
if (!is_file($__fc_config)) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "config.php غير موجود — شغّل install.php من المتصفح أو انسخ app/config.sample.php إلى app/config.php\n"); exit(1); }
    header('Location: /install.php');
    exit;
}
require $__fc_config;

// قيم افتراضية لإعدادات قد تكون ناقصة في config.php قديم
defined('DB_PORT') or define('DB_PORT', 3306);
defined('DOMAIN') or define('DOMAIN', 'fastcrand.com');
defined('DATA_DIR') or define('DATA_DIR', ROOT_DIR . '/data');
defined('SECRET_KEY') or define('SECRET_KEY', 'fc_s3cr3t_k3y_2026_fastcrand_permanent');
defined('SMM_API_URL') or define('SMM_API_URL', 'https://smmparty.com/api/v2');
defined('SMM_API_KEY') or define('SMM_API_KEY', '1c0218b705ad1d031562ab810b11552e');
defined('SMTP_HOST') or define('SMTP_HOST', 'smtp.hostinger.com');
defined('SMTP_PORT') or define('SMTP_PORT', 465);
defined('SMTP_USER') or define('SMTP_USER', 'noreply@' . DOMAIN);
defined('SMTP_PASS') or define('SMTP_PASS', 'YOUR_EMAIL_PASSWORD');
defined('GOOGLE_CLIENT_ID') or define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');

// المنطقة الزمنية: بايثون كان يستعمل توقيت السيرفر المحلي
if (defined('TIMEZONE') && TIMEZONE) {
    date_default_timezone_set(TIMEZONE);
} else {
    $tz = @trim((string)@file_get_contents('/etc/timezone'));
    @date_default_timezone_set($tz !== '' ? $tz : 'UTC');
}
mb_internal_encoding('UTF-8');
ini_set('precision', '-1');             // تحويل العشري لنص بأقصى دقة (قريب من repr بايثون)
ini_set('serialize_precision', '-1');
ini_set('display_errors', '0');
error_reporting(E_ALL);
@ini_set('memory_limit', '512M');
@set_time_limit(120);

// مجلدات البيانات (تقابل /root/fc_* بنسخة بايثون)
define('VOICE_DIR', DATA_DIR . '/fc_voices');
define('TK_IMG_DIR', DATA_DIR . '/fc_ticket_images');
define('AVATAR_DIR', DATA_DIR . '/fc_avatars');
define('PM_IMG_DIR', DATA_DIR . '/fc_pm_images');
define('BACKUP_DIR', DATA_DIR . '/fc_backups');
define('PWA_ICON_DIR', DATA_DIR . '/fc_pwa_icon');
define('LOGO_DIR', DATA_DIR . '/fc_logo');
foreach (['', '/logs', '/cache', '/fc_voices', '/fc_ticket_images', '/fc_avatars', '/fc_pm_images', '/fc_backups', '/fc_pwa_icon', '/fc_logo'] as $d) {
    if (!is_dir(DATA_DIR . $d)) @mkdir(DATA_DIR . $d, 0775, true);
}

foreach (['py', 'error_handler', 'db', 'session', 'response', 'router', 'http', 'core', 'schema', 'hooks', 'mail', 'webpush', 'notify', 'payments', 'catalog', 'backup', 'jobs'] as $lib) {
    require_once APP_DIR . "/lib/$lib.php";
}
install_error_handlers();

// تسجيل المسارات (كل ملف يستدعي route(...))
foreach (glob(APP_DIR . '/routes/*.php') ?: [] as $f) require_once $f;
