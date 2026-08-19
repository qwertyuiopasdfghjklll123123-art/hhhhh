<?php
/* ======================================================================
   لوحة تحكم الموارد البشرية (HR) — ملف واحد مستقل بالكامل
   يدير: الفروع، الموظفين في كل الفروع، الطلبات، الرواتب، سعر الصرف، الإعدادات
   لا يعتمد على أي ملف آخر سوى config.php (الذي ينشئه install.php تلقائياً)
   القسم الأول: منطق PHP بالكامل (Backend)
   القسم الثاني: الواجهة HTML/CSS (Frontend) — بعد وسم "الواجهة" أدناه
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: /install.php');
    exit;
}
require_once __DIR__ . '/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

/* ---------------------- دوال مساعدة عامة ---------------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die('تعذر الاتصال بقاعدة البيانات. تحقق من إعدادات config.php.');
    }
    return $pdo;
}

function e(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function money(float $amount): string { return number_format($amount, 0) . ' د.ع'; }
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function flash_set(string $type, string $message): void { $_SESSION['flash'] = ['type' => $type, 'message' => $message]; }
function flash_get(): ?array
{
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; }
function csrf_verify(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('انتهت صلاحية الجلسة. الرجاء إعادة تحميل الصفحة والمحاولة مجدداً.');
    }
}

function generate_employee_number(PDO $pdo): string
{
    $last = (int) $pdo->query("SELECT MAX(CAST(employee_number AS UNSIGNED)) FROM employees WHERE employee_number REGEXP '^[0-9]+$'")->fetchColumn();
    return (string) max(1001, $last + 1);
}
function notify_user(PDO $pdo, int $userId, string $title, string $message = ''): void
{
    $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)")->execute([$userId, $title, $message]);
}

/** يرفع ملفاً (صورة/مستمسك) إلى uploads/<sub> ويعيد المسار النسبي، أو null إن لم يُرفع شيء */
function handle_upload(string $field, string $sub, array $allowedExt): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }
    $dir = __DIR__ . '/uploads/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return null;
    }
    return 'uploads/' . $sub . '/' . $filename;
}

/* ---------------------- تسجيل الخروج ---------------------- */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /hr.php');
    exit;
}

/* ---------------------- تسجيل الدخول ---------------------- */
$currentUser = $_SESSION['hr_user'] ?? null;
$loginError = null;

if (!$currentUser && is_post() && ($_POST['form'] ?? '') === 'login') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare("SELECT * FROM users WHERE username = ? AND role = 'hr' AND status = 'active' LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['hr_user'] = ['id' => (int) $row['id'], 'username' => $row['username'], 'full_name' => 'مدير الموارد البشرية'];
        header('Location: /hr.php');
        exit;
    }
    $loginError = 'بيانات الدخول غير صحيحة.';
}

if (!$currentUser) {
    require __DIR__ . '/config.php'; // no-op, config already loaded — kept for clarity
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل دخول الموارد البشرية — شركة الصوى للصرافة</title>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Tajawal:wght@400;500;700;800&display=swap');
    :root{--primary:#006b73;--primary-dark:#004b52;--primary-gradient:linear-gradient(135deg,#006b73 0%,#0A8A94 100%);--accent:#c99a3d;--bg:#F0F4F8;--border:#E1E8ED;--text:#1A2E35;--text-muted:#8AA0B0;--danger:#df4b4b;--radius:14px;}
    *{box-sizing:border-box;}
    body{margin:0;font-family:'IBM Plex Sans Arabic','Tajawal',sans-serif;background:var(--bg);color:var(--text);direction:rtl;text-align:right;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
    .card{width:100%;max-width:400px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 10px 30px rgba(0,107,115,.10);padding:36px 32px;}
    .logo{text-align:center;font-size:34px;color:var(--accent);margin-bottom:6px;}
    h1{text-align:center;font-size:18px;margin:0 0 4px;color:var(--primary-dark);}
    .sub{text-align:center;color:var(--text-muted);font-size:13px;margin-bottom:22px;}
    .form-group{margin-bottom:16px;}
    label{display:block;font-size:13px;color:var(--text-muted);margin-bottom:6px;}
    input{width:100%;padding:11px 14px;border-radius:10px;border:1.5px solid var(--border);background:#fff;color:var(--text);font-family:inherit;font-size:14px;outline:none;}
    input:focus{border-color:var(--primary);}
    .btn{width:100%;padding:11px 20px;border-radius:10px;border:none;font-weight:600;font-size:14px;cursor:pointer;background:var(--primary-gradient);color:#fff;box-shadow:0 4px 14px rgba(0,107,115,.25);}
    .alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;background:#FCEAEA;border:1px solid #F6C6C6;color:#B23A3A;}
    </style>
    </head>
    <body>
    <div class="card">
        <div class="logo">✥</div>
        <h1>الموارد البشرية</h1>
        <div class="sub">شركة الصوى للصرافة</div>
        <?php if ($loginError): ?><div class="alert"><?= e($loginError) ?></div><?php endif; ?>
        <form method="post" action="/hr.php">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="login">
            <div class="form-group"><label>البريد الإلكتروني</label><input type="text" name="username" required autofocus></div>
            <div class="form-group"><label>كلمة المرور</label><input type="password" name="password" required></div>
            <button type="submit" class="btn">تسجيل الدخول</button>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = db();

$validTabs = ['dashboard', 'branches', 'employees', 'requests', 'payroll', 'reports', 'exchange_rate', 'settings'];
$tab = $_GET['tab'] ?? 'dashboard';
if (!in_array($tab, $validTabs, true)) {
    $tab = 'dashboard';
}

/* ---------------------- معالجة النماذج (POST) ---------------------- */
if (is_post() && ($_POST['form'] ?? '') !== 'login') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $redirectTab = $_POST['tab'] ?? $tab;

    switch ($action) {
        case 'branch_save':
            $name = trim($_POST['name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $notes = trim($_POST['notes'] ?? '');
            if ($name === '') {
                flash_set('danger', 'اسم الفرع مطلوب.');
            } elseif (!empty($_POST['id'])) {
                $stmt = $pdo->prepare("UPDATE branches SET name=?, location=?, status=?, notes=? WHERE id=?");
                $stmt->execute([$name, $location, $status, $notes, (int) $_POST['id']]);
                flash_set('success', 'تم تحديث بيانات الفرع.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO branches (name, location, status, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $location, $status, $notes]);
                flash_set('success', 'تم إضافة الفرع بنجاح.');
            }
            break;

        case 'branch_toggle':
            $stmt = $pdo->prepare("UPDATE branches SET status = IF(status='active','inactive','active') WHERE id=?");
            $stmt->execute([(int) $_POST['id']]);
            flash_set('success', 'تم تحديث حالة الفرع.');
            break;

        case 'employee_save':
            $fullName = trim($_POST['full_name'] ?? '');
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $jobTitle = trim($_POST['job_title'] ?? '');
            $nationalId = trim($_POST['national_id'] ?? '');
            $hireDate = $_POST['hire_date'] ?: null;
            $baseSalary = (float) ($_POST['base_salary'] ?? 0);
            $isManager = !empty($_POST['is_branch_manager']) ? 1 : 0;
            $initialPassword = (string) ($_POST['initial_password'] ?? '');

            if ($fullName === '' || $branchId === 0) {
                flash_set('danger', 'الاسم والفرع مطلوبان.');
                break;
            }

            $photoPath = handle_upload('photo', 'photos', ['jpg', 'jpeg', 'png', 'webp']);
            $documentPath = handle_upload('documents', 'documents', ['jpg', 'jpeg', 'png', 'pdf']);

            $pdo->beginTransaction();
            try {
                $empNumber = generate_employee_number($pdo);
                $stmt = $pdo->prepare("INSERT INTO employees (branch_id, employee_number, full_name, national_id, job_title, hire_date, base_salary, photo, documents, is_branch_manager, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$branchId, $empNumber, $fullName, $nationalId, $jobTitle, $hireDate, $baseSalary, $photoPath, $documentPath, $isManager]);
                $employeeId = (int) $pdo->lastInsertId();

                if ($initialPassword !== '' && strlen($initialPassword) >= 4) {
                    $role = $isManager ? 'branch_manager' : 'employee';
                    $hash = password_hash($initialPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (role, username, password_hash, employee_id, branch_id, status) VALUES (?, ?, ?, ?, ?, 'active')");
                    $stmt->execute([$role, $empNumber, $hash, $employeeId, $branchId]);
                }

                $pdo->commit();
                flash_set('success', "تم إضافة الموظف بنجاح. رقم التوظيف: {$empNumber}" . ($initialPassword !== '' ? ' — تم إنشاء حساب دخول له.' : ''));
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('danger', 'تعذر إضافة الموظف: ' . $e->getMessage());
            }
            break;

        case 'employee_status':
            $stmt = $pdo->prepare("UPDATE employees SET status = IF(status='active','inactive','active') WHERE id=?");
            $stmt->execute([(int) $_POST['id']]);
            $stmt2 = $pdo->prepare("UPDATE users SET status = (SELECT status FROM employees WHERE id = ?) WHERE employee_id = ?");
            $stmt2->execute([(int) $_POST['id'], (int) $_POST['id']]);
            flash_set('success', 'تم تحديث حالة الموظف.');
            break;

        case 'request_review':
            $id = (int) ($_POST['id'] ?? 0);
            $decision = $_POST['decision'] === 'approved' ? 'approved' : 'rejected';
            $note = trim($_POST['review_note'] ?? '');
            $stmt = $pdo->prepare("UPDATE requests SET status=?, reviewed_by=?, review_note=? WHERE id=?");
            $stmt->execute([$decision, $currentUser['id'], $note, $id]);

            $empStmt = $pdo->prepare("SELECT u.id AS user_id FROM requests r LEFT JOIN users u ON u.employee_id=r.employee_id WHERE r.id=?");
            $empStmt->execute([$id]);
            $row = $empStmt->fetch();
            if ($row && $row['user_id']) {
                notify_user($pdo, (int) $row['user_id'], $decision === 'approved' ? 'تم قبول طلبك' : 'تم رفض طلبك', $note);
            }
            flash_set('success', 'تم تحديث حالة الطلب.');
            break;

        case 'payroll_save':
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $month = (int) ($_POST['period_month'] ?? date('n'));
            $year = (int) ($_POST['period_year'] ?? date('Y'));
            $bonus = (float) ($_POST['bonus'] ?? 0);
            $deduction = (float) ($_POST['deduction'] ?? 0);

            $empStmt = $pdo->prepare("SELECT branch_id, base_salary FROM employees WHERE id=?");
            $empStmt->execute([$employeeId]);
            $emp = $empStmt->fetch();
            if (!$emp) {
                flash_set('danger', 'الموظف غير موجود.');
                break;
            }
            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, period_month, period_year, base_salary, bonus, deduction, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE base_salary=VALUES(base_salary), bonus=VALUES(bonus), deduction=VALUES(deduction)");
            $stmt->execute([$employeeId, $emp['branch_id'], $month, $year, $emp['base_salary'], $bonus, $deduction]);
            flash_set('success', 'تم حفظ سجل الراتب.');
            break;

        case 'rate_update':
            $rate = (float) ($_POST['rate'] ?? 0);
            if ($rate > 0) {
                $pdo->prepare("UPDATE settings SET usd_exchange_rate=? ORDER BY id DESC LIMIT 1")->execute([$rate]);
                $pdo->prepare("INSERT INTO exchange_rate_history (rate, updated_by) VALUES (?, ?)")->execute([$rate, $currentUser['username']]);
                flash_set('success', 'تم تحديث سعر الصرف.');
            }
            break;

        case 'settings_save':
            $companyName = trim($_POST['company_name'] ?? '');
            $companyEmail = trim($_POST['company_email'] ?? '');
            $workStart = $_POST['work_start_time'] ?? '09:00';
            $workEnd = $_POST['work_end_time'] ?? '15:00';
            $stmt = $pdo->prepare("UPDATE settings SET company_name=?, company_email=?, work_start_time=?, work_end_time=? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$companyName, $companyEmail, $workStart, $workEnd]);
            flash_set('success', 'تم حفظ إعدادات النظام.');
            break;
    }

    header('Location: /hr.php?tab=' . $redirectTab);
    exit;
}

/* ---------------------- تجهيز بيانات كل تبويب ---------------------- */
$branchesList = $pdo->query("SELECT * FROM branches ORDER BY created_at DESC")->fetchAll();

if ($tab === 'dashboard') {
    $stats = [
        'branches' => (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE status='active'")->fetchColumn(),
        'employees' => (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn(),
        'pending_requests' => (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status='pending'")->fetchColumn(),
    ];
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date=? AND status IN ('present','late')");
    $stmt->execute([$today]);
    $presentToday = (int) $stmt->fetchColumn();
    $rate = $pdo->query("SELECT usd_exchange_rate FROM settings ORDER BY id DESC LIMIT 1")->fetchColumn();
    $recentRequests = $pdo->query("SELECT r.*, e.full_name, b.name AS branch_name FROM requests r JOIN employees e ON e.id=r.employee_id JOIN branches b ON b.id=r.branch_id ORDER BY r.created_at DESC LIMIT 6")->fetchAll();
    $branchesWithCount = $pdo->query("SELECT b.*, (SELECT COUNT(*) FROM employees e WHERE e.branch_id=b.id AND e.status='active') AS employee_count FROM branches b ORDER BY b.created_at DESC LIMIT 6")->fetchAll();
}

if ($tab === 'employees') {
    $filterBranch = (int) ($_GET['branch_id'] ?? 0);
    $sql = "SELECT e.*, b.name AS branch_name FROM employees e JOIN branches b ON b.id=e.branch_id";
    $params = [];
    if ($filterBranch > 0) {
        $sql .= " WHERE e.branch_id = ?";
        $params[] = $filterBranch;
    }
    $sql .= " ORDER BY e.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $employeesList = $stmt->fetchAll();
}

if ($tab === 'requests') {
    $filterStatus = $_GET['status'] ?? 'pending';
    $sql = "SELECT r.*, e.full_name, b.name AS branch_name FROM requests r JOIN employees e ON e.id=r.employee_id JOIN branches b ON b.id=r.branch_id";
    if (in_array($filterStatus, ['pending', 'approved', 'rejected'], true)) {
        $sql .= " WHERE r.status = " . $pdo->quote($filterStatus);
    }
    $sql .= " ORDER BY r.created_at DESC";
    $requestsList = $pdo->query($sql)->fetchAll();
}

if ($tab === 'payroll') {
    $pMonth = (int) ($_GET['month'] ?? date('n'));
    $pYear = (int) ($_GET['year'] ?? date('Y'));
    $employeesAll = $pdo->query("SELECT id, full_name, base_salary, branch_id FROM employees WHERE status='active' ORDER BY full_name")->fetchAll();
    $stmt = $pdo->prepare("SELECT p.*, e.full_name FROM payroll p JOIN employees e ON e.id=p.employee_id WHERE p.period_month=? AND p.period_year=? ORDER BY e.full_name");
    $stmt->execute([$pMonth, $pYear]);
    $payrollList = $stmt->fetchAll();
    $payrollByEmp = [];
    foreach ($payrollList as $p) {
        $payrollByEmp[(int) $p['employee_id']] = $p;
    }
}

if ($tab === 'exchange_rate') {
    $currentRate = $pdo->query("SELECT usd_exchange_rate FROM settings ORDER BY id DESC LIMIT 1")->fetchColumn();
    $rateHistory = $pdo->query("SELECT * FROM exchange_rate_history ORDER BY created_at DESC LIMIT 15")->fetchAll();
}

if ($tab === 'settings') {
    $settingsRow = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1")->fetch();
}

if ($tab === 'reports') {
    $reportType = in_array($_GET['report_type'] ?? '', ['attendance', 'payroll', 'leave', 'comprehensive'], true) ? $_GET['report_type'] : 'attendance';
    $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $reportBranch = (int) ($_GET['report_branch'] ?? 0);
    $reportColumns = [];
    $reportRows = [];

    if ($reportType === 'attendance') {
        $reportColumns = ['التاريخ', 'الموظف', 'الفرع', 'الدخول', 'الخروج', 'الحالة'];
        $sql = "SELECT a.attendance_date, e.full_name, b.name AS branch_name, a.check_in, a.check_out, a.status
                FROM attendance a JOIN employees e ON e.id=a.employee_id JOIN branches b ON b.id=a.branch_id
                WHERE a.attendance_date BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        if ($reportBranch > 0) { $sql .= " AND a.branch_id = ?"; $params[] = $reportBranch; }
        $sql .= " ORDER BY a.attendance_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $statusAr = ['present' => 'حاضر', 'late' => 'تأخير', 'absent' => 'غائب'];
        foreach ($stmt->fetchAll() as $r) {
            $reportRows[] = [$r['attendance_date'], $r['full_name'], $r['branch_name'], $r['check_in'] ?? '-', $r['check_out'] ?? '-', $statusAr[$r['status']] ?? $r['status']];
        }
    } elseif ($reportType === 'payroll') {
        $reportColumns = ['الموظف', 'الفرع', 'الشهر', 'السنة', 'الأساسي', 'المكافأة', 'الخصم', 'الصافي', 'الحالة'];
        $sql = "SELECT p.*, e.full_name, b.name AS branch_name FROM payroll p JOIN employees e ON e.id=p.employee_id JOIN branches b ON b.id=p.branch_id
                WHERE DATE(p.created_at) BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        if ($reportBranch > 0) { $sql .= " AND p.branch_id = ?"; $params[] = $reportBranch; }
        $sql .= " ORDER BY p.period_year DESC, p.period_month DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $net = (float) $r['base_salary'] + (float) $r['bonus'] - (float) $r['deduction'];
            $reportRows[] = [$r['full_name'], $r['branch_name'], $r['period_month'], $r['period_year'], $r['base_salary'], $r['bonus'], $r['deduction'], $net, $r['status'] === 'delivered' ? 'تم التسليم' : 'قيد الانتظار'];
        }
    } elseif ($reportType === 'leave') {
        $reportColumns = ['التاريخ', 'الموظف', 'الفرع', 'النوع', 'من', 'إلى', 'الحالة'];
        $sql = "SELECT r.*, e.full_name, b.name AS branch_name FROM requests r JOIN employees e ON e.id=r.employee_id JOIN branches b ON b.id=r.branch_id
                WHERE DATE(r.created_at) BETWEEN ? AND ?";
        $params = [$dateFrom, $dateTo];
        if ($reportBranch > 0) { $sql .= " AND r.branch_id = ?"; $params[] = $reportBranch; }
        $sql .= " ORDER BY r.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $typeAr = ['leave' => 'إجازة', 'advance' => 'سلفة', 'complaint' => 'شكوى', 'resignation' => 'استقالة'];
        $statusAr = ['pending' => 'بانتظار المراجعة', 'approved' => 'مقبول', 'rejected' => 'مرفوض'];
        foreach ($stmt->fetchAll() as $r) {
            $reportRows[] = [substr($r['created_at'], 0, 10), $r['full_name'], $r['branch_name'], $typeAr[$r['type']] ?? $r['type'], $r['date_from'] ?? '-', $r['date_to'] ?? '-', $statusAr[$r['status']]];
        }
    } else {
        $reportColumns = ['الفرع', 'إجمالي الإيرادات', 'إجمالي المصروفات', 'الصافي'];
        $sql = "SELECT b.id, b.name,
                COALESCE(SUM(CASE WHEN l.entry_type='income' THEN l.amount ELSE 0 END),0) AS income,
                COALESCE(SUM(CASE WHEN l.entry_type='expense' THEN l.amount ELSE 0 END),0) AS expense
                FROM branches b LEFT JOIN daily_ledger l ON l.branch_id=b.id AND l.entry_date BETWEEN ? AND ?
                WHERE 1=1";
        $params = [$dateFrom, $dateTo];
        if ($reportBranch > 0) { $sql .= " AND b.id = ?"; $params[] = $reportBranch; }
        $sql .= " GROUP BY b.id, b.name ORDER BY b.name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $reportRows[] = [$r['name'], money((float) $r['income']), money((float) $r['expense']), money((float) $r['income'] - (float) $r['expense'])];
        }
    }

    if (isset($_GET['export'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="report_' . $reportType . '_' . date('Ymd_His') . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, $reportColumns);
        foreach ($reportRows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}

$pageTitle = 'لوحة تحكم الموارد البشرية';
$typeLabels = ['leave' => 'إجازة', 'advance' => 'سلفة', 'complaint' => 'شكوى', 'resignation' => 'استقالة'];
$statusLabels = ['pending' => ['بانتظار المراجعة', 'warning'], 'approved' => ['مقبول', 'success'], 'rejected' => ['مرفوض', 'danger']];

/* ======================================================================
   الواجهة (HTML / CSS View)
   ====================================================================== */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Tajawal:wght@400;500;700;800&display=swap');
:root{--primary:#006b73;--primary-light:#0A8A94;--primary-dark:#004b52;--primary-gradient:linear-gradient(135deg,#006b73 0%,#0A8A94 100%);--accent:#c99a3d;--green:#159447;--red:#df4b4b;--orange:#d98c1a;--bg:#F0F4F8;--bg-card:#FFFFFF;--border:#E1E8ED;--text:#1A2E35;--text-secondary:#4A6A78;--text-muted:#8AA0B0;--radius-sm:8px;--radius-md:14px;--radius-lg:20px;--radius-full:9999px;--shadow-sm:0 2px 8px rgba(0,107,115,.06);--shadow-md:0 4px 20px rgba(0,107,115,.08);}
*{box-sizing:border-box;}
html,body{margin:0;padding:0;min-height:100%;}
body{font-family:'IBM Plex Sans Arabic','Tajawal',sans-serif;background:var(--bg);color:var(--text);direction:rtl;text-align:right;min-height:100vh;}
a{color:inherit;text-decoration:none;} ul{list-style:none;margin:0;padding:0;}
.form-group{margin-bottom:16px;} label{display:block;font-size:13px;color:var(--text-secondary);margin-bottom:6px;font-weight:500;}
.form-control,select.form-control,textarea.form-control{width:100%;padding:11px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border);background:#fff;color:var(--text);font-family:inherit;font-size:14px;outline:none;transition:border-color .2s;}
.form-control:focus{border-color:var(--primary);}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:var(--radius-sm);border:none;font-family:inherit;font-weight:600;font-size:14px;cursor:pointer;transition:transform .1s;}
.btn:active{transform:scale(.98);}
.btn-primary{background:var(--primary-gradient);color:#fff;box-shadow:0 4px 14px rgba(0,107,115,.25);}
.btn-secondary{background:#EFF3F6;color:var(--text);border:1.5px solid var(--border);}
.btn-danger{background:var(--red);color:#fff;} .btn-success{background:var(--green);color:#fff;}
.btn-block{width:100%;} .btn-sm{padding:6px 12px;font-size:12px;}
.alert{padding:12px 16px;border-radius:var(--radius-sm);font-size:13px;margin-bottom:16px;border:1px solid transparent;}
.alert-danger{background:#FCEAEA;border-color:#F6C6C6;color:#B23A3A;}
.alert-success{background:#E8F6EE;border-color:#B9E4C9;color:#0E6B34;}
.app-shell{display:flex;min-height:100vh;}
.sidebar{width:260px;flex-shrink:0;background:var(--bg-card);border-left:1px solid var(--border);padding:22px 16px;position:sticky;top:0;height:100vh;overflow-y:auto;}
.sidebar-brand{display:flex;align-items:center;gap:10px;padding:0 6px 20px;border-bottom:1px solid var(--border);margin-bottom:16px;}
.sidebar-brand .mark{font-size:26px;color:var(--accent);} .sidebar-brand .name{font-weight:800;font-size:15px;color:var(--primary-dark);} .sidebar-brand .role{font-size:11px;color:var(--text-muted);}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);font-size:13.5px;color:var(--text-secondary);margin-bottom:2px;font-weight:500;}
.nav-link:hover{background:#EFF6F7;color:var(--primary);}
.nav-link.active{background:var(--primary);color:#fff;font-weight:700;box-shadow:var(--shadow-sm);}
.nav-link .icon{font-size:16px;width:20px;text-align:center;}
.sidebar-footer{margin-top:18px;padding-top:14px;border-top:1px solid var(--border);}
.main{flex:1;min-width:0;padding:26px 30px 60px;}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:12px;}
.topbar h1{font-size:20px;margin:0;color:var(--primary-dark);}
.user-chip{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--border);padding:8px 14px;border-radius:var(--radius-full);font-size:13px;box-shadow:var(--shadow-sm);}
.user-chip .avatar{width:30px;height:30px;border-radius:50%;background:var(--primary-gradient);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);padding:18px 20px;box-shadow:var(--shadow-sm);}
.stat-card .label{color:var(--text-muted);font-size:12.5px;margin-bottom:8px;} .stat-card .value{font-size:26px;font-weight:800;color:var(--primary-dark);}
.card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px 22px;margin-bottom:20px;box-shadow:var(--shadow-sm);}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;} .card-header h2{font-size:16px;margin:0;color:var(--primary-dark);}
.table-wrap{overflow-x:auto;} table.data-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.data-table th,.data-table td{padding:12px 10px;text-align:right;border-bottom:1px solid var(--border);white-space:nowrap;}
.data-table th{color:var(--text-muted);font-weight:600;font-size:12.5px;} .data-table tbody tr:hover{background:#F7FAFB;}
.badge{display:inline-block;padding:4px 10px;border-radius:var(--radius-full);font-size:11.5px;font-weight:700;}
.badge-success{background:#E8F6EE;color:var(--green);} .badge-danger{background:#FCEAEA;color:var(--red);}
.badge-warning{background:#FCF2E3;color:var(--orange);} .badge-info{background:#E6F4F5;color:var(--primary);} .badge-muted{background:#EEF1F3;color:var(--text-muted);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;} .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.empty-state{text-align:center;padding:40px 10px;color:var(--text-muted);} .empty-state .icon{font-size:34px;margin-bottom:10px;}
.menu-toggle{display:none;background:var(--bg-card);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;cursor:pointer;}
.honor-list{display:flex;flex-direction:column;gap:10px;}
.honor-item{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:var(--radius-md);background:#F7FAFB;border:1px solid var(--border);}
.honor-item .medal{font-size:22px;} .honor-item .name{font-weight:700;flex:1;} .honor-item .pct{font-weight:800;color:var(--primary);}
@media (max-width:720px){.grid-2,.grid-3{grid-template-columns:1fr;} .sidebar{position:fixed;right:-280px;z-index:50;transition:right .2s;} .sidebar.open{right:0;} .main{padding:18px 16px 50px;} .menu-toggle{display:inline-flex;}}
</style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="mark">✥</div>
            <div>
                <div class="name">شركة الصوى للصرافة</div>
                <div class="role">الموارد البشرية</div>
            </div>
        </div>
        <ul>
            <li><a class="nav-link <?= $tab==='dashboard'?'active':'' ?>" href="?tab=dashboard"><span class="icon">🏠</span><span>لوحة التحكم</span></a></li>
            <li><a class="nav-link <?= $tab==='branches'?'active':'' ?>" href="?tab=branches"><span class="icon">🏢</span><span>الفروع</span></a></li>
            <li><a class="nav-link <?= $tab==='employees'?'active':'' ?>" href="?tab=employees"><span class="icon">👥</span><span>الموظفون (كل الفروع)</span></a></li>
            <li><a class="nav-link <?= $tab==='requests'?'active':'' ?>" href="?tab=requests"><span class="icon">📥</span><span>الطلبات والإجازات</span></a></li>
            <li><a class="nav-link <?= $tab==='payroll'?'active':'' ?>" href="?tab=payroll"><span class="icon">💰</span><span>الرواتب</span></a></li>
            <li><a class="nav-link <?= $tab==='reports'?'active':'' ?>" href="?tab=reports"><span class="icon">📊</span><span>التقارير</span></a></li>
            <li><a class="nav-link <?= $tab==='exchange_rate'?'active':'' ?>" href="?tab=exchange_rate"><span class="icon">💵</span><span>سعر الصرف</span></a></li>
            <li><a class="nav-link <?= $tab==='settings'?'active':'' ?>" href="?tab=settings"><span class="icon">⚙️</span><span>إعدادات النظام</span></a></li>
        </ul>
        <div class="sidebar-footer">
            <a class="nav-link" href="/hr.php?logout=1"><span class="icon">🚪</span><span>تسجيل الخروج</span></a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <button class="menu-toggle">☰ القائمة</button>
                <h1><?= e($pageTitle) ?></h1>
            </div>
            <div class="user-chip">
                <div class="avatar"><?= e(mb_substr($currentUser['full_name'], 0, 1)) ?></div>
                <div><?= e($currentUser['full_name']) ?></div>
            </div>
        </div>

        <?php $flash = flash_get(); ?>
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="label">إجمالي الفروع النشطة</div><div class="value"><?= e((string)$stats['branches']) ?></div></div>
                <div class="stat-card"><div class="label">إجمالي الموظفين</div><div class="value"><?= e((string)$stats['employees']) ?></div></div>
                <div class="stat-card"><div class="label">الحضور اليوم</div><div class="value"><?= e((string)$presentToday) ?></div></div>
                <div class="stat-card"><div class="label">طلبات بانتظار الموافقة</div><div class="value"><?= e((string)$stats['pending_requests']) ?></div></div>
                <div class="stat-card"><div class="label">سعر صرف الدولار</div><div class="value"><?= e((string)$rate) ?> د.ع</div></div>
            </div>
            <div class="grid-2">
                <div class="card">
                    <div class="card-header"><h2>أحدث الطلبات</h2><a class="btn btn-secondary btn-sm" href="?tab=requests">عرض الكل</a></div>
                    <?php if (empty($recentRequests)): ?><div class="empty-state"><div class="icon">📭</div>لا توجد طلبات حالياً</div>
                    <?php else: ?>
                    <div class="table-wrap"><table class="data-table">
                        <thead><tr><th>الموظف</th><th>الفرع</th><th>النوع</th><th>الحالة</th></tr></thead>
                        <tbody><?php foreach ($recentRequests as $r): $st = $statusLabels[$r['status']]; ?>
                            <tr><td><?= e($r['full_name']) ?></td><td><?= e($r['branch_name']) ?></td><td><?= e($typeLabels[$r['type']] ?? $r['type']) ?></td><td><span class="badge badge-<?= $st[1] ?>"><?= $st[0] ?></span></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="card-header"><h2>الفروع</h2><a class="btn btn-secondary btn-sm" href="?tab=branches">إدارة الفروع</a></div>
                    <?php if (empty($branchesWithCount)): ?><div class="empty-state"><div class="icon">🏢</div>لا توجد فروع بعد</div>
                    <?php else: ?>
                    <div class="table-wrap"><table class="data-table">
                        <thead><tr><th>الفرع</th><th>الموقع</th><th>الموظفون</th><th>الحالة</th></tr></thead>
                        <tbody><?php foreach ($branchesWithCount as $b): ?>
                            <tr><td><?= e($b['name']) ?></td><td><?= e($b['location']) ?></td><td><?= e((string)$b['employee_count']) ?></td><td><span class="badge <?= $b['status']==='active'?'badge-success':'badge-muted' ?>"><?= $b['status']==='active'?'نشط':'غير نشط' ?></span></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'branches'): ?>
            <div class="card">
                <div class="card-header"><h2>إضافة فرع جديد</h2></div>
                <form method="post" action="/hr.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="branch_save">
                    <input type="hidden" name="tab" value="branches">
                    <div class="grid-2">
                        <div class="form-group"><label>اسم الفرع *</label><input type="text" name="name" class="form-control" required></div>
                        <div class="form-group"><label>الموقع</label><input type="text" name="location" class="form-control"></div>
                    </div>
                    <div class="form-group"><label>ملاحظات</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                    <button type="submit" class="btn btn-primary">حفظ الفرع</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2>قائمة الفروع (<?= count($branchesList) ?>)</h2></div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الاسم</th><th>الموقع</th><th>الحالة</th><th>إجراء</th></tr></thead>
                    <tbody><?php foreach ($branchesList as $b): ?>
                        <tr>
                            <td><?= e($b['name']) ?></td>
                            <td><?= e($b['location']) ?></td>
                            <td><span class="badge <?= $b['status']==='active'?'badge-success':'badge-muted' ?>"><?= $b['status']==='active'?'نشط':'غير نشط' ?></span></td>
                            <td>
                                <form method="post" action="/hr.php" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="branch_toggle">
                                    <input type="hidden" name="tab" value="branches">
                                    <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?= $b['status']==='active'?'تعطيل':'تفعيل' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>

        <?php elseif ($tab === 'employees'): ?>
            <div class="card">
                <div class="card-header"><h2>إضافة موظف جديد</h2></div>
                <form method="post" action="/hr.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="employee_save">
                    <input type="hidden" name="tab" value="employees">
                    <div class="grid-3">
                        <div class="form-group"><label>الاسم الكامل *</label><input type="text" name="full_name" class="form-control" required></div>
                        <div class="form-group"><label>الفرع *</label>
                            <select name="branch_id" class="form-control" required>
                                <option value="">اختر الفرع</option>
                                <?php foreach ($branchesList as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>المسمى الوظيفي</label><input type="text" name="job_title" class="form-control"></div>
                    </div>
                    <div class="grid-3">
                        <div class="form-group"><label>رقم الهوية الوطنية</label><input type="text" name="national_id" class="form-control"></div>
                        <div class="form-group"><label>تاريخ التعيين</label><input type="date" name="hire_date" class="form-control"></div>
                        <div class="form-group"><label>الراتب الأساسي</label><input type="number" step="0.01" name="base_salary" class="form-control" value="0"></div>
                    </div>
                    <div class="grid-3">
                        <div class="form-group"><label>الصورة الشخصية</label><input type="file" name="photo" class="form-control" accept="image/*"></div>
                        <div class="form-group"><label>ملف المستمسكات</label><input type="file" name="documents" class="form-control" accept="image/*,.pdf"></div>
                        <div class="form-group"><label>كلمة مرور حساب الدخول (اختياري)</label><input type="text" name="initial_password" class="form-control" placeholder="اتركه فارغاً لعدم إنشاء حساب دخول"></div>
                    </div>
                    <div class="form-group" style="display:flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="is_branch_manager" value="1" id="is_mgr"> <label for="is_mgr" style="margin:0;">تعيينه مديراً للفرع</label>
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الموظف</button>
                </form>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>قائمة الموظفين (<?= count($employeesList) ?>)</h2>
                    <form method="get" action="/hr.php" style="display:flex;gap:8px;">
                        <input type="hidden" name="tab" value="employees">
                        <select name="branch_id" class="form-control" onchange="this.form.submit()">
                            <option value="0">كل الفروع</option>
                            <?php foreach ($branchesList as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $filterBranch===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الرقم</th><th>الاسم</th><th>الفرع</th><th>المسمى</th><th>الراتب</th><th>الحالة</th><th>إجراء</th></tr></thead>
                    <tbody><?php foreach ($employeesList as $emp): ?>
                        <tr>
                            <td><?= e($emp['employee_number']) ?></td>
                            <td><?= e($emp['full_name']) ?> <?= $emp['is_branch_manager'] ? '<span class="badge badge-info">مدير فرع</span>' : '' ?></td>
                            <td><?= e($emp['branch_name']) ?></td>
                            <td><?= e($emp['job_title']) ?></td>
                            <td><?= money((float)$emp['base_salary']) ?></td>
                            <td><span class="badge <?= $emp['status']==='active'?'badge-success':'badge-muted' ?>"><?= $emp['status']==='active'?'نشط':'غير نشط' ?></span></td>
                            <td>
                                <form method="post" action="/hr.php" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="employee_status">
                                    <input type="hidden" name="tab" value="employees">
                                    <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?= $emp['status']==='active'?'تعطيل':'تفعيل' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>

        <?php elseif ($tab === 'requests'): ?>
            <div class="card">
                <div class="card-header">
                    <h2>الطلبات</h2>
                    <form method="get" action="/hr.php" style="display:flex;gap:8px;">
                        <input type="hidden" name="tab" value="requests">
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>بانتظار المراجعة</option>
                            <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>مقبولة</option>
                            <option value="rejected" <?= $filterStatus==='rejected'?'selected':'' ?>>مرفوضة</option>
                            <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>الكل</option>
                        </select>
                    </form>
                </div>
                <?php if (empty($requestsList)): ?><div class="empty-state"><div class="icon">📭</div>لا توجد طلبات</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الموظف</th><th>الفرع</th><th>النوع</th><th>التفاصيل</th><th>الحالة</th><th>إجراء</th></tr></thead>
                    <tbody><?php foreach ($requestsList as $r): $st = $statusLabels[$r['status']]; ?>
                        <tr>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e($r['branch_name']) ?></td>
                            <td><?= e($typeLabels[$r['type']] ?? $r['type']) ?></td>
                            <td><?= e($r['details']) ?> <?= $r['amount'] ? money((float)$r['amount']) : '' ?></td>
                            <td><span class="badge badge-<?= $st[1] ?>"><?= $st[0] ?></span></td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                <form method="post" action="/hr.php" style="display:flex;gap:6px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_review">
                                    <input type="hidden" name="tab" value="requests">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" name="decision" value="approved" class="btn btn-success btn-sm">قبول</button>
                                    <button type="submit" name="decision" value="rejected" class="btn btn-danger btn-sm">رفض</button>
                                </form>
                                <?php else: ?>
                                    <span class="badge badge-muted">تمت المراجعة</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'payroll'): ?>
            <div class="card">
                <div class="card-header">
                    <h2>رواتب الشهر <?= (int)$pMonth ?> / <?= (int)$pYear ?></h2>
                    <form method="get" action="/hr.php" style="display:flex;gap:8px;">
                        <input type="hidden" name="tab" value="payroll">
                        <select name="month" class="form-control" onchange="this.form.submit()">
                            <?php for ($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $pMonth===$m?'selected':'' ?>><?= $m ?></option><?php endfor; ?>
                        </select>
                        <select name="year" class="form-control" onchange="this.form.submit()">
                            <?php for ($y=(int)date('Y')-1;$y<=(int)date('Y')+1;$y++): ?><option value="<?= $y ?>" <?= $pYear===$y?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
                        </select>
                    </form>
                </div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الموظف</th><th>الراتب الأساسي</th><th>المكافأة</th><th>الخصم</th><th>الصافي</th><th>تعديل</th></tr></thead>
                    <tbody><?php foreach ($employeesAll as $emp): $p = $payrollByEmp[$emp['id']] ?? null; $bonus = $p['bonus'] ?? 0; $ded = $p['deduction'] ?? 0; $net = (float)$emp['base_salary'] + (float)$bonus - (float)$ded; ?>
                        <tr>
                            <td><?= e($emp['full_name']) ?></td>
                            <td><?= money((float)$emp['base_salary']) ?></td>
                            <td colspan="2">
                                <form method="post" action="/hr.php" style="display:flex;gap:6px;align-items:center;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="payroll_save">
                                    <input type="hidden" name="tab" value="payroll">
                                    <input type="hidden" name="employee_id" value="<?= (int)$emp['id'] ?>">
                                    <input type="hidden" name="period_month" value="<?= (int)$pMonth ?>">
                                    <input type="hidden" name="period_year" value="<?= (int)$pYear ?>">
                                    <input type="number" step="0.01" name="bonus" class="form-control" value="<?= e((string)$bonus) ?>" placeholder="مكافأة" style="max-width:110px;">
                                    <input type="number" step="0.01" name="deduction" class="form-control" value="<?= e((string)$ded) ?>" placeholder="خصم" style="max-width:110px;">
                                    <button type="submit" class="btn btn-secondary btn-sm">حفظ</button>
                                </form>
                            </td>
                            <td><?= money($net) ?></td>
                            <td><span class="badge <?= ($p['status'] ?? 'pending')==='delivered'?'badge-success':'badge-warning' ?>"><?= ($p['status'] ?? 'pending')==='delivered'?'تم التسليم':'قيد الانتظار' ?></span></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>

        <?php elseif ($tab === 'reports'): ?>
            <div class="card">
                <div class="card-header"><h2>إعدادات التقرير</h2></div>
                <form method="get" action="/hr.php">
                    <input type="hidden" name="tab" value="reports">
                    <div class="grid-3">
                        <div class="form-group"><label>نوع التقرير</label>
                            <select name="report_type" class="form-control">
                                <option value="attendance" <?= $reportType==='attendance'?'selected':'' ?>>تقرير الحضور</option>
                                <option value="payroll" <?= $reportType==='payroll'?'selected':'' ?>>تقرير الرواتب</option>
                                <option value="leave" <?= $reportType==='leave'?'selected':'' ?>>تقرير الإجازات والطلبات</option>
                                <option value="comprehensive" <?= $reportType==='comprehensive'?'selected':'' ?>>تقرير شامل (الإيجاز اليومي)</option>
                            </select>
                        </div>
                        <div class="form-group"><label>من تاريخ</label><input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>"></div>
                        <div class="form-group"><label>إلى تاريخ</label><input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>"></div>
                    </div>
                    <div class="form-group"><label>الفرع</label>
                        <select name="report_branch" class="form-control">
                            <option value="0">جميع الفروع</option>
                            <?php foreach ($branchesList as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $reportBranch===(int)$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">إنشاء التقرير</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header">
                    <h2>النتائج (<?= count($reportRows) ?>)</h2>
                    <a class="btn btn-secondary btn-sm" href="?tab=reports&report_type=<?= e($reportType) ?>&date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>&report_branch=<?= (int)$reportBranch ?>&export=1">⬇ تحميل CSV</a>
                </div>
                <?php if (empty($reportRows)): ?><div class="empty-state"><div class="icon">📊</div>لا توجد بيانات ضمن هذا النطاق</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><?php foreach ($reportColumns as $col): ?><th><?= e($col) ?></th><?php endforeach; ?></tr></thead>
                    <tbody><?php foreach ($reportRows as $row): ?>
                        <tr><?php foreach ($row as $cell): ?><td><?= e((string)$cell) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'exchange_rate'): ?>
            <div class="grid-2">
                <div class="card">
                    <div class="card-header"><h2>تحديث سعر الصرف</h2></div>
                    <div style="font-size:28px;font-weight:800;color:var(--primary);margin-bottom:16px;">السعر الحالي: <?= e((string)$currentRate) ?> د.ع</div>
                    <form method="post" action="/hr.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="rate_update">
                        <input type="hidden" name="tab" value="exchange_rate">
                        <div class="form-group"><label>سعر صرف الدولار الجديد (د.ع)</label><input type="number" step="0.01" name="rate" class="form-control" required></div>
                        <button type="submit" class="btn btn-primary btn-block">تحديث السعر</button>
                    </form>
                </div>
                <div class="card">
                    <div class="card-header"><h2>سجل التحديثات</h2></div>
                    <div class="table-wrap"><table class="data-table">
                        <thead><tr><th>التاريخ</th><th>السعر</th><th>بواسطة</th></tr></thead>
                        <tbody><?php foreach ($rateHistory as $h): ?>
                            <tr><td><?= e($h['created_at']) ?></td><td><?= e($h['rate']) ?> د.ع</td><td><?= e($h['updated_by']) ?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                </div>
            </div>

        <?php elseif ($tab === 'settings'): ?>
            <div class="card" style="max-width:560px;">
                <div class="card-header"><h2>إعدادات الشركة</h2></div>
                <form method="post" action="/hr.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="settings_save">
                    <input type="hidden" name="tab" value="settings">
                    <div class="form-group"><label>اسم الشركة</label><input type="text" name="company_name" class="form-control" value="<?= e($settingsRow['company_name']) ?>"></div>
                    <div class="form-group"><label>البريد الإلكتروني</label><input type="email" name="company_email" class="form-control" value="<?= e($settingsRow['company_email']) ?>"></div>
                    <div class="grid-2">
                        <div class="form-group"><label>وقت بداية الدوام</label><input type="time" name="work_start_time" class="form-control" value="<?= e(substr($settingsRow['work_start_time'],0,5)) ?>"></div>
                        <div class="form-group"><label>وقت نهاية الدوام</label><input type="time" name="work_end_time" class="form-control" value="<?= e(substr($settingsRow['work_end_time'],0,5)) ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الإعدادات</button>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.menu-toggle');
    var sidebar = document.querySelector('.sidebar');
    if (toggle && sidebar) toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
});
</script>
</body>
</html>
