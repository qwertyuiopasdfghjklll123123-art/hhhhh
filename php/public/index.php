<?php
/**
 * نقطة الدخول الوحيدة للموقع (front controller) — بديل Flask app.run().
 * كل الطلبات التي لا تطابق ملفاً فعلياً تمرّ من هنا (انظر .htaccess).
 */
if (PHP_SAPI === 'cli-server') {   // php -S: قدّم الملفات الثابتة الموجودة مباشرة
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $f = __DIR__ . rawurldecode((string)$p);
    if ($p !== '/' && is_file($f) && substr($f, -10) !== '/index.php') return false;
}
require __DIR__ . '/../core/bootstrap.php';
fc_session_load();
ensure_bootstrap_admin();
dispatch();
