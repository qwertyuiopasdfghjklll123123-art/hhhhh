<?php
// ============================================================
// لوحة تحكم الأدمن - استضافتي
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
requireAdmin($pdo);

$admin = currentUser($pdo);
$section = $_GET['section'] ?? 'orders';
if (!in_array($section, ['orders', 'topups', 'plans', 'payments', 'settings'], true)) {
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
        $originalPriceRaw = trim($_POST['original_price'] ?? '');
        $originalPrice = $originalPriceRaw === '' ? null : (float)$originalPriceRaw;
        $yearlyPriceRaw = trim($_POST['price_yearly'] ?? '');
        $yearlyPrice = $yearlyPriceRaw === '' ? null : (float)$yearlyPriceRaw;
        $badge = trim($_POST['badge'] ?? '') ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '' || $cpu === '' || $ram === '' || $storage === '' || $bandwidth === '' || $price <= 0) {
            adminRedirect('plans', null, 'الرجاء تعبئة جميع الحقول المطلوبة (السعر يجب أن يكون أكبر من صفر).');
        }
        if ($originalPrice !== null && $originalPrice <= $price) {
            $originalPrice = null;
        }
        if ($yearlyPrice !== null && $yearlyPrice <= 0) {
            $yearlyPrice = null;
        }

        $previousOriginalPrice = null;
        if ($id > 0) {
            $prevStmt = $pdo->prepare('SELECT original_price FROM vps_plans WHERE id = ?');
            $prevStmt->execute([$id]);
            $previousOriginalPrice = $prevStmt->fetchColumn();
            $previousOriginalPrice = $previousOriginalPrice !== false && $previousOriginalPrice !== null ? (float)$previousOriginalPrice : null;

            $pdo->prepare('UPDATE vps_plans SET name=?, icon=?, cpu=?, ram=?, storage=?, bandwidth=?, price=?, original_price=?, price_yearly=?, badge=?, is_active=?, sort_order=? WHERE id=?')
                ->execute([$name, $icon, $cpu, $ram, $storage, $bandwidth, $price, $originalPrice, $yearlyPrice, $badge, $isActive, $sortOrder, $id]);
        } else {
            $pdo->prepare('INSERT INTO vps_plans (name, icon, cpu, ram, storage, bandwidth, price, original_price, price_yearly, badge, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $icon, $cpu, $ram, $storage, $bandwidth, $price, $originalPrice, $yearlyPrice, $badge, $isActive, $sortOrder]);
        }

        if ($isActive && $originalPrice !== null && $originalPrice !== $previousOriginalPrice) {
            $discountPct = (int)round((($originalPrice - $price) / $originalPrice) * 100);
            $userIds = $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($userIds as $uid) {
                notifyUser($pdo, (int)$uid, '🔥 خصم جديد!', 'احصل الآن على خصم ' . $discountPct . '% على باقة "' . $name . '" - بسعر $' . money($price) . ' بدلاً من $' . money($originalPrice) . '.', 'system');
            }
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
        $currencyCode = trim($_POST['currency_code'] ?? '') ?: 'USD';
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
                $pdo->prepare('UPDATE payment_methods SET name=?, icon=?, account_number=?, instructions=?, currency_code=?, is_active=?, sort_order=?, logo_path=? WHERE id=?')
                    ->execute([$name, $icon, $account, $instructions, $currencyCode, $isActive, $sortOrder, $logoPath, $id]);
            } else {
                $pdo->prepare('UPDATE payment_methods SET name=?, icon=?, account_number=?, instructions=?, currency_code=?, is_active=?, sort_order=? WHERE id=?')
                    ->execute([$name, $icon, $account, $instructions, $currencyCode, $isActive, $sortOrder, $id]);
            }
        } else {
            $pdo->prepare('INSERT INTO payment_methods (name, icon, account_number, instructions, currency_code, is_active, sort_order, logo_path) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$name, $icon, $account, $instructions, $currencyCode, $isActive, $sortOrder, $logoPath]);
        }
        adminRedirect('payments', 'تم حفظ طريقة الدفع بنجاح.');
    }

    if ($action === 'pm_delete') {
        $pdo->prepare('DELETE FROM payment_methods WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        adminRedirect('payments', 'تم حذف طريقة الدفع.');
    }

    if ($action === 'currency_save') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $symbol = trim($_POST['symbol'] ?? '');
        $rate = (float)($_POST['rate_per_usd'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (!preg_match('/^[A-Z]{3}$/', $code) || $name === '' || $symbol === '' || $rate <= 0) {
            adminRedirect('settings', null, 'رمز العملة يجب أن يكون 3 أحرف (مثل USD)، مع اسم ورمز وسعر صرف أكبر من صفر.');
        }

        $pdo->prepare('INSERT INTO currencies (code, name, symbol, rate_per_usd, is_active, sort_order) VALUES (?,?,?,?,?,?)
            ON CONFLICT(code) DO UPDATE SET name = excluded.name, symbol = excluded.symbol, rate_per_usd = excluded.rate_per_usd, is_active = excluded.is_active, sort_order = excluded.sort_order')
            ->execute([$code, $name, $symbol, $rate, $isActive, $sortOrder]);
        adminRedirect('settings', 'تم حفظ العملة بنجاح.');
    }

    if ($action === 'currency_delete') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        if ($code === 'USD') {
            adminRedirect('settings', null, 'لا يمكن حذف الدولار الأمريكي، فهو العملة الأساسية للأسعار.');
        }
        $pdo->prepare('DELETE FROM currencies WHERE code = ?')->execute([$code]);
        adminRedirect('settings', 'تم حذف العملة.');
    }

    if ($action === 'broadcast_notification') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if ($title === '') {
            adminRedirect('settings', null, 'الرجاء إدخال عنوان الإشعار.');
        }
        $userIds = $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($userIds as $uid) {
            notifyUser($pdo, (int)$uid, $title, $body, 'system');
        }
        adminRedirect('settings', 'تم إرسال الإشعار إلى ' . count($userIds) . ' مستخدم.');
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
        $expiryInterval = $order['billing_cycle'] === 'yearly' ? '+1 year' : '+1 month';
        $expiry = date('Y-m-d', strtotime($expiryInterval));

        if ($ip === '' || $username === '' || $password === '') {
            adminRedirect('orders', null, 'الرجاء تعبئة عنوان IP واسم المستخدم وكلمة المرور لتفعيل الاستضافة.');
        }

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO hosting (user_id, order_id, name, plan, ip, username, password, status, expiry_date) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$order['user_id'], $orderId, $hostName, $plan['name'] ?? '-', $ip, $username, $password, 'active', $expiry]);
        $pdo->prepare("UPDATE orders SET status = 'approved', decided_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE order_id = ?")->execute([$orderId]);
        notifyUser($pdo, $order['user_id'], '✅ تم قبول طلبك', 'تم تفعيل استضافتك (' . $hostName . ') وهي جاهزة الآن ضمن "سيرفراتي".', 'order_approved');
        $pdo->commit();

        adminRedirect('orders', 'تم قبول الطلب وتفعيل الاستضافة للمستخدم.');
    }

    if ($action === 'order_reject') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            $pdo->prepare("UPDATE orders SET status = 'rejected', decided_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE invoices SET status = 'rejected' WHERE order_id = ?")->execute([$orderId]);
            notifyUser($pdo, $order['user_id'], '❌ تم رفض طلبك', 'تم رفض طلب الاشتراك. تواصل مع الدعم الفني لمعرفة السبب.', 'order_rejected');
        }
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
            notifyUser($pdo, $inv['user_id'], '💰 تم شحن رصيدك', 'تم إضافة $' . money($inv['amount']) . ' إلى رصيد حسابك.', 'topup_approved');
            $pdo->commit();
        }
        adminRedirect('topups', 'تم تأكيد الشحن وإضافة الرصيد للمستخدم.');
    }

    if ($action === 'topup_reject') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND status = 'pending' AND order_id IS NULL");
        $stmt->execute([$invId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inv) {
            $pdo->prepare("UPDATE invoices SET status = 'rejected' WHERE id = ?")->execute([$invId]);
            notifyUser($pdo, $inv['user_id'], '❌ تم رفض طلب الشحن', 'لم نتمكن من تأكيد إيصال التحويل الخاص بك. تواصل مع الدعم الفني.', 'topup_rejected');
        }
        adminRedirect('topups', 'تم رفض طلب الشحن.');
    }

    if ($action === 'settings_save') {
        setSetting($pdo, 'site_name', trim($_POST['site_name'] ?? '') ?: 'استضافتي');
        setSetting($pdo, 'site_tagline', trim($_POST['site_tagline'] ?? ''));
        setSetting($pdo, 'nvidia_api_key', trim($_POST['nvidia_api_key'] ?? ''));
        setSetting($pdo, 'nvidia_model', trim($_POST['nvidia_model'] ?? '') ?: 'openai/gpt-oss-120b');
        setSetting($pdo, 'google_client_id', trim($_POST['google_client_id'] ?? ''));
        if (trim($_POST['google_client_secret'] ?? '') !== '') {
            setSetting($pdo, 'google_client_secret', trim($_POST['google_client_secret']));
        }
        setSetting($pdo, 'app_currency', trim($_POST['app_currency'] ?? ''));

        [$logoPath, $uploadErr] = handleImageUpload('site_logo', LOGOS_DIR, 'uploads/logos');
        if ($uploadErr) {
            adminRedirect('settings', null, $uploadErr);
        }
        if ($logoPath) {
            setSetting($pdo, 'site_logo', $logoPath);
        }

        adminRedirect('settings', 'تم حفظ الإعدادات بنجاح.');
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
        <a class="admin-tab <?php echo $section === 'settings' ? 'active' : ''; ?>" href="admin.php?section=settings"><i class="fas fa-gear"></i> الإعدادات</a>
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
        } elseif ($section === 'settings') {
            renderAdminSettings($pdo);
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
                    <?php echo e($o['user_name']); ?> <span style="color:var(--text-muted);font-weight:600">#<?php echo (int)$o['id']; ?></span>
                    <span><?php echo e($o['user_email'] ?: $o['user_phone']); ?></span>
                </div>
                <span class="pill <?php echo $statusPill; ?>"><?php echo $statusLabel; ?></span>
            </div>

            <div class="order-meta">
                <div><strong><?php echo $o['plan_icon']; ?> <?php echo e($o['plan_name']); ?></strong><span>الباقة</span></div>
                <div><strong>$<?php echo money($o['amount']); ?></strong><span>المبلغ</span></div>
                <div><strong><?php echo $o['billing_cycle'] === 'yearly' ? 'سنوي' : 'شهري'; ?></strong><span>مدة الاشتراك</span></div>
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
                            <label class="field-label">اسم المستخدم</label>
                            <input type="text" name="host_username" class="text-input" placeholder="root" required dir="ltr">
                        </div>
                        <div class="field-row">
                            <label class="field-label">كلمة المرور</label>
                            <input type="text" name="host_password" class="text-input" placeholder="كلمة مرور قوية" required dir="ltr">
                        </div>
                    </div>
                    <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px">
                        <i class="fas fa-circle-info"></i>
                        مدة الاشتراك <?php echo $o['billing_cycle'] === 'yearly' ? 'سنوية' : 'شهرية'; ?> (كما اختارها المستخدم عند الطلب)،
                        وسيُحسب تاريخ الانتهاء تلقائياً: <?php echo e(date('Y-m-d', strtotime($o['billing_cycle'] === 'yearly' ? '+1 year' : '+1 month'))); ?>.
                    </p>
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
                    <?php echo e($inv['user_name']); ?> <span style="color:var(--text-muted);font-weight:600">#<?php echo (int)$inv['id']; ?></span>
                    <span><?php echo e($inv['user_email'] ?: $inv['user_phone']); ?></span>
                </div>
                <span class="pill <?php echo $statusPill; ?>"><?php echo $statusLabel; ?></span>
            </div>
            <div class="order-meta">
                <div><strong>$<?php echo money($inv['amount']); ?></strong><span>المبلغ</span></div>
                <div><strong><?php echo e($inv['invoice_number']); ?></strong><span>رقم الفاتورة</span></div>
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
                <div class="field-row"><label class="field-label">السعر قبل الخصم (اختياري)</label><input type="number" step="0.01" min="0.01" name="original_price" class="text-input" placeholder="اتركه فارغاً بدون خصم"></div>
                <div class="field-row"><label class="field-label">السعر السنوي (اختياري)</label><input type="number" step="0.01" min="0.01" name="price_yearly" class="text-input" placeholder="اتركه فارغاً لإخفاء خيار الاشتراك السنوي"></div>
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
                <span>
                    <span class="plan-icon-preview"><?php echo $plan['icon']; ?></span> <strong><?php echo e($plan['name']); ?></strong> —
                    <?php if (!empty($plan['original_price'])): ?>
                        <s class="text-muted">$<?php echo money($plan['original_price']); ?></s>
                    <?php endif; ?>
                    $<?php echo money($plan['price']); ?>/شهر
                </span>
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
                    <div class="field-row"><label class="field-label">السعر قبل الخصم (اختياري)</label><input type="number" step="0.01" min="0.01" name="original_price" class="text-input" value="<?php echo e($plan['original_price'] ?? ''); ?>" placeholder="اتركه فارغاً بدون خصم"></div>
                    <div class="field-row"><label class="field-label">السعر السنوي (اختياري)</label><input type="number" step="0.01" min="0.01" name="price_yearly" class="text-input" value="<?php echo e($plan['price_yearly'] ?? ''); ?>" placeholder="اتركه فارغاً لإخفاء خيار الاشتراك السنوي"></div>
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
    $currencies = getAllCurrencies($pdo);
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
                <div class="field-row">
                    <label class="field-label">العملة التي تستقبل بها الدفع</label>
                    <select name="currency_code" class="text-input">
                        <?php foreach ($currencies as $c): ?>
                        <option value="<?php echo e($c['code']); ?>" <?php echo $c['code'] === 'USD' ? 'selected' : ''; ?>><?php echo e($c['name']); ?> (<?php echo e($c['symbol']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                    <div class="field-row">
                        <label class="field-label">العملة التي تستقبل بها الدفع</label>
                        <select name="currency_code" class="text-input">
                            <?php foreach ($currencies as $c): ?>
                            <option value="<?php echo e($c['code']); ?>" <?php echo $c['code'] === $pm['currency_code'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?> (<?php echo e($c['symbol']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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

// ============================================================
// قسم: الإعدادات (اسم الموقع، الشعار، مفاتيح الذكاء الاصطناعي، Google OAuth)
// ============================================================
function renderAdminSettings(PDO $pdo) {
    $s = getAllSettings($pdo);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $redirectUri = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/index.php?action=google_callback';
    $currencies = getAllCurrencies($pdo);
    ?>
    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-shop"></i> اسم الموقع والشعار</h2></div>
        <form method="POST" action="admin.php?section=settings" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="settings_save">
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">اسم الموقع</label><input type="text" name="site_name" class="text-input" value="<?php echo e($s['site_name'] ?? ''); ?>" required></div>
                <div class="field-row"><label class="field-label">الشعار النصي (Tagline)</label><input type="text" name="site_tagline" class="text-input" value="<?php echo e($s['site_tagline'] ?? ''); ?>"></div>
            </div>
            <div class="field-row">
                <label class="field-label">شعار الموقع (صورة)</label>
                <?php if (!empty($s['site_logo'])): ?>
                    <div style="margin-bottom:8px"><img src="<?php echo e($s['site_logo']); ?>" alt="" style="width:56px;height:56px;border-radius:14px;object-fit:cover;border:1px solid var(--border-color)"></div>
                <?php endif; ?>
                <input type="file" name="site_logo" class="text-input" accept="image/png,image/jpeg,image/webp">
            </div>
            <div class="field-row">
                <label class="field-label">عملة عرض الأسعار</label>
                <select name="app_currency" class="text-input">
                    <option value="">تلقائي حسب بلد الزائر</option>
                    <?php foreach ($currencies as $c): ?>
                    <option value="<?php echo e($c['code']); ?>" <?php echo ($s['app_currency'] ?? '') === $c['code'] ? 'selected' : ''; ?>><?php echo e($c['name']); ?> (<?php echo e($c['symbol']); ?>) - دائماً</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="admin-card-header" style="margin-top:20px"><h2><i class="fas fa-robot"></i> المساعد الذكي (NVIDIA API)</h2></div>
            <div class="field-row"><label class="field-label">مفتاح API</label><input type="text" name="nvidia_api_key" class="text-input" value="<?php echo e($s['nvidia_api_key'] ?? ''); ?>" dir="ltr" placeholder="nvapi-..."></div>
            <div class="field-row"><label class="field-label">اسم النموذج (Model)</label><input type="text" name="nvidia_model" class="text-input" value="<?php echo e($s['nvidia_model'] ?? ''); ?>" dir="ltr"></div>

            <div class="admin-card-header" style="margin-top:20px"><h2><i class="fab fa-google"></i> تسجيل الدخول عبر Google</h2></div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.8">
                أنشئ OAuth Client ID من
                <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--accent);font-weight:700">Google Cloud Console</a>
                من نوع "Web application"، وأضف رابط إعادة التوجيه التالي بالضبط ضمن Authorized redirect URIs:
            </p>
            <div class="text-input" style="direction:ltr;text-align:left;font-family:monospace;font-size:12px;margin-bottom:14px;user-select:all"><?php echo e($redirectUri); ?></div>
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">Google Client ID</label><input type="text" name="google_client_id" class="text-input" value="<?php echo e($s['google_client_id'] ?? ''); ?>" dir="ltr"></div>
                <div class="field-row"><label class="field-label">Google Client Secret</label><input type="text" name="google_client_secret" class="text-input" placeholder="<?php echo !empty($s['google_client_secret']) ? '•••••••• (محفوظ - اتركه فارغاً للإبقاء عليه)' : ''; ?>" dir="ltr"></div>
            </div>

            <button type="submit" class="btn btn-accent" style="margin-top:8px"><i class="fas fa-floppy-disk"></i> حفظ الإعدادات</button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-coins"></i> العملات وأسعار الصرف</h2></div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.8">
            كل الأسعار في النظام مخزّنة بالدولار الأمريكي كعملة أساس. أضف هنا أي عملة أخرى وسعر صرفها مقابل الدولار،
            لتُستخدم في عرض الأسعار للزوار وفي طرق الدفع.
        </p>
        <form method="POST" action="admin.php?section=settings">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="currency_save">
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">رمز العملة (3 أحرف)</label><input type="text" name="code" class="text-input" placeholder="SAR" maxlength="3" dir="ltr" style="text-transform:uppercase" required></div>
                <div class="field-row"><label class="field-label">اسم العملة</label><input type="text" name="name" class="text-input" placeholder="ريال سعودي" required></div>
                <div class="field-row"><label class="field-label">رمز مختصر</label><input type="text" name="symbol" class="text-input" placeholder="ر.س" required></div>
                <div class="field-row"><label class="field-label">سعر الصرف مقابل 1 دولار</label><input type="number" step="0.0001" min="0.0001" name="rate_per_usd" class="text-input" placeholder="3.75" required></div>
            </div>
            <div class="checkbox-row"><input type="checkbox" name="is_active" id="newCurrencyActive" checked><label for="newCurrencyActive">مفعّلة</label></div>
            <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-plus"></i> إضافة / تحديث العملة</button>
        </form>

        <?php foreach ($currencies as $c): ?>
        <div class="settings-item" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border-color)">
            <form method="POST" action="admin.php?section=settings" style="width:100%">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="currency_save">
                <input type="hidden" name="code" value="<?php echo e($c['code']); ?>">
                <div class="field-grid-2">
                    <div class="field-row"><label class="field-label">الرمز</label><input type="text" class="text-input" value="<?php echo e($c['code']); ?>" disabled dir="ltr"></div>
                    <div class="field-row"><label class="field-label">اسم العملة</label><input type="text" name="name" class="text-input" value="<?php echo e($c['name']); ?>" required></div>
                    <div class="field-row"><label class="field-label">رمز مختصر</label><input type="text" name="symbol" class="text-input" value="<?php echo e($c['symbol']); ?>" required></div>
                    <div class="field-row"><label class="field-label">سعر الصرف مقابل 1 دولار</label><input type="number" step="0.0001" min="0.0001" name="rate_per_usd" class="text-input" value="<?php echo e($c['rate_per_usd']); ?>" required></div>
                </div>
                <div class="checkbox-row"><input type="checkbox" name="is_active" id="currencyActive<?php echo e($c['code']); ?>" <?php echo $c['is_active'] ? 'checked' : ''; ?>><label for="currencyActive<?php echo e($c['code']); ?>">مفعّلة</label></div>
                <div class="order-actions">
                    <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-floppy-disk"></i> حفظ</button>
                </div>
            </form>
            <?php if ($c['code'] !== 'USD'): ?>
            <form method="POST" action="admin.php?section=settings" style="margin-top:8px" onsubmit="return confirmAndSubmit(this, 'حذف هذه العملة؟')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="currency_delete">
                <input type="hidden" name="code" value="<?php echo e($c['code']); ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> حذف العملة</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-bullhorn"></i> إرسال إشعار لجميع المستخدمين</h2></div>
        <form method="POST" action="admin.php?section=settings" onsubmit="return confirmAndSubmit(this, 'إرسال هذا الإشعار لجميع المستخدمين؟')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="broadcast_notification">
            <div class="field-row"><label class="field-label">عنوان الإشعار</label><input type="text" name="title" class="text-input" placeholder="📢 تحديث جديد" required></div>
            <div class="field-row"><label class="field-label">نص الإشعار (اختياري)</label><textarea name="body" class="text-input" placeholder="تفاصيل الإشعار..."></textarea></div>
            <button type="submit" class="btn btn-accent"><i class="fas fa-paper-plane"></i> إرسال للجميع</button>
        </form>
    </div>
    <?php
}
