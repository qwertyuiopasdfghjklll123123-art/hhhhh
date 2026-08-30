<?php
// ============================================================
// لوحة تحكم الأدمن - استضافتي
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
requireAdmin($pdo);

$admin = currentUser($pdo);
$section = $_GET['section'] ?? 'orders';
if (!in_array($section, ['orders', 'topups', 'plans', 'payments'], true)) {
    $section = 'orders';
}

function adminRedirect($section, $msg = null, $err = null) {
    $url = 'admin.php?section=' . urlencode($section);
    if ($msg) $url .= '&msg=' . urlencode($msg);
    if ($err) $url .= '&err=' . urlencode($err);
    header('Location: ' . $url);
    exit;
}

// ============================================================
// معالجة الإجراءات
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'plan_save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: '🚀';
        $cpu = trim($_POST['cpu'] ?? '');
        $ram = trim($_POST['ram'] ?? '');
        $storage = trim($_POST['storage'] ?? '');
        $bandwidth = trim($_POST['bandwidth'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $badge = trim($_POST['badge'] ?? '') ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '' || $cpu === '' || $ram === '' || $storage === '' || $bandwidth === '' || $price <= 0) {
            adminRedirect('plans', null, 'الرجاء تعبئة جميع الحقول المطلوبة (السعر يجب أن يكون أكبر من صفر).');
        }

        if ($id > 0) {
            $pdo->prepare('UPDATE vps_plans SET name=?, icon=?, cpu=?, ram=?, storage=?, bandwidth=?, price=?, badge=?, is_active=?, sort_order=? WHERE id=?')
                ->execute([$name, $icon, $cpu, $ram, $storage, $bandwidth, $price, $badge, $isActive, $sortOrder, $id]);
        } else {
            $pdo->prepare('INSERT INTO vps_plans (name, icon, cpu, ram, storage, bandwidth, price, badge, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $icon, $cpu, $ram, $storage, $bandwidth, $price, $badge, $isActive, $sortOrder]);
        }
        adminRedirect('plans', 'تم حفظ الباقة بنجاح.');
    }

    if ($action === 'plan_delete') {
        $pdo->prepare('DELETE FROM vps_plans WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        adminRedirect('plans', 'تم حذف الباقة.');
    }

    if ($action === 'pm_save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-money-bill-wave';
        $account = trim($_POST['account_number'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '') {
            adminRedirect('payments', null, 'الرجاء إدخال اسم طريقة الدفع.');
        }

        [$logoPath, $uploadErr] = handleImageUpload('logo', LOGOS_DIR, 'uploads/logos');
        if ($uploadErr) {
            adminRedirect('payments', null, $uploadErr);
        }

        if ($id > 0) {
            if ($logoPath) {
                $pdo->prepare('UPDATE payment_methods SET name=?, icon=?, account_number=?, instructions=?, is_active=?, sort_order=?, logo_path=? WHERE id=?')
                    ->execute([$name, $icon, $account, $instructions, $isActive, $sortOrder, $logoPath, $id]);
            } else {
                $pdo->prepare('UPDATE payment_methods SET name=?, icon=?, account_number=?, instructions=?, is_active=?, sort_order=? WHERE id=?')
                    ->execute([$name, $icon, $account, $instructions, $isActive, $sortOrder, $id]);
            }
        } else {
            $pdo->prepare('INSERT INTO payment_methods (name, icon, account_number, instructions, is_active, sort_order, logo_path) VALUES (?,?,?,?,?,?,?)')
                ->execute([$name, $icon, $account, $instructions, $isActive, $sortOrder, $logoPath]);
        }
        adminRedirect('payments', 'تم حفظ طريقة الدفع بنجاح.');
    }

    if ($action === 'pm_delete') {
        $pdo->prepare('DELETE FROM payment_methods WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        adminRedirect('payments', 'تم حذف طريقة الدفع.');
    }

    if ($action === 'order_fulfill') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            adminRedirect('orders', null, 'الطلب غير موجود أو تمت معالجته مسبقاً.');
        }
        $planStmt = $pdo->prepare('SELECT * FROM vps_plans WHERE id = ?');
        $planStmt->execute([$order['plan_id']]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);

        $hostName = trim($_POST['host_name'] ?? '') ?: ('خادم ' . ($plan['name'] ?? ''));
        $ip = trim($_POST['host_ip'] ?? '');
        $username = trim($_POST['host_username'] ?? '');
        $password = trim($_POST['host_password'] ?? '');
        $expiry = trim($_POST['host_expiry'] ?? '') ?: date('Y-m-d', strtotime('+30 days'));

        if ($ip === '' || $username === '' || $password === '') {
            adminRedirect('orders', null, 'الرجاء تعبئة عنوان IP واسم المستخدم وكلمة المرور لتفعيل الاستضافة.');
        }

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO hosting (user_id, order_id, name, plan, ip, username, password, status, expiry_date) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$order['user_id'], $orderId, $hostName, $plan['name'] ?? '-', $ip, $username, $password, 'active', $expiry]);
        $pdo->prepare("UPDATE orders SET status = 'approved', decided_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE order_id = ?")->execute([$orderId]);
        $pdo->commit();

        adminRedirect('orders', 'تم قبول الطلب وتفعيل الاستضافة للمستخدم.');
    }

    if ($action === 'order_reject') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $pdo->prepare("UPDATE orders SET status = 'rejected', decided_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'")->execute([$orderId]);
        $pdo->prepare("UPDATE invoices SET status = 'rejected' WHERE order_id = ?")->execute([$orderId]);
        adminRedirect('orders', 'تم رفض الطلب.');
    }

    if ($action === 'topup_approve') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND status = 'pending' AND order_id IS NULL");
        $stmt->execute([$invId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$inv['amount'], $inv['user_id']]);
            $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = ?")->execute([$invId]);
            $pdo->commit();
        }
        adminRedirect('topups', 'تم تأكيد الشحن وإضافة الرصيد للمستخدم.');
    }

    if ($action === 'topup_reject') {
        $pdo->prepare("UPDATE invoices SET status = 'rejected' WHERE id = ? AND order_id IS NULL")->execute([(int)($_POST['invoice_id'] ?? 0)]);
        adminRedirect('topups', 'تم رفض طلب الشحن.');
    }

    adminRedirect($section);
}

// ============================================================
// إحصائيات سريعة
// ============================================================
$pendingOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$pendingTopupsCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'pending' AND order_id IS NULL")->fetchColumn();
$usersCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 0')->fetchColumn();
$activeHostingCount = (int)$pdo->query("SELECT COUNT(*) FROM hosting WHERE status = 'active'")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - استضافتي</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg-primary: #f7f4f0;
            --bg-secondary: #ffffff;
            --bg-card: #fbf7f3;
            --bg-card-hover: #fdeee0;
            --text-primary: #221a12;
            --text-secondary: #6b5d50;
            --text-muted: #998a7c;
            --accent: #ff7a1a;
            --accent-dark: #ee6a05;
            --accent-light: #ffa64d;
            --accent-glow: rgba(255,122,26,.12);
            --border-color: #f0e6da;
            --border-active: rgba(255,122,26,.3);
            --shadow: 0 10px 40px rgba(34,26,18,.08);
            --shadow-sm: 0 6px 20px rgba(34,26,18,.05);
            --radius: 18px;
            --radius-sm: 12px;
            --transition: .2s ease;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'IBM Plex Sans Arabic','Tajawal',system-ui,sans-serif; background:var(--bg-primary); color:var(--text-primary); padding-bottom:60px; }
        a { color:inherit; text-decoration:none; }
        .hidden { display:none !important; }

        .admin-header {
            display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
            padding: 16px 24px; background:var(--bg-secondary); border-bottom:1px solid var(--border-color);
            position:sticky; top:0; z-index:10;
        }
        .admin-header .brand { display:flex; align-items:center; gap:10px; font-weight:900; font-size:16px; }
        .admin-header .brand .logo-mark {
            width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--accent),var(--accent-dark));
            color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px;
        }
        .admin-header .right-links { display:flex; align-items:center; gap:14px; font-size:12px; color:var(--text-secondary); }
        .admin-header .right-links a:hover { color:var(--accent); }

        .admin-tabs {
            display:flex; gap:8px; padding: 14px 24px; overflow-x:auto; background:var(--bg-secondary); border-bottom:1px solid var(--border-color);
        }
        .admin-tab {
            display:flex; align-items:center; gap:6px; padding:9px 16px; border-radius:999px; font-size:13px; font-weight:700;
            background:var(--bg-card); color:var(--text-secondary); white-space:nowrap; position:relative;
        }
        .admin-tab.active { background:linear-gradient(135deg,var(--accent),var(--accent-dark)); color:#fff; }
        .admin-tab .tab-badge {
            background:#dc2626; color:#fff; border-radius:999px; font-size:10px; padding:1px 6px; margin-inline-start:4px;
        }

        .admin-container { max-width: 980px; margin:0 auto; padding: 20px; }

        .stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:20px; }
        .stat-tile { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:14px 10px; text-align:center; }
        .stat-tile .num { font-size:20px; font-weight:900; color:var(--accent); }
        .stat-tile .label { font-size:10px; color:var(--text-muted); margin-top:2px; }

        .flash-msg { background:rgba(34,197,94,.12); color:#16a34a; border:1px solid rgba(34,197,94,.3); border-radius:var(--radius-sm); padding:12px 16px; font-size:13px; margin-bottom:16px; }
        .flash-err { background:rgba(239,68,68,.1); color:#dc2626; border:1px solid rgba(239,68,68,.25); border-radius:var(--radius-sm); padding:12px 16px; font-size:13px; margin-bottom:16px; }

        .admin-card { background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:var(--radius); padding:20px; margin-bottom:16px; }
        .admin-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:8px; }
        .admin-card-header h2 { font-size:15px; font-weight:800; display:flex; align-items:center; gap:8px; }
        .admin-card-header h2 i { color:var(--accent); }

        .btn { display:inline-flex; align-items:center; gap:6px; padding:9px 16px; border-radius:var(--radius-sm); border:none; font-family:inherit; font-size:12px; font-weight:700; cursor:pointer; transition:var(--transition); }
        .btn-accent { background:linear-gradient(135deg,var(--accent),var(--accent-dark)); color:#fff; }
        .btn-accent:hover { transform:translateY(-1px); }
        .btn-outline { background:var(--bg-card); color:var(--text-secondary); border:1px solid var(--border-color); }
        .btn-outline:hover { border-color:var(--accent); color:var(--accent); }
        .btn-danger { background:rgba(239,68,68,.1); color:#dc2626; border:1px solid rgba(239,68,68,.25); }
        .btn-danger:hover { background:rgba(239,68,68,.18); }
        .btn-sm { padding:7px 12px; font-size:11px; }
        .btn-block { width:100%; justify-content:center; }

        .field-label { display:block; font-size:12px; font-weight:700; color:var(--text-secondary); margin-bottom:6px; }
        .field-row { margin-bottom:14px; }
        .field-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .text-input {
            width:100%; padding:11px 12px; border-radius:var(--radius-sm); border:1.5px solid var(--border-color);
            background:var(--bg-card); color:var(--text-primary); font-size:13px; font-family:inherit; outline:none; transition:var(--transition);
        }
        .text-input:focus { border-color:var(--accent); }
        textarea.text-input { resize:vertical; min-height:70px; }
        .checkbox-row { display:flex; align-items:center; gap:8px; font-size:12px; color:var(--text-secondary); margin-bottom:14px; }

        table.admin-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.admin-table th { text-align:right; padding:10px 8px; color:var(--text-muted); font-size:11px; border-bottom:1px solid var(--border-color); white-space:nowrap; }
        table.admin-table td { padding:12px 8px; border-bottom:1px solid var(--border-color); vertical-align:middle; }
        table.admin-table tr:last-child td { border-bottom:none; }
        .table-scroll { overflow-x:auto; }

        .pill { padding:2px 10px; border-radius:999px; font-size:10px; font-weight:700; display:inline-block; white-space:nowrap; }
        .pill-green { background:rgba(16,185,129,.12); color:#059669; }
        .pill-amber { background:rgba(251,191,36,.15); color:#b45309; }
        .pill-red { background:rgba(239,68,68,.12); color:#dc2626; }
        .pill-gray { background:rgba(107,93,80,.12); color:var(--text-secondary); }

        .order-card { border:1.5px solid var(--border-color); border-radius:var(--radius); padding:16px; margin-bottom:12px; }
        .order-card.pending { border-color: rgba(251,191,36,.5); background: rgba(251,191,36,.04); }
        .order-card-top { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
        .order-card-top .who { font-weight:800; font-size:14px; }
        .order-card-top .who span { display:block; font-weight:400; font-size:11px; color:var(--text-muted); margin-top:2px; }
        .order-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:8px; margin-bottom:12px; font-size:12px; }
        .order-meta div strong { display:block; font-size:13px; color:var(--text-primary); }
        .order-meta div span { color:var(--text-muted); font-size:11px; }
        .proof-thumb { width:70px; height:70px; object-fit:cover; border-radius:var(--radius-sm); border:1px solid var(--border-color); }
        .order-actions { display:flex; gap:8px; flex-wrap:wrap; }
        .fulfill-form { margin-top:14px; padding-top:14px; border-top:1.5px dashed var(--border-color); }

        .pm-logo-preview, .plan-icon-preview { font-size:28px; }
        .pm-row-icon { width:40px; height:40px; border-radius:50%; background:var(--accent-glow); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:16px; overflow:hidden; }
        .pm-row-icon img { width:100%; height:100%; object-fit:cover; }

        @media (max-width: 640px) {
            .stats-row { grid-template-columns:repeat(2,1fr); }
            .field-grid-2 { grid-template-columns:1fr; }
            .admin-container { padding:14px; }
        }
    </style>
</head>
<body>
    <header class="admin-header">
        <div class="brand"><div class="logo-mark"><i class="fas fa-server"></i></div> لوحة تحكم استضافتي</div>
        <div class="right-links">
            <span><i class="fas fa-user-shield"></i> <?php echo e($admin['name']); ?></span>
            <a href="index.php" target="_blank"><i class="fas fa-arrow-up-left-from-circle"></i> عرض الموقع</a>
            <a href="index.php?logout=1"><i class="fas fa-right-from-bracket"></i> تسجيل الخروج</a>
        </div>
    </header>

    <nav class="admin-tabs">
        <a class="admin-tab <?php echo $section === 'orders' ? 'active' : ''; ?>" href="admin.php?section=orders">
            <i class="fas fa-clipboard-list"></i> الطلبات
            <?php if ($pendingOrdersCount): ?><span class="tab-badge"><?php echo $pendingOrdersCount; ?></span><?php endif; ?>
        </a>
        <a class="admin-tab <?php echo $section === 'topups' ? 'active' : ''; ?>" href="admin.php?section=topups">
            <i class="fas fa-wallet"></i> شحن الرصيد
            <?php if ($pendingTopupsCount): ?><span class="tab-badge"><?php echo $pendingTopupsCount; ?></span><?php endif; ?>
        </a>
        <a class="admin-tab <?php echo $section === 'plans' ? 'active' : ''; ?>" href="admin.php?section=plans"><i class="fas fa-server"></i> الباقات</a>
        <a class="admin-tab <?php echo $section === 'payments' ? 'active' : ''; ?>" href="admin.php?section=payments"><i class="fas fa-credit-card"></i> طرق الدفع</a>
    </nav>

    <div class="admin-container">
        <div class="stats-row">
            <div class="stat-tile"><div class="num"><?php echo $pendingOrdersCount; ?></div><div class="label">طلبات قيد المراجعة</div></div>
            <div class="stat-tile"><div class="num"><?php echo $pendingTopupsCount; ?></div><div class="label">طلبات شحن معلقة</div></div>
            <div class="stat-tile"><div class="num"><?php echo $usersCount; ?></div><div class="label">إجمالي المستخدمين</div></div>
            <div class="stat-tile"><div class="num"><?php echo $activeHostingCount; ?></div><div class="label">استضافات نشطة</div></div>
        </div>

        <?php if (!empty($_GET['msg'])): ?><div class="flash-msg"><i class="fas fa-circle-check"></i> <?php echo e($_GET['msg']); ?></div><?php endif; ?>
        <?php if (!empty($_GET['err'])): ?><div class="flash-err"><i class="fas fa-triangle-exclamation"></i> <?php echo e($_GET['err']); ?></div><?php endif; ?>

        <?php
        if ($section === 'plans') {
            renderAdminPlans($pdo);
        } elseif ($section === 'payments') {
            renderAdminPayments($pdo);
        } elseif ($section === 'topups') {
            renderAdminTopups($pdo);
        } else {
            renderAdminOrders($pdo);
        }
        ?>
    </div>

    <script>
        function confirmAndSubmit(form, message) {
            if (confirm(message)) form.submit();
            return false;
        }
        function toggleFulfillForm(orderId) {
            const el = document.getElementById('fulfill-' + orderId);
            el.classList.toggle('hidden');
        }
    </script>
</body>
</html>
<?php

// ============================================================
// قسم: الطلبات
// ============================================================
function renderAdminOrders(PDO $pdo) {
    $orders = $pdo->query("
        SELECT o.*, u.name AS user_name, u.phone AS user_phone, u.email AS user_email,
               p.name AS plan_name, p.icon AS plan_icon,
               pm.name AS pm_name
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN vps_plans p ON p.id = o.plan_id
        LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
        ORDER BY (o.status = 'pending') DESC, o.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-clipboard-list"></i> سجل الطلبات</h2></div>

        <?php if (!$orders): ?>
            <p style="color:var(--text-muted);font-size:13px;text-align:center;padding:24px 0">لا توجد طلبات بعد.</p>
        <?php endif; ?>

        <?php foreach ($orders as $o):
            $statusLabel = ['pending' => '⏳ قيد المراجعة', 'approved' => '✅ مقبول', 'rejected' => '❌ مرفوض'][$o['status']] ?? $o['status'];
            $statusPill = ['pending' => 'pill-amber', 'approved' => 'pill-green', 'rejected' => 'pill-red'][$o['status']] ?? 'pill-gray';
        ?>
        <div class="order-card <?php echo $o['status'] === 'pending' ? 'pending' : ''; ?>">
            <div class="order-card-top">
                <div class="who">
                    <?php echo e($o['user_name']); ?>
                    <span><?php echo e($o['user_email'] ?: $o['user_phone']); ?></span>
                </div>
                <span class="pill <?php echo $statusPill; ?>"><?php echo $statusLabel; ?></span>
            </div>

            <div class="order-meta">
                <div><strong><?php echo $o['plan_icon']; ?> <?php echo e($o['plan_name']); ?></strong><span>الباقة</span></div>
                <div><strong>$<?php echo money($o['amount']); ?></strong><span>المبلغ</span></div>
                <div><strong><?php echo e($o['pm_name'] ?: 'رصيد الحساب'); ?></strong><span>طريقة الدفع</span></div>
                <div><strong><?php echo e(substr($o['created_at'], 0, 16)); ?></strong><span>تاريخ الطلب</span></div>
            </div>

            <?php if ($o['proof_image']): ?>
                <a href="<?php echo e($o['proof_image']); ?>" target="_blank" title="عرض إيصال التحويل">
                    <img src="<?php echo e($o['proof_image']); ?>" class="proof-thumb" alt="إيصال التحويل">
                </a>
            <?php endif; ?>

            <?php if ($o['status'] === 'pending'): ?>
            <div class="order-actions" style="margin-top:12px">
                <button type="button" class="btn btn-accent btn-sm" onclick="toggleFulfillForm(<?php echo (int)$o['id']; ?>)"><i class="fas fa-check"></i> قبول وتفعيل الاستضافة</button>
                <form method="POST" action="admin.php?section=orders" style="display:inline" onsubmit="return confirmAndSubmit(this, 'هل أنت متأكد من رفض هذا الطلب؟')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="order_reject">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-xmark"></i> رفض</button>
                </form>
            </div>

            <div class="fulfill-form hidden" id="fulfill-<?php echo (int)$o['id']; ?>">
                <form method="POST" action="admin.php?section=orders">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="order_fulfill">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">

                    <div class="field-row">
                        <label class="field-label">اسم الاستضافة (اختياري)</label>
                        <input type="text" name="host_name" class="text-input" placeholder="خادم <?php echo e($o['plan_name']); ?> - <?php echo e($o['user_name']); ?>">
                    </div>
                    <div class="field-grid-2">
                        <div class="field-row">
                            <label class="field-label">عنوان IP</label>
                            <input type="text" name="host_ip" class="text-input" placeholder="192.168.1.100" required dir="ltr">
                        </div>
                        <div class="field-row">
                            <label class="field-label">تاريخ الانتهاء</label>
                            <input type="date" name="host_expiry" class="text-input" value="<?php echo e(date('Y-m-d', strtotime('+30 days'))); ?>">
                        </div>
                        <div class="field-row">
                            <label class="field-label">اسم المستخدم</label>
                            <input type="text" name="host_username" class="text-input" placeholder="root" required dir="ltr">
                        </div>
                        <div class="field-row">
                            <label class="field-label">كلمة المرور</label>
                            <input type="text" name="host_password" class="text-input" placeholder="كلمة مرور قوية" required dir="ltr">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-accent btn-block"><i class="fas fa-server"></i> تفعيل الاستضافة وإرسالها للمستخدم</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

// ============================================================
// قسم: شحن الرصيد
// ============================================================
function renderAdminTopups(PDO $pdo) {
    $invoices = $pdo->query("
        SELECT i.*, u.name AS user_name, u.phone AS user_phone, u.email AS user_email
        FROM invoices i JOIN users u ON u.id = i.user_id
        WHERE i.order_id IS NULL
        ORDER BY (i.status = 'pending') DESC, i.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-wallet"></i> طلبات شحن الرصيد</h2></div>

        <?php if (!$invoices): ?>
            <p style="color:var(--text-muted);font-size:13px;text-align:center;padding:24px 0">لا توجد طلبات شحن حتى الآن.</p>
        <?php endif; ?>

        <?php foreach ($invoices as $inv):
            $statusLabel = ['pending' => '⏳ قيد المراجعة', 'paid' => '✅ تم الشحن', 'rejected' => '❌ مرفوض'][$inv['status']] ?? $inv['status'];
            $statusPill = ['pending' => 'pill-amber', 'paid' => 'pill-green', 'rejected' => 'pill-red'][$inv['status']] ?? 'pill-gray';
        ?>
        <div class="order-card <?php echo $inv['status'] === 'pending' ? 'pending' : ''; ?>">
            <div class="order-card-top">
                <div class="who">
                    <?php echo e($inv['user_name']); ?>
                    <span><?php echo e($inv['user_email'] ?: $inv['user_phone']); ?></span>
                </div>
                <span class="pill <?php echo $statusPill; ?>"><?php echo $statusLabel; ?></span>
            </div>
            <div class="order-meta">
                <div><strong>$<?php echo money($inv['amount']); ?></strong><span>المبلغ</span></div>
                <div><strong><?php echo e(substr($inv['created_at'], 0, 16)); ?></strong><span>تاريخ الطلب</span></div>
            </div>
            <?php if ($inv['status'] === 'pending'): ?>
            <div class="order-actions" style="margin-top:12px">
                <form method="POST" action="admin.php?section=topups" style="display:inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="topup_approve">
                    <input type="hidden" name="invoice_id" value="<?php echo (int)$inv['id']; ?>">
                    <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-check"></i> تأكيد الاستلام وإضافة الرصيد</button>
                </form>
                <form method="POST" action="admin.php?section=topups" style="display:inline" onsubmit="return confirmAndSubmit(this, 'هل أنت متأكد من رفض طلب الشحن؟')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="topup_reject">
                    <input type="hidden" name="invoice_id" value="<?php echo (int)$inv['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-xmark"></i> رفض</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

// ============================================================
// قسم: الباقات
// ============================================================
function renderAdminPlans(PDO $pdo) {
    $plans = $pdo->query('SELECT * FROM vps_plans ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-plus"></i> إضافة باقة جديدة</h2></div>
        <form method="POST" action="admin.php?section=plans">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="plan_save">
            <input type="hidden" name="id" value="0">
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">اسم الباقة</label><input type="text" name="name" class="text-input" required></div>
                <div class="field-row"><label class="field-label">أيقونة (إيموجي)</label><input type="text" name="icon" class="text-input" value="🚀"></div>
                <div class="field-row"><label class="field-label">المعالج (CPU)</label><input type="text" name="cpu" class="text-input" placeholder="2 Core" required></div>
                <div class="field-row"><label class="field-label">الذاكرة (RAM)</label><input type="text" name="ram" class="text-input" placeholder="4 GB" required></div>
                <div class="field-row"><label class="field-label">التخزين</label><input type="text" name="storage" class="text-input" placeholder="100 GB SSD" required></div>
                <div class="field-row"><label class="field-label">الباندويث</label><input type="text" name="bandwidth" class="text-input" placeholder="2 TB" required></div>
                <div class="field-row"><label class="field-label">السعر الشهري ($)</label><input type="number" step="0.01" min="0.01" name="price" class="text-input" required></div>
                <div class="field-row"><label class="field-label">شارة (اختياري)</label><input type="text" name="badge" class="text-input" placeholder="🔥 الأكثر طلباً"></div>
                <div class="field-row"><label class="field-label">ترتيب العرض</label><input type="number" name="sort_order" class="text-input" value="0"></div>
            </div>
            <div class="checkbox-row"><input type="checkbox" name="is_active" id="newPlanActive" checked><label for="newPlanActive">مفعّلة وتظهر للمستخدمين</label></div>
            <button type="submit" class="btn btn-accent"><i class="fas fa-plus"></i> إضافة الباقة</button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-server"></i> الباقات الحالية (<?php echo count($plans); ?>)</h2></div>
        <?php foreach ($plans as $plan): ?>
        <details style="border-bottom:1px solid var(--border-color);padding:10px 0">
            <summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;list-style:none">
                <span><span class="plan-icon-preview"><?php echo $plan['icon']; ?></span> <strong><?php echo e($plan['name']); ?></strong> — $<?php echo money($plan['price']); ?>/شهر</span>
                <span class="pill <?php echo $plan['is_active'] ? 'pill-green' : 'pill-gray'; ?>"><?php echo $plan['is_active'] ? 'مفعّلة' : 'موقوفة'; ?></span>
            </summary>
            <form method="POST" action="admin.php?section=plans" style="margin-top:14px">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="plan_save">
                <input type="hidden" name="id" value="<?php echo (int)$plan['id']; ?>">
                <div class="field-grid-2">
                    <div class="field-row"><label class="field-label">اسم الباقة</label><input type="text" name="name" class="text-input" value="<?php echo e($plan['name']); ?>" required></div>
                    <div class="field-row"><label class="field-label">أيقونة (إيموجي)</label><input type="text" name="icon" class="text-input" value="<?php echo e($plan['icon']); ?>"></div>
                    <div class="field-row"><label class="field-label">المعالج (CPU)</label><input type="text" name="cpu" class="text-input" value="<?php echo e($plan['cpu']); ?>" required></div>
                    <div class="field-row"><label class="field-label">الذاكرة (RAM)</label><input type="text" name="ram" class="text-input" value="<?php echo e($plan['ram']); ?>" required></div>
                    <div class="field-row"><label class="field-label">التخزين</label><input type="text" name="storage" class="text-input" value="<?php echo e($plan['storage']); ?>" required></div>
                    <div class="field-row"><label class="field-label">الباندويث</label><input type="text" name="bandwidth" class="text-input" value="<?php echo e($plan['bandwidth']); ?>" required></div>
                    <div class="field-row"><label class="field-label">السعر الشهري ($)</label><input type="number" step="0.01" min="0.01" name="price" class="text-input" value="<?php echo e($plan['price']); ?>" required></div>
                    <div class="field-row"><label class="field-label">شارة (اختياري)</label><input type="text" name="badge" class="text-input" value="<?php echo e($plan['badge']); ?>"></div>
                    <div class="field-row"><label class="field-label">ترتيب العرض</label><input type="number" name="sort_order" class="text-input" value="<?php echo (int)$plan['sort_order']; ?>"></div>
                </div>
                <div class="checkbox-row"><input type="checkbox" name="is_active" id="planActive<?php echo (int)$plan['id']; ?>" <?php echo $plan['is_active'] ? 'checked' : ''; ?>><label for="planActive<?php echo (int)$plan['id']; ?>">مفعّلة وتظهر للمستخدمين</label></div>
                <div class="order-actions">
                    <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-floppy-disk"></i> حفظ التعديلات</button>
                </div>
            </form>
            <form method="POST" action="admin.php?section=plans" style="margin-top:8px" onsubmit="return confirmAndSubmit(this, 'حذف هذه الباقة نهائياً؟')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="plan_delete">
                <input type="hidden" name="id" value="<?php echo (int)$plan['id']; ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> حذف الباقة</button>
            </form>
        </details>
        <?php endforeach; ?>
    </div>
    <?php
}

// ============================================================
// قسم: طرق الدفع
// ============================================================
function renderAdminPayments(PDO $pdo) {
    $methods = $pdo->query('SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-plus"></i> إضافة طريقة دفع جديدة</h2></div>
        <form method="POST" action="admin.php?section=payments" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="pm_save">
            <input type="hidden" name="id" value="0">
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">اسم طريقة الدفع</label><input type="text" name="name" class="text-input" placeholder="زين كاش" required></div>
                <div class="field-row"><label class="field-label">أيقونة FontAwesome (اختياري)</label><input type="text" name="icon" class="text-input" placeholder="fa-mobile-screen" dir="ltr"></div>
                <div class="field-row"><label class="field-label">رقم الحساب / التحويل</label><input type="text" name="account_number" class="text-input" placeholder="07xxxxxxxxx" dir="ltr"></div>
                <div class="field-row"><label class="field-label">ترتيب العرض</label><input type="number" name="sort_order" class="text-input" value="0"></div>
            </div>
            <div class="field-row"><label class="field-label">تعليمات الدفع</label><textarea name="instructions" class="text-input" placeholder="حوّل المبلغ إلى الرقم أعلاه ثم ارفع صورة الإيصال."></textarea></div>
            <div class="field-row"><label class="field-label">شعار (صورة، اختياري)</label><input type="file" name="logo" class="text-input" accept="image/png,image/jpeg,image/webp"></div>
            <div class="checkbox-row"><input type="checkbox" name="is_active" id="newPmActive" checked><label for="newPmActive">مفعّلة وتظهر للمستخدمين</label></div>
            <button type="submit" class="btn btn-accent"><i class="fas fa-plus"></i> إضافة طريقة الدفع</button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-credit-card"></i> طرق الدفع الحالية (<?php echo count($methods); ?>)</h2></div>
        <?php foreach ($methods as $pm): ?>
        <details style="border-bottom:1px solid var(--border-color);padding:10px 0">
            <summary style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;list-style:none;gap:10px">
                <span style="display:flex;align-items:center;gap:10px">
                    <span class="pm-row-icon">
                        <?php if ($pm['logo_path']): ?><img src="<?php echo e($pm['logo_path']); ?>" alt=""><?php else: ?><i class="fas <?php echo e($pm['icon']); ?>"></i><?php endif; ?>
                    </span>
                    <strong><?php echo e($pm['name']); ?></strong>
                </span>
                <span class="pill <?php echo $pm['is_active'] ? 'pill-green' : 'pill-gray'; ?>"><?php echo $pm['is_active'] ? 'مفعّلة' : 'موقوفة'; ?></span>
            </summary>
            <form method="POST" action="admin.php?section=payments" enctype="multipart/form-data" style="margin-top:14px">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="pm_save">
                <input type="hidden" name="id" value="<?php echo (int)$pm['id']; ?>">
                <div class="field-grid-2">
                    <div class="field-row"><label class="field-label">اسم طريقة الدفع</label><input type="text" name="name" class="text-input" value="<?php echo e($pm['name']); ?>" required></div>
                    <div class="field-row"><label class="field-label">أيقونة FontAwesome</label><input type="text" name="icon" class="text-input" value="<?php echo e($pm['icon']); ?>" dir="ltr"></div>
                    <div class="field-row"><label class="field-label">رقم الحساب / التحويل</label><input type="text" name="account_number" class="text-input" value="<?php echo e($pm['account_number']); ?>" dir="ltr"></div>
                    <div class="field-row"><label class="field-label">ترتيب العرض</label><input type="number" name="sort_order" class="text-input" value="<?php echo (int)$pm['sort_order']; ?>"></div>
                </div>
                <div class="field-row"><label class="field-label">تعليمات الدفع</label><textarea name="instructions" class="text-input"><?php echo e($pm['instructions']); ?></textarea></div>
                <div class="field-row"><label class="field-label">تغيير الشعار (اختياري)</label><input type="file" name="logo" class="text-input" accept="image/png,image/jpeg,image/webp"></div>
                <div class="checkbox-row"><input type="checkbox" name="is_active" id="pmActive<?php echo (int)$pm['id']; ?>" <?php echo $pm['is_active'] ? 'checked' : ''; ?>><label for="pmActive<?php echo (int)$pm['id']; ?>">مفعّلة وتظهر للمستخدمين</label></div>
                <div class="order-actions">
                    <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-floppy-disk"></i> حفظ التعديلات</button>
                </div>
            </form>
            <form method="POST" action="admin.php?section=payments" style="margin-top:8px" onsubmit="return confirmAndSubmit(this, 'حذف طريقة الدفع هذه نهائياً؟')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="pm_delete">
                <input type="hidden" name="id" value="<?php echo (int)$pm['id']; ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> حذف</button>
            </form>
        </details>
        <?php endforeach; ?>
    </div>
    <?php
}
