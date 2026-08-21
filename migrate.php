<?php
/* ======================================================================
   أداة تحديث قاعدة البيانات (Migration) — لتشغيلها مرة واحدة فقط على
   قاعدة بيانات مثبّتة سابقاً حتى تلحق بآخر تحديثات النظام (المسؤول العام،
   نظام السلف بالخصم الشهري، عدد المسافرين، حقول الموظف الإضافية، ...).
   آمنة للتشغيل أكثر من مرة: تتحقق من وجود كل عمود/جدول قبل إضافته.
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Baghdad');

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: /install.php');
    exit;
}
require_once __DIR__ . '/config.php';

function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    die('تعذر الاتصال بقاعدة البيانات: ' . h($e->getMessage()));
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

/** كل خطوة: [وصف عربي, دالة تتحقق إن كانت مطلوبة, دالة تنفذ التعديل] */
function migration_steps(): array
{
    return [
        [
            'label' => 'إضافة عمود اسم الأم لجدول الموظفين',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'employees', 'mother_name'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE employees ADD COLUMN mother_name VARCHAR(100) DEFAULT NULL AFTER national_id"),
        ],
        [
            'label' => 'إضافة أعمدة الشفت (نوعه ووقت البداية والنهاية) لجدول الموظفين',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'employees', 'shift_type'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE employees
                ADD COLUMN shift_type ENUM('morning','evening') NOT NULL DEFAULT 'morning' AFTER duty_end_date,
                ADD COLUMN shift_start TIME DEFAULT NULL AFTER shift_type,
                ADD COLUMN shift_end TIME DEFAULT NULL AFTER shift_start"),
        ],
        [
            'label' => 'إضافة دور "المسؤول العام" إلى جدول المستخدمين',
            'needed' => function (PDO $pdo) {
                $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
                $col = $stmt->fetch();
                return $col && strpos($col['Type'], 'general_manager') === false;
            },
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE users MODIFY role ENUM('hr','branch_manager','employee','general_manager') NOT NULL"),
        ],
        [
            'label' => 'إضافة مرحلة موافقة مدير الفرع على الطلبات (branch_approved) وأعمدة المراجعة المنفصلة',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'requests', 'branch_reviewed_by'),
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE requests MODIFY status ENUM('pending','branch_approved','approved','rejected') NOT NULL DEFAULT 'pending'");
                $pdo->exec("ALTER TABLE requests
                    ADD COLUMN branch_reviewed_by INT DEFAULT NULL,
                    ADD COLUMN branch_review_note VARCHAR(255) DEFAULT NULL,
                    ADD COLUMN branch_reviewed_at TIMESTAMP NULL DEFAULT NULL,
                    ADD COLUMN hr_reviewed_by INT DEFAULT NULL,
                    ADD COLUMN hr_review_note VARCHAR(255) DEFAULT NULL,
                    ADD COLUMN hr_reviewed_at TIMESTAMP NULL DEFAULT NULL");
            },
        ],
        [
            'label' => 'إضافة أعمدة السلفة (الخصم الشهري المعتمد والرصيد المتبقي) لجدول الطلبات',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'requests', 'approved_monthly_deduction'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE requests
                ADD COLUMN approved_monthly_deduction DECIMAL(12,2) DEFAULT NULL,
                ADD COLUMN remaining_balance DECIMAL(12,2) DEFAULT NULL"),
        ],
        [
            'label' => 'إضافة عدد المسافرين ومرحلة اعتماد المسؤول العام لجدول الإيجازات اليومية',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'daily_briefs', 'travelers_count'),
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE daily_briefs MODIFY status ENUM('pending','hr_approved','approved','rejected') NOT NULL DEFAULT 'pending'");
                $pdo->exec("ALTER TABLE daily_briefs
                    ADD COLUMN travelers_count INT NOT NULL DEFAULT 0 AFTER previous_profit,
                    ADD COLUMN gm_review_note VARCHAR(255) DEFAULT NULL,
                    ADD COLUMN gm_reviewed_by INT DEFAULT NULL,
                    ADD COLUMN gm_reviewed_at TIMESTAMP NULL DEFAULT NULL");
            },
        ],
        [
            'label' => 'إضافة إعدادات خصم التأخير (مدة السماح وقيمة الخصم لكل ساعة)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'settings', 'late_grace_minutes'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE settings
                ADD COLUMN late_grace_minutes INT NOT NULL DEFAULT 15,
                ADD COLUMN late_deduction_per_hour DECIMAL(10,2) NOT NULL DEFAULT 0"),
        ],
        [
            'label' => 'إنشاء جدول سجل تعديلات الرواتب (payroll_adjustments)',
            'needed' => fn(PDO $pdo) => !table_exists($pdo, 'payroll_adjustments'),
            'run' => fn(PDO $pdo) => $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_adjustments (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
        ],
        [
            'label' => 'إضافة رقم الهاتف لجدول الموظفين',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'employees', 'phone_number'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE employees ADD COLUMN phone_number VARCHAR(30) DEFAULT NULL AFTER mother_name"),
        ],
        [
            'label' => 'إضافة صورة الفرع لجدول الفروع',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'branches', 'photo'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE branches ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER geofence_radius"),
        ],
        [
            'label' => 'إضافة دور "المساهم" واسم العرض إلى جدول المستخدمين',
            'needed' => function (PDO $pdo) {
                $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
                $col = $stmt->fetch();
                return ($col && strpos($col['Type'], 'shareholder') === false) || !column_exists($pdo, 'users', 'display_name');
            },
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE users MODIFY role ENUM('hr','branch_manager','employee','general_manager','shareholder') NOT NULL");
                if (!column_exists($pdo, 'users', 'display_name')) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN display_name VARCHAR(100) DEFAULT NULL");
                }
            },
        ],
        [
            'label' => 'إنشاء جدول نوافذ صرف الرواتب الشهرية (payroll_windows)',
            'needed' => fn(PDO $pdo) => !table_exists($pdo, 'payroll_windows'),
            'run' => fn(PDO $pdo) => $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_windows (
                id INT AUTO_INCREMENT PRIMARY KEY,
                period_month TINYINT NOT NULL,
                period_year SMALLINT NOT NULL,
                opened_by INT DEFAULT NULL,
                opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                UNIQUE KEY uniq_period (period_month, period_year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
        ],
        [
            'label' => 'إضافة شعار الشركة القابل للتعديل من إعدادات HR (company_logo)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'settings', 'company_logo'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE settings ADD COLUMN company_logo VARCHAR(255) DEFAULT NULL"),
        ],
        [
            'label' => 'إنشاء جدول سجل الأخطاء (error_log) لتتبع مشاكل الأزرار والعمليات الفاشلة',
            'needed' => fn(PDO $pdo) => !table_exists($pdo, 'error_log'),
            'run' => fn(PDO $pdo) => $pdo->exec("CREATE TABLE IF NOT EXISTS error_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                app VARCHAR(20) NOT NULL,
                action VARCHAR(60) NOT NULL,
                user_role VARCHAR(30) DEFAULT NULL,
                user_id INT DEFAULT NULL,
                message VARCHAR(500) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
        ],
        [
            'label' => 'اعتماد الإيجاز اليومي يتطلب موافقة كل من HR والمسؤول العام معاً (قرار مستقل لكل منهما)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'daily_briefs', 'hr_decision'),
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE daily_briefs MODIFY status ENUM('pending','hr_approved','gm_approved','approved','rejected') NOT NULL DEFAULT 'pending'");
                $pdo->exec("ALTER TABLE daily_briefs
                    ADD COLUMN hr_decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER status,
                    ADD COLUMN gm_decision ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' AFTER hr_decision");
                $pdo->exec("UPDATE daily_briefs SET
                    hr_decision = CASE
                        WHEN status = 'rejected' AND reviewed_by IS NOT NULL AND gm_reviewed_by IS NULL THEN 'rejected'
                        WHEN status IN ('hr_approved','approved') THEN 'approved'
                        WHEN status = 'rejected' AND reviewed_by IS NOT NULL THEN 'rejected'
                        ELSE 'pending'
                    END,
                    gm_decision = CASE
                        WHEN status = 'approved' THEN 'approved'
                        WHEN status = 'rejected' AND gm_reviewed_by IS NOT NULL THEN 'rejected'
                        ELSE 'pending'
                    END");
            },
        ],
        [
            'label' => 'إضافة عمود خصم التأخير المنفصل لجدول الرواتب (لإمكانية إلغائه عند تصحيح حضور متأخر)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'payroll', 'late_deduction'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE payroll ADD COLUMN late_deduction DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER deduction"),
        ],
        [
            'label' => 'جعل صلاحية تسليم الرواتب لكل فرع على حدة بدل صلاحية واحدة لكل الفروع',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'payroll_windows', 'branch_id'),
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE payroll_windows ADD COLUMN branch_id INT DEFAULT NULL AFTER id");
                $pdo->exec("ALTER TABLE payroll_windows DROP INDEX uniq_period");
                $pdo->exec("ALTER TABLE payroll_windows ADD UNIQUE KEY uniq_branch_period (branch_id, period_month, period_year)");
            },
        ],
        [
            'label' => 'إصلاح إيجازات عالقة: إعادة نشر إيجاز تمت مراجعته سابقاً كانت لا تصفّر قرار HR/المسؤول العام فتبقى الإيجاز بلا أزرار موافقة',
            'needed' => fn(PDO $pdo) => (int) $pdo->query("SELECT COUNT(*) FROM daily_briefs WHERE status='pending' AND (hr_decision <> 'pending' OR gm_decision <> 'pending')")->fetchColumn() > 0,
            'run' => fn(PDO $pdo) => $pdo->exec("UPDATE daily_briefs SET hr_decision='pending', gm_decision='pending', hr_note=NULL, gm_review_note=NULL, reviewed_by=NULL, reviewed_at=NULL, gm_reviewed_by=NULL, gm_reviewed_at=NULL WHERE status='pending' AND (hr_decision <> 'pending' OR gm_decision <> 'pending')"),
        ],
        [
            'label' => 'إضافة إمكانية إرفاق ملف مع الإيجاز اليومي نفسه (يصل لـ HR والمسؤول العام)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'daily_briefs', 'attachment'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE daily_briefs ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER note"),
        ],
        [
            'label' => 'ربط قيود الإيجاز بالإيجاز الذي نُشرت ضمنه، حتى تُصفَّر شاشة كتابة الإيجاز بعد النشر',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'daily_ledger', 'brief_id'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE daily_ledger ADD COLUMN brief_id INT DEFAULT NULL AFTER attachment"),
        ],
        [
            'label' => 'إضافة الشفت المسائي الثاني للموظف (راتب ووقت دوام منفصلان تماماً عن الشفت الأساسي)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'employees', 'has_evening_shift'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE employees
                ADD COLUMN has_evening_shift TINYINT(1) NOT NULL DEFAULT 0 AFTER base_salary,
                ADD COLUMN evening_shift_start TIME DEFAULT NULL AFTER has_evening_shift,
                ADD COLUMN evening_shift_end TIME DEFAULT NULL AFTER evening_shift_start,
                ADD COLUMN evening_base_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER evening_shift_end"),
        ],
        [
            'label' => 'إضافة عمود الرقم الوظيفي لحسابات المستخدمين (لإنشاء حسابات HR/مسؤول عام جديدة بنفس صلاحياتها)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'users', 'employee_number'),
            'run' => fn(PDO $pdo) => $pdo->exec("ALTER TABLE users ADD COLUMN employee_number VARCHAR(20) DEFAULT NULL AFTER branch_id"),
        ],
        [
            'label' => 'فصل حضور الشفت الصباحي عن المسائي لنفس اليوم (بصمة مستقلة لكل شفت)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'attendance', 'shift_period'),
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE attendance ADD COLUMN shift_period ENUM('morning','evening') NOT NULL DEFAULT 'morning' AFTER attendance_date");
                $pdo->exec("ALTER TABLE attendance ADD UNIQUE KEY uniq_emp_date_period (employee_id, attendance_date, shift_period)");
                $pdo->exec("ALTER TABLE attendance DROP INDEX uniq_emp_date");
            },
        ],
        [
            'label' => 'فصل تسليم راتب الشفت الصباحي عن المسائي (كل شفت له تسليم مستقل)',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'payroll', 'shift_period'),
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE payroll ADD COLUMN shift_period ENUM('morning','evening') NOT NULL DEFAULT 'morning' AFTER period_year");
                $pdo->exec("ALTER TABLE payroll ADD UNIQUE KEY uniq_emp_period_shift (employee_id, period_month, period_year, shift_period)");
                $pdo->exec("ALTER TABLE payroll DROP INDEX uniq_emp_period");
            },
        ],
        [
            'label' => 'توسيع جدول الطلبات: طلبات مسؤولي الفروع (سلفة/استقالة/مستلزمات) وطلب إجازة بالأيام أو الساعات بدون خصم',
            'needed' => fn(PDO $pdo) => !column_exists($pdo, 'requests', 'requested_by_role'),
            'run' => function (PDO $pdo) {
                $pdo->exec("ALTER TABLE requests MODIFY COLUMN type ENUM('leave','advance','complaint','resignation','supplies') NOT NULL");
                $pdo->exec("ALTER TABLE requests
                    ADD COLUMN requested_by_role ENUM('employee','branch_manager') NOT NULL DEFAULT 'employee' AFTER type,
                    ADD COLUMN leave_unit ENUM('days','hours') DEFAULT NULL AFTER date_to,
                    ADD COLUMN leave_amount DECIMAL(6,2) DEFAULT NULL AFTER leave_unit");
            },
        ],
        [
            'label' => 'إنشاء جدول سجل التدقيق (تدقيق يشوفه كل المسؤولين العامين: من قام بماذا)',
            'needed' => fn(PDO $pdo) => !table_exists($pdo, 'audit_log'),
            'run' => fn(PDO $pdo) => $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                actor_role VARCHAR(30) NOT NULL,
                actor_name VARCHAR(100) DEFAULT NULL,
                actor_number VARCHAR(50) DEFAULT NULL,
                action_type VARCHAR(50) NOT NULL,
                description VARCHAR(500) NOT NULL,
                branch_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
        ],
        [
            'label' => 'إنشاء جدول لائحة الشرف (أفضل الموظفين حضوراً شهرياً)',
            'needed' => fn(PDO $pdo) => !table_exists($pdo, 'honor_roll'),
            'run' => fn(PDO $pdo) => $pdo->exec("CREATE TABLE IF NOT EXISTS honor_roll (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
        ],
        [
            'label' => 'إنشاء جدول طلبات أفضل 3 موظفين حضوراً شهرياً (مراجعة تلقائية آخر الشهر ينتظر اعتماد المسؤول العام)',
            'needed' => fn(PDO $pdo) => !table_exists($pdo, 'monthly_honor_requests'),
            'run' => fn(PDO $pdo) => $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_honor_requests (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"),
        ],
    ];
}

$steps = migration_steps();
$pending = [];
foreach ($steps as $i => $step) {
    if ($step['needed']($pdo)) {
        $pending[] = $i;
    }
}

$results = [];
$ranNow = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1') {
    $ranNow = true;
    foreach ($steps as $i => $step) {
        if (!in_array($i, $pending, true)) {
            continue;
        }
        try {
            $step['run']($pdo);
            $results[] = ['label' => $step['label'], 'ok' => true, 'error' => null];
        } catch (Throwable $e) {
            $results[] = ['label' => $step['label'], 'ok' => false, 'error' => $e->getMessage()];
        }
    }
    // أعد فحص ما تبقى بعد التنفيذ
    $pending = [];
    foreach ($steps as $i => $step) {
        if ($step['needed']($pdo)) {
            $pending[] = $i;
        }
    }
}

/* ======================================================================
   إنشاء حساب المسؤول العام إن لم يكن موجوداً — يحدث هذا مع الأنظمة التي
   ثُبِّتت قبل إضافة دور "المسؤول العام"، حيث لا ينشئ تحديث القاعدة وحده
   أي حساب، فيبقى النظام بلا حساب مسؤول عام قابل لتسجيل الدخول به.
   ====================================================================== */
$gmAccountMissing = false;
try {
    $gmAccountMissing = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='general_manager'")->fetchColumn() === 0;
} catch (Throwable $e) {
    // تجاهل — سيُعاد الفحص بعد تنفيذ تحديثات القاعدة أعلاه
}

$gmCreateResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['create_gm'] ?? '') === '1') {
    $gmEmail = trim($_POST['gm_email'] ?? '');
    $gmPassword = (string) ($_POST['gm_password'] ?? '');
    $gmPasswordConfirm = (string) ($_POST['gm_password_confirm'] ?? '');
    if ($gmEmail === '' || $gmPassword === '') {
        $gmCreateResult = ['ok' => false, 'error' => 'الرجاء تعبئة البريد الإلكتروني وكلمة المرور.'];
    } elseif ($gmPassword !== $gmPasswordConfirm) {
        $gmCreateResult = ['ok' => false, 'error' => 'كلمتا المرور غير متطابقتين.'];
    } elseif (strlen($gmPassword) < 6) {
        $gmCreateResult = ['ok' => false, 'error' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.'];
    } else {
        try {
            $existing = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $existing->execute([$gmEmail]);
            if ((int) $existing->fetchColumn() > 0) {
                $gmCreateResult = ['ok' => false, 'error' => 'هذا البريد الإلكتروني مستخدم من قبل حساب آخر في النظام.'];
            } else {
                $hash = password_hash($gmPassword, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (role, username, password_hash, status) VALUES ('general_manager', ?, ?, 'active')")
                    ->execute([$gmEmail, $hash]);
                $gmCreateResult = ['ok' => true];
                $gmAccountMissing = false;
            }
        } catch (Throwable $e) {
            $gmCreateResult = ['ok' => false, 'error' => 'تعذر إنشاء الحساب — تأكد من تنفيذ تحديثات قاعدة البيانات أعلاه أولاً (يجب أن يكون دور "المسؤول العام" مضافاً لقاعدة البيانات).'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تحديث قاعدة البيانات — شركة الصوى للصرافة</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap');
:root{--primary:#006b73;--primary-light:#0A8A94;--primary-dark:#004b52;--primary-gradient:linear-gradient(135deg,#006b73 0%,#0A8A94 100%);--accent:#c99a3d;--bg:#F0F4F8;--border:#E1E8ED;--text:#1A2E35;--text-muted:#8AA0B0;--success:#159447;--danger:#df4b4b;--radius:14px;}
*{box-sizing:border-box;}
body{margin:0;font-family:'IBM Plex Sans Arabic',sans-serif;background:var(--bg);color:var(--text);direction:rtl;text-align:right;min-height:100vh;padding:24px;}
.card{width:100%;max-width:680px;margin:0 auto;background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 10px 30px rgba(0,107,115,.10);padding:32px;}
h1{font-size:20px;font-weight:700;margin:0 0 6px;color:var(--primary-dark);}
.sub{color:var(--text-muted);font-size:13px;margin-bottom:20px;}
.step{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-radius:10px;background:#F7FAFB;margin-bottom:8px;font-size:13px;}
.badge{padding:3px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
.badge-pending{background:#FFF4E0;color:#B87400;}
.badge-done{background:#E8F6EE;color:var(--success);}
.badge-ok{background:#E8F6EE;color:var(--success);}
.badge-fail{background:#FCEAEA;color:var(--danger);}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;}
.alert-success{background:#E8F6EE;color:#0E6B34;}
.alert-info{background:#EAF4F6;color:var(--primary-dark);}
.btn{display:inline-block;padding:11px 24px;border-radius:10px;border:none;font-family:inherit;font-weight:600;font-size:14px;cursor:pointer;background:var(--primary-gradient);color:#fff;text-decoration:none;box-shadow:0 4px 14px rgba(0,107,115,.25);}
.btn.secondary{background:#fff;color:var(--primary);border:1.5px solid var(--primary);box-shadow:none;}
.links{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}
</style>
</head>
<body>
<div class="card">
    <h1>🔧 تحديث قاعدة البيانات</h1>
    <div class="sub">أداة تُشغَّل مرة واحدة لتحديث قاعدة بيانات مثبَّتة سابقاً بآخر إضافات النظام، دون حذف أو تعديل أي بيانات موجودة.</div>

    <?php if ($ranNow): ?>
        <?php $allOk = !in_array(false, array_column($results, 'ok'), true); ?>
        <div class="alert <?= $allOk ? 'alert-success' : 'alert-info' ?>">
            <?= $allOk ? '✅ تم تنفيذ التحديثات المطلوبة بنجاح.' : '⚠️ تم تنفيذ بعض التحديثات، راجع الأخطاء أدناه.' ?>
        </div>
        <?php foreach ($results as $r): ?>
            <div class="step">
                <span><?= h($r['label']) ?></span>
                <span class="badge <?= $r['ok'] ? 'badge-ok' : 'badge-fail' ?>"><?= $r['ok'] ? 'تم' : 'فشل' ?></span>
            </div>
            <?php if (!$r['ok']): ?><div style="font-size:12px;color:var(--danger);margin-bottom:8px;"><?= h($r['error']) ?></div><?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (empty($pending)): ?>
        <div class="alert alert-success">✅ قاعدة البيانات محدَّثة بالكامل، لا توجد تحديثات معلَّقة.</div>
        <div class="links">
            <a class="btn" href="/hr">لوحة HR</a>
            <a class="btn secondary" href="/general">لوحة المسؤول العام</a>
            <a class="btn secondary" href="/branch">لوحة مدير الفرع</a>
        </div>
    <?php else: ?>
        <div class="alert alert-info">التحديثات التالية بانتظار التنفيذ (<?= count($pending) ?>):</div>
        <?php foreach ($steps as $i => $step): ?>
            <?php if (in_array($i, $pending, true)): ?>
                <div class="step">
                    <span><?= h($step['label']) ?></span>
                    <span class="badge badge-pending">معلّق</span>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <form method="post" style="margin-top:18px;">
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="btn">تشغيل التحديث الآن</button>
        </form>
    <?php endif; ?>

    <?php if ($gmCreateResult && $gmCreateResult['ok']): ?>
        <div class="card" style="max-width:none;box-shadow:none;border:1.5px solid var(--accent);margin-top:20px;padding:20px;">
            <h1 style="font-size:16px;">👤 حساب المسؤول العام</h1>
            <div class="alert alert-success">✅ تم إنشاء حساب المسؤول العام بنجاح. يمكنك الآن <a href="/general">تسجيل الدخول</a>.</div>
        </div>
    <?php elseif ($gmAccountMissing || ($gmCreateResult && !$gmCreateResult['ok'])): ?>
        <div class="card" style="max-width:none;box-shadow:none;border:1.5px solid var(--accent);margin-top:20px;padding:20px;">
            <h1 style="font-size:16px;">👤 لا يوجد حساب "مسؤول عام" في النظام</h1>
            <div class="sub">هذا يحدث في الأنظمة التي ثُبِّتت قبل إضافة دور المسؤول العام. أنشئ حساباً الآن لتتمكن من تسجيل الدخول.</div>
                <?php if ($gmCreateResult && !$gmCreateResult['ok']): ?>
                    <div class="alert" style="background:#FCEAEA;color:var(--danger);"><?= h($gmCreateResult['error']) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="create_gm" value="1">
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12.5px;margin-bottom:5px;color:var(--text-muted);">البريد الإلكتروني لتسجيل الدخول</label>
                        <input type="email" name="gm_email" required style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13.5px;">
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:12.5px;margin-bottom:5px;color:var(--text-muted);">كلمة المرور (6 أحرف على الأقل)</label>
                        <input type="password" name="gm_password" required minlength="6" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13.5px;">
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:12.5px;margin-bottom:5px;color:var(--text-muted);">تأكيد كلمة المرور</label>
                        <input type="password" name="gm_password_confirm" required minlength="6" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13.5px;">
                    </div>
                    <button type="submit" class="btn">إنشاء حساب المسؤول العام</button>
                </form>
        </div>
    <?php endif; ?>

    <p style="font-size:11.5px;color:var(--text-muted);margin-top:24px;">
        بعد اكتمال كل التحديثات (لا تظهر أي عناصر "معلّق") وإنشاء حساب المسؤول العام إن لزم، يُفضَّل حذف هذا الملف (migrate.php) من السيرفر لأنه لا حاجة له بعد ذلك.
    </p>
</div>
</body>
</html>
