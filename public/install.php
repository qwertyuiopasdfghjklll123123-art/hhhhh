<?php
/**
 * مثبّت بسيط بصفحة واحدة: يكتب core/config.php ويبني الجداول تلقائياً، بدل تعديل الملف
 * يدوياً عبر File Manager. مستقل كلياً عن bootstrap.php (ما يحتاج config.php موجوداً
 * أصلاً - هذا بالضبط سبب وجوده) - أول شيء يفعله دائماً هو رفض العمل لو config.php موجود
 * فعلاً (حتى ما يُعاد استخدامه لتخريب موقع شغّال).
 */
$CONFIG_PATH = __DIR__ . '/../core/config.php';
if (is_file($CONFIG_PATH)) {
    header('Location: /');
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim((string)($_POST['db_host'] ?? 'localhost'));
    $dbPort = (int)($_POST['db_port'] ?? 3306) ?: 3306;
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $domain = trim((string)($_POST['domain'] ?? ''));
    $adminEmail = mb_strtolower(trim((string)($_POST['admin_email'] ?? '')), 'UTF-8');
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminPassword2 = (string)($_POST['admin_password2'] ?? '');

    if ($dbName === '' || $dbUser === '') $errors[] = 'اكتب اسم قاعدة البيانات والمستخدم.';
    if ($domain === '') $errors[] = 'اكتب دومينك (بدون https://).';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'أدخل بريد إلكتروني صحيح لحسابك كأدمن.';
    if (mb_strlen($adminPassword, 'UTF-8') < 8) $errors[] = 'كلمة مرور الأدمن 8 محارف على الأقل.';
    if ($adminPassword !== $adminPassword2) $errors[] = 'كلمتا المرور غير متطابقتين.';

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('USE `' . str_replace('`', '', $dbName) . '`');
        } catch (Throwable $e) {
            $errors[] = 'تعذر الاتصال بقاعدة البيانات: ' . $e->getMessage() . ' - تأكد من بيانات MySQL وإن القاعدة منشأة فعلاً من لوحة الاستضافة.';
        }
    }

    if (!$errors && $pdo) {
        try {
            require __DIR__ . '/../core/lib/sqlsplit.php';
            $n = 0;
            SqlSplitter::eachStatement(__DIR__ . '/../sql/database.sql', function ($stmt) use ($pdo, &$n) { $pdo->exec($stmt); $n++; });
        } catch (Throwable $e) {
            $errors[] = 'تعذر إنشاء جداول قاعدة البيانات: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        $secretKey = bin2hex(random_bytes(32));
        $gatewayToken = bin2hex(random_bytes(24));
        $configBody = "<?php\n"
            . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
            . "define('DB_PORT', " . var_export($dbPort, true) . ");\n"
            . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
            . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
            . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
            . "define('DOMAIN', " . var_export($domain, true) . ");\n"
            . "define('SECRET_KEY', " . var_export($secretKey, true) . ");\n"
            . "define('ADMIN_EMAIL', " . var_export($adminEmail, true) . ");\n"
            . "define('ADMIN_PASSWORD', " . var_export($adminPassword, true) . ");\n"
            . "define('ADMIN_NAME', 'الأدمن');\n"
            . "define('MASTER_ADMIN_PHONE', '');\n"
            . "define('MASTER_ADMIN_CODE', '');\n"
            . "define('GOOGLE_CLIENT_ID', '');\n"
            . "define('GATEWAY_BASE_URL', 'http://127.0.0.1:3000');\n"
            . "define('GATEWAY_TOKEN', " . var_export($gatewayToken, true) . ");\n"
            . "define('WA_PROVIDER_DRIVER', 'none');\n"
            . "define('WA_PROVIDER_CONFIG', []);\n"
            . "define('PLAN_NAME', 'الخطة الاحترافية');\n"
            . "define('PLAN_PRICE_IQD', 60000);\n"
            . "define('TRIAL_DAYS', 3);\n"
            . "define('PLAN_ACTIVATION_DAYS', 30);\n"
            . "define('WHATSAPP_PAY_NUMBER', '');\n";
        if (@file_put_contents($CONFIG_PATH, $configBody) === false) {
            $errors[] = 'تعذر كتابة core/config.php (مشكلة صلاحيات) - انسخ core/config.sample.php إلى core/config.php يدوياً عبر File Manager واكتب فيه نفس البيانات.';
        } else {
            header('Location: /login/email?installed=1');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تثبيت واصل</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--bg:#f7f4f0;--card:#fff;--border:#f0e6da;--primary:#2563eb;--primary-light:#3b82f6;--text:#221a12;--muted:#6b5d50;--danger:#ef4444;--input-bg:#fbf7f3}
*{box-sizing:border-box}
body{margin:0;font-family:'IBM Plex Sans Arabic',Tahoma,sans-serif;background:var(--bg);color:var(--text);padding:24px 16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:20px;max-width:440px;margin:20px auto;padding:26px 24px;box-shadow:0 8px 30px rgba(34,26,18,.08)}
h1{font-size:19px;font-weight:900;margin:0 0 4px}
p.sub{color:var(--muted);font-size:12.5px;margin:0 0 18px}
fieldset{border:1px solid var(--border);border-radius:14px;padding:4px 14px 14px;margin:0 0 14px}
legend{font-size:12px;font-weight:800;color:var(--primary);padding:0 6px}
label{font-size:11.5px;color:var(--muted);font-weight:700;display:block;margin:10px 0 5px}
input{width:100%;padding:11px 12px;border:1.5px solid var(--border);border-radius:11px;font-size:14px;background:var(--input-bg);font-family:inherit;color:var(--text)}
input:focus{outline:none;border-color:var(--primary)}
button{width:100%;margin-top:16px;padding:14px;border:none;border-radius:13px;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-size:15px;font-weight:800;cursor:pointer}
.errs{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.25);border-radius:12px;padding:12px 14px;margin-bottom:16px;font-size:12.5px;color:var(--danger)}
.errs ul{margin:0;padding-right:18px}
</style>
</head>
<body>
<div class="card">
  <h1>تثبيت واصل</h1>
  <p class="sub">يكتب ملف الإعدادات وينشئ جداول قاعدة البيانات تلقائياً - خطوة واحدة بس.</p>
  <?php if ($errors): ?>
  <div class="errs"><ul><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
  <?php endif; ?>
  <form method="post">
    <fieldset>
      <legend>قاعدة البيانات (من لوحة الاستضافة ← MySQL Databases)</legend>
      <label>Host</label><input name="db_host" value="<?= h($old['db_host'] ?? 'localhost') ?>">
      <label>Port</label><input name="db_port" value="<?= h($old['db_port'] ?? '3306') ?>">
      <label>اسم القاعدة</label><input name="db_name" value="<?= h($old['db_name'] ?? '') ?>" required>
      <label>مستخدم القاعدة</label><input name="db_user" value="<?= h($old['db_user'] ?? '') ?>" required>
      <label>كلمة مرور القاعدة</label><input name="db_pass" type="password" value="<?= h($old['db_pass'] ?? '') ?>">
    </fieldset>
    <fieldset>
      <legend>الموقع</legend>
      <label>الدومين (بدون https://)</label><input name="domain" value="<?= h($old['domain'] ?? ($_SERVER['HTTP_HOST'] ?? '')) ?>" required>
    </fieldset>
    <fieldset>
      <legend>حسابك كأدمن</legend>
      <label>البريد الإلكتروني</label><input name="admin_email" type="email" value="<?= h($old['admin_email'] ?? '') ?>" required>
      <label>كلمة المرور (8 محارف فأكثر)</label><input name="admin_password" type="password" minlength="8" required>
      <label>تأكيد كلمة المرور</label><input name="admin_password2" type="password" minlength="8" required>
    </fieldset>
    <button type="submit">ثبّت الموقع</button>
  </form>
</div>
</body>
</html>
