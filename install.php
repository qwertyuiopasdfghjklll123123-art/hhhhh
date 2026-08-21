<?php
/* ======================================================================
   المثبّت التلقائي (Auto-Installer) — ملف واحد شامل ومستقل
   لا يعتمد على أي ملف آخر. ينشئ قاعدة البيانات والجداول ويكتب config.php
   القسم الأول: منطق PHP — القسم الثاني: الواجهة HTML/CSS
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Baghdad');

function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/** تعريف الجداول — يُستدعى بعد نجاح الاتصال بقاعدة البيانات */
function install_schema(PDO $pdo): void
{
    $tables = [
        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(150) NOT NULL DEFAULT 'شركة الصرافة',
            company_email VARCHAR(150) DEFAULT NULL,
            company_logo VARCHAR(255) DEFAULT NULL,
            work_start_time TIME NOT NULL DEFAULT '09:00:00',
            work_end_time TIME NOT NULL DEFAULT '15:00:00',
            usd_exchange_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            late_grace_minutes INT NOT NULL DEFAULT 15,
            late_deduction_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'branches' => "CREATE TABLE IF NOT EXISTS branches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            location VARCHAR(150) DEFAULT NULL,
            latitude DECIMAL(10,6) DEFAULT NULL,
            longitude DECIMAL(10,6) DEFAULT NULL,
            geofence_radius INT NOT NULL DEFAULT 100,
            photo VARCHAR(255) DEFAULT NULL,
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
            mother_name VARCHAR(100) DEFAULT NULL,
            phone_number VARCHAR(30) DEFAULT NULL,
            job_title VARCHAR(100) DEFAULT NULL,
            birth_date DATE DEFAULT NULL,
            hire_date DATE DEFAULT NULL,
            duty_start_date DATE DEFAULT NULL,
            duty_end_date DATE DEFAULT NULL,
            shift_type ENUM('morning','evening') NOT NULL DEFAULT 'morning',
            shift_start TIME DEFAULT NULL,
            shift_end TIME DEFAULT NULL,
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            has_evening_shift TINYINT(1) NOT NULL DEFAULT 0,
            evening_shift_start TIME DEFAULT NULL,
            evening_shift_end TIME DEFAULT NULL,
            evening_base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            rating DECIMAL(2,1) NOT NULL DEFAULT 0,
            photo VARCHAR(255) DEFAULT NULL,
            documents VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_branch_manager TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_emp_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role ENUM('hr','branch_manager','employee','general_manager','shareholder') NOT NULL,
            display_name VARCHAR(100) DEFAULT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            employee_id INT DEFAULT NULL,
            branch_id INT DEFAULT NULL,
            employee_number VARCHAR(20) DEFAULT NULL,
            phone_number VARCHAR(30) DEFAULT NULL,
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
            shift_period ENUM('morning','evening') NOT NULL DEFAULT 'morning',
            check_in TIME DEFAULT NULL,
            check_out TIME DEFAULT NULL,
            status ENUM('present','late','absent') NOT NULL DEFAULT 'present',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_date_period (employee_id, attendance_date, shift_period),
            CONSTRAINT fk_att_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_att_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'payroll' => "CREATE TABLE IF NOT EXISTS payroll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            shift_period ENUM('morning','evening') NOT NULL DEFAULT 'morning',
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
            deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            late_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pending','delivered') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_period_shift (employee_id, period_month, period_year, shift_period),
            CONSTRAINT fk_pay_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_pay_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'requests' => "CREATE TABLE IF NOT EXISTS requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            type ENUM('leave','advance','complaint','resignation','supplies') NOT NULL,
            requested_by_role ENUM('employee','branch_manager') NOT NULL DEFAULT 'employee',
            details TEXT DEFAULT NULL,
            amount DECIMAL(12,2) DEFAULT NULL,
            date_from DATE DEFAULT NULL,
            date_to DATE DEFAULT NULL,
            leave_unit ENUM('days','hours') DEFAULT NULL,
            leave_amount DECIMAL(6,2) DEFAULT NULL,
            status ENUM('pending','branch_approved','approved','rejected') NOT NULL DEFAULT 'pending',
            branch_reviewed_by INT DEFAULT NULL,
            branch_review_note VARCHAR(255) DEFAULT NULL,
            branch_reviewed_at TIMESTAMP NULL DEFAULT NULL,
            hr_reviewed_by INT DEFAULT NULL,
            hr_review_note VARCHAR(255) DEFAULT NULL,
            hr_reviewed_at TIMESTAMP NULL DEFAULT NULL,
            approved_monthly_deduction DECIMAL(12,2) DEFAULT NULL,
            remaining_balance DECIMAL(12,2) DEFAULT NULL,
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
            attachment VARCHAR(255) DEFAULT NULL,
            brief_id INT DEFAULT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'delegations' => "CREATE TABLE IF NOT EXISTS delegations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL,
            delegated_employee_id INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('active','ended') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_del_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
            CONSTRAINT fk_del_employee FOREIGN KEY (delegated_employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'daily_briefs' => "CREATE TABLE IF NOT EXISTS daily_briefs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL,
            brief_date DATE NOT NULL,
            total_income DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_expense DECIMAL(14,2) NOT NULL DEFAULT 0,
            previous_profit DECIMAL(14,2) NOT NULL DEFAULT 0,
            travelers_count INT NOT NULL DEFAULT 0,
            note TEXT DEFAULT NULL,
            attachment VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','hr_approved','gm_approved','approved','rejected') NOT NULL DEFAULT 'pending',
            hr_decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            gm_decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            hr_note VARCHAR(255) DEFAULT NULL,
            submitted_by INT DEFAULT NULL,
            reviewed_by INT DEFAULT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            gm_review_note VARCHAR(255) DEFAULT NULL,
            gm_reviewed_by INT DEFAULT NULL,
            gm_reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_branch_date (branch_id, brief_date),
            CONSTRAINT fk_brief_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
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

        'payroll_windows' => "CREATE TABLE IF NOT EXISTS payroll_windows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT DEFAULT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            opened_by INT DEFAULT NULL,
            opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            UNIQUE KEY uniq_branch_period (branch_id, period_month, period_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'payroll_adjustments' => "CREATE TABLE IF NOT EXISTS payroll_adjustments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            type ENUM('salary','bonus','deduction') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_padj_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_padj_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'error_log' => "CREATE TABLE IF NOT EXISTS error_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            app VARCHAR(20) NOT NULL,
            action VARCHAR(60) NOT NULL,
            user_role VARCHAR(30) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            message VARCHAR(500) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'audit_log' => "CREATE TABLE IF NOT EXISTS audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actor_role VARCHAR(30) NOT NULL,
            actor_name VARCHAR(100) DEFAULT NULL,
            actor_number VARCHAR(50) DEFAULT NULL,
            action_type VARCHAR(50) NOT NULL,
            description VARCHAR(500) NOT NULL,
            branch_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'honor_roll' => "CREATE TABLE IF NOT EXISTS honor_roll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            attendance_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            reward_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            approved_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_period_honor (employee_id, period_month, period_year),
            CONSTRAINT fk_honor_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'monthly_honor_requests' => "CREATE TABLE IF NOT EXISTS monthly_honor_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            attendance_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            rank_no TINYINT NOT NULL DEFAULT 0,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reward_amount DECIMAL(12,2) DEFAULT NULL,
            reviewed_by INT DEFAULT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_period_honorreq (employee_id, period_month, period_year),
            CONSTRAINT fk_honorreq_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    // الترتيب مهم بسبب المفاتيح الأجنبية
    foreach (['settings', 'branches', 'employees', 'users', 'attendance', 'payroll', 'requests', 'daily_ledger', 'delegations', 'daily_briefs', 'exchange_rate_history', 'notifications', 'payroll_windows', 'payroll_adjustments', 'error_log', 'audit_log', 'honor_roll', 'monthly_honor_requests'] as $table) {
        $pdo->exec($tables[$table]);
    }
}

/** إدراج البيانات الافتراضية: فرع تجريبي، إعدادات، حساب HR الأول، حساب المسؤول العام الأول */
function install_seed(PDO $pdo, string $hrEmail, string $hrPassword, string $gmEmail, string $gmPassword): void
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

    $gmExists = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'general_manager'")->fetchColumn();
    if ((int) $gmExists === 0) {
        $hash = password_hash($gmPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (role, username, password_hash, status) VALUES ('general_manager', ?, ?, 'active')");
        $stmt->execute([$gmEmail, $hash]);
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
    $gmEmail = trim($_POST['gm_email'] ?? '');
    $gmPassword = (string) ($_POST['gm_password'] ?? '');
    $gmPasswordConfirm = (string) ($_POST['gm_password_confirm'] ?? '');

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
        $errors[] = 'كلمتا مرور الموارد البشرية غير متطابقتين.';
    }
    if (!filter_var($gmEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني للمسؤول العام غير صالح.';
    }
    if (strlen($gmPassword) < 8) {
        $errors[] = 'كلمة مرور المسؤول العام يجب ألا تقل عن 8 أحرف.';
    }
    if ($gmPassword !== $gmPasswordConfirm) {
        $errors[] = 'كلمتا مرور المسؤول العام غير متطابقتين.';
    }

    if (empty($errors)) {
        try {
            $dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $ourTables = ['settings', 'branches', 'employees', 'users', 'attendance', 'payroll', 'requests', 'daily_ledger', 'delegations', 'daily_briefs', 'exchange_rate_history', 'notifications', 'payroll_windows', 'payroll_adjustments', 'error_log'];
            $existing = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $conflicting = array_intersect($ourTables, $existing);
            if (!empty($conflicting)) {
                throw new RuntimeException(
                    'قاعدة البيانات "' . $dbName . '" تحتوي بالفعل على جداول من نظام آخر تحمل نفس الأسماء المطلوبة (' .
                    implode('، ', $conflicting) . '). لتجنب تعارض البيانات، أنشئ قاعدة بيانات جديدة فارغة ' .
                    'من cPanel (MySQL Databases) خاصة بهذا النظام فقط، ثم أعد المحاولة ببياناتها هنا.'
                );
            }

            $step = 3;
            install_schema($pdo);
            install_seed($pdo, $hrEmail, $hrPassword, $gmEmail, $gmPassword);

            $configContent = "<?php\n" .
                "define('DB_HOST', " . var_export($dbHost, true) . ");\n" .
                "define('DB_NAME', " . var_export($dbName, true) . ");\n" .
                "define('DB_USER', " . var_export($dbUser, true) . ");\n" .
                "define('DB_PASS', " . var_export($dbPass, true) . ");\n" .
                "define('DB_CHARSET', 'utf8mb4');\n";

            if (file_put_contents($configFile, $configContent) === false) {
                throw new RuntimeException('تعذر كتابة ملف config.php. تحقق من صلاحيات الكتابة في المجلد.');
            }

            foreach (['uploads', 'uploads/photos', 'uploads/documents', 'uploads/ledger', 'uploads/sessions'] as $dir) {
                $path = __DIR__ . '/' . $dir;
                if (!is_dir($path)) {
                    @mkdir($path, 0755, true);
                }
            }
            @file_put_contents(__DIR__ . '/uploads/sessions/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");

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
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Tajawal:wght@400;500;700;800&display=swap');
:root{--primary:#006b73;--primary-light:#0A8A94;--primary-dark:#004b52;--primary-gradient:linear-gradient(135deg,#006b73 0%,#0A8A94 100%);--accent:#c99a3d;--bg:#F0F4F8;--border:#E1E8ED;--text:#1A2E35;--text-muted:#8AA0B0;--success:#159447;--danger:#df4b4b;--radius:14px;}
*{box-sizing:border-box;}
body{margin:0;font-family:'IBM Plex Sans Arabic','Tajawal',sans-serif;background:var(--bg);color:var(--text);direction:rtl;text-align:right;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.card{width:100%;max-width:560px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 10px 30px rgba(0,107,115,.10);padding:36px 32px;}
.logo{text-align:center;font-size:34px;color:var(--accent);margin-bottom:6px;}
h1{text-align:center;font-size:20px;font-weight:700;margin:0 0 4px;color:var(--primary-dark);}
.sub{text-align:center;color:var(--text-muted);font-size:13px;margin-bottom:24px;}
.steps{display:flex;gap:8px;margin-bottom:24px;}
.steps span{flex:1;height:4px;border-radius:4px;background:var(--border);}
.steps span.done{background:var(--primary);}
.form-group{margin-bottom:16px;}
label{display:block;font-size:13px;color:var(--text-muted);margin-bottom:6px;}
input{width:100%;padding:11px 14px;border-radius:10px;border:1.5px solid var(--border);background:#fff;color:var(--text);font-family:inherit;font-size:14px;outline:none;}
input:focus{border-color:var(--primary);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.btn{display:block;width:100%;padding:11px 20px;border-radius:10px;border:none;font-family:inherit;font-weight:600;font-size:14px;cursor:pointer;background:var(--primary-gradient);color:#fff;text-align:center;text-decoration:none;box-shadow:0 4px 14px rgba(0,107,115,.25);}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;border:1px solid transparent;}
.alert-danger{background:#FCEAEA;border-color:#F6C6C6;color:#B23A3A;}
.alert-success{background:#E8F6EE;border-color:#B9E4C9;color:#0E6B34;}
.req-box{padding:14px 16px;margin-bottom:20px;background:#F7FAFB;border-radius:10px;}
.req-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px;}
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
.badge-success{background:#E8F6EE;color:var(--success);}
.badge-danger{background:#FCEAEA;color:var(--danger);}
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
            <a class="btn" href="/hr">دخول الموارد البشرية (HR)</a>
            <a class="btn" href="/general">دخول المسؤول العام</a>
            <a class="btn" href="/branch">دخول مدير الفرع</a>
            <a class="btn" href="/">دخول الموظف</a>
        </div>

    <?php elseif ($success): ?>
        <div class="alert alert-success">تم تثبيت النظام بنجاح! يمكنك الآن تسجيل الدخول بحساب الموارد البشرية الذي أنشأته.</div>
        <a class="btn" href="/hr">تسجيل الدخول إلى لوحة الموارد البشرية</a>

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
                <hr style="border-color:var(--border);margin:20px 0;">
                <div class="form-group" style="font-size:13px;color:var(--text-muted);">حساب المسؤول العام الأول (اعتماد نهائي فوق HR)</div>
                <div class="form-group"><label>البريد الإلكتروني لتسجيل الدخول</label><input type="email" name="gm_email" value="<?= h($_POST['gm_email'] ?? '') ?>" required></div>
                <div class="grid-2">
                    <div class="form-group"><label>كلمة المرور</label><input type="password" name="gm_password" required></div>
                    <div class="form-group"><label>تأكيد كلمة المرور</label><input type="password" name="gm_password_confirm" required></div>
                </div>
                <button type="submit" class="btn">تثبيت النظام الآن</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
