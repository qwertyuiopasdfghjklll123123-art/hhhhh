<?php
/**
 * إنشاء حساب أدمن (أو ترقيته/تعيين كلمة مرور له لو موجود) من سطر الأوامر:
 *     php tools/create_admin.php me@example.com 'كلمة-مرور-قوية' ["الاسم"]
 * يفيد إن فقدت الدخول للوحة الإدارة أو لم تستعمل ADMIN_EMAIL في config.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../core/bootstrap.php';

$email = mb_strtolower(trim((string)($argv[1] ?? '')), 'UTF-8');
$pw = (string)($argv[2] ?? '');
$name = trim((string)($argv[3] ?? '')) ?: 'الأدمن';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($pw, 'UTF-8') < 8) {
    fwrite(STDERR, "الاستعمال: php tools/create_admin.php me@example.com 'كلمة-مرور-8-محارف-فأكثر' [الاسم]\n");
    exit(1);
}

$conn = get_db();
$u = $conn->execute('SELECT id FROM users WHERE email=?', [$email])->fetchone();
if ($u) {
    $conn->execute('UPDATE users SET is_admin=1, plan_active=1, password_hash=? WHERE id=?',
        [password_hash($pw, PASSWORD_BCRYPT), $u['id']]);
    echo "تمت ترقية المستخدم $email إلى أدمن وتعيين كلمة المرور الجديدة\n";
} else {
    $conn->execute(
        'INSERT INTO users (email, name, password_hash, is_admin, plan_active, created_at) VALUES (?,?,?,1,1,?)',
        [$email, $name, password_hash($pw, PASSWORD_BCRYPT), now_str()]
    );
    echo "أُنشئ حساب الأدمن $email\n";
}
$conn->commit();
Conn::shutdown();
