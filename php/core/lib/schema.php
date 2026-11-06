<?php
/**
 * إنشاء الجداول + البيانات الافتراضية من ملف واحد: sql/database.sql
 * (يقابل init_db() في app.py:881-1287 وما بعده 1292-1438).
 *
 * الطريقة المعتادة: تستورد الملف يدوياً من phpMyAdmin (Import) ثم تكتب بيانات القاعدة في core/config.php.
 * هذه الدوال تنفّذ الملف نفسه من PHP (سطر الأوامر: php tools/init_db.php) وهي آمنة عند التكرار
 * (CREATE TABLE IF NOT EXISTS + INSERT IGNORE).
 *
 * حساب الأدمن: لا يوجد مستخدم داخل الملف. أول زيارة للموقع تنشئه من ADMIN_EMAIL / ADMIN_PASSWORD في config.php
 * إن لم يكن هناك أي أدمن (انظر ensure_bootstrap_admin).
 */

function database_sql_path(): string { return dirname(__DIR__, 2) . '/sql/database.sql'; }

function schema_statements(): array {
    $stmts = [];
    SqlSplitter::eachStatement(database_sql_path(), function ($s) use (&$stmts) { $stmts[] = $s; });
    return $stmts;
}

/** ينفّذ كل جمل database.sql (ينشئ الجداول الناقصة ويزرع البيانات الافتراضية مرة واحدة) */
function create_schema(PDO $pdo): int {
    $n = 0;
    foreach (schema_statements() as $st) { $pdo->exec($st); $n++; }
    return $n;
}

function init_db(): void {
    $conn = get_db();
    create_schema($conn->pdo);
    ensure_schema_upgrade();
    // المزود الرئيسي يعرض خدماته تلقائياً
    try { $conn->execute('UPDATE providers SET show_in_store=1 WHERE is_default=1 AND show_in_store=0'); } catch (Throwable $e) {}
    $conn->commit();
    _backfill_display_ids();
    _backfill_public_ids();
    $msg = bootstrap_admin_from_config();
    if ($msg !== null) echo $msg . "\n";
}

/* ------------------------------ حساب الأدمن الأول من config.php ------------------------------ */

function admin_bootstrap_flag(): string { return DATA_DIR . '/cache/admin_bootstrap.done'; }

/** هل القيم المكتوبة في config.php صالحة لإنشاء أدمن؟ يرجع [email, password, name] أو null */
function admin_config_values(): ?array {
    if (!defined('ADMIN_EMAIL') || !defined('ADMIN_PASSWORD')) return null;
    $email = mb_strtolower(trim((string)ADMIN_EMAIL), 'UTF-8');
    $pw = (string)ADMIN_PASSWORD;
    if ($email === '' || $pw === '') return null;
    $name = defined('ADMIN_NAME') && trim((string)ADMIN_NAME) !== '' ? trim((string)ADMIN_NAME) : 'الأدمن';
    return [$email, $pw, $name];
}

/**
 * ينشئ حساب الأدمن من ADMIN_EMAIL/ADMIN_PASSWORD إن لم يوجد أي أدمن بالقاعدة.
 * @return string|null رسالة بما حدث (أو null إن لم يكن هناك شيء لفعله)
 * @throws Throwable إن لم تكن القاعدة جاهزة بعد (الجداول غير مستوردة)
 */
function bootstrap_admin_from_config(): ?string {
    $v = admin_config_values();
    if ($v === null) return null;
    [$email, $pw, $name] = $v;
    $conn = get_db();
    if ($conn->execute('SELECT id FROM users WHERE is_admin=1 LIMIT 1')->fetchone()) {
        @file_put_contents(admin_bootstrap_flag(), date('c'));
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        log_app_error('ERROR', 'ADMIN_EMAIL في config.php ليس بريداً صالحاً — لم يُنشأ حساب الأدمن');
        return 'ADMIN_EMAIL غير صالح — لم يُنشأ حساب الأدمن';
    }
    if (mb_strlen($pw, 'UTF-8') < 8) {
        log_app_error('ERROR', 'ADMIN_PASSWORD في config.php أقصر من 8 محارف — لم يُنشأ حساب الأدمن');
        return 'ADMIN_PASSWORD أقصر من 8 محارف — لم يُنشأ حساب الأدمن';
    }
    $existing = $conn->execute('SELECT id FROM users WHERE email=?', [$email])->fetchone();
    if ($existing) {
        $conn->execute('UPDATE users SET is_admin=1, verified=1, banned=0, password=? WHERE id=?', [hash_pw($pw), $existing['id']]);
        $msg = "تمت ترقية $email إلى أدمن";
    } else {
        $conn->execute('INSERT INTO users(name,email,password,verified,is_admin) VALUES(?,?,?,1,1)', [$name, $email, hash_pw($pw)]);
        $msg = "أُنشئ حساب الأدمن $email";
    }
    $conn->commit();
    _backfill_public_ids();
    @file_put_contents(admin_bootstrap_flag(), date('c'));
    @file_put_contents(DATA_DIR . '/logs/admin_audit.log', json_encode(['t' => date('c'), 'event' => 'admin_bootstrap', 'email' => $email], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    return $msg . ' — احذف ADMIN_PASSWORD من core/config.php بعد أول تسجيل دخول';
}

/**
 * يُستدعى مع كل طلب ويب (رخيص بعد النجاح الأول: ملف علم).
 * - إعدادات قديمة بلا ثابت ADMIN_EMAIL: لا شيء.
 * - ADMIN_EMAIL موجود ولا يوجد أي أدمن: ينشئه إن كانت القيم صالحة، وإلا تظهر صفحة إعداد واضحة تقول ماذا تكتب.
 */
function ensure_bootstrap_admin(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ensure_schema_upgrade();   // أعمدة/جداول الإصدارات الأحدث للقواعد المستوردة من ملف قديم (مرة واحدة)
    if (!defined('ADMIN_EMAIL') || is_file(admin_bootstrap_flag())) return;
    try {
        bootstrap_admin_from_config();
        $hasAdmin = (bool)get_db()->execute('SELECT id FROM users WHERE is_admin=1 LIMIT 1')->fetchone();
    } catch (Throwable $e) {
        // أول تشغيل ولم تكتمل التهيئة: اشرح المشكلة بوضوح على أي رابط (بما فيه الصفحة الرئيسية) بدل أن تبدو الصفحة سليمة
        log_app_error('EXCEPTION', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        $hint = fc_db_setup_hint($e);
        if ($hint !== null) fc_setup_problem($hint[0], $hint[1]);
        return;
    }
    if ($hasAdmin) { @file_put_contents(admin_bootstrap_flag(), date('c')); return; }
    $v = admin_config_values();
    fc_setup_problem('لا يوجد حساب أدمن بعد', $v === null
        ? ['افتح <code>core/config.php</code> واكتب <code>ADMIN_EMAIL</code> (بريدك) و <code>ADMIN_PASSWORD</code> (8 محارف فأكثر).', 'أعد تحميل الصفحة: يُنشأ حساب الأدمن تلقائياً.']
        : ['<code>ADMIN_EMAIL</code> أو <code>ADMIN_PASSWORD</code> في <code>core/config.php</code> غير صالح: اكتب بريداً صحيحاً وكلمة مرور من 8 محارف فأكثر.', 'أعد تحميل الصفحة.']);
}
