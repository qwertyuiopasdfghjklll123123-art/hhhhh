<?php
/**
 * تهيئة التطبيق: الإعدادات + المكتبات + معالجات الأخطاء + تسجيل المسارات.
 * يُستدعى من public/index.php (ويب) ومن cron.php / tools/*.php (سطر الأوامر).
 */
define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

/**
 * صفحة "الموقع غير مهيّأ بعد" (503) — لمشاكل الإعداد فقط: لا تعرض أي سر (الاسم/المضيف/كلمة المرور)،
 * والتفاصيل التقنية تُسجَّل بـ data/logs/app_errors.log.
 * @param string[] $steps
 */
function fc_setup_problem(string $title, array $steps, int $code = 503): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $title . "\n" . implode("\n", array_map(fn($s) => ' - ' . strip_tags($s), $steps)) . "\n");
        exit(1);
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $wantsJson = strncmp($uri, '/api/', 5) === 0 || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') === 0;
    http_response_code($code);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => $title], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $li = '';
    foreach ($steps as $s) $li .= '<li>' . $s . '</li>';
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title><style>body{font-family:Tahoma,Arial,sans-serif;background:#0f1220;color:#e8eaf6;margin:0;padding:24px}'
        . '.box{max-width:680px;margin:40px auto;background:#171a2e;border:1px solid #2a2f4f;border-radius:14px;padding:24px;line-height:1.9}'
        . 'h1{margin-top:0;font-size:20px;color:#ffcb6b}code{background:#0f1220;padding:2px 8px;border-radius:6px;direction:ltr;display:inline-block}li{margin:6px 0}</style></head><body><div class="box"><h1>'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><ol>' . $li . '</ol></div></body></html>';
    exit;
}

// FC_CONFIG: مسار بديل لملف الإعدادات (لأدوات سطر الأوامر والاختبارات)
$__fc_config = getenv('FC_CONFIG') ?: APP_DIR . '/config.php';
if (!is_file($__fc_config)) {
    fc_setup_problem('ملف الإعدادات غير موجود', [
        'الملف <code>core/config.php</code> غير موجود: أعد رفعه من الأرشيف الأصلي (أو انسخ <code>core/config.sample.php</code> إليه إن كنت تستعمل git).',
        'اكتب بيانات قاعدة البيانات داخله (<code>DB_HOST / DB_NAME / DB_USER / DB_PASS</code>) ثم أعد تحميل الصفحة.',
    ]);
}
require $__fc_config;
if (defined('SECRET_KEY') && SECRET_KEY === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET') {
    fc_setup_problem('لم يُغيَّر مفتاح الجلسات (SECRET_KEY)', [
        'افتح <code>core/config.php</code> وضع في <code>SECRET_KEY</code> نصاً عشوائياً طويلاً سرّياً (30 محرفاً فأكثر).',
        'هذا المفتاح يحمي حسابات المستخدمين والأدمن من التزوير، فلا تتركه بقيمته النموذجية.',
    ]);
}
if ((defined('DB_NAME') && DB_NAME === 'YOUR_DB_NAME') || (defined('DB_USER') && DB_USER === 'YOUR_DB_USER')) {
    fc_setup_problem('لم تُكتب بيانات قاعدة البيانات بعد', [
        'افتح الملف <code>core/config.php</code> واكتب اسم القاعدة والمستخدم وكلمة المرور (<code>DB_NAME / DB_USER / DB_PASS</code>).',
        'استورد الملف <code>sql/database.sql</code> داخل القاعدة من phpMyAdmin (Import) إن لم تكن قد فعلت.',
        'أعد تحميل الصفحة.',
    ]);
}

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
define('CS_IMG_DIR', DATA_DIR . '/fc_cs_images');   // لوغوهات الألعاب/الخدمات المخصصة
foreach (['', '/logs', '/cache', '/fc_voices', '/fc_ticket_images', '/fc_avatars', '/fc_pm_images', '/fc_backups', '/fc_pwa_icon', '/fc_logo', '/fc_cs_images'] as $d) {
    if (!is_dir(DATA_DIR . $d)) @mkdir(DATA_DIR . $d, 0775, true);
}

foreach (['py', 'sqlsplit', 'error_handler', 'db', 'session', 'response', 'router', 'http', 'core', 'schema', 'hooks', 'mail', 'webpush', 'notify', 'payments', 'catalog', 'backup', 'jobs', 'app_routes', 'security', 'transfer', 'auth2'] as $lib) {
    require_once APP_DIR . "/lib/$lib.php";
}
install_error_handlers();

// تسجيل المسارات (كل ملف يستدعي route(...))
foreach (glob(APP_DIR . '/routes/*.php') ?: [] as $f) require_once $f;
