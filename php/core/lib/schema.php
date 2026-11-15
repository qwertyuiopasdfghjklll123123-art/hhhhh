<?php
/**
 * إنشاء الجداول من ملف واحد: sql/database.sql (يقابل init_db() بـ webapp.py).
 * آمنة عند التكرار (CREATE TABLE IF NOT EXISTS). تُنفَّذ من public/index.php بأول طلب
 * ومن tools/init_db.php بسطر الأوامر - الطريقتان تؤديان لنفس النتيجة.
 *
 * حساب الأدمن: لا يوجد مستخدم داخل sql/database.sql. أول زيارة للموقع تنشئه من
 * ADMIN_EMAIL/ADMIN_PASSWORD بـ core/config.php إن لم يكن هناك أدمن أصلاً (bootstrap_admin_from_config).
 */

function database_sql_path(): string { return dirname(__DIR__, 2) . '/sql/database.sql'; }

function schema_statements(): array {
    $stmts = [];
    SqlSplitter::eachStatement(database_sql_path(), function ($s) use (&$stmts) { $stmts[] = $s; });
    return $stmts;
}

/** ينفّذ كل جمل database.sql (ينشئ الجداول الناقصة) */
function create_schema(PDO $pdo): int {
    $n = 0;
    foreach (schema_statements() as $st) { $pdo->exec($st); $n++; }
    return $n;
}

/** ينشئ حساب الأدمن من config.php لو ما فيه أدمن بعد. يرجع رسالة نصية (CLI) أو null */
function bootstrap_admin_from_config(): ?string {
    $conn = get_db();
    $hasAdmin = $conn->execute('SELECT COUNT(*) AS n FROM users WHERE is_admin=1')->fetchone()['n'] > 0;
    if ($hasAdmin) return null;
    $email = defined('ADMIN_EMAIL') ? trim((string)ADMIN_EMAIL) : '';
    $password = defined('ADMIN_PASSWORD') ? (string)ADMIN_PASSWORD : '';
    if ($email === '' || $password === '') return null;
    $existing = $conn->execute('SELECT id FROM users WHERE email=?', [mb_strtolower($email)])->fetchone();
    $now = gmdate('Y-m-d H:i');
    if ($existing) {
        $conn->execute('UPDATE users SET is_admin=1, password_hash=?, plan_active=1 WHERE id=?',
            [password_hash($password, PASSWORD_BCRYPT), $existing['id']]);
        $conn->commit();
        return 'تمت ترقية الحساب الموجود ' . $email . ' إلى أدمن.';
    }
    $conn->execute(
        'INSERT INTO users (email, name, password_hash, is_admin, plan_active, created_at) VALUES (?,?,?,1,1,?)',
        [mb_strtolower($email), 'الأدمن', password_hash($password, PASSWORD_BCRYPT), $now]
    );
    $conn->commit();
    return 'تم إنشاء حساب الأدمن ' . $email . ' تلقائياً.';
}

function init_db(): void {
    $conn = get_db();
    create_schema($conn->pdo);
    $conn->commit();
    $msg = bootstrap_admin_from_config();
    if ($msg !== null) echo $msg . "\n";
}
