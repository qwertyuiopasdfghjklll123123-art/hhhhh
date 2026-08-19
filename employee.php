<?php
/* ======================================================================
   نافذة الموظف — ملف واحد مستقل بالكامل
   يدير: الحضور الذاتي (بصمة)، تقديم الطلبات، عرض الملف الوظيفي والراتب
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

/* ---------------------- تسجيل الخروج ---------------------- */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /employee.php');
    exit;
}

/* ---------------------- تسجيل الدخول ---------------------- */
$currentUser = $_SESSION['employee_user'] ?? null;
$loginError = null;

if (!$currentUser && is_post() && ($_POST['form'] ?? '') === 'login') {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare("SELECT u.*, e.full_name FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.username = ? AND u.role = 'employee' AND u.status = 'active' LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify($password, $row['password_hash'])) {
        $_SESSION['employee_user'] = [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'employee_id' => (int) $row['employee_id'],
        ];
        header('Location: /employee.php');
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
    <title>تسجيل دخول الموظف — شركة الصوى للصرافة</title>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap');
    :root{--bg:#0f172a;--bg-soft:#16213a;--surface:#1b2544;--border:#2b3660;--text:#e8ecf7;--text-muted:#9aa4c4;--primary:#d4af37;--danger:#ef4444;--radius:14px;}
    *{box-sizing:border-box;}
    body{margin:0;font-family:'Cairo','Tahoma',sans-serif;background:radial-gradient(circle at top left,#16213a 0%,#0f172a 55%,#0b1120 100%);color:var(--text);direction:rtl;text-align:right;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
    .card{width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 10px 30px rgba(0,0,0,.35);padding:36px 32px;}
    .logo{text-align:center;font-size:34px;color:var(--primary);margin-bottom:6px;}
    h1{text-align:center;font-size:18px;margin:0 0 4px;}
    .sub{text-align:center;color:var(--text-muted);font-size:13px;margin-bottom:22px;}
    .form-group{margin-bottom:16px;}
    label{display:block;font-size:13px;color:var(--text-muted);margin-bottom:6px;}
    input{width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-soft);color:var(--text);font-family:inherit;font-size:14px;outline:none;}
    input:focus{border-color:var(--primary);}
    .btn{width:100%;padding:11px 20px;border-radius:10px;border:none;font-weight:600;font-size:14px;cursor:pointer;background:linear-gradient(135deg,var(--primary),#b8912a);color:#1a1200;}
    .alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fca5a5;}
    </style>
    </head>
    <body>
    <div class="card">
        <div class="logo">✥</div>
        <h1>نافذة الموظف</h1>
        <div class="sub">شركة الصوى للصرافة</div>
        <?php if ($loginError): ?><div class="alert"><?= e($loginError) ?></div><?php endif; ?>
        <form method="post" action="/employee.php">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="login">
            <div class="form-group"><label>رقم التوظيف</label><input type="text" name="username" required autofocus></div>
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
$employeeId = (int) $currentUser['employee_id'];

if (!$employeeId) {
    http_response_code(403);
    die('هذا الحساب غير مرتبط بملف موظف. الرجاء مراجعة الموارد البشرية.');
}

$employee = $pdo->prepare("SELECT e.*, b.name AS branch_name FROM employees e JOIN branches b ON b.id=e.branch_id WHERE e.id=?");
$employee->execute([$employeeId]);
$employee = $employee->fetch();
if (!$employee) {
    http_response_code(404);
    die('تعذر العثور على بيانات الموظف.');
}
$branchId = (int) $employee['branch_id'];

$validTabs = ['dashboard', 'attendance', 'requests', 'profile'];
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
        case 'check_in':
            $today = date('Y-m-d');
            $now = date('H:i:s');
            $settingsRow = $pdo->query("SELECT work_start_time FROM settings ORDER BY id DESC LIMIT 1")->fetch();
            $status = ($settingsRow && $now > $settingsRow['work_start_time']) ? 'late' : 'present';
            $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, branch_id, attendance_date, check_in, status)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), status=VALUES(status)");
            $stmt->execute([$employeeId, $branchId, $today, $now, $status]);
            flash_set('success', 'تم تسجيل حضورك بنجاح.');
            break;

        case 'check_out':
            $today = date('Y-m-d');
            $now = date('H:i:s');
            $stmt = $pdo->prepare("UPDATE attendance SET check_out=? WHERE employee_id=? AND attendance_date=?");
            $stmt->execute([$now, $employeeId, $today]);
            flash_set('success', 'تم تسجيل انصرافك بنجاح.');
            break;

        case 'request_submit':
            $type = $_POST['type'] ?? '';
            if (!in_array($type, ['leave', 'advance', 'complaint', 'resignation'], true)) {
                flash_set('danger', 'نوع الطلب غير صالح.');
                break;
            }
            $details = trim($_POST['details'] ?? '');
            $amount = $type === 'advance' ? (float) ($_POST['amount'] ?? 0) : null;
            $dateFrom = $type === 'leave' ? ($_POST['date_from'] ?: null) : null;
            $dateTo = $type === 'leave' ? ($_POST['date_to'] ?: null) : null;

            $stmt = $pdo->prepare("INSERT INTO requests (employee_id, branch_id, type, details, amount, date_from, date_to, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$employeeId, $branchId, $type, $details, $amount, $dateFrom, $dateTo]);
            flash_set('success', 'تم إرسال طلبك بنجاح، بانتظار المراجعة.');
            break;
    }

    header('Location: /employee.php?tab=' . $redirectTab);
    exit;
}

/* ---------------------- تجهيز بيانات كل تبويب ---------------------- */
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?");
$stmt->execute([$employeeId, $today]);
$todayAttendance = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$stmt->execute([$currentUser['id']]);
$unreadNotifications = (int) $stmt->fetchColumn();

if ($tab === 'dashboard') {
    $monthStart = date('Y-m-01');
    $stmt = $pdo->prepare("SELECT
        SUM(status IN ('present','late')) AS present_days,
        SUM(status='absent') AS absent_days,
        COUNT(*) AS total_days
        FROM attendance WHERE employee_id=? AND attendance_date >= ?");
    $stmt->execute([$employeeId, $monthStart]);
    $commitment = $stmt->fetch();
    $totalDays = max(1, (int) ($commitment['total_days'] ?? 0));
    $commitmentPct = round((((int)($commitment['present_days'] ?? 0)) / $totalDays) * 100);

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$currentUser['id']]);
    $notifications = $stmt->fetchAll();
}

if ($tab === 'attendance') {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? ORDER BY attendance_date DESC LIMIT 20");
    $stmt->execute([$employeeId]);
    $attendanceHistory = $stmt->fetchAll();
}

if ($tab === 'requests') {
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE employee_id=? ORDER BY created_at DESC");
    $stmt->execute([$employeeId]);
    $myRequests = $stmt->fetchAll();
}

if ($tab === 'profile') {
    $month = (int) date('n');
    $year = (int) date('Y');
    $stmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id=? AND period_month=? AND period_year=?");
    $stmt->execute([$employeeId, $month, $year]);
    $payrollRow = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM daily_ledger WHERE branch_id=? AND entry_date=? ORDER BY created_at DESC");
    $stmt->execute([$branchId, $today]);
    $branchLedgerToday = $stmt->fetchAll();
}

$pageTitle = 'نافذة الموظف';
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
@import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap');
:root{--bg:#0f172a;--bg-soft:#16213a;--surface:#1b2544;--surface-2:#212c4f;--border:#2b3660;--text:#e8ecf7;--text-muted:#9aa4c4;--primary:#d4af37;--primary-soft:rgba(212,175,55,.15);--success:#22c55e;--danger:#ef4444;--warning:#f59e0b;--radius:14px;}
*{box-sizing:border-box;}
html,body{margin:0;padding:0;min-height:100%;}
body{font-family:'Cairo','Tahoma',sans-serif;background:radial-gradient(circle at top left,#16213a 0%,#0f172a 55%,#0b1120 100%);color:var(--text);direction:rtl;text-align:right;min-height:100vh;}
a{color:inherit;text-decoration:none;} ul{list-style:none;margin:0;padding:0;}
.form-group{margin-bottom:16px;} label{display:block;font-size:13px;color:var(--text-muted);margin-bottom:6px;}
.form-control,select.form-control,textarea.form-control{width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border);background:var(--bg-soft);color:var(--text);font-family:inherit;font-size:14px;outline:none;}
.form-control:focus{border-color:var(--primary);}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:10px;border:none;font-family:inherit;font-weight:600;font-size:14px;cursor:pointer;}
.btn-primary{background:linear-gradient(135deg,var(--primary),#b8912a);color:#1a1200;}
.btn-secondary{background:var(--surface-2);color:var(--text);border:1px solid var(--border);}
.btn-danger{background:var(--danger);color:#fff;} .btn-success{background:var(--success);color:#06280f;}
.btn:disabled{opacity:.5;cursor:not-allowed;}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;border:1px solid transparent;}
.alert-danger{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.4);color:#fca5a5;}
.alert-success{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.4);color:#86efac;}
.app-shell{display:flex;min-height:100vh;}
.sidebar{width:260px;flex-shrink:0;background:var(--surface);border-left:1px solid var(--border);padding:22px 16px;position:sticky;top:0;height:100vh;overflow-y:auto;}
.sidebar-brand{display:flex;align-items:center;gap:10px;padding:0 6px 20px;border-bottom:1px solid var(--border);margin-bottom:16px;}
.sidebar-brand .mark{font-size:26px;color:var(--primary);} .sidebar-brand .name{font-weight:800;font-size:15px;} .sidebar-brand .role{font-size:11px;color:var(--text-muted);}
.nav-link{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;font-size:13.5px;color:var(--text-muted);margin-bottom:2px;}
.nav-link:hover{background:var(--surface-2);color:var(--text);}
.nav-link.active{background:var(--primary-soft);color:var(--primary);font-weight:700;}
.nav-link .icon{font-size:16px;width:20px;text-align:center;}
.sidebar-footer{margin-top:18px;padding-top:14px;border-top:1px solid var(--border);}
.main{flex:1;min-width:0;padding:26px 30px 60px;}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;flex-wrap:wrap;gap:12px;}
.topbar h1{font-size:20px;margin:0;} .topbar .sub{color:var(--text-muted);font-size:12.5px;margin-top:4px;}
.user-chip{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);padding:8px 14px;border-radius:30px;font-size:13px;}
.user-chip .avatar{width:30px;height:30px;border-radius:50%;background:var(--primary-soft);color:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.stat-card .label{color:var(--text-muted);font-size:12.5px;margin-bottom:8px;} .stat-card .value{font-size:26px;font-weight:800;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;margin-bottom:20px;}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;} .card-header h2{font-size:16px;margin:0;}
.table-wrap{overflow-x:auto;} table.data-table{width:100%;border-collapse:collapse;font-size:13.5px;}
.data-table th,.data-table td{padding:12px 10px;text-align:right;border-bottom:1px solid var(--border);white-space:nowrap;}
.data-table th{color:var(--text-muted);font-weight:600;font-size:12.5px;} .data-table tbody tr:hover{background:rgba(255,255,255,0.02);}
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:700;}
.badge-success{background:rgba(34,197,94,.15);color:#4ade80;} .badge-danger{background:rgba(239,68,68,.15);color:#f87171;}
.badge-warning{background:rgba(245,158,11,.15);color:#fbbf24;} .badge-muted{background:rgba(255,255,255,.08);color:var(--text-muted);}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;} .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.empty-state{text-align:center;padding:40px 10px;color:var(--text-muted);} .empty-state .icon{font-size:34px;margin-bottom:10px;}
.menu-toggle{display:none;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 12px;cursor:pointer;}
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
                <div class="role">موظف — <?= e($employee['branch_name']) ?></div>
            </div>
        </div>
        <ul>
            <li><a class="nav-link <?= $tab==='dashboard'?'active':'' ?>" href="?tab=dashboard"><span class="icon">🏠</span><span>الرئيسية</span></a></li>
            <li><a class="nav-link <?= $tab==='attendance'?'active':'' ?>" href="?tab=attendance"><span class="icon">🕒</span><span>البصمة</span></a></li>
            <li><a class="nav-link <?= $tab==='requests'?'active':'' ?>" href="?tab=requests"><span class="icon">📝</span><span>طلباتي</span></a></li>
            <li><a class="nav-link <?= $tab==='profile'?'active':'' ?>" href="?tab=profile"><span class="icon">👤</span><span>ملفي الوظيفي</span></a></li>
        </ul>
        <div class="sidebar-footer">
            <a class="nav-link" href="/employee.php?logout=1"><span class="icon">🚪</span><span>تسجيل الخروج</span></a>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <button class="menu-toggle">☰ القائمة</button>
                <h1><?= e($pageTitle) ?></h1>
                <div class="sub"><?= e($employee['full_name']) ?> — <?= e($employee['job_title']) ?> — رقم التوظيف: <?= e($employee['employee_number']) ?></div>
            </div>
            <div class="user-chip">
                <div class="avatar"><?= e(mb_substr($employee['full_name'], 0, 1)) ?></div>
                <div><?= e($employee['full_name']) ?></div>
            </div>
        </div>

        <?php $flash = flash_get(); ?>
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="label">نسبة الالتزام هذا الشهر</div><div class="value"><?= e((string)$commitmentPct) ?>%</div></div>
                <div class="stat-card"><div class="label">حالة اليوم</div><div class="value"><?= $todayAttendance ? '✅ حاضر' : '⏳ لم تسجل بعد' ?></div></div>
                <div class="stat-card"><div class="label">إشعارات جديدة</div><div class="value"><?= e((string)$unreadNotifications) ?></div></div>
            </div>
            <div class="card">
                <div class="card-header"><h2>آخر الإشعارات</h2></div>
                <?php if (empty($notifications)): ?><div class="empty-state"><div class="icon">🔔</div>لا توجد إشعارات</div>
                <?php else: ?>
                <ul>
                    <?php foreach ($notifications as $n): ?>
                        <li style="padding:10px 0;border-bottom:1px solid var(--border);">
                            <div style="font-weight:700;"><?= e($n['title']) ?></div>
                            <div style="color:var(--text-muted);font-size:12.5px;"><?= e($n['message']) ?> — <?= e($n['created_at']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'attendance'): ?>
            <div class="card" style="text-align:center;">
                <div style="font-size:14px;color:var(--text-muted);margin-bottom:14px;">
                    آخر تسجيل: <?= $todayAttendance ? (e($todayAttendance['check_in'] ?? '-') . ($todayAttendance['check_out'] ? ' → ' . e($todayAttendance['check_out']) : '')) : 'لم تسجل حضورك اليوم بعد' ?>
                </div>
                <div style="display:flex;gap:12px;justify-content:center;">
                    <form method="post" action="/employee.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="check_in">
                        <input type="hidden" name="tab" value="attendance">
                        <button type="submit" class="btn btn-success" <?= $todayAttendance && $todayAttendance['check_in'] ? 'disabled' : '' ?>>تسجيل حضور (دخول)</button>
                    </form>
                    <form method="post" action="/employee.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="check_out">
                        <input type="hidden" name="tab" value="attendance">
                        <button type="submit" class="btn btn-danger" <?= !$todayAttendance || !$todayAttendance['check_in'] ? 'disabled' : '' ?>>تسجيل انصراف (خروج)</button>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2>سجل الحضور الأخير</h2></div>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>التاريخ</th><th>الدخول</th><th>الخروج</th><th>الحالة</th></tr></thead>
                    <tbody><?php foreach ($attendanceHistory as $a): $badge = ['present'=>'badge-success','late'=>'badge-warning','absent'=>'badge-danger'][$a['status']]; $lbl = ['present'=>'حاضر','late'=>'تأخير','absent'=>'غائب'][$a['status']]; ?>
                        <tr><td><?= e($a['attendance_date']) ?></td><td><?= e($a['check_in'] ?? '-') ?></td><td><?= e($a['check_out'] ?? '-') ?></td><td><span class="badge <?= $badge ?>"><?= $lbl ?></span></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>

        <?php elseif ($tab === 'requests'): ?>
            <div class="card">
                <div class="card-header"><h2>تقديم طلب جديد</h2></div>
                <form method="post" action="/employee.php" id="requestForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_submit">
                    <input type="hidden" name="tab" value="requests">
                    <div class="form-group">
                        <label>نوع الطلب</label>
                        <select name="type" class="form-control" id="reqType">
                            <option value="leave">إجازة</option>
                            <option value="advance">سلفة</option>
                            <option value="complaint">شكوى</option>
                            <option value="resignation">استقالة</option>
                        </select>
                    </div>
                    <div class="grid-2" id="leaveFields">
                        <div class="form-group"><label>من تاريخ</label><input type="date" name="date_from" class="form-control"></div>
                        <div class="form-group"><label>إلى تاريخ</label><input type="date" name="date_to" class="form-control"></div>
                    </div>
                    <div class="form-group" id="amountField" style="display:none;">
                        <label>المبلغ المطلوب</label><input type="number" step="0.01" name="amount" class="form-control">
                    </div>
                    <div class="form-group"><label>التفاصيل</label><textarea name="details" class="form-control" rows="3"></textarea></div>
                    <button type="submit" class="btn btn-primary">إرسال الطلب</button>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><h2>طلباتي السابقة</h2></div>
                <?php if (empty($myRequests)): ?><div class="empty-state"><div class="icon">📝</div>لا توجد طلبات سابقة</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>النوع</th><th>التفاصيل</th><th>الحالة</th><th>ملاحظة المراجعة</th></tr></thead>
                    <tbody><?php foreach ($myRequests as $r): $st = $statusLabels[$r['status']]; ?>
                        <tr><td><?= e($typeLabels[$r['type']] ?? $r['type']) ?></td><td><?= e($r['details']) ?></td><td><span class="badge badge-<?= $st[1] ?>"><?= $st[0] ?></span></td><td><?= e($r['review_note']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php endif; ?>
            </div>
            <script>
            document.getElementById('reqType').addEventListener('change', function () {
                document.getElementById('leaveFields').style.display = this.value === 'leave' ? 'grid' : 'none';
                document.getElementById('amountField').style.display = this.value === 'advance' ? 'block' : 'none';
            });
            </script>

        <?php elseif ($tab === 'profile'): ?>
            <div class="card">
                <div class="card-header"><h2>البيانات الشخصية</h2></div>
                <div class="grid-3">
                    <div><div style="color:var(--text-muted);font-size:12px;">الاسم</div><div><?= e($employee['full_name']) ?></div></div>
                    <div><div style="color:var(--text-muted);font-size:12px;">المنصب</div><div><?= e($employee['job_title']) ?></div></div>
                    <div><div style="color:var(--text-muted);font-size:12px;">الفرع</div><div><?= e($employee['branch_name']) ?></div></div>
                    <div><div style="color:var(--text-muted);font-size:12px;">تاريخ التعيين</div><div><?= e($employee['hire_date']) ?></div></div>
                    <div><div style="color:var(--text-muted);font-size:12px;">رقم التوظيف</div><div><?= e($employee['employee_number']) ?></div></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2>الراتب والخصومات — الشهر الحالي</h2></div>
                <?php if (!$payrollRow): ?>
                    <div class="empty-state"><div class="icon">💰</div>لم يتم إعداد راتب هذا الشهر بعد</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <tbody>
                        <tr><td>الراتب الأساسي</td><td><?= money((float)$payrollRow['base_salary']) ?></td></tr>
                        <tr><td>المكافآت</td><td>+ <?= money((float)$payrollRow['bonus']) ?></td></tr>
                        <tr><td>الخصومات</td><td>- <?= money((float)$payrollRow['deduction']) ?></td></tr>
                        <tr><td><strong>الصافي</strong></td><td><strong><?= money((float)$payrollRow['base_salary'] + (float)$payrollRow['bonus'] - (float)$payrollRow['deduction']) ?></strong></td></tr>
                        <tr><td>حالة التسليم</td><td><span class="badge <?= $payrollRow['status']==='delivered'?'badge-success':'badge-warning' ?>"><?= $payrollRow['status']==='delivered'?'تم التسليم':'قيد الانتظار' ?></span></td></tr>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
            <div class="card">
                <div class="card-header"><h2>إيجاز الفرع اليومي (للاطلاع فقط)</h2></div>
                <div style="font-size:12.5px;color:var(--text-muted);margin-bottom:10px;">للمشاهدة فقط، لا يمكنك التعديل أو الحذف.</div>
                <?php if (empty($branchLedgerToday)): ?><div class="empty-state"><div class="icon">📒</div>لا توجد قيود اليوم</div>
                <?php else: ?>
                <div class="table-wrap"><table class="data-table">
                    <thead><tr><th>النوع</th><th>المبلغ</th><th>البيان</th></tr></thead>
                    <tbody><?php foreach ($branchLedgerToday as $l): ?>
                        <tr><td><span class="badge <?= $l['entry_type']==='income'?'badge-success':'badge-danger' ?>"><?= $l['entry_type']==='income'?'إيراد':'صرف' ?></span></td><td><?= money((float)$l['amount']) ?></td><td><?= e($l['description']) ?></td></tr>
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
