<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
mb_internal_encoding('UTF-8');

/* ملف تنصيب أولي — يضبط اتصال MySQL أولاً (إلزامي، الموقع يعتمد عليه حصراً
   بلا رجوع لملفات JSON)، ثم اسم الموقع وبيانات دخول الأدمن. يشترك بطبقة
   التخزين نفسها التي يستخدمها index.php عبر includes/db.php، لذا يعمل
   بشكل صحيح سواء شُغّل قبل أول زيارة للتطبيق أو بعدها. */

require_once __DIR__ . '/includes/db.php';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$dbConfigFile = DB_CONFIG_FILE;
$dbConfig = file_exists($dbConfigFile) ? json_decode((string)file_get_contents($dbConfigFile), true) : [];
$dbConfig = is_array($dbConfig) ? $dbConfig : [];

$dbSaved = isset($_GET['saved']) && $_GET['saved'] === 'mysql';
$dbError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'mysql') {
    $host = trim((string)($_POST['db_host'] ?? ''));
    $name = trim((string)($_POST['db_name'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');
    if ($host === '' || $name === '' || $user === '') {
        $dbError = 'الرجاء تعبئة المضيف واسم القاعدة واسم المستخدم — الموقع يعتمد على MySQL حصراً';
    } else {
        $dbError = db_test_connection($host, $name, $user, $pass);
        if ($dbError === null) {
            $dbConfig = ['host' => $host, 'name' => $name, 'user' => $user, 'pass' => $pass];
            file_put_contents($dbConfigFile, json_encode($dbConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            @unlink(DATA_DIR . '/.mysql_ready');
            /* ثوابت DB_HOST/DB_NAME/... تحمل القيم القديمة (عُرّفت أول تحميل
               للطلب قبل هذا الحفظ) ولا يمكن إعادة تعريفها بنفس الطلب — نعيد
               تحميل الصفحة بطلب جديد كي تُقرأ القيم المحفوظة توّاً من جديد. */
            header('Location: install.php?saved=mysql');
            exit;
        }
    }
}

/* لا نلمس db_read/db_write إلا بعد التأكد أن الاتصال فعلاً قائم الآن (وليس
   فقط أن ملف الإعدادات موجود) — تجنباً لتوقف install.php نفسه بصفحة الخطأ
   قبل أن يتاح للزائر فرصة تصحيح بيانات الاتصال من النموذج أدناه. */
$mysqlReady = db_connect() !== null;

$users = [];
$hasAdmin = false;
if ($mysqlReady) {
    $users = db_read('users');
    foreach ($users as $u) if (!empty($u['is_admin']) && !empty($u['password_hash'])) { $hasAdmin = true; break; }
}

$done = false;
$error = '';

if ($mysqlReady && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'site') {
    $siteName = trim((string)($_POST['site_name'] ?? ''));
    $adminName = trim((string)($_POST['admin_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if ($siteName === '' || $adminName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $error = 'الرجاء تعبئة كل الحقول بشكل صحيح (كلمة المرور 6 خانات على الأقل)';
    } elseif ($password !== $confirm) {
        $error = 'كلمة المرور وتأكيدها غير متطابقتين';
    } else {
        $settings = db_read('settings', ['monthly_fee' => 15000, 'categories' => [], 'payment_methods' => [], 'coupons' => []]);
        $settings['site_name'] = $siteName;
        db_write('settings', $settings);

        $found = false;
        foreach ($users as &$u) {
            if (!empty($u['is_admin'])) {
                $u['name'] = $adminName;
                $u['email'] = mb_strtolower($email);
                $u['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                $found = true;
            }
        }
        unset($u);
        if (!$found) {
            $maxId = 0;
            foreach ($users as $x) $maxId = max($maxId, (int)($x['id'] ?? 0));
            $users[] = [
                'id' => $maxId + 1, 'name' => $adminName, 'email' => mb_strtolower($email),
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'phone' => '',
                'wallet' => 0, 'wallet_log' => [], 'favorites' => ['stores' => [], 'products' => []],
                'is_admin' => true, 'created_at' => time(),
            ];
        }
        db_write('users', $users);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تنصيب التطبيق</title>
<style>
*{box-sizing:border-box;font-family:'Segoe UI',Tahoma,sans-serif}
body{background:#f8f6f3;margin:0;padding:24px;display:flex;justify-content:center;min-height:100vh}
.box{max-width:420px;width:100%;background:#fff;border-radius:18px;padding:26px;box-shadow:0 4px 20px rgba(0,0,0,.08);height:fit-content;margin-top:24px}
h1{font-size:1.2rem;margin:0 0 18px}
.field{margin-bottom:14px}
label{display:block;font-size:.8rem;font-weight:700;margin-bottom:6px;color:#333}
input{width:100%;padding:11px;border:1px solid #ddd;border-radius:10px;font-size:.9rem}
button{width:100%;padding:13px;background:#f2b100;border:none;border-radius:10px;font-weight:800;cursor:pointer;font-size:.9rem}
.err{background:#fde8e8;color:#c0392b;padding:10px;border-radius:10px;margin-bottom:14px;font-size:.8rem}
.ok{background:#e6f7ee;color:#1e824c;padding:16px;border-radius:10px;font-size:.85rem;line-height:1.8}
.warn{background:#fff8e1;color:#8a6d00;padding:12px;border-radius:10px;font-size:.78rem;margin-top:14px;line-height:1.8}
a.btn{display:block;text-align:center;text-decoration:none;background:#1a1a2e;color:#fff;padding:12px;border-radius:10px;margin-top:12px;font-weight:700}
</style>
</head>
<body>
<div class="box">
<h1>🗄️ الخطوة 1 — الاتصال بقاعدة بيانات MySQL</h1>
<p style="font-size:.76rem;color:#666;line-height:1.8;margin-bottom:16px">هذا الموقع يعتمد على MySQL حصراً لتخزين بياناته (لا يوجد تخزين بديل). أنشئ قاعدة بيانات ومستخدماً لها من لوحة استضافتك (cPanel غالباً)، ثم أدخل بياناتها هنا — يُختبر الاتصال أولاً قبل الحفظ، وتُنقل أي بيانات JSON قديمة (من نسخة سابقة) إليها تلقائياً بأول اتصال ناجح دون أي فقدان.</p>
<?php if ($dbSaved): ?><div class="ok" style="margin-bottom:14px">✅ تم اختبار الاتصال وحفظه.</div><?php endif; ?>
<?php if ($dbError): ?><div class="err"><?= h($dbError) ?></div><?php endif; ?>
<?php if ($mysqlReady): ?><div class="ok" style="margin-bottom:14px">✅ الاتصال بقاعدة البيانات يعمل الآن.</div><?php endif; ?>
<form method="post">
    <input type="hidden" name="form" value="mysql">
    <div class="field"><label>المضيف (Host)</label><input type="text" name="db_host" value="<?= h($dbConfig['host'] ?? '') ?>" placeholder="localhost" style="direction:ltr;text-align:left" required></div>
    <div class="field"><label>اسم قاعدة البيانات</label><input type="text" name="db_name" value="<?= h($dbConfig['name'] ?? '') ?>" style="direction:ltr;text-align:left" required></div>
    <div class="field"><label>اسم المستخدم</label><input type="text" name="db_user" value="<?= h($dbConfig['user'] ?? '') ?>" style="direction:ltr;text-align:left" required></div>
    <div class="field"><label>كلمة المرور</label><input type="password" name="db_pass" value="<?= h($dbConfig['pass'] ?? '') ?>" style="direction:ltr;text-align:left"></div>
    <button type="submit">اختبار وحفظ</button>
</form>
</div>

<div class="box">
<h1>🛠️ الخطوة 2 — اسم الموقع وحساب الأدمن</h1>
<?php if (!$mysqlReady): ?>
    <div class="warn">⚠️ اضبط الاتصال بقاعدة بيانات MySQL أعلاه أولاً — هذا النموذج يُفعَّل بعدها مباشرة.</div>
<?php elseif ($done): ?>
    <div class="ok">✅ تم التنصيب بنجاح! يمكنك الآن تسجيل الدخول بالبريد وكلمة المرور اللي حددتهم.</div>
    <a class="btn" href="index.php">فتح التطبيق</a>
    <div class="warn">⚠️ لأمان موقعك، احذف ملف install.php من الاستضافة الآن.</div>
<?php else: ?>
    <?php if ($hasAdmin): ?><div class="warn" style="margin-bottom:14px">يوجد حساب أدمن مُهيّأ مسبقاً — إرسال هذا النموذج سيستبدل بريده وكلمة مروره بما تكتبه هنا.</div><?php endif; ?>
    <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="form" value="site">
        <div class="field"><label>اسم الموقع</label><input type="text" name="site_name" value="<?= h($_POST['site_name'] ?? '') ?>" required></div>
        <div class="field"><label>اسم الأدمن</label><input type="text" name="admin_name" value="<?= h($_POST['admin_name'] ?? '') ?>" required></div>
        <div class="field"><label>بريد الأدمن</label><input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required></div>
        <div class="field"><label>كلمة المرور</label><input type="password" name="password" minlength="6" required></div>
        <div class="field"><label>تأكيد كلمة المرور</label><input type="password" name="confirm" minlength="6" required></div>
        <button type="submit">تنصيب</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
