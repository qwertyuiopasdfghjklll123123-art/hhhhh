<?php
/* ======================================================================
   نافذة الموظف — ملف واحد شامل
   يدير: الحضور الذاتي (بصمة)، تقديم الطلبات، عرض الملف الوظيفي والراتب
   القسم الأول: منطق PHP بالكامل (Backend)
   القسم الثاني: الواجهة HTML/CSS (Frontend) — بعد وسم "الواجهة" أدناه
   ====================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$currentUser = require_role(['employee']);
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
if (is_post()) {
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

        case 'mark_notifications_read':
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$currentUser['id']]);
            break;
    }

    redirect('/employee.php?tab=' . $redirectTab);
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
<link rel="stylesheet" href="/assets/css/style.css">
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
            <a class="nav-link" href="/logout.php"><span class="icon">🚪</span><span>تسجيل الخروج</span></a>
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
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" data-autohide><?= e($flash['message']) ?></div><?php endif; ?>

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
                    <div><div class="label" style="color:var(--text-muted);font-size:12px;">الاسم</div><div><?= e($employee['full_name']) ?></div></div>
                    <div><div class="label" style="color:var(--text-muted);font-size:12px;">المنصب</div><div><?= e($employee['job_title']) ?></div></div>
                    <div><div class="label" style="color:var(--text-muted);font-size:12px;">الفرع</div><div><?= e($employee['branch_name']) ?></div></div>
                    <div><div class="label" style="color:var(--text-muted);font-size:12px;">تاريخ التعيين</div><div><?= e($employee['hire_date']) ?></div></div>
                    <div><div class="label" style="color:var(--text-muted);font-size:12px;">رقم التوظيف</div><div><?= e($employee['employee_number']) ?></div></div>
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
<script src="/assets/js/app.js"></script>
</body>
</html>
