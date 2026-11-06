<?php
/**
 * مثبّت الموقع (مرة واحدة): يفحص المتطلبات، يتصل بـ MySQL، ينشئ الجداول والبيانات الافتراضية،
 * ويكتب core/config.php. يرفض العمل إذا كان core/config.php موجوداً (لحماية موقع قيد التشغيل).
 * لإعادة التثبيت: احذف core/config.php أولاً.
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$CORE = dirname(__DIR__) . '/core';
$CONFIG = $CORE . '/config.php';
$ROOT = dirname(__DIR__);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function page(string $body, int $code = 200): void {
    http_response_code($code);
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تثبيت الموقع</title><style>
body{font-family:Tahoma,Arial,sans-serif;background:#0f1220;color:#e8eaf6;margin:0;padding:24px}
.box{max-width:760px;margin:0 auto;background:#171a2e;border:1px solid #2a2f4f;border-radius:14px;padding:24px}
h1{margin-top:0;font-size:22px}h2{font-size:16px;margin:22px 0 8px;color:#9fb3ff}
label{display:block;margin:10px 0 4px;font-size:13px;color:#b8bfe0}
input[type=text],input[type=password],input[type=email],input[type=number]{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #333a63;background:#0f1220;color:#fff;font-size:14px;direction:ltr;text-align:left}
button{margin-top:18px;padding:12px 22px;border:0;border-radius:10px;background:#4f7cff;color:#fff;font-size:15px;cursor:pointer}
table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:6px 8px;border-bottom:1px solid #262b4a;text-align:right}
.ok{color:#5be584}.bad{color:#ff6b6b}.warn{color:#ffcb6b}.note{font-size:12px;color:#8e96c4;margin:4px 0}
code,pre{background:#0f1220;padding:2px 6px;border-radius:6px;direction:ltr;display:inline-block}pre{display:block;padding:10px;overflow:auto;text-align:left}
.radio label{display:block;margin:6px 0;color:#e8eaf6}
</style></head><body><div class="box">' . $body . '</div></body></html>';
    exit;
}

if (is_file($CONFIG)) {
    page('<h1>الموقع مثبّت مسبقاً</h1><p>الملف <code>core/config.php</code> موجود، لذلك تم تعطيل المثبّت لحماية الموقع.</p>'
        . '<p class="note">لإعادة التثبيت احذف <code>core/config.php</code> ثم أعد فتح هذه الصفحة.</p>', 403);
}

/* ------------------------- فحص المتطلبات ------------------------- */
$checks = [];
$checks[] = ['PHP ≥ 8.0', PHP_VERSION_ID >= 80000, PHP_VERSION];
foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'zip', 'gd', 'json', 'fileinfo'] as $ext) {
    $checks[] = ["امتداد $ext", extension_loaded($ext), extension_loaded($ext) ? 'مفعّل' : 'غير مفعّل'];
}
$gdOk = extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagepng');
$checks[] = ['GD يدعم PNG', $gdOk && (gd_info()['PNG Support'] ?? false), ''];
$checks[] = ['مجلد core قابل للكتابة (لكتابة config.php)', is_writable($CORE), $CORE];
$dataDir = $ROOT . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0775, true);
$checks[] = ['مجلد data قابل للكتابة', is_dir($dataDir) && is_writable($dataDir), $dataDir];
$checks[] = ['proc_open متاح (لتشغيل cron.php بعمليات منفصلة)', function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true), 'اختياري'];
$hardFail = false;
foreach ($checks as $c) { if (!$c[1] && strpos($c[0], 'proc_open') === false) $hardFail = true; }

function checks_html(array $checks): string {
    $h = '<table><tr><th>المتطلب</th><th>الحالة</th><th>تفاصيل</th></tr>';
    foreach ($checks as $c) {
        $opt = strpos($c[0], 'proc_open') !== false;
        $h .= '<tr><td>' . h($c[0]) . '</td><td class="' . ($c[1] ? 'ok' : ($opt ? 'warn' : 'bad')) . '">' . ($c[1] ? '✔' : ($opt ? '⚠' : '✘')) . '</td><td>' . h($c[2]) . '</td></tr>';
    }
    return $h . '</table>';
}

$v = [
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'fastcrand', 'db_user' => '', 'db_pass' => '',
    'domain' => preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')), 'secret_mode' => 'new', 'secret_custom' => '',
    'admin_email' => '', 'admin_pass' => '', 'remove_demo' => '1',
];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$hardFail) {
    foreach ($v as $k => $_) if (isset($_POST[$k])) $v[$k] = trim((string)$_POST[$k]);
    $v['remove_demo'] = isset($_POST['remove_demo']) ? '1' : '';
    try {
        if ($v['db_name'] === '' || $v['db_user'] === '') throw new RuntimeException('أدخل اسم قاعدة البيانات والمستخدم');
        if (!preg_match('/^[A-Za-z0-9_\-$]+$/', $v['db_name'])) throw new RuntimeException('اسم قاعدة البيانات يحتوي محارف غير مسموحة');
        if ($v['admin_email'] !== '' && !filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('بريد الأدمن غير صالح');
        if ($v['admin_pass'] !== '' && strlen($v['admin_pass']) < 8) throw new RuntimeException('كلمة مرور الأدمن 8 محارف على الأقل');

        // 1) الاتصال (بدون تحديد قاعدة أولاً لنتمكن من إنشائها إن لزم)
        $dsn = 'mysql:host=' . $v['db_host'] . ';port=' . (int)$v['db_port'] . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, $v['db_user'], $v['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new RuntimeException('تعذّر الاتصال بـ MySQL: ' . $e->getMessage());
        }
        // 2) إصدار الخادم
        $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($ver, 'mariadb') !== false;
        $num = preg_replace('/^(\d+\.\d+\.\d+).*/', '$1', preg_replace('/^5\.5\.5-/', '', $ver));
        if ($isMaria ? version_compare($num, '10.2.1', '<') : version_compare($num, '8.0.13', '<')) {
            throw new RuntimeException("إصدار قاعدة البيانات ($ver) قديم: المطلوب MySQL ≥ 8.0.13 أو MariaDB ≥ 10.2.1");
        }
        // 3) إنشاء/اختيار القاعدة
        try { $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $v['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'); } catch (PDOException $e) { /* قد لا تملك الصلاحية؛ نجرّب الاستعمال */ }
        try { $pdo->exec('USE `' . $v['db_name'] . '`'); }
        catch (PDOException $e) { throw new RuntimeException('القاعدة ' . $v['db_name'] . ' غير موجودة ولا يمكن إنشاؤها بهذا المستخدم: ' . $e->getMessage()); }
        $have = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
        if ($have > 0) throw new RuntimeException('القاعدة تحتوي جداول موقع مسبقاً (جدول users موجود). استعمل قاعدة فارغة، أو احذف الجداول، أو شغّل tools/init_db.php مع config.php يدوي.');

        // 4) مفتاح الجلسة
        $secret = $v['secret_mode'] === 'legacy' ? 'fc_s3cr3t_k3y_2026_fastcrand_permanent'
            : ($v['secret_mode'] === 'custom' ? $v['secret_custom'] : bin2hex(random_bytes(32)));
        if (strlen($secret) < 16) throw new RuntimeException('مفتاح الجلسة قصير جداً (16 محرفاً على الأقل)');

        // 5) كتابة config.php
        $cfg = "<?php\n// أُنشئ بواسطة install.php بتاريخ " . date('Y-m-d H:i:s') . "\n"
            . "define('DB_HOST', " . var_export($v['db_host'], true) . ");\n"
            . "define('DB_PORT', " . (int)$v['db_port'] . ");\n"
            . "define('DB_NAME', " . var_export($v['db_name'], true) . ");\n"
            . "define('DB_USER', " . var_export($v['db_user'], true) . ");\n"
            . "define('DB_PASS', " . var_export($v['db_pass'], true) . ");\n\n"
            . "define('SECRET_KEY', " . var_export($secret, true) . ");\n"
            . "define('DOMAIN', " . var_export($v['domain'], true) . ");\n"
            . "define('DATA_DIR', dirname(__DIR__) . '/data');\n\n"
            . "define('SMM_API_URL', 'https://smmparty.com/api/v2');\n"
            . "define('SMM_API_KEY', '1c0218b705ad1d031562ab810b11552e');\n\n"
            . "define('SMTP_HOST', 'smtp.hostinger.com');\n"
            . "define('SMTP_PORT', 465);\n"
            . "define('SMTP_USER', 'noreply@' . DOMAIN);\n"
            . "define('SMTP_PASS', 'YOUR_EMAIL_PASSWORD');\n\n"
            . "define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com');\n";
        if (@file_put_contents($CONFIG, $cfg, LOCK_EX) === false) throw new RuntimeException('تعذّرت كتابة core/config.php — امنح PHP صلاحية الكتابة على مجلد core أو انسخ المحتوى يدوياً.');
        @chmod($CONFIG, 0640);

        // 6) الجداول + البيانات الافتراضية
        require $CORE . '/bootstrap.php';
        init_db();
        $note = '';
        if ($v['admin_email'] !== '' || $v['admin_pass'] !== '') {
            $conn = get_db();
            if ($v['admin_email'] !== '') $conn->execute("UPDATE users SET email=? WHERE email='admin@admin.com'", [mb_strtolower($v['admin_email'], 'UTF-8')]);
            if ($v['admin_pass'] !== '') $conn->execute('UPDATE users SET password=? WHERE is_admin=1', [hash_pw($v['admin_pass'])]);
            $conn->commit();
            $note .= '<li>تم تغيير بيانات دخول الأدمن.</li>';
        }
        if ($v['remove_demo'] === '1') {
            $conn = get_db();
            $conn->execute("DELETE FROM users WHERE email='test@test.com'");
            $conn->commit();
            $note .= '<li>تم حذف المستخدم التجريبي test@test.com.</li>';
        }
        Conn::shutdown();

        $adminLine = ($v['admin_email'] !== '' || $v['admin_pass'] !== '')
            ? '<p>سجّل الدخول بحساب الأدمن الذي أدخلته.</p>'
            : '<p class="warn">⚠ حساب الأدمن الافتراضي: <code>admin@admin.com</code> / <code>2sebvWFr6BYwVBrV</code> — <b>غيّر كلمة المرور فوراً</b> من الموقع.</p>';
        $cron = 'php ' . $ROOT . '/cron.php';
        page('<h1 class="ok">✔ تم التثبيت بنجاح</h1><ul>' . $note . '<li>الجداول والبيانات الافتراضية جاهزة.</li><li>تم إنشاء <code>core/config.php</code>.</li></ul>' . $adminLine
            . '<h2>الخطوات التالية</h2><ol>'
            . '<li>فعّل المهام الدورية (الطلبات المعلّقة، النسخ الاحتياطي، المزامنة...) بإضافة هذا السطر إلى crontab:<pre>* * * * * ' . h($cron) . ' &gt;/dev/null 2&gt;&amp;1</pre></li>'
            . '<li>لنقل بيانات موقع بايثون القديم: <pre>php ' . h($ROOT) . '/tools/migrate_from_sqlite.php --sqlite=/root/fastcrand.db --files=/root</pre></li>'
            . '<li>احذف الملف <code>public/install.php</code> (لن يعمل مجدداً ما دام config.php موجوداً، لكن الحذف أفضل).</li>'
            . '<li><a style="color:#9fb3ff" href="/">افتح الموقع</a></li></ol>');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$f = fn(string $k) => h($v[$k]);
$disabled = $hardFail ? ' disabled' : '';
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$isLocal = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', (string)($_SERVER['HTTP_HOST'] ?? ''));
page('<h1>تثبيت الموقع (PHP + MySQL)</h1>'
    . (!$isHttps && !$isLocal ? '<p class="warn">⚠ أنت تفتح المثبّت عبر اتصال غير مشفّر (HTTP): كلمة مرور قاعدة البيانات وكلمة مرور الأدمن ستُرسل بلا تشفير. إن كانت شهادة SSL مفعّلة لدومينك فافتح هذه الصفحة عبر <code>https://</code> بدلاً من ذلك.</p>' : '')
    . '<h2>فحص المتطلبات</h2>' . checks_html($checks)
    . ($hardFail ? '<p class="bad">أصلح المتطلبات الفاشلة (✘) ثم أعد تحميل الصفحة.</p>' : '')
    . ($error !== '' ? '<p class="bad"><b>خطأ:</b> ' . h($error) . '</p>' : '')
    . '<form method="post" autocomplete="off"><h2>قاعدة البيانات</h2>'
    . '<label>المضيف</label><input type="text" name="db_host" value="' . $f('db_host') . '">'
    . '<label>المنفذ</label><input type="number" name="db_port" value="' . $f('db_port') . '">'
    . '<label>اسم القاعدة</label><input type="text" name="db_name" value="' . $f('db_name') . '">'
    . '<label>المستخدم</label><input type="text" name="db_user" value="' . $f('db_user') . '">'
    . '<label>كلمة المرور</label><input type="password" name="db_pass" value="">'
    . '<p class="note">المطلوب MySQL ≥ 8.0.13 أو MariaDB ≥ 10.2.1. إن لم تكن القاعدة موجودة وكان للمستخدم صلاحية الإنشاء سيُنشئها المثبّت.</p>'
    . '<h2>الموقع</h2><label>الدومين</label><input type="text" name="domain" value="' . $f('domain') . '">'
    . '<h2>مفتاح توقيع الجلسات (SECRET_KEY)</h2><div class="radio">'
    . '<label><input type="radio" name="secret_mode" value="new"' . ($v['secret_mode'] === 'new' ? ' checked' : '') . '> توليد مفتاح عشوائي قوي (موصى به — سيُطلب من المستخدمين تسجيل الدخول مرة واحدة)</label>'
    . '<label><input type="radio" name="secret_mode" value="legacy"' . ($v['secret_mode'] === 'legacy' ? ' checked' : '') . '> المفتاح القديم الافتراضي (يُبقي جلسات الموقع القديم صالحة، لكنه معروف علناً — غير آمن)</label>'
    . '<label><input type="radio" name="secret_mode" value="custom"' . ($v['secret_mode'] === 'custom' ? ' checked' : '') . '> مفتاحي الخاص (الصق مفتاح موقع بايثون الحالي لإبقاء الجلسات):</label></div>'
    . '<input type="text" name="secret_custom" value="' . $f('secret_custom') . '">'
    . '<h2>حساب الأدمن (اختياري)</h2><p class="note">بدونهما يُنشأ حساب الأدمن الافتراضي المعروف من النسخة الأصلية — يُنصح بتغييره الآن.</p>'
    . '<label>بريد الأدمن الجديد</label><input type="email" name="admin_email" value="' . $f('admin_email') . '">'
    . '<label>كلمة مرور الأدمن الجديدة (8 محارف فأكثر)</label><input type="password" name="admin_pass" value="">'
    . '<label><input type="checkbox" name="remove_demo" value="1"' . ($v['remove_demo'] === '1' ? ' checked' : '') . '> حذف المستخدم التجريبي test@test.com</label>'
    . '<button type="submit"' . $disabled . '>تثبيت</button></form>');
