<?php
// خادم php -S للتطوير/الاختبار المحلي فقط: يمرّر الملفات الحقيقية (sw.js، الأيقونات...)
// كما هي بدل تمريرها لهذا الراوتر - نفس ما تفعله RewriteCond ...-f بـ public/.htaccess
// على استضافة حقيقية (Apache). لا تأثير على الإنتاج (PHP_SAPI هناك هو fpm-fcgi/apache2handler).
if (PHP_SAPI === 'cli-server') {
    $__wsl_static = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($__wsl_static !== __DIR__ . '/index.php' && is_file($__wsl_static)) return false;
}
require __DIR__ . '/../core/bootstrap.php';

try {
    $conn = get_db();
    $conn->execute('SELECT 1 FROM users LIMIT 1');
} catch (DBError $e) {
    $hint = wsl_db_setup_hint($e->getPrevious() ?? $e);
    if ($hint !== null) wsl_setup_problem($hint[0], $hint[1]);
    throw $e;
}
if ((int)$conn->execute('SELECT COUNT(*) AS n FROM users WHERE is_admin=1')->fetchone()['n'] === 0) {
    if (!defined('ADMIN_EMAIL') || ADMIN_EMAIL === '' || !defined('ADMIN_PASSWORD') || ADMIN_PASSWORD === '') {
        wsl_setup_problem('لا يوجد حساب أدمن بعد', [
            'افتح <code>core/config.php</code> واكتب <code>ADMIN_EMAIL</code> و <code>ADMIN_PASSWORD</code>.',
            'أعد تحميل الصفحة - يُنشأ حساب الأدمن تلقائياً.',
        ]);
    }
    init_db();
}
wsl_run_pending_migrations();

wsl_session_load();
dispatch();
