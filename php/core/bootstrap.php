<?php
/**
 * تهيئة التطبيق: الإعدادات + المكتبات + معالجات الأخطاء + تسجيل المسارات.
 * يُستدعى من public/index.php (ويب) ومن cron.php / tools/*.php (سطر الأوامر).
 */
define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));

/**
 * صفحة "الموقع غير مهيّأ بعد" (503) - لمشاكل الإعداد فقط: لا تعرض أي سر، والتفاصيل
 * التقنية تُسجَّل بـ data/logs/app_errors.log.
 * @param string[] $steps
 */
function wsl_setup_problem(string $title, array $steps, int $code = 503): void {
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

// WSL_CONFIG: مسار بديل لملف الإعدادات (لأدوات سطر الأوامر والاختبارات)
$__wsl_config = getenv('WSL_CONFIG') ?: APP_DIR . '/config.php';
if (!is_file($__wsl_config)) {
    wsl_setup_problem('ملف الإعدادات غير موجود', [
        'الملف <code>core/config.php</code> غير موجود: انسخ <code>core/config.sample.php</code> إليه واكتب بياناتك.',
        'اكتب بيانات قاعدة البيانات داخله (<code>DB_NAME / DB_USER / DB_PASS</code>) ثم أعد تحميل الصفحة.',
    ]);
}
require $__wsl_config;
if (defined('SECRET_KEY') && SECRET_KEY === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET') {
    wsl_setup_problem('لم يُغيَّر مفتاح الجلسات (SECRET_KEY)', [
        'افتح <code>core/config.php</code> وضع في <code>SECRET_KEY</code> نصاً عشوائياً طويلاً سرّياً (30 محرفاً فأكثر).',
        'هذا المفتاح يحمي حسابات المستخدمين والأدمن من التزوير، فلا تتركه بقيمته النموذجية.',
    ]);
}
if ((defined('DB_NAME') && DB_NAME === 'YOUR_DB_NAME') || (defined('DB_USER') && DB_USER === 'YOUR_DB_USER')) {
    wsl_setup_problem('لم تُكتب بيانات قاعدة البيانات بعد', [
        'افتح الملف <code>core/config.php</code> واكتب اسم القاعدة والمستخدم وكلمة المرور (<code>DB_NAME / DB_USER / DB_PASS</code>).',
        'استورد الملف <code>sql/database.sql</code> داخل القاعدة من phpMyAdmin (Import) إن لم تكن قد فعلت.',
        'أعد تحميل الصفحة.',
    ]);
}

defined('DB_PORT') or define('DB_PORT', 3306);
defined('DOMAIN') or define('DOMAIN', 'localhost');
defined('DATA_DIR') or define('DATA_DIR', ROOT_DIR . '/data');
defined('SECRET_KEY') or define('SECRET_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET');
defined('ADMIN_NAME') or define('ADMIN_NAME', 'الأدمن');
defined('MASTER_ADMIN_PHONE') or define('MASTER_ADMIN_PHONE', '');
defined('MASTER_ADMIN_CODE') or define('MASTER_ADMIN_CODE', '');
defined('GOOGLE_CLIENT_ID') or define('GOOGLE_CLIENT_ID', '');
defined('WA_PROVIDER_DRIVER') or define('WA_PROVIDER_DRIVER', 'none');
defined('WA_PROVIDER_CONFIG') or define('WA_PROVIDER_CONFIG', []);
defined('PLAN_NAME') or define('PLAN_NAME', 'الخطة الاحترافية');
defined('PLAN_PRICE_IQD') or define('PLAN_PRICE_IQD', 60000);
defined('TRIAL_DAYS') or define('TRIAL_DAYS', 3);
defined('PLAN_ACTIVATION_DAYS') or define('PLAN_ACTIVATION_DAYS', 30);
defined('WHATSAPP_PAY_NUMBER') or define('WHATSAPP_PAY_NUMBER', '');
defined('DEFAULT_COUNTRY_CODE_FALLBACK') or define('DEFAULT_COUNTRY_CODE_FALLBACK', '964');

if (defined('TIMEZONE') && TIMEZONE) {
    date_default_timezone_set(TIMEZONE);
} else {
    @date_default_timezone_set('UTC');
}
mb_internal_encoding('UTF-8');
ini_set('precision', '-1');
ini_set('serialize_precision', '-1');
ini_set('display_errors', '0');
error_reporting(E_ALL);
@ini_set('memory_limit', '256M');
@set_time_limit(120);

define('LOGO_DIR', DATA_DIR . '/wsl_logo');
foreach (['', '/logs', '/cache', '/wsl_logo'] as $d) {
    if (!is_dir(DATA_DIR . $d)) @mkdir(DATA_DIR . $d, 0775, true);
}

foreach (['py', 'sqlsplit', 'error_handler', 'db', 'session', 'response', 'router', 'http', 'core', 'schema', 'hooks', 'security', 'wa_provider'] as $lib) {
    require_once APP_DIR . "/lib/$lib.php";
}
install_error_handlers();

// تسجيل المسارات (كل ملف يستدعي route(...))
foreach (glob(APP_DIR . '/routes/*.php') ?: [] as $f) require_once $f;
