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

/**
 * ترقية التنصيبات الموجودة فعلاً (قبل إضافة المحفظة/طرق الدفع): أعمدة جديدة على جداول
 * قديمة لا يضيفها CREATE TABLE IF NOT EXISTS. كل سطر مستقل وآمن عند التكرار (يتجاهل
 * خطأ "duplicate column" لو كان العمود موجوداً أصلاً من تنصيب جديد).
 */
function run_column_migrations(PDO $pdo): void {
    $alters = [
        "ALTER TABLE users ADD COLUMN balance_iqd INT NOT NULL DEFAULT 0",
        "ALTER TABLE site_settings ADD COLUMN price_per_message_iqd INT NOT NULL DEFAULT 0",
        "ALTER TABLE site_settings ADD COLUMN usd_to_iqd_rate INT NOT NULL DEFAULT 1310",
    ];
    foreach ($alters as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
}

function init_db(): void {
    $conn = get_db();
    create_schema($conn->pdo);
    run_column_migrations($conn->pdo);
    $conn->commit();
    $msg = bootstrap_admin_from_config();
    wsl_ensure_payment_method_singletons();
    if ($msg !== null) echo $msg . "\n";
}

/**
 * ترقية موقع يعمل أصلاً فعلياً (أدمن موجود من قبل) لنسخة أحدث فيها أعمدة/صفوف جديدة.
 * init_db() أعلاه ينفَّذ مرة واحدة فقط لحظة التنصيب الأول (public/index.php يستدعيها
 * فقط لو ما فيه أدمن بعد) فلا يصل لموقع يعمل من قبل بعد نشر هذا التحديث. هذه الدالة
 * بالعكس: رخيصة تماماً (فحص ملف واحد) فتستطيع الاستدعاء من كل طلب بأمان، وتنفّذ
 * الترقية الفعلية مرة واحدة بس عبر ملف علامة بـ data/cache، مثل آلية session_epoch.
 */
function wsl_run_pending_migrations(): void {
    $marker = DATA_DIR . '/cache/schema_version';
    $current = 2; // زِد هذا الرقم كل ما أُضيفت ترقية/بذرة جديدة بـ run_column_migrations أو wsl_ensure_payment_method_singletons
    $have = is_file($marker) ? (int)trim((string)@file_get_contents($marker)) : 0;
    if ($have >= $current) return;
    $conn = get_db();
    run_column_migrations($conn->pdo);
    $conn->commit();
    wsl_ensure_payment_method_singletons();
    @file_put_contents($marker, (string)$current, LOCK_EX);
}
