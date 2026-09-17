<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (is_installed()) {
    start_secure_session();
    flash('error', 'النظام مُثبَّت بالفعل. لإعادة التثبيت احذف ملف config/config.php من السيرفر أولاً.');
    redirect('login.php');
}

start_secure_session();
send_security_headers();

$errors = [];
$old = [
    'db_host'     => 'localhost',
    'db_port'     => '3306',
    'db_name'     => '',
    'db_user'     => '',
    'admin_name'  => '',
    'admin_email' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();

    $old['db_host']     = trim((string) ($_POST['db_host'] ?? ''));
    $old['db_port']     = trim((string) ($_POST['db_port'] ?? '3306')) ?: '3306';
    $old['db_name']     = trim((string) ($_POST['db_name'] ?? ''));
    $old['db_user']     = trim((string) ($_POST['db_user'] ?? ''));
    $db_pass            = (string) ($_POST['db_pass'] ?? '');
    $old['admin_name']  = trim((string) ($_POST['admin_name'] ?? ''));
    $old['admin_email'] = trim((string) ($_POST['admin_email'] ?? ''));
    $admin_password     = (string) ($_POST['admin_password'] ?? '');
    $admin_password_confirm = (string) ($_POST['admin_password_confirm'] ?? '');

    if ($old['db_host'] === '') {
        $errors[] = 'عنوان سيرفر قاعدة البيانات (DB Host) مطلوب.';
    }
    if (!preg_match('/^\d+$/', $old['db_port'])) {
        $errors[] = 'منفذ قاعدة البيانات (Port) يجب أن يكون رقماً.';
    }
    if ($old['db_name'] === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $old['db_name'])) {
        $errors[] = 'اسم قاعدة البيانات مطلوب، ويجب أن يحتوي أحرفاً إنجليزية/أرقام/شرطة سفلية فقط.';
    }
    if ($old['db_user'] === '') {
        $errors[] = 'مستخدم قاعدة البيانات (DB User) مطلوب.';
    }
    if ($old['admin_name'] === '') {
        $errors[] = 'اسم المسؤول مطلوب.';
    }
    if (!filter_var($old['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني للمسؤول غير صالح.';
    }
    if (strlen($admin_password) < 8) {
        $errors[] = 'كلمة مرور المسؤول يجب أن تكون 8 أحرف على الأقل.';
    }
    if ($admin_password !== $admin_password_confirm) {
        $errors[] = 'كلمتا المرور غير متطابقتين.';
    }

    if (empty($errors)) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $old['db_host'], $old['db_port']);
            $pdo = new PDO($dsn, $old['db_user'], $db_pass, [
                PDO::ATTR_ERRMODE               => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                PDO::ATTR_TIMEOUT               => 10,
            ]);

            $dbNameSafe = str_replace('`', '', $old['db_name']);
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameSafe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                // بعض مزوّدي الاستضافة المشتركة لا يسمحون بصلاحية CREATE DATABASE
                // ويكتفون بإنشائها مسبقاً من لوحة التحكم؛ نكمل ونحاول استخدامها مباشرة.
            }
            $pdo->exec("USE `{$dbNameSafe}`");

            $schema = file_get_contents(__DIR__ . '/database/schema.sql');
            if ($schema === false) {
                throw new RuntimeException('تعذّرت قراءة ملف database/schema.sql.');
            }
            $pdo->exec($schema);

            $existingAdmin = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ((int) $existingAdmin === 0) {
                $hash = password_hash($admin_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'admin', 'active')");
                $stmt->execute([$old['admin_name'], $old['admin_email'], $hash]);
            }

            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $appUrl    = $scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $scriptDir;

            $configArray = [
                'db' => [
                    'host'    => $old['db_host'],
                    'port'    => $old['db_port'],
                    'name'    => $old['db_name'],
                    'user'    => $old['db_user'],
                    'pass'    => $db_pass,
                    'charset' => 'utf8mb4',
                ],
                'app' => [
                    'name'     => 'لوحة إدارة المشاريع',
                    'url'      => rtrim($appUrl, '/'),
                    'key'      => 'base64:' . base64_encode(random_bytes(32)),
                    'debug'    => false,
                    'timezone' => 'Asia/Baghdad',
                ],
            ];

            $configDir = __DIR__ . '/config';
            if (!is_dir($configDir) && !mkdir($configDir, 0750, true) && !is_dir($configDir)) {
                throw new RuntimeException('تعذّر إنشاء مجلد config.');
            }

            $configContent = "<?php\n"
                . "// تم إنشاء هذا الملف تلقائياً بواسطة install.php بتاريخ " . date('Y-m-d H:i:s') . "\n"
                . "// لا تشارك هذا الملف أو ترفعه إلى مستودع عام - يحتوي بيانات اتصال قاعدة البيانات ومفتاح التشفير.\n"
                . 'return ' . var_export($configArray, true) . ";\n";

            if (file_put_contents($configDir . '/config.php', $configContent, LOCK_EX) === false) {
                throw new RuntimeException('تعذّرت الكتابة في config/config.php. تحقق من صلاحيات الكتابة على المجلد.');
            }
            @chmod($configDir . '/config.php', 0640);

            try {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$old['admin_email']]);
                $adminId = $stmt->fetchColumn();
                if ($adminId) {
                    $pdo->prepare('INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)')
                        ->execute([(int) $adminId, 'install', 'تثبيت النظام وإنشاء حساب المسؤول', $_SERVER['REMOTE_ADDR'] ?? null]);
                }
            } catch (Throwable $e) {
                // لا نوقف التثبيت بسبب فشل تسجيل النشاط
            }

            flash('success', 'تم تثبيت النظام بنجاح! سجّل الدخول بحساب المسؤول الذي أنشأته.');
            redirect('login.php');
        } catch (Throwable $e) {
            $errors[] = 'فشل التثبيت: ' . $e->getMessage();
        }
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>التثبيت التلقائي · لوحة إدارة المشاريع</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<div class="auth-shell">
  <div class="auth-card install-card">
    <div class="auth-logo"><i class="fa-solid fa-diagram-project"></i></div>
    <h1 class="auth-title">التثبيت التلقائي للنظام</h1>
    <p class="auth-sub">أدخل بيانات الاتصال بقاعدة بيانات MySQL وأنشئ حساب المسؤول الرئيسي. سيتم إنشاء قاعدة البيانات والجداول تلقائياً إن لم تكن موجودة.</p>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div>
          <?php foreach ($errors as $err): ?>
            <div><?= e($err) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <form method="post" action="install.php" class="stack-form" autocomplete="off">
      <?= csrf_field() ?>

      <div class="form-section-title"><i class="fa-solid fa-database"></i> بيانات الاتصال بقاعدة البيانات</div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label" for="db_host">DB Host</label>
          <input class="form-control" type="text" id="db_host" name="db_host" value="<?= e($old['db_host']) ?>" placeholder="localhost" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="db_port">Port</label>
          <input class="form-control" type="text" id="db_port" name="db_port" value="<?= e($old['db_port']) ?>" placeholder="3306" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label" for="db_name">DB Name</label>
        <input class="form-control" type="text" id="db_name" name="db_name" value="<?= e($old['db_name']) ?>" placeholder="pm_dashboard" required>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label" for="db_user">DB User</label>
          <input class="form-control" type="text" id="db_user" name="db_user" value="<?= e($old['db_user']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="db_pass">DB Password</label>
          <input class="form-control" type="password" id="db_pass" name="db_pass" autocomplete="new-password">
        </div>
      </div>

      <div class="form-section-title"><i class="fa-solid fa-user-shield"></i> حساب المسؤول الرئيسي (Admin)</div>
      <div class="form-group">
        <label class="form-label" for="admin_name">الاسم الكامل</label>
        <input class="form-control" type="text" id="admin_name" name="admin_name" value="<?= e($old['admin_name']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="admin_email">البريد الإلكتروني</label>
        <input class="form-control" type="email" id="admin_email" name="admin_email" value="<?= e($old['admin_email']) ?>" required>
      </div>
      <div class="form-grid-2">
        <div class="form-group">
          <label class="form-label" for="admin_password">كلمة المرور</label>
          <input class="form-control" type="password" id="admin_password" name="admin_password" autocomplete="new-password" minlength="8" required>
        </div>
        <div class="form-group">
          <label class="form-label" for="admin_password_confirm">تأكيد كلمة المرور</label>
          <input class="form-control" type="password" id="admin_password_confirm" name="admin_password_confirm" autocomplete="new-password" minlength="8" required>
        </div>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-lg">
        <i class="fa-solid fa-rocket"></i> ابدأ التثبيت
      </button>
    </form>
  </div>
</div>
</body>
</html>
