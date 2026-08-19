<?php
/* ======================================================================
   لوحة تحكم مدير الفرع — ملف واحد شامل
   يدير: موظفي الفرع فقط، الحضور والبصمة، طلبات الموظفين، الإيجاز اليومي
   القسم الأول: منطق PHP بالكامل (Backend)
   القسم الثاني: الواجهة HTML/CSS (Frontend) — بعد وسم "الواجهة" أدناه
   ====================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$currentUser = require_role(['branch_manager']);
$pdo = db();
$branchId = (int) $currentUser['branch_id'];

if (!$branchId) {
    http_response_code(403);
    die('حساب مدير الفرع هذا غير مرتبط بأي فرع. الرجاء مراجعة الموارد البشرية.');
}

$validTabs = ['dashboard', 'employees', 'attendance', 'requests', 'ledger'];
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
            $pdo->beginTransaction();
            try {
                $empNumber = generate_employee_number($pdo);
                $stmt = $pdo->prepare("INSERT INTO employees (branch_id, employee_number, full_name, national_id, job_title, hire_date, base_salary, is_branch_manager, status) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'active')");
                $stmt->execute([$branchId, $empNumber, $fullName, $nationalId, $jobTitle, $hireDate, $baseSalary]);
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
            $stmt = $pdo->prepare("INSERT INTO daily_ledger (branch_id, entry_date, entry_type, amount, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$branchId, $entryDate, $entryType, $amount, $description, $currentUser['id']]);
            flash_set('success', 'تم إضافة القيد.');
            break;

        case 'ledger_delete':
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM daily_ledger WHERE id=? AND branch_id=?")->execute([$id, $branchId]);
            flash_set('success', 'تم حذف القيد.');
            break;
    }

    redirect('/branch.php?tab=' . $redirectTab);
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
<link rel="stylesheet" href="/assets/css/style.css">
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
            <li><a class="nav-link <?= $tab==='ledger'?'active':'' ?>" href="?tab=ledger"><span class="icon">📒</span><span>الإيجاز اليومي</span></a></li>
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
            </div>
            <div class="user-chip">
                <div class="avatar"><?= e(mb_substr($currentUser['full_name'], 0, 1)) ?></div>
                <div><?= e($currentUser['full_name']) ?></div>
            </div>
        </div>

        <?php $flash = flash_get(); ?>
        <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" data-autohide><?= e($flash['message']) ?></div><?php endif; ?>

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
                <form method="post" action="/branch.php">
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

        <?php elseif ($tab === 'ledger'): ?>
            <div class="stat-grid">
                <div class="stat-card"><div class="label">إجمالي الإيرادات</div><div class="value" style="color:var(--success);"><?= money($income) ?></div></div>
                <div class="stat-card"><div class="label">إجمالي المصروفات</div><div class="value" style="color:var(--danger);"><?= money($expense) ?></div></div>
                <div class="stat-card"><div class="label">صافي اليوم</div><div class="value"><?= money($income - $expense) ?></div></div>
            </div>
            <div class="card">
                <div class="card-header"><h2>إضافة قيد جديد</h2></div>
                <form method="post" action="/branch.php">
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
                    <div class="form-group"><label>البيان / الملاحظات</label><input type="text" name="description" class="form-control"></div>
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
                    <thead><tr><th>النوع</th><th>المبلغ</th><th>البيان</th><th>إجراء</th></tr></thead>
                    <tbody><?php foreach ($ledgerEntries as $l): ?>
                        <tr>
                            <td><span class="badge <?= $l['entry_type']==='income'?'badge-success':'badge-danger' ?>"><?= $l['entry_type']==='income'?'💰 إيراد':'💸 صرف' ?></span></td>
                            <td><?= money((float)$l['amount']) ?></td>
                            <td><?= e($l['description']) ?></td>
                            <td>
                                <form method="post" action="/branch.php" data-confirm="هل تريد حذف هذا القيد؟" style="display:inline;">
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
<script src="/assets/js/app.js"></script>
</body>
</html>
