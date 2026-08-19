<?php
/* ======================================================================
   لوحة تحكم مدير الفرع — ملف واحد مستقل بالكامل
   يدير: موظفي الفرع فقط، الحضور والبصمة، طلبات الموظفين، الإيجاز اليومي
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

/** يرفع ملفاً (صورة/مستمسك/مرفق) إلى uploads/<sub> ويعيد المسار النسبي، أو null إن لم يُرفع شيء */
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
    header('Location: /branch.php');
    exit;
}

/* ---------------------- تسجيل الدخول ---------------------- */
$currentUser = $_SESSION['branch_user'] ?? null;
$loginError = null;

if (!$currentUser && is_post() && ($_POST['form'] ?? '') === 'login') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare("SELECT u.*, e.full_name FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.username = ? AND u.role = 'branch_manager' AND u.status = 'active' LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['branch_user'] = [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'full_name' => $row['full_name'] ?? $row['username'],
            'branch_id' => (int) $row['branch_id'],
        ];
        header('Location: /branch.php');
        exit;
    }
    $loginError = 'بيانات الدخول غير صحيحة.';
}

if (!$currentUser) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل دخول مدير الفرع — شركة الصوى للصرافة</title>
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
        <h1>مدير الفرع</h1>
        <div class="sub">شركة الصوى للصرافة</div>
        <?php if ($loginError): ?><div class="alert"><?= e($loginError) ?></div><?php endif; ?>
        <form method="post" action="/branch.php">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="login">
            <div class="form-group"><label>رقم الموظف</label><input type="text" name="username" required autofocus></div>
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
$branchId = (int) $currentUser['branch_id'];

if (!$branchId) {
    http_response_code(403);
    die('حساب مدير الفرع هذا غير مرتبط بأي فرع. الرجاء مراجعة الموارد البشرية.');
}

$validTabs = ['dashboard', 'employees', 'attendance', 'requests', 'delegations', 'ledger'];
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
        case 'employee_save':
            $fullName = trim($_POST['full_name'] ?? '');
            $jobTitle = trim($_POST['job_title'] ?? '');
            $nationalId = trim($_POST['national_id'] ?? '');
            $hireDate = $_POST['hire_date'] ?: null;
            $baseSalary = (float) ($_POST['base_salary'] ?? 0);
            $initialPassword = (string) ($_POST['initial_password'] ?? '');

            if ($fullName === '') {
                flash_set('danger', 'اسم الموظف مطلوب.');
                break;
            }
            $photoPath = handle_upload('photo', 'photos', ['jpg', 'jpeg', 'png', 'webp']);
            $documentPath = handle_upload('documents', 'documents', ['jpg', 'jpeg', 'png', 'pdf']);

            $pdo->beginTransaction();
            try {
                $empNumber = generate_employee_number($pdo);
                $stmt = $pdo->prepare("INSERT INTO employees (branch_id, employee_number, full_name, national_id, job_title, hire_date, base_salary, photo, documents, is_branch_manager, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active')");
                $stmt->execute([$branchId, $empNumber, $fullName, $nationalId, $jobTitle, $hireDate, $baseSalary, $photoPath, $documentPath]);
                $employeeId = (int) $pdo->lastInsertId();

                if ($initialPassword !== '' && strlen($initialPassword) >= 4) {
                    $hash = password_hash($initialPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (role, username, password_hash, employee_id, branch_id, status) VALUES ('employee', ?, ?, ?, ?, 'active')");
                    $stmt->execute([$empNumber, $hash, $employeeId, $branchId]);
                }
                $pdo->commit();
                flash_set('success', "تم إضافة الموظف بنجاح. رقم التوظيف: {$empNumber}");
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('danger', 'تعذر إضافة الموظف: ' . $e->getMessage());
            }
            break;

        case 'employee_status':
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE employees SET status = IF(status='active','inactive','active') WHERE id=? AND branch_id=?");
            $stmt->execute([$id, $branchId]);
            $pdo->prepare("UPDATE users SET status = (SELECT status FROM employees WHERE id=?) WHERE employee_id=?")->execute([$id, $id]);
            flash_set('success', 'تم تحديث حالة الموظف.');
            break;

        case 'attendance_save':
            $employeeId = (int) ($_POST['employee_id'] ?? 0);
            $date = $_POST['attendance_date'] ?: date('Y-m-d');
            $checkIn = $_POST['check_in'] ?: null;
            $checkOut = $_POST['check_out'] ?: null;

            $ownCheck = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id=? AND branch_id=?");
            $ownCheck->execute([$employeeId, $branchId]);
            if (!$ownCheck->fetchColumn()) {
                flash_set('danger', 'الموظف غير تابع لهذا الفرع.');
                break;
            }

            $status = 'present';
            if ($checkIn) {
                $settingsRow = $pdo->query("SELECT work_start_time FROM settings ORDER BY id DESC LIMIT 1")->fetch();
                if ($settingsRow && $checkIn > $settingsRow['work_start_time']) {
                    $status = 'late';
                }
            } else {
                $status = 'absent';
            }

            $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, branch_id, attendance_date, check_in, check_out, status)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out), status=VALUES(status)");
            $stmt->execute([$employeeId, $branchId, $date, $checkIn, $checkOut, $status]);
            flash_set('success', 'تم تسجيل الحضور.');
            break;

        case 'request_review':
            $id = (int) ($_POST['id'] ?? 0);
            $decision = $_POST['decision'] === 'approved' ? 'approved' : 'rejected';
            $note = trim($_POST['review_note'] ?? '');
            $stmt = $pdo->prepare("UPDATE requests SET status=?, reviewed_by=?, review_note=? WHERE id=? AND branch_id=?");
            $stmt->execute([$decision, $currentUser['id'], $note, $id, $branchId]);

            $empStmt = $pdo->prepare("SELECT u.id AS user_id FROM requests r LEFT JOIN users u ON u.employee_id=r.employee_id WHERE r.id=?");
            $empStmt->execute([$id]);
            $row = $empStmt->fetch();
            if ($row && $row['user_id']) {
                notify_user($pdo, (int) $row['user_id'], $decision === 'approved' ? 'تم قبول طلبك' : 'تم رفض طلبك', $note);
            }
            flash_set('success', 'تم تحديث حالة الطلب.');
            break;

        case 'ledger_save':
            $entryType = $_POST['entry_type'] === 'expense' ? 'expense' : 'income';
            $amount = (float) ($_POST['amount'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            $entryDate = $_POST['entry_date'] ?: date('Y-m-d');
            if ($amount <= 0) {
                flash_set('danger', 'المبلغ يجب أن يكون أكبر من صفر.');
                break;
            }
            $attachmentPath = handle_upload('attachment', 'ledger', ['jpg', 'jpeg', 'png', 'pdf']);
            $stmt = $pdo->prepare("INSERT INTO daily_ledger (branch_id, entry_date, entry_type, amount, description, attachment, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branchId, $entryDate, $entryType, $amount, $description, $attachmentPath, $currentUser['id']]);
            flash_set('success', 'تم إضافة القيد.');
            break;

        case 'delegation_save':
            $delegatedEmployeeId = (int) ($_POST['delegated_employee_id'] ?? 0);
            $startDate = $_POST['start_date'] ?? date('Y-m-d');
            $endDate = $_POST['end_date'] ?? date('Y-m-d');
            $ownCheck = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id=? AND branch_id=?");
            $ownCheck->execute([$delegatedEmployeeId, $branchId]);
            if (!$ownCheck->fetchColumn()) {
                flash_set('danger', 'الموظف غير تابع لهذا الفرع.');
                break;
            }
            $pdo->prepare("UPDATE delegations SET status='ended' WHERE branch_id=? AND status='active'")->execute([$branchId]);
            $stmt = $pdo->prepare("INSERT INTO delegations (branch_id, delegated_employee_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$branchId, $delegatedEmployeeId, $startDate, $endDate]);
            flash_set('success', 'تم حفظ التفويض.');
            break;

        case 'delegation_end':
            $pdo->prepare("UPDATE delegations SET status='ended' WHERE branch_id=? AND status='active'")->execute([$branchId]);
            flash_set('success', 'تم إلغاء التفويض.');
            break;

        case 'ledger_delete':
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM daily_ledger WHERE id=? AND branch_id=?")->execute([$id, $branchId]);
            flash_set('success', 'تم حذف القيد.');
            break;
    }

    header('Location: /branch.php?tab=' . $redirectTab);
    exit;
}

/* ---------------------- تجهيز بيانات كل تبويب ---------------------- */
$branchInfo = $pdo->prepare("SELECT * FROM branches WHERE id=?");
$branchInfo->execute([$branchId]);
$branchInfo = $branchInfo->fetch();

$employeesList = $pdo->prepare("SELECT * FROM employees WHERE branch_id=? ORDER BY created_at DESC");
$employeesList->execute([$branchId]);
$employeesList = $employeesList->fetchAll();

if ($tab === 'dashboard') {
    $activeCount = (int) count(array_filter($employeesList, fn($e) => $e['status'] === 'active'));
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE branch_id=? AND attendance_date=? AND status IN ('present','late')");
    $stmt->execute([$branchId, $today]);
    $presentToday = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE branch_id=? AND status='pending'");
    $stmt->execute([$branchId]);
    $pendingRequests = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT r.*, e.full_name FROM requests r JOIN employees e ON e.id=r.employee_id WHERE r.branch_id=? ORDER BY r.created_at DESC LIMIT 6");
    $stmt->execute([$branchId]);
    $recentRequests = $stmt->fetchAll();
}

if ($tab === 'attendance') {
    $attDate = $_GET['date'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("SELECT a.*, e.full_name, e.job_title FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.branch_id=? AND a.attendance_date=? ORDER BY e.full_name");
    $stmt->execute([$branchId, $attDate]);
    $attendanceList = $stmt->fetchAll();
}

if ($tab === 'requests') {
    $filterStatus = $_GET['status'] ?? 'pending';
    $sql = "SELECT r.*, e.full_name FROM requests r JOIN employees e ON e.id=r.employee_id WHERE r.branch_id = ?";
    $params = [$branchId];
    if (in_array($filterStatus, ['pending', 'approved', 'rejected'], true)) {
        $sql .= " AND r.status = ?";
        $params[] = $filterStatus;
    }
    $sql .= " ORDER BY r.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requestsList = $stmt->fetchAll();
}

if ($tab === 'ledger') {
    $ledgerDate = $_GET['date'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM daily_ledger WHERE branch_id=? AND entry_date=? ORDER BY created_at DESC");
    $stmt->execute([$branchId, $ledgerDate]);
    $ledgerEntries = $stmt->fetchAll();
    $income = array_sum(array_map(fn($r) => $r['entry_type']==='income' ? (float)$r['amount'] : 0, $ledgerEntries));
    $expense = array_sum(array_map(fn($r) => $r['entry_type']==='expense' ? (float)$r['amount'] : 0, $ledgerEntries));
}

if ($tab === 'delegations') {
    $stmt = $pdo->prepare("SELECT d.*, e.full_name FROM delegations d JOIN employees e ON e.id=d.delegated_employee_id WHERE d.branch_id=? ORDER BY d.created_at DESC");
    $stmt->execute([$branchId]);
    $delegationsList = $stmt->fetchAll();
    $activeDelegation = null;
    foreach ($delegationsList as $d) {
        if ($d['status'] === 'active') { $activeDelegation = $d; break; }
    }
}

$pageTitle = 'لوحة تحكم مدير الفرع';
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
.badge-warning{background:#FCF2E3;color:var(--orange);} .badge-muted{background:#EEF1F3;color:var(--text-muted);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;} .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.empty-state{text-align:center;padding:40px 10px;color:var(--text-muted);} .empty-state .icon{font-size:34px;margin-bottom:10px;}
.menu-toggle{display:none;background:var(--bg-card);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;cursor:pointer;}
.bottom-nav{display:none;}
@media (max-width:720px){.grid-2,.grid-3{grid-template-columns:1fr;} .sidebar{position:fixed;right:-280px;z-index:50;transition:right .2s;} .sidebar.open{right:0;} .main{padding:18px 16px 90px;} .menu-toggle{display:inline-flex;}
.bottom-nav{display:flex;position:fixed;bottom:0;right:0;left:0;background:#fff;border-top:1px solid var(--border);box-shadow:0 -4px 16px rgba(0,107,115,.08);z-index:40;padding:8px 4px;}
.bottom-nav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 2px;font-size:10.5px;color:var(--text-muted);}
.bottom-nav a .icon{font-size:18px;} .bottom-nav a.active{color:var(--primary);font-weight:700;}}
</style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="mark">✥</div>
            <div>
                <div class="name">شركة الصوى للصرافة</div>
                <div class="role">مدير فرع — <?= e($branchInfo['name'] ?? '') ?></div>
            </div>
        </div>
        <ul>
            <li><a class="nav-link <?= $tab==='dashboard'?'active':'' ?>" href="?tab=dashboard"><span class="icon">🏠</span><span>لوحة التحكم</span></a></li>
            <li><a class="nav-link <?= $tab==='employees'?'active':'' ?>" href="?tab=employees"><span class="icon">👥</span><span>موظفو الفرع</span></a></li>
            <li><a class="nav-link <?= $tab==='attendance'?'active':'' ?>" href="?tab=attendance"><span class="icon">🕒</span><span>الحضور والبصمة</span></a></li>
            <li><a class="nav-link <?= $tab==='requests'?'active':'' ?>" href="?tab=requests"><span class="icon">📥</span><span>طلبات الموظفين</span></a></li>
            <li><a class="nav-link <?= $tab==='delegations'?'active':'' ?>" href="?tab=delegations"><span class="icon">✍</span><span>التفويضات</span></a></li>
            <li><a class="nav-link <?= $tab==='ledger'?'active':'' ?>" href="?tab=ledger"><span class="icon">📒</span><span>الإيجاز اليومي</span></a></li>
        </ul>
        <div class="sidebar-footer">
            <a class="nav-link" href="/branch.php?logout=1"><span class="icon">🚪</span><span>تسجيل الخروج</span></a>
        </div>
    </aside>

    <nav class="bottom-nav">
        <a href="?tab=dashboard" class="<?= $tab==='dashboard'?'active':'' ?>"><span class="icon">🏠</span><span>الرئيسية</span></a>
        <a href="?tab=employees" class="<?= $tab==='employees'?'active':'' ?>"><span class="icon">👥</span><span>الموظفون</span></a>
        <a href="?tab=attendance" class="<?= $tab==='attendance'?'active':'' ?>"><span class="icon">🕒</span><span>الحضور</span></a>
        <a href="?tab=requests" class="<?= $tab==='requests'?'active':'' ?>"><span class="icon">📥</span><span>الطلبات</span></a>
        <a href="?tab=ledger" class="<?= $tab==='ledger'?'active':'' ?>"><span class="icon">📒</span><span>الإيجاز</span></a>
    </nav>

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
                <div class="stat-card"><div class="label">عدد الموظفين النشطين</div><div class="value"><?= e((string)$activeCount) ?></div></div>
                <div class="stat-card"><div class="label">الحضور اليوم</div><div class="value"><?= e((string)$presentToday) ?></div></div>
                <div class="stat-card"><div class="label">طلبات بانتظار الموافقة</div><div class="value"><?= e((string)$pendingRequests) ?></div></div>
            </div>
            <div class="card">
                <div class="card-header"><h2>أحدث الطلبات</h2><a class="btn btn-secondary btn-sm" href="?tab=requests">عرض الكل</a></div>
                <?php if (empty($recentRequests)): ?><div class="empty-state"><div class="icon">📭</div>لا توجد طلبات حالياً</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الموظف</th><th>النوع</th><th>الحالة</th></tr></thead>
                    <tbody><?php foreach ($recentRequests as $r): $st = $statusLabels[$r['status']]; ?>
                        <tr><td><?= e($r['full_name']) ?></td><td><?= e($typeLabels[$r['type']] ?? $r['type']) ?></td><td><span class="badge badge-<?= $st[1] ?>"><?= $st[0] ?></span></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'employees'): ?>
            <div class="card">
                <div class="card-header"><h2>إضافة موظف جديد</h2></div>
                <form method="post" action="/branch.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="employee_save">
                    <input type="hidden" name="tab" value="employees">
                    <div class="grid-3">
                        <div class="form-group"><label>الاسم الكامل *</label><input type="text" name="full_name" class="form-control" required></div>
                        <div class="form-group"><label>المسمى الوظيفي</label><input type="text" name="job_title" class="form-control"></div>
                        <div class="form-group"><label>رقم الهوية الوطنية</label><input type="text" name="national_id" class="form-control"></div>
                    </div>
                    <div class="grid-3">
                        <div class="form-group"><label>تاريخ التعيين</label><input type="date" name="hire_date" class="form-control"></div>
                        <div class="form-group"><label>الراتب الأساسي</label><input type="number" step="0.01" name="base_salary" class="form-control" value="0"></div>
                        <div class="form-group"><label>كلمة مرور حساب الدخول (اختياري)</label><input type="text" name="initial_password" class="form-control"></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>الصورة الشخصية</label><input type="file" name="photo" class="form-control" accept="image/*"></div>
                        <div class="form-group"><label>ملف المستمسكات</label><input type="file" name="documents" class="form-control" accept="image/*,.pdf"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الموظف</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2>قائمة الموظفين (<?= count($employeesList) ?>)</h2></div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الرقم</th><th>الاسم</th><th>المسمى</th><th>الراتب</th><th>الحالة</th><th>إجراء</th></tr></thead>
                    <tbody><?php foreach ($employeesList as $emp): ?>
                        <tr>
                            <td><?= e($emp['employee_number']) ?></td>
                            <td><?= e($emp['full_name']) ?></td>
                            <td><?= e($emp['job_title']) ?></td>
                            <td><?= money((float)$emp['base_salary']) ?></td>
                            <td><span class="badge <?= $emp['status']==='active'?'badge-success':'badge-muted' ?>"><?= $emp['status']==='active'?'نشط':'غير نشط' ?></span></td>
                            <td>
                                <form method="post" action="/branch.php" style="display:inline;">
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

        <?php elseif ($tab === 'attendance'): ?>
            <div class="card">
                <div class="card-header"><h2>تسجيل حضور يدوي</h2></div>
                <form method="post" action="/branch.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="attendance_save">
                    <input type="hidden" name="tab" value="attendance">
                    <div class="grid-3">
                        <div class="form-group"><label>الموظف</label>
                            <select name="employee_id" class="form-control" required>
                                <?php foreach ($employeesList as $emp): if ($emp['status']!=='active') continue; ?>
                                    <option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>التاريخ</label><input type="date" name="attendance_date" class="form-control" value="<?= e(date('Y-m-d')) ?>"></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>وقت الدخول</label><input type="time" name="check_in" class="form-control"></div>
                        <div class="form-group"><label>وقت الخروج</label><input type="time" name="check_out" class="form-control"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ الحضور</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header">
                    <h2>سجل الحضور</h2>
                    <form method="get" action="/branch.php" style="display:flex;gap:8px;">
                        <input type="hidden" name="tab" value="attendance">
                        <input type="date" name="date" class="form-control" value="<?= e($attDate) ?>" onchange="this.form.submit()">
                    </form>
                </div>
                <?php if (empty($attendanceList)): ?><div class="empty-state"><div class="icon">🕒</div>لا يوجد سجل حضور لهذا اليوم</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الموظف</th><th>المسمى</th><th>الدخول</th><th>الخروج</th><th>الحالة</th></tr></thead>
                    <tbody><?php foreach ($attendanceList as $a): $badge = ['present'=>'badge-success','late'=>'badge-warning','absent'=>'badge-danger'][$a['status']]; $lbl = ['present'=>'حاضر','late'=>'تأخير','absent'=>'غائب'][$a['status']]; ?>
                        <tr><td><?= e($a['full_name']) ?></td><td><?= e($a['job_title']) ?></td><td><?= e($a['check_in'] ?? '-') ?></td><td><?= e($a['check_out'] ?? '-') ?></td><td><span class="badge <?= $badge ?>"><?= $lbl ?></span></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'requests'): ?>
            <div class="card">
                <div class="card-header">
                    <h2>طلبات الموظفين</h2>
                    <form method="get" action="/branch.php" style="display:flex;gap:8px;">
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
                    <thead><tr><th>الموظف</th><th>النوع</th><th>التفاصيل</th><th>الحالة</th><th>إجراء</th></tr></thead>
                    <tbody><?php foreach ($requestsList as $r): $st = $statusLabels[$r['status']]; ?>
                        <tr>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e($typeLabels[$r['type']] ?? $r['type']) ?></td>
                            <td><?= e($r['details']) ?> <?= $r['amount'] ? money((float)$r['amount']) : '' ?></td>
                            <td><span class="badge badge-<?= $st[1] ?>"><?= $st[0] ?></span></td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                <form method="post" action="/branch.php" style="display:flex;gap:6px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="request_review">
                                    <input type="hidden" name="tab" value="requests">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" name="decision" value="approved" class="btn btn-success btn-sm">قبول</button>
                                    <button type="submit" name="decision" value="rejected" class="btn btn-danger btn-sm">رفض</button>
                                </form>
                                <?php else: ?><span class="badge badge-muted">تمت المراجعة</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'delegations'): ?>
            <div class="card">
                <div class="card-header"><h2>✍ تفويض كتابة الإيجاز</h2></div>
                <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:14px;">فوّض أحد موظفي الفرع بكتابة الإيجاز اليومي نيابة عنك خلال فترة غيابك.</div>
                <?php if ($activeDelegation): ?>
                    <div class="alert alert-success" style="display:flex;justify-content:space-between;align-items:center;">
                        <span>✅ تفويض فعال: <strong><?= e($activeDelegation['full_name']) ?></strong> من <?= e($activeDelegation['start_date']) ?> إلى <?= e($activeDelegation['end_date']) ?></span>
                        <form method="post" action="/branch.php" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delegation_end">
                            <input type="hidden" name="tab" value="delegations">
                            <button type="submit" class="btn btn-danger btn-sm">إلغاء التفويض</button>
                        </form>
                    </div>
                <?php endif; ?>
                <form method="post" action="/branch.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delegation_save">
                    <input type="hidden" name="tab" value="delegations">
                    <div class="grid-3">
                        <div class="form-group"><label>الموظف المفوَّض</label>
                            <select name="delegated_employee_id" class="form-control" required>
                                <?php foreach ($employeesList as $emp): if ($emp['status']!=='active') continue; ?>
                                    <option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group"><label>تاريخ البداية</label><input type="date" name="start_date" class="form-control" value="<?= e(date('Y-m-d')) ?>" required></div>
                        <div class="form-group"><label>تاريخ النهاية</label><input type="date" name="end_date" class="form-control" required></div>
                    </div>
                    <button type="submit" class="btn btn-primary">حفظ التفويض</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2>سجل التفويضات</h2></div>
                <?php if (empty($delegationsList)): ?><div class="empty-state"><div class="icon">✍</div>لا توجد تفويضات سابقة</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>الموظف</th><th>المدة</th><th>الحالة</th></tr></thead>
                    <tbody><?php foreach ($delegationsList as $d): ?>
                        <tr>
                            <td><?= e($d['full_name']) ?></td>
                            <td><?= e($d['start_date']) ?> — <?= e($d['end_date']) ?></td>
                            <td><span class="badge <?= $d['status']==='active'?'badge-success':'badge-muted' ?>"><?= $d['status']==='active'?'✅ فعال':'منتهي' ?></span></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'ledger'): ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="label">إجمالي الإيرادات</div><div class="value" style="color:var(--success);"><?= money($income) ?></div></div>
                <div class="stat-card"><div class="label">إجمالي المصروفات</div><div class="value" style="color:var(--danger);"><?= money($expense) ?></div></div>
                <div class="stat-card"><div class="label">صافي اليوم</div><div class="value"><?= money($income - $expense) ?></div></div>
            </div>
            <div class="card">
                <div class="card-header"><h2>إضافة قيد جديد</h2></div>
                <form method="post" action="/branch.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ledger_save">
                    <input type="hidden" name="tab" value="ledger">
                    <div class="grid-3">
                        <div class="form-group"><label>نوع القيد</label>
                            <select name="entry_type" class="form-control">
                                <option value="income">💰 إيراد (دخل)</option>
                                <option value="expense">💸 صرف (مصروف)</option>
                            </select>
                        </div>
                        <div class="form-group"><label>المبلغ</label><input type="number" step="0.01" name="amount" class="form-control" required></div>
                        <div class="form-group"><label>التاريخ</label><input type="date" name="entry_date" class="form-control" value="<?= e(date('Y-m-d')) ?>"></div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group"><label>البيان / الملاحظات</label><input type="text" name="description" class="form-control"></div>
                        <div class="form-group"><label>رفع ملف (اختياري)</label><input type="file" name="attachment" class="form-control" accept="image/*,.pdf"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">إضافة القيد</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header">
                    <h2>قائمة القيود</h2>
                    <form method="get" action="/branch.php" style="display:flex;gap:8px;">
                        <input type="hidden" name="tab" value="ledger">
                        <input type="date" name="date" class="form-control" value="<?= e($ledgerDate) ?>" onchange="this.form.submit()">
                    </form>
                </div>
                <?php if (empty($ledgerEntries)): ?><div class="empty-state"><div class="icon">📒</div>لا توجد قيود لهذا اليوم</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>النوع</th><th>المبلغ</th><th>البيان</th><th>المرفق</th><th>إجراء</th></tr></thead>
                    <tbody><?php foreach ($ledgerEntries as $l): ?>
                        <tr>
                            <td><span class="badge <?= $l['entry_type']==='income'?'badge-success':'badge-danger' ?>"><?= $l['entry_type']==='income'?'💰 إيراد':'💸 صرف' ?></span></td>
                            <td><?= money((float)$l['amount']) ?></td>
                            <td><?= e($l['description']) ?></td>
                            <td><?php if ($l['attachment']): ?><a href="/<?= e($l['attachment']) ?>" target="_blank" class="badge badge-muted">📎 عرض</a><?php else: ?>-<?php endif; ?></td>
                            <td>
                                <form method="post" action="/branch.php" onsubmit="return confirm('هل تريد حذف هذا القيد؟');" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="ledger_delete">
                                    <input type="hidden" name="tab" value="ledger">
                                    <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">حذف</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
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
