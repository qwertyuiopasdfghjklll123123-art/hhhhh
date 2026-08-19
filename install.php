<?php
/* ======================================================================
   المثبّت التلقائي (Auto-Installer) — ملف واحد شامل ومستقل
   لا يعتمد على أي ملف آخر. ينشئ قاعدة البيانات والجداول ويكتب config.php
   القسم الأول: منطق PHP — القسم الثاني: الواجهة HTML/CSS
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/** تعريف الجداول — يُستدعى بعد نجاح الاتصال بقاعدة البيانات */
function install_schema(PDO $pdo): void
{
    $tables = [
        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(150) NOT NULL DEFAULT 'شركة الصرافة',
            company_email VARCHAR(150) DEFAULT NULL,
            work_start_time TIME NOT NULL DEFAULT '09:00:00',
            work_end_time TIME NOT NULL DEFAULT '15:00:00',
            usd_exchange_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'branches' => "CREATE TABLE IF NOT EXISTS branches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            location VARCHAR(150) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'employees' => "CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL,
            employee_number VARCHAR(20) NOT NULL UNIQUE,
            full_name VARCHAR(100) NOT NULL,
            national_id VARCHAR(50) DEFAULT NULL,
            job_title VARCHAR(100) DEFAULT NULL,
            hire_date DATE DEFAULT NULL,
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            is_branch_manager TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_emp_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role ENUM('hr','branch_manager','employee') NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            employee_id INT DEFAULT NULL,
            branch_id INT DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'attendance' => "CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            check_in TIME DEFAULT NULL,
            check_out TIME DEFAULT NULL,
            status ENUM('present','late','absent') NOT NULL DEFAULT 'present',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_date (employee_id, attendance_date),
            CONSTRAINT fk_att_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_att_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'payroll' => "CREATE TABLE IF NOT EXISTS payroll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
            deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pending','delivered') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_period (employee_id, period_month, period_year),
            CONSTRAINT fk_pay_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_pay_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'requests' => "CREATE TABLE IF NOT EXISTS requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            type ENUM('leave','advance','complaint','resignation') NOT NULL,
            details TEXT DEFAULT NULL,
            amount DECIMAL(12,2) DEFAULT NULL,
            date_from DATE DEFAULT NULL,
            date_to DATE DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reviewed_by INT DEFAULT NULL,
            review_note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_req_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_req_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'daily_ledger' => "CREATE TABLE IF NOT EXISTS daily_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL,
            entry_date DATE NOT NULL,
            entry_type ENUM('income','expense') NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'exchange_rate_history' => "CREATE TABLE IF NOT EXISTS exchange_rate_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rate DECIMAL(10,2) NOT NULL,
            updated_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'notifications' => "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(150) NOT NULL,
            message VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // الترتيب مهم بسبب المفاتيح الأجنبية
    foreach (['settings', 'branches', 'employees', 'users', 'attendance', 'payroll', 'requests', 'daily_ledger', 'exchange_rate_history', 'notifications'] as $table) {
        $pdo->exec($tables[$table]);
    }
}

/** إدراج البيانات الافتراضية: فرع تجريبي، إعدادات، حساب HR الأول */
function install_seed(PDO $pdo, string $hrEmail, string $hrPassword): void
{
    if ((int) $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO settings (company_name, company_email, usd_exchange_rate) VALUES ('شركة الصوى للصرافة', 'info@example.com', 1320)");
    }

    if (!(int) $pdo->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1")->fetchColumn()) {
        $stmt = $pdo->prepare("INSERT INTO branches (name, location, status) VALUES (?, ?, 'active')");
        $stmt->execute(['الفرع الرئيسي', 'بغداد']);
    }

    $hrExists = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'hr'")->fetchColumn();
    if ((int) $hrExists === 0) {
        $hash = password_hash($hrPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (role, username, password_hash, status) VALUES ('hr', ?, ?, 'active')");
        $stmt->execute([$hrEmail, $hash]);
    }
}

$configFile = __DIR__ . '/config.php';
$alreadyInstalled = file_exists($configFile);

$errors = [];
$success = false;
$step = 1;

$requirements = [
    'PHP 8.0 فأعلى' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'امتداد PDO' => extension_loaded('pdo'),
    'امتداد PDO MySQL' => extension_loaded('pdo_mysql'),
    'صلاحية الكتابة في المجلد الحالي' => is_writable(__DIR__),
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

    if (empty($errors)) {
        try {
            $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $step = 3;
            install_schema($pdo);
            install_seed($pdo, $hrEmail, $hrPassword);

            $configContent = "<?php\n" .
                "define('DB_HOST', " . var_export($dbHost, true) . ");\n" .
                "define('DB_NAME', " . var_export($dbName, true) . ");\n" .
                "define('DB_USER', " . var_export($dbUser, true) . ");\n" .
                "define('DB_PASS', " . var_export($dbPass, true) . ");\n" .
                "define('DB_CHARSET', 'utf8mb4');\n";

            if (file_put_contents($configFile, $configContent) === false) {
                throw new RuntimeException('تعذر كتابة ملف config.php. تحقق من صلاحيات الكتابة في المجلد.');
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap');
:root{--bg:#0f172a;--bg-soft:#16213a;--surface:#1b2544;--border:#2b3660;--text:#e8ecf7;--text-muted:#9aa4c4;--primary:#d4af37;--primary-soft:rgba(212,175,55,.15);--success:#22c55e;--danger:#ef4444;--radius:14px;}
*{box-sizing:border-box;}
body{margin:0;font-family:'Cairo','Tahoma',sans-serif;background:radial-gradient(circle at top left,#16213a 0%,#0f172a 55%,#0b1120 100%);color:var(--text);direction:rtl;text-align:right;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.card{width:100%;max-width:560px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 10px 30px rgba(0,0,0,.35);padding:36px 32px;}
.logo{text-align:center;font-size:34px;color:var(--primary);margin-bottom:6px;}
h1{text-align:center;font-size:20px;font-weight:700;margin:0 0 4px;}
.sub{text-align:center;color:var(--text-muted);font-size:13px;margin-bottom:24px;}
.steps{display:flex;gap:8px;margin-bottom:24px;}
.steps span{flex:1;height:4px;border-radius:4px;background:var(--border);}
.steps span.done{background:var(--primary);}
.form-group{margin-bottom:16px;}
label{display:block;font-size:13px;color:var(--text-muted);margin-bottom:6px;}
input{width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-soft);color:var(--text);font-family:inherit;font-size:14px;outline:none;}
input:focus{border-color:var(--primary);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.btn{display:block;width:100%;padding:11px 20px;border-radius:10px;border:none;font-family:inherit;font-weight:600;font-size:14px;cursor:pointer;background:linear-gradient(135deg,var(--primary),#b8912a);color:#1a1200;text-align:center;text-decoration:none;}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;border:1px solid transparent;}
.alert-danger{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.4);color:#fca5a5;}
.alert-success{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.4);color:#86efac;}
.req-box{padding:14px 16px;margin-bottom:20px;background:var(--bg-soft);border-radius:10px;}
.req-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px;}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
.badge-success{background:rgba(34,197,94,.15);color:#4ade80;}
.badge-danger{background:rgba(239,68,68,.15);color:#f87171;}
.links{display:flex;flex-direction:column;gap:10px;margin-top:10px;}
</style>
</head>
<body>
<div class="card">
    <div class="logo">✥</div>
    <h1>مثبّت نظام إدارة الصرافة</h1>
    <div class="sub">إعداد قاعدة البيانات والحساب الإداري الأول تلقائياً</div>

    <div class="steps">
        <span class="<?= $step >= 1 ? 'done' : '' ?>"></span>
        <span class="<?= $step >= 2 ? 'done' : '' ?>"></span>
        <span class="<?= $step >= 3 ? 'done' : '' ?>"></span>
        <span class="<?= $step >= 4 ? 'done' : '' ?>"></span>
    </div>

    <?php if ($alreadyInstalled): ?>
        <div class="alert alert-success">النظام مُثبّت بالفعل. لإعادة التثبيت احذف ملف <code>config.php</code> من السيرفر أولاً.</div>
        <div class="links">
            <a class="btn" href="/hr.php">دخول الموارد البشرية (HR)</a>
            <a class="btn" href="/branch.php">دخول مدير الفرع</a>
            <a class="btn" href="/employee.php">دخول الموظف</a>
        </div>

    <?php elseif ($success): ?>
        <div class="alert alert-success">تم تثبيت النظام بنجاح! يمكنك الآن تسجيل الدخول بحساب الموارد البشرية الذي أنشأته.</div>
        <a class="btn" href="/hr.php">تسجيل الدخول إلى لوحة الموارد البشرية</a>

    <?php else: ?>
        <?php if (!$requirementsOk): ?><div class="alert alert-danger">بعض متطلبات السيرفر غير متوفرة:</div><?php endif; ?>
        <div class="req-box">
            <?php foreach ($requirements as $label => $ok): ?>
                <div class="req-row"><span><?= h($label) ?></span><span class="badge <?= $ok ? 'badge-success' : 'badge-danger' ?>"><?= $ok ? 'متوفر' : 'غير متوفر' ?></span></div>
            <?php endforeach; ?>
        </div>

        <?php if ($requirementsOk): ?>
            <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>

            <form method="post" action="/install.php">
                <div class="grid-2">
                    <div class="form-group"><label>عنوان سيرفر قاعدة البيانات (Host)</label><input type="text" name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>" required></div>
                    <div class="form-group"><label>اسم قاعدة البيانات</label><input type="text" name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>" required></div>
                </div>
                <div class="grid-2">
                    <div class="form-group"><label>مستخدم قاعدة البيانات</label><input type="text" name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" required></div>
                    <div class="form-group"><label>كلمة مرور قاعدة البيانات</label><input type="password" name="db_pass"></div>
                </div>
                <hr style="border-color:var(--border);margin:20px 0;">
                <div class="form-group" style="font-size:13px;color:var(--text-muted);">حساب مدير الموارد البشرية الأول</div>
                <div class="form-group"><label>البريد الإلكتروني لتسجيل الدخول</label><input type="email" name="hr_email" value="<?= h($_POST['hr_email'] ?? '') ?>" required></div>
                <div class="grid-2">
                    <div class="form-group"><label>كلمة المرور</label><input type="password" name="hr_password" required></div>
                    <div class="form-group"><label>تأكيد كلمة المرور</label><input type="password" name="hr_password_confirm" required></div>
                </div>
                <button type="submit" class="btn">تثبيت النظام الآن</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
