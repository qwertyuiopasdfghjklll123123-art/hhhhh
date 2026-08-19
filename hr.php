<?php
/* ======================================================================
   لوحة تحكم الموارد البشرية (HR) — ملف واحد شامل
   يدير: الفروع، الموظفين في كل الفروع، الطلبات، الرواتب، سعر الصرف، الإعدادات
   القسم الأول: منطق PHP بالكامل (Backend)
   القسم الثاني: الواجهة HTML/CSS (Frontend) — بعد وسم "الواجهة" أدناه
   ====================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
$currentUser = require_role(['hr']);
$pdo = db();

$validTabs = ['dashboard', 'branches', 'employees', 'requests', 'payroll', 'exchange_rate', 'settings'];
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

            $pdo->beginTransaction();
            try {
                $empNumber = generate_employee_number($pdo);
                $stmt = $pdo->prepare("INSERT INTO employees (branch_id, employee_number, full_name, national_id, job_title, hire_date, base_salary, is_branch_manager, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
                $stmt->execute([$branchId, $empNumber, $fullName, $nationalId, $jobTitle, $hireDate, $baseSalary, $isManager]);
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

            $empStmt = $pdo->prepare("SELECT e.id, u.id AS user_id FROM requests r JOIN employees e ON e.id=r.employee_id LEFT JOIN users u ON u.employee_id=e.id WHERE r.id=?");
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

    redirect('/hr.php?tab=' . $redirectTab);
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
<link rel="stylesheet" href="/assets/css/style.css">
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
            <li><a class="nav-link <?= $tab==='exchange_rate'?'active':'' ?>" href="?tab=exchange_rate"><span class="icon">💵</span><span>سعر الصرف</span></a></li>
            <li><a class="nav-link <?= $tab==='settings'?'active':'' ?>" href="?tab=settings"><span class="icon">⚙️</span><span>إعدادات النظام</span></a></li>
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
                <form method="post" action="/hr.php">
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
                        <div class="form-group" style="display:flex;align-items:center;gap:8px;padding-top:22px;">
                            <input type="checkbox" name="is_branch_manager" value="1" id="is_mgr"> <label for="is_mgr" style="margin:0;">تعيينه مديراً للفرع</label>
                        </div>
                        <div class="form-group"><label>كلمة مرور حساب الدخول (اختياري)</label><input type="text" name="initial_password" class="form-control" placeholder="اتركه فارغاً لعدم إنشاء حساب دخول"></div>
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
<script src="/assets/js/app.js"></script>
</body>
</html>
