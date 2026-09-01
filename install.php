<?php
// ============================================================
// ملف تنصيب استضافتي
// يُستخدم مرة واحدة فقط: إعداد الاتصال بقاعدة بيانات MySQL وإنشاء حساب الأدمن الأول
// ============================================================

define('ISTIDAFATI_INSTALLER', true);
require_once __DIR__ . '/includes/bootstrap.php';

$alreadyInstalled = loadDbConfig() !== null;
$errors = [];
$success = false;

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306') ?: '3306';
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');

    $siteName = trim($_POST['site_name'] ?? '') ?: 'استضافتي';

    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'الرجاء تعبئة جميع بيانات قاعدة البيانات (المضيف، اسم القاعدة، المستخدم).';
    }
    if ($adminName === '') {
        $errors[] = 'الرجاء إدخال اسم مدير النظام.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني لحساب الأدمن غير صحيح.';
    }
    if (strlen($adminPassword) < 6) {
        $errors[] = 'كلمة مرور الأدمن يجب أن تكون 6 أحرف على الأقل.';
    }
    if ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'كلمتا مرور الأدمن غير متطابقتين.';
    }

    $pdo = null;
    if (!$errors) {
        try {
            $pdo = connectDb(['db_host' => $dbHost, 'db_port' => $dbPort, 'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass]);
        } catch (PDOException $e) {
            $errors[] = 'تعذر الاتصال بقاعدة البيانات. تحقق من صحة البيانات المدخلة (المضيف، المنفذ، اسم القاعدة، المستخدم، كلمة المرور). تفاصيل الخطأ: ' . $e->getMessage();
        }
    }

    if (!$errors && $pdo) {
        try {
            initSchema($pdo, $dbName);

            $existingAdmin = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $existingAdmin->execute([$adminEmail]);
            if ($existingAdmin->fetch()) {
                $errors[] = 'هذا البريد الإلكتروني مستخدم بالفعل في قاعدة البيانات.';
            } else {
                $pdo->prepare('INSERT INTO users (name, email, password_hash, is_admin, balance, referral_code) VALUES (?,?,?,1,0,?)')
                    ->execute([$adminName, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), generateReferralCode()]);

                setSetting($pdo, 'site_name', $siteName);

                $configContent = "<?php\n"
                    . "// تم إنشاء هذا الملف تلقائياً بواسطة install.php - لا تشاركه مع أحد\n"
                    . "return " . var_export([
                        'db_host' => $dbHost,
                        'db_port' => $dbPort,
                        'db_name' => $dbName,
                        'db_user' => $dbUser,
                        'db_pass' => $dbPass,
                    ], true) . ";\n";

                if (@file_put_contents(DB_CONFIG_FILE, $configContent) === false) {
                    $errors[] = 'تعذر إنشاء ملف الإعدادات (includes/db_config.php). تأكد من أن مجلد includes قابل للكتابة (صلاحيات 755 أو أعلى)، ثم أعد المحاولة.';
                } else {
                    $success = true;
                }
            }
        } catch (PDOException $e) {
            $errors[] = 'حدث خطأ أثناء إعداد جداول قاعدة البيانات. تأكد من أن المستخدم لديه صلاحيات CREATE/ALTER الكافية. تفاصيل الخطأ: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنصيب استضافتي</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-primary: #f7f4f0; --bg-card: #ffffff; --text-primary: #221a12; --text-secondary: #6b5d50;
            --text-muted: #998a7c; --accent: #ff7a1a; --accent-dark: #ee6a05; --border-color: #f0e6da;
            --radius: 18px; --radius-sm: 12px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif;
            background: var(--bg-primary); color: var(--text-primary); padding: 24px 16px 60px;
        }
        .wrap { max-width: 560px; margin: 0 auto; }
        .brand { text-align: center; margin-bottom: 22px; }
        .brand .logo { width: 56px; height: 56px; border-radius: 16px; background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 24px; margin: 0 auto 10px; }
        .brand h1 { font-size: 19px; font-weight: 900; }
        .brand p { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }
        .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 20px; margin-bottom: 16px; box-shadow: 0 10px 40px rgba(34,26,18,.06); }
        .card h2 { font-size: 14px; font-weight: 800; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; color: var(--accent-dark); }
        .field-row { margin-bottom: 12px; }
        .field-row label { display: block; font-size: 12.5px; font-weight: 700; color: var(--text-secondary); margin-bottom: 5px; }
        .field-row input {
            width: 100%; padding: 11px 13px; border-radius: var(--radius-sm); border: 1.5px solid var(--border-color);
            background: var(--bg-primary); color: var(--text-primary); font-size: 14px; font-family: inherit; outline: none;
        }
        .field-row input:focus { border-color: var(--accent); }
        .field-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .hint { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        .btn-submit {
            width: 100%; padding: 14px; border: none; border-radius: var(--radius-sm);
            background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: #fff;
            font-size: 15px; font-weight: 800; font-family: inherit; cursor: pointer; margin-top: 6px;
        }
        .alert { border-radius: var(--radius-sm); padding: 12px 14px; font-size: 13px; margin-bottom: 16px; line-height: 1.7; }
        .alert-error { background: rgba(239,68,68,.1); color: #b91c1c; }
        .alert-success { background: rgba(16,185,129,.1); color: #059669; }
        .alert ul { padding-inline-start: 18px; margin-top: 4px; }
        .success-screen { text-align: center; padding: 30px 10px; }
        .success-screen .icon { width: 68px; height: 68px; border-radius: 50%; background: rgba(16,185,129,.12); color: #059669; font-size: 30px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
        .success-screen h2 { font-size: 18px; font-weight: 900; margin-bottom: 8px; }
        .success-screen p { font-size: 13px; color: var(--text-muted); margin-bottom: 20px; line-height: 1.7; }
        .btn-link { display: inline-block; padding: 12px 28px; border-radius: var(--radius-sm); background: linear-gradient(135deg, var(--accent), var(--accent-dark)); color: #fff; text-decoration: none; font-weight: 800; font-size: 14px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="brand">
            <div class="logo"><i class="fas fa-server"></i></div>
            <h1>تنصيب منصة استضافتي</h1>
            <p>إعداد قاعدة بيانات MySQL وحساب مدير النظام لأول مرة</p>
        </div>

        <?php if ($alreadyInstalled): ?>
        <div class="card">
            <div class="success-screen">
                <div class="icon"><i class="fas fa-check"></i></div>
                <h2>التطبيق مُنصّب بالفعل</h2>
                <p>تم إعداد قاعدة البيانات وحساب الأدمن مسبقاً على هذا الخادم.<br>لإعادة التنصيب من جديد (على سبيل المثال لنقل الموقع إلى استضافة أخرى)، احذف الملف <code>includes/db_config.php</code> يدوياً عبر الاستضافة ثم أعد تحميل هذه الصفحة.</p>
                <a href="index.php" class="btn-link"><i class="fas fa-arrow-left"></i> الذهاب إلى الموقع</a>
            </div>
        </div>
        <?php elseif ($success): ?>
        <div class="card">
            <div class="success-screen">
                <div class="icon"><i class="fas fa-check"></i></div>
                <h2>تم التنصيب بنجاح!</h2>
                <p>تم إعداد قاعدة البيانات وإنشاء حساب مدير النظام. يمكنك الآن تسجيل الدخول والبدء بإدارة المنصة من لوحة التحكم.</p>
                <a href="index.php" class="btn-link"><i class="fas fa-arrow-left"></i> الذهاب إلى الموقع</a>
            </div>
        </div>
        <?php else: ?>

        <?php if ($errors): ?>
        <div class="alert alert-error">
            <strong>تعذر إتمام التنصيب:</strong>
            <ul>
                <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" action="install.php">
            <?php echo csrfField(); ?>

            <div class="card">
                <h2><i class="fas fa-database"></i> بيانات الاتصال بقاعدة بيانات MySQL</h2>
                <div class="field-grid-2">
                    <div class="field-row">
                        <label>مضيف قاعدة البيانات (Host)</label>
                        <input type="text" name="db_host" value="<?php echo e($_POST['db_host'] ?? 'localhost'); ?>" required dir="ltr">
                    </div>
                    <div class="field-row">
                        <label>المنفذ (Port)</label>
                        <input type="text" name="db_port" value="<?php echo e($_POST['db_port'] ?? '3306'); ?>" required dir="ltr">
                    </div>
                </div>
                <div class="field-row">
                    <label>اسم قاعدة البيانات</label>
                    <input type="text" name="db_name" value="<?php echo e($_POST['db_name'] ?? ''); ?>" required dir="ltr" placeholder="مثال: istidafati_db">
                </div>
                <div class="field-grid-2">
                    <div class="field-row">
                        <label>مستخدم قاعدة البيانات</label>
                        <input type="text" name="db_user" value="<?php echo e($_POST['db_user'] ?? ''); ?>" required dir="ltr">
                    </div>
                    <div class="field-row">
                        <label>كلمة مرور قاعدة البيانات</label>
                        <input type="password" name="db_pass" dir="ltr">
                    </div>
                </div>
                <p class="hint">هذه البيانات توفّرها لوحة الاستضافة (مثل cPanel) عند إنشاء قاعدة بيانات MySQL جديدة ومستخدم لها.</p>
            </div>

            <div class="card">
                <h2><i class="fas fa-shop"></i> اسم الموقع</h2>
                <div class="field-row">
                    <label>اسم الموقع (يمكن تغييره لاحقاً من الإعدادات)</label>
                    <input type="text" name="site_name" value="<?php echo e($_POST['site_name'] ?? 'استضافتي'); ?>">
                </div>
            </div>

            <div class="card">
                <h2><i class="fas fa-user-shield"></i> حساب مدير النظام (الأدمن)</h2>
                <div class="field-row">
                    <label>الاسم الكامل</label>
                    <input type="text" name="admin_name" value="<?php echo e($_POST['admin_name'] ?? ''); ?>" required>
                </div>
                <div class="field-row">
                    <label>البريد الإلكتروني</label>
                    <input type="email" name="admin_email" value="<?php echo e($_POST['admin_email'] ?? ''); ?>" required dir="ltr">
                </div>
                <div class="field-grid-2">
                    <div class="field-row">
                        <label>كلمة المرور</label>
                        <input type="password" name="admin_password" required dir="ltr" placeholder="6 أحرف على الأقل">
                    </div>
                    <div class="field-row">
                        <label>تأكيد كلمة المرور</label>
                        <input type="password" name="admin_password_confirm" required dir="ltr">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-submit"><i class="fas fa-rocket"></i> بدء التنصيب</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
