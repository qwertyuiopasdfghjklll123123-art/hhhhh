<?php
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

wsl_session_load();
dispatch();
