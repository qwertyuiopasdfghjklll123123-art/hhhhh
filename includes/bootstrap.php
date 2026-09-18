<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!is_installed()) {
    header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') ? '../install.php' : 'install.php'));
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/migrations.php';

start_secure_session();
send_security_headers();

date_default_timezone_set(app_config()['app']['timezone'] ?? 'UTC');

// تحديثات على مخطط قاعدة البيانات صدرت بعد تثبيتك الأصلي؟ نعترض الطلب هنا بدل
// ترك أي استعلام لاحق يفشل بخطأ فادح — الأدمن يرى زر تحديث تلقائي بضغطة واحدة.
// نستثني login.php: منطقه (attempt_login عبر SELECT *) لا يفترض أي عمود جديد،
// ولا بد أن يبقى الدخول ممكناً ليصل الأدمن أصلاً إلى زر التحديث بعد تسجيل الدخول.
$isLoginPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'login.php';
if (!$isLoginPage && needs_schema_migration()) {
    handle_pending_migration_gate();
}
