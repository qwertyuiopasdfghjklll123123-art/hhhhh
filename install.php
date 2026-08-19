<?php
/* ======================================================================
   المثبّت التلقائي (Auto-Installer) — ملف واحد شامل
   القسم الأول: منطق PHP (التحقق من المتطلبات، إنشاء الجداول، كتابة الإعدادات)
   القسم الثاني: الواجهة HTML/CSS — بعد وسم "الواجهة" أدناه
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/schema.php';

$configFile = __DIR__ . '/config/config.php';
$alreadyInstalled = file_exists($configFile);

$errors = [];
$success = false;
$step = 1;

$requirements = [
    'PHP 8.0 فأعلى' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'امتداد PDO' => extension_loaded('pdo'),
    'امتداد PDO MySQL' => extension_loaded('pdo_mysql'),
    'صلاحية الكتابة في مجلد config' => is_writable(__DIR__ . '/config'),
    'صلاحية الكتابة في مجلد uploads' => is_writable(__DIR__ . '/uploads'),
];
$requirementsOk = !in_array(false, $requirements, true);

if ($alreadyInstalled) {
    $step = 4;
}

if (!$alreadyInstalled && $requirementsOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $step = 2;

    $dbHost = trim($_POST['db_host'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');
    $hrEmail = trim($_POST['hr_email'] ?? '');
    $hrPassword = (string) ($_POST['hr_password'] ?? '');
    $hrPasswordConfirm = (string) ($_POST['hr_password_confirm'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'الرجاء تعبئة جميع بيانات الاتصال بقاعدة البيانات.';
    }
    if (!filter_var($hrEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني لمدير الموارد البشرية غير صالح.';
    }
    if (strlen($hrPassword) < 8) {
        $errors[] = 'كلمة مرور مدير الموارد البشرية يجب ألا تقل عن 8 أحرف.';
    }
    if ($hrPassword !== $hrPasswordConfirm) {
        $errors[] = 'كلمتا المرور غير متطابقتين.';
    }
    if ($appUrl === '') {
        $appUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }

    if (empty($errors)) {
        try {
            $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $step = 3;

            $statements = schema_statements();
            foreach (schema_creation_order() as $table) {
                $pdo->exec($statements[$table]);
            }

            seed_default_data($pdo, $hrEmail, $hrPassword);

            $appSecret = bin2hex(random_bytes(32));
            $configContent = "<?php\n" .
                "define('DB_HOST', " . var_export($dbHost, true) . ");\n" .
                "define('DB_NAME', " . var_export($dbName, true) . ");\n" .
                "define('DB_USER', " . var_export($dbUser, true) . ");\n" .
                "define('DB_PASS', " . var_export($dbPass, true) . ");\n" .
                "define('DB_CHARSET', 'utf8mb4');\n" .
                "define('APP_URL', " . var_export($appUrl, true) . ");\n" .
                "define('APP_SECRET', " . var_export($appSecret, true) . ");\n" .
                "define('APP_INSTALLED', true);\n";

            if (file_put_contents($configFile, $configContent) === false) {
                throw new RuntimeException('تعذر كتابة ملف الإعدادات config/config.php. تحقق من صلاحيات المجلد.');
            }

            $success = true;
            $step = 4;
        } catch (PDOException $e) {
            $errors[] = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
            $step = 1;
        } catch (Throwable $e) {
            $errors[] = 'حدث خطأ أثناء التثبيت: ' . $e->getMessage();
            $step = 1;
        }
    }
}

function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* ======================================================================
   الواجهة (HTML / CSS View)
   ====================================================================== */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تثبيت النظام — شركة الصوى للصرافة</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card" style="max-width: 560px;">
        <div class="auth-logo">✥</div>
        <div class="auth-title">مثبّت نظام إدارة الصرافة</div>
        <div class="auth-subtitle">إعداد قاعدة البيانات والحساب الإداري الأول تلقائياً</div>

        <div class="install-steps">
            <span class="<?= $step >= 1 ? 'done' : '' ?>"></span>
            <span class="<?= $step >= 2 ? 'done' : '' ?>"></span>
            <span class="<?= $step >= 3 ? 'done' : '' ?>"></span>
            <span class="<?= $step >= 4 ? 'done' : '' ?>"></span>
        </div>

        <?php if ($alreadyInstalled): ?>
            <div class="alert alert-success">
                النظام مُثبّت بالفعل. إذا كنت تريد إعادة التثبيت، احذف ملف <code>config/config.php</code> من السيرفر أولاً.
            </div>
            <a class="btn btn-primary btn-block" href="/login.php">الذهاب إلى صفحة تسجيل الدخول</a>

        <?php elseif ($success): ?>
            <div class="alert alert-success">
                تم تثبيت النظام بنجاح! تم إنشاء قاعدة البيانات والجداول، وحساب مدير الموارد البشرية جاهز للدخول.
            </div>
            <a class="btn btn-primary btn-block" href="/login.php">تسجيل الدخول الآن</a>

        <?php else: ?>

            <?php if (!$requirementsOk): ?>
                <div class="alert alert-danger">لا يمكن المتابعة — بعض متطلبات السيرفر غير متوفرة:</div>
            <?php endif; ?>

            <div class="card" style="padding:14px 16px; margin-bottom:20px;">
                <?php foreach ($requirements as $label => $ok): ?>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;">
                        <span><?= h($label) ?></span>
                        <span class="badge <?= $ok ? 'badge-success' : 'badge-danger' ?>"><?= $ok ? 'متوفر' : 'غير متوفر' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($requirementsOk): ?>

                <?php foreach ($errors as $err): ?>
                    <div class="alert alert-danger"><?= h($err) ?></div>
                <?php endforeach; ?>

                <form method="post" action="/install.php">
                    <div class="form-group">
                        <label>عنوان النظام (URL)</label>
                        <input type="text" name="app_url" class="form-control" placeholder="https://example.com" value="<?= h($_POST['app_url'] ?? '') ?>">
                    </div>

                    <div class="grid-2">
                        <div class="form-group">
                            <label>عنوان سيرفر قاعدة البيانات (Host)</label>
                            <input type="text" name="db_host" class="form-control" value="<?= h($_POST['db_host'] ?? 'localhost') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>اسم قاعدة البيانات</label>
                            <input type="text" name="db_name" class="form-control" value="<?= h($_POST['db_name'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>مستخدم قاعدة البيانات</label>
                            <input type="text" name="db_user" class="form-control" value="<?= h($_POST['db_user'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>كلمة مرور قاعدة البيانات</label>
                            <input type="password" name="db_pass" class="form-control">
                        </div>
                    </div>

                    <hr style="border-color: var(--border); margin: 20px 0;">
                    <div class="form-group" style="font-size:13px;color:var(--text-muted);">حساب مدير الموارد البشرية الأول (الصلاحية الكاملة)</div>

                    <div class="form-group">
                        <label>البريد الإلكتروني لتسجيل الدخول</label>
                        <input type="email" name="hr_email" class="form-control" value="<?= h($_POST['hr_email'] ?? '') ?>" required>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>كلمة المرور</label>
                            <input type="password" name="hr_password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>تأكيد كلمة المرور</label>
                            <input type="password" name="hr_password_confirm" class="form-control" required>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-block">تثبيت النظام الآن</button>
                </form>

            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
