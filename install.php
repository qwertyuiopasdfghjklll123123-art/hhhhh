<?php
// ================================================================
// معالج التنصيب: يقوم بـ
//  1) الاتصال بقاعدة بيانات MySQL وإنشاء الجداول (install/schema.sql)
//  2) استيراد بيانات logs/database.json القديمة إن وُجدت (اختياري)
//  3) إنشاء/تأكيد حساب المدير
//  4) كتابة config.php بإعدادات الاتصال
// يرفض العمل إذا كان config.php موجوداً بالفعل لمنع إعادة التنصيب
// وفقدان الاتصال الحالي بالخطأ.
// ================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/functions.php';

session_start();
if (empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(16));
}

$alreadyInstalled = file_exists(__DIR__ . '/config.php');
$legacyJsonPath = __DIR__ . '/logs/database.json';
$hasLegacyData = file_exists($legacyJsonPath);

$errors = [];
$success = false;
$summary = null;

$old = [
    'db_host' => $_POST['db_host'] ?? 'localhost',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? '',
    'db_user' => $_POST['db_user'] ?? '',
    'admin_fullname' => $_POST['admin_fullname'] ?? 'مدير النظام',
    'admin_email' => $_POST['admin_email'] ?? '',
    'import_legacy' => isset($_POST['import_legacy']),
];

function runSqlFile(PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if ($stmt === '') continue;
        $pdo->exec($stmt);
    }
}

function importLegacyData(PDO $pdo, array $json): array {
    $summary = ['users' => 0, 'categories' => 0];

    if (!empty($json['users']) && is_array($json['users'])) {
        foreach ($json['users'] as $u) {
            if (empty($u['email']) || empty($u['password'])) continue;
            if (db_get_user_by_email($pdo, $u['email'])) continue;
            $stmt = $pdo->prepare('INSERT INTO users (id, fullname, email, password, is_admin, created_at, last_login) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([
                !empty($u['id']) ? (int)$u['id'] : null,
                $u['fullname'] ?? '',
                $u['email'],
                $u['password'],
                !empty($u['isAdmin']) ? 1 : 0,
                $u['created_at'] ?? db_now(),
                $u['last_login'] ?? null,
            ]);
            $summary['users']++;
        }
        $max = (int)$pdo->query('SELECT COALESCE(MAX(id),0) AS m FROM users')->fetch()['m'];
        $pdo->exec('ALTER TABLE users AUTO_INCREMENT = ' . ($max + 1));
    }

    if (!empty($json['catalog']) && is_array($json['catalog'])) {
        db_sync_catalog($pdo, $json['catalog']);
        $summary['categories'] = count($json['catalog']['categories'] ?? []);
    }

    if (!empty($json['settings']) && is_array($json['settings'])) {
        db_save_settings($pdo, $json['settings']);
    }

    db_ensure_stats_row($pdo);
    if (!empty($json['stats']) && is_array($json['stats'])) {
        $s = $json['stats'];
        $pdo->prepare('UPDATE stats SET total_visitors=?, total_favorites=?, total_orders=? WHERE id=1')->execute([
            (int)($s['total_visitors'] ?? 0), (int)($s['total_favorites'] ?? 0), (int)($s['total_orders'] ?? 0),
        ]);
        if (!empty($s['daily_visits']) && is_array($s['daily_visits'])) {
            foreach ($s['daily_visits'] as $date => $count) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) continue;
                $pdo->prepare('INSERT INTO daily_visits (visit_date, count) VALUES (?,?) ON DUPLICATE KEY UPDATE count = VALUES(count)')->execute([$date, (int)$count]);
            }
        }
        if (!empty($s['most_requested_items']) && is_array($s['most_requested_items'])) {
            foreach ($s['most_requested_items'] as $item) {
                if (empty($item['name']) || empty($item['category'])) continue;
                $pdo->prepare('INSERT INTO most_requested_items (name, category, type, count) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE count = VALUES(count)')
                    ->execute([$item['name'], $item['category'], $item['type'] ?? 'product', (int)($item['count'] ?? 1)]);
            }
        }
    }

    if (!empty($json['push_subscriptions']) && is_array($json['push_subscriptions'])) {
        foreach ($json['push_subscriptions'] as $sub) {
            $pdo->prepare('INSERT INTO push_subscriptions (subscription, created_at) VALUES (?, ?)')
                ->execute([json_encode($sub, JSON_UNESCAPED_UNICODE), db_now()]);
        }
    }

    return $summary;
}

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['install_token']) || !hash_equals($_SESSION['install_token'], $_POST['install_token'])) {
        $errors[] = 'انتهت صلاحية النموذج، الرجاء إعادة المحاولة.';
    }

    $dbHost = trim($_POST['db_host'] ?? '');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminFullname = trim($_POST['admin_fullname'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $importLegacy = isset($_POST['import_legacy']) && $hasLegacyData;

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'يرجى تعبئة بيانات الاتصال بقاعدة البيانات (المضيف، اسم القاعدة، المستخدم).';
    }
    if (!ctype_digit($dbPort)) {
        $errors[] = 'منفذ قاعدة البيانات يجب أن يكون رقماً.';
    }
    if ($adminFullname === '' || $adminEmail === '' || $adminPassword === '') {
        $errors[] = 'يرجى تعبئة بيانات حساب المدير بالكامل.';
    }
    if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني لحساب المدير غير صالح.';
    }
    if ($adminPassword !== '' && strlen($adminPassword) < 4) {
        $errors[] = 'كلمة مرور المدير يجب أن تكون 4 أحرف على الأقل.';
    }

    $legacyJson = null;
    if ($importLegacy) {
        $raw = @file_get_contents($legacyJsonPath);
        $legacyJson = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($legacyJson)) {
            $errors[] = 'تعذّرت قراءة ملف logs/database.json الحالي، تحقق أنه ملف JSON صالح.';
        }
    }

    if (empty($errors)) {
        try {
            $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            require_once __DIR__ . '/includes/data.php';

            runSqlFile($pdo, __DIR__ . '/install/schema.sql');

            $summary = ['users' => 0, 'categories' => 0];
            if ($importLegacy && $legacyJson) {
                $summary = importLegacyData($pdo, $legacyJson);
            } else {
                db_ensure_settings_row($pdo);
                db_ensure_stats_row($pdo);
            }

            $existingAdmin = db_get_user_by_email($pdo, $adminEmail);
            if ($existingAdmin) {
                if (!$existingAdmin['is_admin']) {
                    db_update_user($pdo, $existingAdmin['id'], ['is_admin' => 1]);
                }
            } else {
                db_create_user($pdo, $adminFullname, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), true);
            }

            $configContents = "<?php\n"
                . "// تم إنشاؤه تلقائياً بواسطة install.php - لا تشاركه مع أحد\n"
                . 'define(\'DB_HOST\', ' . var_export($dbHost, true) . ");\n"
                . 'define(\'DB_PORT\', ' . var_export($dbPort, true) . ");\n"
                . 'define(\'DB_NAME\', ' . var_export($dbName, true) . ");\n"
                . 'define(\'DB_USER\', ' . var_export($dbUser, true) . ");\n"
                . 'define(\'DB_PASS\', ' . var_export($dbPass, true) . ");\n"
                . 'define(\'DB_CHARSET\', \'utf8mb4\');' . "\n";

            if (!@file_put_contents(__DIR__ . '/config.php', $configContents)) {
                $errors[] = 'تعذّرت كتابة ملف config.php تلقائياً. انسخ المحتوى التالي وأنشئ الملف يدوياً بجانب index.php:';
                $errors[] = $configContents;
            } else {
                $success = true;
                unset($_SESSION['install_token']);
            }
        } catch (Throwable $e) {
            $errors[] = 'فشل الاتصال بقاعدة البيانات أو إنشاء الجداول: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تنصيب Almulla</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Tahoma', Arial, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 30px 15px; }
    .wrap { max-width: 640px; margin: 0 auto; }
    h1 { font-size: 1.4rem; margin-bottom: 4px; }
    p.sub { color: #94a3b8; margin-top: 0; }
    .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 22px; margin-bottom: 18px; }
    .card h2 { font-size: 1rem; margin-top: 0; color: #a5b4fc; }
    label { display: block; font-size: 0.85rem; margin: 12px 0 4px; color: #cbd5e1; }
    input[type=text], input[type=email], input[type=password], input[type=number] {
        width: 100%; padding: 9px 12px; border-radius: 8px; border: 1px solid #475569;
        background: #0f172a; color: #e2e8f0; font-size: 0.9rem;
    }
    .row { display: flex; gap: 12px; }
    .row > div { flex: 1; }
    .checkbox-row { display: flex; align-items: center; gap: 8px; margin-top: 14px; }
    .checkbox-row input { width: auto; }
    button { margin-top: 20px; width: 100%; padding: 12px; background: #6366f1; color: white; border: none;
        border-radius: 8px; font-size: 0.95rem; cursor: pointer; font-weight: 700; }
    button:hover { background: #4f46e5; }
    .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 0.85rem; white-space: pre-wrap; }
    .alert-error { background: rgba(239,68,68,0.15); border: 1px solid #ef4444; color: #fecaca; }
    .alert-success { background: rgba(16,185,129,0.15); border: 1px solid #10b981; color: #a7f3d0; }
    .alert-info { background: rgba(99,102,241,0.12); border: 1px solid #6366f1; color: #c7d2fe; }
    code { background: #0f172a; padding: 2px 6px; border-radius: 4px; }
    a { color: #a5b4fc; }
</style>
</head>
<body>
<div class="wrap">
    <h1>🛠️ تنصيب تطبيق Almulla</h1>
    <p class="sub">نقل قاعدة البيانات إلى MySQL وإنشاء حساب المدير</p>

    <?php if ($alreadyInstalled): ?>
        <div class="card">
            <div class="alert alert-info">
                التطبيق مُنصَّب بالفعل (تم العثور على <code>config.php</code>).<br>
                لإعادة التنصيب من جديد، احذف ملف <code>config.php</code> أولاً ثم أعد تحميل هذه الصفحة.<br>
                <strong>لأمان موقعك، يفضّل حذف <code>install.php</code> ومجلد <code>install/</code> بعد التأكد أن كل شيء يعمل.</strong>
            </div>
            <a href="index.php">&larr; الذهاب إلى الموقع</a>
        </div>

    <?php elseif ($success): ?>
        <div class="card">
            <div class="alert alert-success">
                ✅ تم التنصيب بنجاح!<br>
                <?php if (!empty($importLegacy) && $summary): ?>
                    تم استيراد <?php echo (int)$summary['users']; ?> مستخدم و<?php echo (int)$summary['categories']; ?> فئة من البيانات القديمة.<br>
                <?php endif; ?>
                يمكنك الآن تسجيل الدخول بحساب المدير الذي أدخلته.
            </div>
            <div class="alert alert-error" style="background:rgba(239,68,68,0.12);">
                ⚠️ لأمان موقعك: احذف الآن ملف <code>install.php</code> ومجلد <code>install/</code> من السيرفر.
                طالما بقيا موجودين، بإمكان أي زائر محاولة إعادة تشغيل التنصيب.
            </div>
            <a href="index.php">&larr; الذهاب إلى الموقع</a>
        </div>

    <?php else: ?>
        <?php foreach ($errors as $err): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endforeach; ?>

        <?php if ($hasLegacyData): ?>
            <div class="alert alert-info">
                📦 تم العثور على بيانات سابقة في <code>logs/database.json</code>. يمكنك استيرادها تلقائياً أدناه.
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="install_token" value="<?php echo htmlspecialchars($_SESSION['install_token'], ENT_QUOTES, 'UTF-8'); ?>">

            <div class="card">
                <h2>1) الاتصال بقاعدة بيانات MySQL</h2>
                <div class="row">
                    <div>
                        <label>مضيف قاعدة البيانات (Host)</label>
                        <input type="text" name="db_host" value="<?php echo htmlspecialchars($old['db_host'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="localhost" required>
                    </div>
                    <div style="max-width:110px;">
                        <label>المنفذ</label>
                        <input type="text" name="db_port" value="<?php echo htmlspecialchars($old['db_port'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="3306" required>
                    </div>
                </div>
                <label>اسم قاعدة البيانات</label>
                <input type="text" name="db_name" value="<?php echo htmlspecialchars($old['db_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <label>اسم مستخدم قاعدة البيانات</label>
                <input type="text" name="db_user" value="<?php echo htmlspecialchars($old['db_user'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <label>كلمة مرور قاعدة البيانات</label>
                <input type="password" name="db_pass" placeholder="اتركه فارغاً إن لم توجد كلمة مرور">
            </div>

            <?php if ($hasLegacyData): ?>
            <div class="card">
                <h2>2) استيراد البيانات الحالية</h2>
                <div class="checkbox-row">
                    <input type="checkbox" id="import_legacy" name="import_legacy" <?php echo $old['import_legacy'] ? 'checked' : ''; ?>>
                    <label for="import_legacy" style="margin:0;">استيراد بيانات logs/database.json الحالية (المستخدمون، الفئات، المنتجات، الإعدادات، الإحصائيات)</label>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <h2><?php echo $hasLegacyData ? '3' : '2'; ?>) حساب المدير</h2>
                <p class="sub" style="font-size:0.8rem;">إذا كان هذا البريد موجوداً ضمن البيانات المستوردة فسيتم ترقيته لصلاحية مدير فقط دون تغيير كلمة مروره الحالية.</p>
                <label>الاسم الكامل</label>
                <input type="text" name="admin_fullname" value="<?php echo htmlspecialchars($old['admin_fullname'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <label>البريد الإلكتروني</label>
                <input type="email" name="admin_email" value="<?php echo htmlspecialchars($old['admin_email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                <label>كلمة المرور</label>
                <input type="password" name="admin_password" required>
            </div>

            <button type="submit">🚀 بدء التنصيب</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
