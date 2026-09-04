<?php
// ============================================================
// لوحة تحكم الأدمن - استضافتي
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';
requireAdmin($pdo);

$admin = currentUser($pdo);
$section = $_GET['section'] ?? 'orders';
if (!in_array($section, ['orders', 'topups', 'plans', 'payments', 'settings', 'backups'], true)) {
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
        $billingCycle = ($_POST['billing_cycle'] ?? '') === 'yearly' ? 'yearly' : 'monthly';
        $price = (float)($_POST['price'] ?? 0);
        $originalPriceRaw = trim($_POST['original_price'] ?? '');
        $originalPrice = $originalPriceRaw === '' ? null : (float)$originalPriceRaw;
        $badge = trim($_POST['badge'] ?? '') ?: null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if ($name === '' || $cpu === '' || $ram === '' || $storage === '' || $bandwidth === '' || $price <= 0) {
            adminRedirect('plans', null, 'الرجاء تعبئة جميع الحقول المطلوبة (السعر يجب أن يكون أكبر من صفر).');
        }
        if ($originalPrice !== null && $originalPrice <= $price) {
            $originalPrice = null;
        }

        [$iconImagePath, $iconImageErr] = handleImageUpload('icon_image', LOGOS_DIR, 'uploads/logos');
        if ($iconImageErr) {
            adminRedirect('plans', null, $iconImageErr);
        }

        $previousOriginalPrice = null;
        if ($id > 0) {
            $prevStmt = $pdo->prepare('SELECT original_price FROM vps_plans WHERE id = ?');
            $prevStmt->execute([$id]);
            $previousOriginalPrice = $prevStmt->fetchColumn();
            $previousOriginalPrice = $previousOriginalPrice !== false && $previousOriginalPrice !== null ? (float)$previousOriginalPrice : null;

            if ($iconImagePath) {
                $pdo->prepare('UPDATE vps_plans SET name=?, icon=?, icon_image=?, cpu=?, ram=?, storage=?, bandwidth=?, price=?, original_price=?, billing_cycle=?, badge=?, is_active=?, sort_order=? WHERE id=?')
                    ->execute([$name, $icon, $iconImagePath, $cpu, $ram, $storage, $bandwidth, $price, $originalPrice, $billingCycle, $badge, $isActive, $sortOrder, $id]);
            } else {
                $pdo->prepare('UPDATE vps_plans SET name=?, icon=?, cpu=?, ram=?, storage=?, bandwidth=?, price=?, original_price=?, billing_cycle=?, badge=?, is_active=?, sort_order=? WHERE id=?')
                    ->execute([$name, $icon, $cpu, $ram, $storage, $bandwidth, $price, $originalPrice, $billingCycle, $badge, $isActive, $sortOrder, $id]);
            }
        } else {
            $pdo->prepare('INSERT INTO vps_plans (name, icon, icon_image, cpu, ram, storage, bandwidth, price, original_price, billing_cycle, badge, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$name, $icon, $iconImagePath, $cpu, $ram, $storage, $bandwidth, $price, $originalPrice, $billingCycle, $badge, $isActive, $sortOrder]);
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
        // هذا النموذج لطرق الدفع اليدوية فقط؛ Binance وآسياسيل ثابتتان وتُعدَّلان عبر
        // pm_save_binance / pm_save_asiacell أدناه (لا يمكن إنشاؤهما أو حذفهما).
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-money-bill-wave';
        $account = trim($_POST['account_number'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $currencyCode = trim($_POST['currency_code'] ?? '') ?: 'USD';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $exchangeRate = (float)($_POST['exchange_rate'] ?? 0);

        if ($name === '') {
            adminRedirect('payments', null, 'الرجاء إدخال اسم طريقة الدفع.');
        }

        [$logoPath, $uploadErr] = handleImageUpload('logo', LOGOS_DIR, 'uploads/logos');
        if ($uploadErr) {
            adminRedirect('payments', null, $uploadErr);
        }

        $methodExtras = json_encode(['exchange_rate' => $exchangeRate > 0 ? $exchangeRate : null]);

        if ($id > 0) {
            $typeStmt = $pdo->prepare('SELECT method_type FROM payment_methods WHERE id = ?');
            $typeStmt->execute([$id]);
            if ($typeStmt->fetchColumn() !== 'manual') {
                adminRedirect('payments', null, 'لا يمكن تعديل هذه الطريقة من هذا النموذج.');
            }
            if ($logoPath) {
                $pdo->prepare('UPDATE payment_methods SET name=?, icon=?, account_number=?, instructions=?, currency_code=?, is_active=?, sort_order=?, logo_path=?, method_extras=? WHERE id=?')
                    ->execute([$name, $icon, $account, $instructions, $currencyCode, $isActive, $sortOrder, $logoPath, $methodExtras, $id]);
            } else {
                $pdo->prepare('UPDATE payment_methods SET name=?, icon=?, account_number=?, instructions=?, currency_code=?, is_active=?, sort_order=?, method_extras=? WHERE id=?')
                    ->execute([$name, $icon, $account, $instructions, $currencyCode, $isActive, $sortOrder, $methodExtras, $id]);
            }
        } else {
            $pdo->prepare("INSERT INTO payment_methods (name, icon, account_number, instructions, currency_code, is_active, sort_order, logo_path, method_type, method_extras) VALUES (?,?,?,?,?,?,?,?,'manual',?)")
                ->execute([$name, $icon, $account, $instructions, $currencyCode, $isActive, $sortOrder, $logoPath, $methodExtras]);
        }
        adminRedirect('payments', 'تم حفظ طريقة الدفع بنجاح.');
    }

    if ($action === 'pm_save_binance') {
        $row = $pdo->query("SELECT id, method_extras FROM payment_methods WHERE method_type = 'binance' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            adminRedirect('payments', null, 'تعذر العثور على طريقة Binance Pay.');
        }
        $existingExtras = json_decode($row['method_extras'] ?? '{}', true) ?: [];
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $binanceApiKey = trim($_POST['binance_api_key'] ?? '');
        $binanceApiSecret = trim($_POST['binance_api_secret'] ?? '');
        $binanceId = trim($_POST['binance_id'] ?? '');

        [$qrCodePath, $qrCodeErr] = handleImageUpload('binance_qr_code', LOGOS_DIR, 'uploads/logos');
        if ($qrCodeErr) {
            adminRedirect('payments', null, $qrCodeErr);
        }
        [$binanceLogoPath, $binanceLogoErr] = handleImageUpload('binance_logo', LOGOS_DIR, 'uploads/logos');
        if ($binanceLogoErr) {
            adminRedirect('payments', null, $binanceLogoErr);
        }

        $methodExtras = json_encode([
            'api_key' => $binanceApiKey !== '' ? $binanceApiKey : ($existingExtras['api_key'] ?? ''),
            'api_secret' => $binanceApiSecret !== '' ? $binanceApiSecret : ($existingExtras['api_secret'] ?? ''),
            'binance_id' => $binanceId,
            'qr_code' => $qrCodePath ?: ($existingExtras['qr_code'] ?? ''),
        ]);
        if ($binanceLogoPath) {
            $pdo->prepare('UPDATE payment_methods SET is_active=?, logo_path=?, method_extras=? WHERE id=?')
                ->execute([$isActive, $binanceLogoPath, $methodExtras, $row['id']]);
        } else {
            $pdo->prepare('UPDATE payment_methods SET is_active = ?, method_extras = ? WHERE id = ?')
                ->execute([$isActive, $methodExtras, $row['id']]);
        }
        adminRedirect('payments', 'تم حفظ إعدادات Binance Pay بنجاح.');
    }

    if ($action === 'pm_save_asiacell') {
        $row = $pdo->query("SELECT id, method_extras FROM payment_methods WHERE method_type = 'asiacell' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            adminRedirect('payments', null, 'تعذر العثور على طريقة آسياسيل.');
        }
        $existingExtras = json_decode($row['method_extras'] ?? '{}', true) ?: [];
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $receiverMsisdn = trim($_POST['asiacell_receiver'] ?? '');
        $exchangeRate = (float)($_POST['asiacell_exchange_rate'] ?? 0);
        $maxTransfer = (int)($_POST['asiacell_max_transfer'] ?? 0);
        $instructions = trim($_POST['instructions'] ?? '');

        if ($receiverMsisdn !== '' && !preg_match('/^(077|078|079)\d{8}$/', $receiverMsisdn)) {
            adminRedirect('payments', null, 'رقم آسياسيل المستقبل غير صحيح، يجب أن يكون بصيغة 07xxxxxxxxx.');
        }

        [$logoPath, $logoErr] = handleImageUpload('logo', LOGOS_DIR, 'uploads/logos');
        if ($logoErr) {
            adminRedirect('payments', null, $logoErr);
        }

        $methodExtras = json_encode([
            'receiver_msisdn' => $receiverMsisdn !== '' ? $receiverMsisdn : ($existingExtras['receiver_msisdn'] ?? ''),
            'exchange_rate' => $exchangeRate > 0 ? $exchangeRate : ($existingExtras['exchange_rate'] ?? 1000),
            'max_transfer' => $maxTransfer > 0 ? $maxTransfer : ($existingExtras['max_transfer'] ?? 10000),
        ]);

        if ($logoPath) {
            $pdo->prepare('UPDATE payment_methods SET is_active=?, instructions=?, logo_path=?, method_extras=? WHERE id=?')
                ->execute([$isActive, $instructions, $logoPath, $methodExtras, $row['id']]);
        } else {
            $pdo->prepare('UPDATE payment_methods SET is_active=?, instructions=?, method_extras=? WHERE id=?')
                ->execute([$isActive, $instructions, $methodExtras, $row['id']]);
        }
        adminRedirect('payments', 'تم حفظ إعدادات آسياسيل بنجاح.');
    }

    if ($action === 'pm_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $typeStmt = $pdo->prepare('SELECT method_type FROM payment_methods WHERE id = ?');
        $typeStmt->execute([$id]);
        if ($typeStmt->fetchColumn() !== 'manual') {
            adminRedirect('payments', null, 'لا يمكن حذف Binance Pay أو آسياسيل، فقط تعطيلهما من إعداداتهما.');
        }
        $pdo->prepare('DELETE FROM payment_methods WHERE id = ?')->execute([$id]);
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
            ON DUPLICATE KEY UPDATE name = VALUES(name), symbol = VALUES(symbol), rate_per_usd = VALUES(rate_per_usd), is_active = VALUES(is_active), sort_order = VALUES(sort_order)')
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

        $vpsId = trim($_POST['vps_id'] ?? '');
        $hostName = trim($_POST['host_name'] ?? '') ?: ('خادم ' . ($plan['name'] ?? ''));
        $ip = trim($_POST['host_ip'] ?? '');
        $username = trim($_POST['host_username'] ?? '');
        $password = trim($_POST['host_password'] ?? '');
        $expiryInterval = $order['billing_cycle'] === 'yearly' ? '+1 year' : '+1 month';
        $expiry = date('Y-m-d', strtotime($expiryInterval));

        if ($vpsId === '' || $ip === '' || $username === '' || $password === '') {
            adminRedirect('orders', null, 'الرجاء تعبئة معرّف VPS وعنوان IP واسم المستخدم وكلمة المرور لتفعيل الاستضافة.');
        }

        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO hosting (user_id, order_id, vps_id, name, plan, ip, username, password, status, expiry_date) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$order['user_id'], $orderId, $vpsId, $hostName, $plan['name'] ?? '-', $ip, $username, $password, 'active', $expiry]);
        $pdo->prepare("UPDATE orders SET status = 'approved', decided_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE order_id = ?")->execute([$orderId]);
        notifyUser($pdo, $order['user_id'], '✅ تم قبول طلبك', 'تم تفعيل استضافتك (' . $hostName . ') وهي جاهزة الآن ضمن "سيرفراتي".', 'order_approved');
        $pdo->commit();

        adminRedirect('orders', 'تم قبول الطلب وتفعيل الاستضافة للمستخدم.');
    }

    if ($action === 'order_fulfill_renewal') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending' AND renewal_hosting_id IS NOT NULL");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            adminRedirect('orders', null, 'طلب التجديد غير موجود أو تمت معالجته مسبقاً.');
        }
        $hostingStmt = $pdo->prepare('SELECT * FROM hosting WHERE id = ?');
        $hostingStmt->execute([$order['renewal_hosting_id']]);
        $hosting = $hostingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hosting) {
            adminRedirect('orders', null, 'الاستضافة المرتبطة بطلب التجديد غير موجودة.');
        }

        $expiryInterval = $order['billing_cycle'] === 'yearly' ? '+1 year' : '+1 month';
        $baseDate = max($hosting['expiry_date'] ?: date('Y-m-d'), date('Y-m-d'));
        $newExpiry = date('Y-m-d', strtotime($baseDate . ' ' . $expiryInterval));

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE hosting SET status = 'active', expiry_date = ? WHERE id = ?")->execute([$newExpiry, $hosting['id']]);
        $pdo->prepare("UPDATE orders SET status = 'approved', decided_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE order_id = ?")->execute([$orderId]);
        notifyUser($pdo, $order['user_id'], '✅ تم تجديد الاستضافة', 'تم تجديد استضافتك (' . $hosting['name'] . ') بنجاح حتى ' . $newExpiry . '.', 'order_approved');
        $pdo->commit();

        adminRedirect('orders', 'تم تجديد الاستضافة بنجاح.');
    }

    if ($action === 'order_reject') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE orders SET status = 'rejected', decided_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$orderId]);
            $pdo->prepare("UPDATE invoices SET status = 'rejected' WHERE order_id = ?")->execute([$orderId]);
            $refunded = false;
            if (empty($order['payment_method_id'])) {
                $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([(float)$order['amount'], $order['user_id']]);
                $refunded = true;
            }
            if (!empty($order['renewal_hosting_id'])) {
                $pdo->prepare("UPDATE hosting SET status = 'expired' WHERE id = ?")->execute([$order['renewal_hosting_id']]);
            }
            $pdo->commit();
            $rejectMsg = $refunded
                ? 'تم رفض طلب الاشتراك وإعادة المبلغ إلى رصيد حسابك. تواصل مع الدعم الفني لمعرفة السبب.'
                : 'تم رفض طلب الاشتراك. تواصل مع الدعم الفني لمعرفة السبب.';
            notifyUser($pdo, $order['user_id'], '❌ تم رفض طلبك', $rejectMsg, 'order_rejected');
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
        setSetting($pdo, 'support_whatsapp', preg_replace('/[^0-9]/', '', $_POST['support_whatsapp'] ?? ''));
        $referralPct = (float)($_POST['referral_discount_pct'] ?? 0);
        if ($referralPct < 0) $referralPct = 0;
        if ($referralPct > 100) $referralPct = 100;
        setSetting($pdo, 'referral_discount_pct', (string)$referralPct);
        setSetting($pdo, 'site_terms', trim($_POST['site_terms'] ?? ''));
        setSetting($pdo, 'site_privacy', trim($_POST['site_privacy'] ?? ''));

        [$logoPath, $uploadErr] = handleImageUpload('site_logo', LOGOS_DIR, 'uploads/logos');
        if ($uploadErr) {
            adminRedirect('settings', null, $uploadErr);
        }
        if ($logoPath) {
            setSetting($pdo, 'site_logo', $logoPath);
        }

        [$aiLogoPath, $aiLogoErr] = handleImageUpload('ai_logo', LOGOS_DIR, 'uploads/logos');
        if ($aiLogoErr) {
            adminRedirect('settings', null, $aiLogoErr);
        }
        if ($aiLogoPath) {
            setSetting($pdo, 'ai_logo', $aiLogoPath);
        }

        adminRedirect('settings', 'تم حفظ الإعدادات بنجاح.');
    }

    if ($action === 'backup_settings_save') {
        setSetting($pdo, 'telegram_chat_id', trim($_POST['telegram_chat_id'] ?? ''));
        if (trim($_POST['telegram_bot_token'] ?? '') !== '') {
            setSetting($pdo, 'telegram_bot_token', trim($_POST['telegram_bot_token']));
        }
        adminRedirect('backups', 'تم حفظ إعدادات تيليجرام.');
    }

    if ($action === 'backup_send_telegram') {
        [$ok, $msg] = runSiteBackupAndSend($pdo);
        adminRedirect('backups', $ok ? 'تم إرسال نسخة احتياطية عبر تيليجرام بنجاح.' : null, $ok ? null : $msg);
    }

    if ($action === 'backup_download') {
        $data = buildSiteBackupData($pdo);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="istidafati-backup-' . date('Y-m-d-His') . '.json"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    if ($action === 'backup_restore') {
        if (empty($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            adminRedirect('backups', null, 'الرجاء اختيار ملف نسخة احتياطية صالح.');
        }
        $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
        $backup = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            adminRedirect('backups', null, 'الملف المرفوع ليس JSON صالحاً.');
        }
        [$ok, $msg, $counts] = restoreSiteBackup($pdo, $backup);
        if (!$ok) {
            adminRedirect('backups', null, $msg);
        }
        $summary = [];
        foreach ($counts as $table => $n) {
            $summary[] = $table . ': ' . $n;
        }
        adminRedirect('backups', 'تمت الاستعادة بنجاح (' . implode('، ', $summary) . '). إن لم يعد حسابك الحالي موجوداً ضمن البيانات المستعادة، سجّل الدخول من جديد بحساب موجود فيها.');
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
    <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/admin.css')); ?>">
</head>
<body>
    <header class="admin-header">
        <div class="brand"><i class="fas fa-gauge"></i> لوحة التحكم</div>
        <div class="right-links">
            <span><i class="fas fa-user-shield"></i> <?php echo e($admin['name']); ?></span>
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
        <a class="admin-tab <?php echo $section === 'backups' ? 'active' : ''; ?>" href="admin.php?section=backups"><i class="fas fa-database"></i> نسخ احتياطي</a>
    </nav>

    <div class="admin-container">
        <?php if ($section === 'orders'): ?>
        <div class="admin-hero">
            <div class="admin-hero-top">
                <div class="admin-hero-icon"><i class="fas fa-gauge-high"></i></div>
                <div>
                    <h3>مرحباً، <?php echo e($admin['name']); ?> 👋</h3>
                    <div class="admin-hero-sub">إليك ملخص نشاط المنصة اليوم</div>
                </div>
            </div>
            <div class="admin-hero-date"><i class="fas fa-calendar-alt"></i> <?php echo date('l, d F Y'); ?></div>
        </div>
        <?php endif; ?>

        <?php if (in_array($section, ['orders', 'topups'], true)): ?>
        <div class="stats-row">
            <div class="stat-tile"><div class="num"><?php echo $pendingOrdersCount; ?></div><div class="label">طلبات قيد المراجعة</div></div>
            <div class="stat-tile"><div class="num"><?php echo $pendingTopupsCount; ?></div><div class="label">طلبات شحن معلقة</div></div>
            <div class="stat-tile"><div class="num"><?php echo $usersCount; ?></div><div class="label">إجمالي المستخدمين</div></div>
            <div class="stat-tile"><div class="num"><?php echo $activeHostingCount; ?></div><div class="label">استضافات نشطة</div></div>
        </div>
        <?php endif; ?>

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
        } elseif ($section === 'backups') {
            renderAdminBackups($pdo);
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
        function showSettingsPanel(btn, key) {
            document.querySelectorAll('.settings-subtabs .subtab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.settings-panel').forEach(p => p.classList.toggle('hidden', p.dataset.panel !== key));
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
               p.name AS plan_name, p.icon AS plan_icon, p.icon_image AS plan_icon_image,
               pm.name AS pm_name, h.vps_id AS vps_id,
               rh.name AS renewal_hosting_name, rh.vps_id AS renewal_vps_id, rh.expiry_date AS renewal_current_expiry
        FROM orders o
        JOIN users u ON u.id = o.user_id
        JOIN vps_plans p ON p.id = o.plan_id
        LEFT JOIN payment_methods pm ON pm.id = o.payment_method_id
        LEFT JOIN hosting h ON h.order_id = o.id
        LEFT JOIN hosting rh ON rh.id = o.renewal_hosting_id
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
                <div><strong><?php echo planIconHtml($o['plan_icon'], $o['plan_icon_image'] ?? null, 18); ?> <?php echo e($o['plan_name']); ?></strong><span>الباقة</span></div>
                <div><strong>$<?php echo money($o['amount']); ?></strong><span>المبلغ</span></div>
                <div><strong><?php echo $o['billing_cycle'] === 'yearly' ? 'سنوي' : 'شهري'; ?></strong><span>مدة الاشتراك</span></div>
                <div><strong><?php echo e($o['pm_name'] ?: 'رصيد الحساب'); ?></strong><span>طريقة الدفع</span></div>
                <div><strong><?php echo e(substr($o['created_at'], 0, 16)); ?></strong><span>تاريخ الطلب</span></div>
                <?php if (!empty($o['vps_id'])): ?>
                <div><strong dir="ltr"><?php echo e($o['vps_id']); ?></strong><span>معرّف VPS</span></div>
                <?php endif; ?>
            </div>

            <?php if ($o['proof_image']): ?>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:4px">
                    <a href="<?php echo e($o['proof_image']); ?>" target="_blank" title="عرض إيصال التحويل">
                        <img src="<?php echo e($o['proof_image']); ?>" class="proof-thumb" alt="إيصال التحويل">
                    </a>
                    <a href="<?php echo e($o['proof_image']); ?>" target="_blank" class="btn btn-accent btn-sm"><i class="fas fa-receipt"></i> عرض وصل الدفع</a>
                </div>
            <?php endif; ?>

            <?php if ($o['status'] === 'pending' && !empty($o['renewal_hosting_id'])): ?>
            <div class="order-meta" style="margin-top:4px">
                <div><strong><?php echo e($o['renewal_vps_id'] ?: $o['renewal_hosting_name'] ?: ('#' . (int)$o['renewal_hosting_id'])); ?></strong><span>تجديد للاستضافة</span></div>
                <div><strong><?php echo e($o['renewal_current_expiry'] ?: '-'); ?></strong><span>تاريخ الانتهاء الحالي</span></div>
            </div>
            <p style="font-size:12px;color:var(--text-muted);margin:6px 0 10px">
                <i class="fas fa-circle-info"></i>
                هذا طلب تجديد تلقائي — تم خصم المبلغ من رصيد المستخدم مسبقاً. الموافقة تُمدّد صلاحية نفس الاستضافة دون الحاجة لإدخال أي بيانات جديدة.
            </p>
            <div class="order-actions" style="margin-top:4px">
                <form method="POST" action="admin.php?section=orders" style="display:inline" onsubmit="return confirmAndSubmit(this, 'تأكيد الموافقة على تجديد هذه الاستضافة؟')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="order_fulfill_renewal">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                    <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-check"></i> الموافقة على التجديد</button>
                </form>
                <form method="POST" action="admin.php?section=orders" style="display:inline" onsubmit="return confirmAndSubmit(this, 'هل أنت متأكد من رفض طلب التجديد؟ سيتم إعادة المبلغ إلى رصيد المستخدم.')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="order_reject">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-xmark"></i> رفض</button>
                </form>
            </div>
            <?php elseif ($o['status'] === 'pending'): ?>
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
                        <label class="field-label">معرّف VPS (Server ID)</label>
                        <input type="text" name="vps_id" class="text-input" placeholder="مثال: VPS-1024" required dir="ltr">
                    </div>
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
            <?php if (!empty($inv['proof_image'])): ?>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:4px">
                    <a href="<?php echo e($inv['proof_image']); ?>" target="_blank" title="عرض إيصال التحويل">
                        <img src="<?php echo e($inv['proof_image']); ?>" class="proof-thumb" alt="إيصال التحويل">
                    </a>
                    <a href="<?php echo e($inv['proof_image']); ?>" target="_blank" class="btn btn-accent btn-sm"><i class="fas fa-receipt"></i> عرض وصل الدفع</a>
                </div>
            <?php endif; ?>
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
        <form method="POST" action="admin.php?section=plans" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="plan_save">
            <input type="hidden" name="id" value="0">
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">اسم الباقة</label><input type="text" name="name" class="text-input" required></div>
                <div class="field-row"><label class="field-label">أيقونة (إيموجي)</label><input type="text" name="icon" class="text-input" value="🚀"></div>
                <div class="field-row"><label class="field-label">أو أيقونة كصورة (اختياري، تُغني عن الإيموجي)</label><input type="file" name="icon_image" class="text-input" accept="image/png,image/jpeg,image/webp"></div>
                <div class="field-row"><label class="field-label">المعالج (CPU)</label><input type="text" name="cpu" class="text-input" placeholder="2 Core" required></div>
                <div class="field-row"><label class="field-label">الذاكرة (RAM)</label><input type="text" name="ram" class="text-input" placeholder="4 GB" required></div>
                <div class="field-row"><label class="field-label">التخزين</label><input type="text" name="storage" class="text-input" placeholder="100 GB SSD" required></div>
                <div class="field-row"><label class="field-label">الباندويث</label><input type="text" name="bandwidth" class="text-input" placeholder="2 TB" required></div>
                <div class="field-row">
                    <label class="field-label">نوع الاشتراك</label>
                    <select name="billing_cycle" class="text-input" required>
                        <option value="monthly">شهري</option>
                        <option value="yearly">سنوي</option>
                    </select>
                    <p style="font-size:11px;color:var(--text-muted);margin-top:4px">تحدّد في أي تبويب (شهري/سنوي) تظهر هذه الباقة للمستخدم.</p>
                </div>
                <div class="field-row"><label class="field-label">السعر ($)</label><input type="number" step="0.01" min="0.01" name="price" class="text-input" required></div>
                <div class="field-row"><label class="field-label">السعر قبل الخصم (اختياري)</label><input type="number" step="0.01" min="0.01" name="original_price" class="text-input" placeholder="اتركه فارغاً بدون خصم"></div>
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
                    <span class="plan-icon-preview"><?php echo planIconHtml($plan['icon'], $plan['icon_image'] ?? null, 22); ?></span> <strong><?php echo e($plan['name']); ?></strong> —
                    <?php if (!empty($plan['original_price'])): ?>
                        <s class="text-muted">$<?php echo money($plan['original_price']); ?></s>
                    <?php endif; ?>
                    $<?php echo money($plan['price']); ?>/<?php echo ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'سنة' : 'شهر'; ?>
                </span>
                <span class="pill <?php echo ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'pill-amber' : 'pill-green'; ?>" style="margin-inline-end:6px"><?php echo ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'سنوي' : 'شهري'; ?></span>
                <span class="pill <?php echo $plan['is_active'] ? 'pill-green' : 'pill-gray'; ?>"><?php echo $plan['is_active'] ? 'مفعّلة' : 'موقوفة'; ?></span>
            </summary>
            <form method="POST" action="admin.php?section=plans" enctype="multipart/form-data" style="margin-top:14px">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="plan_save">
                <input type="hidden" name="id" value="<?php echo (int)$plan['id']; ?>">
                <div class="field-grid-2">
                    <div class="field-row"><label class="field-label">اسم الباقة</label><input type="text" name="name" class="text-input" value="<?php echo e($plan['name']); ?>" required></div>
                    <div class="field-row"><label class="field-label">أيقونة (إيموجي)</label><input type="text" name="icon" class="text-input" value="<?php echo e($plan['icon']); ?>"></div>
                    <div class="field-row">
                        <label class="field-label">أو أيقونة كصورة (اختياري)</label>
                        <?php if (!empty($plan['icon_image'])): ?>
                        <div style="margin-bottom:6px"><img src="<?php echo e($plan['icon_image']); ?>" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color)"></div>
                        <?php endif; ?>
                        <input type="file" name="icon_image" class="text-input" accept="image/png,image/jpeg,image/webp">
                    </div>
                    <div class="field-row"><label class="field-label">المعالج (CPU)</label><input type="text" name="cpu" class="text-input" value="<?php echo e($plan['cpu']); ?>" required></div>
                    <div class="field-row"><label class="field-label">الذاكرة (RAM)</label><input type="text" name="ram" class="text-input" value="<?php echo e($plan['ram']); ?>" required></div>
                    <div class="field-row"><label class="field-label">التخزين</label><input type="text" name="storage" class="text-input" value="<?php echo e($plan['storage']); ?>" required></div>
                    <div class="field-row"><label class="field-label">الباندويث</label><input type="text" name="bandwidth" class="text-input" value="<?php echo e($plan['bandwidth']); ?>" required></div>
                    <div class="field-row">
                        <label class="field-label">نوع الاشتراك</label>
                        <select name="billing_cycle" class="text-input" required>
                            <option value="monthly" <?php echo ($plan['billing_cycle'] ?? 'monthly') === 'monthly' ? 'selected' : ''; ?>>شهري</option>
                            <option value="yearly" <?php echo ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'selected' : ''; ?>>سنوي</option>
                        </select>
                    </div>
                    <div class="field-row"><label class="field-label">السعر ($)</label><input type="number" step="0.01" min="0.01" name="price" class="text-input" value="<?php echo e($plan['price']); ?>" required></div>
                    <div class="field-row"><label class="field-label">السعر قبل الخصم (اختياري)</label><input type="number" step="0.01" min="0.01" name="original_price" class="text-input" value="<?php echo e($plan['original_price'] ?? ''); ?>" placeholder="اتركه فارغاً بدون خصم"></div>
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
    $binance = $pdo->query("SELECT * FROM payment_methods WHERE method_type = 'binance' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $asiacell = $pdo->query("SELECT * FROM payment_methods WHERE method_type = 'asiacell' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $manualMethods = $pdo->query("SELECT * FROM payment_methods WHERE method_type = 'manual' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $currencies = getAllCurrencies($pdo);
    $binanceExtras = $binance ? (json_decode($binance['method_extras'] ?? '{}', true) ?: []) : [];
    $asiacellExtras = $asiacell ? (json_decode($asiacell['method_extras'] ?? '{}', true) ?: []) : [];
    $binanceHasKeys = !empty($binanceExtras['api_key'] ?? '');
    ?>
    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i class="fas fa-coins"></i> Binance Pay</h2>
            <span class="pill <?php echo ($binance && $binance['is_active']) ? 'pill-green' : 'pill-gray'; ?>"><?php echo ($binance && $binance['is_active']) ? 'مفعّلة' : 'موقوفة'; ?></span>
        </div>
        <div class="text-muted" style="font-size:12px;margin-bottom:14px">طريقة دفع تلقائي ثابتة في النظام - عبّئ الإعدادات أدناه ثم فعّلها. تحقق فوري من عملية الدفع دون مراجعة يدوية.</div>
        <?php if ($binance): ?>
        <form method="POST" action="admin.php?section=payments" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="pm_save_binance">
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">Binance API Key</label><input type="text" name="binance_api_key" class="text-input" dir="ltr" placeholder="<?php echo $binanceHasKeys ? '•••• اتركه فارغاً للإبقاء على المفتاح الحالي' : 'API Key'; ?>" autocomplete="off"></div>
                <div class="field-row"><label class="field-label">Binance API Secret</label><input type="text" name="binance_api_secret" class="text-input" dir="ltr" placeholder="<?php echo $binanceHasKeys ? '•••• اتركه فارغاً للإبقاء على المفتاح الحالي' : 'API Secret'; ?>" autocomplete="off"></div>
                <div class="field-row"><label class="field-label">Binance Pay ID (يظهر للمستخدم)</label><input type="text" name="binance_id" class="text-input" dir="ltr" value="<?php echo e($binanceExtras['binance_id'] ?? ''); ?>" placeholder="123456789"></div>
                <div class="field-row">
                    <label class="field-label">رمز QR للدفع (اختياري)</label>
                    <?php if (!empty($binanceExtras['qr_code'])): ?>
                    <div style="margin-bottom:6px"><img src="<?php echo e($binanceExtras['qr_code']); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color)"></div>
                    <?php endif; ?>
                    <input type="file" name="binance_qr_code" class="text-input" accept="image/png,image/jpeg,image/webp">
                </div>
                <div class="field-row">
                    <label class="field-label">الشعار (صورة، اختياري)</label>
                    <?php if (!empty($binance['logo_path'])): ?>
                    <div style="margin-bottom:6px"><img src="<?php echo e($binance['logo_path']); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color)"></div>
                    <?php endif; ?>
                    <input type="file" name="binance_logo" class="text-input" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>
            <div class="checkbox-row"><input type="checkbox" name="is_active" id="binanceActive" <?php echo $binance['is_active'] ? 'checked' : ''; ?>><label for="binanceActive">مفعّلة وتظهر للمستخدمين</label></div>
            <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-floppy-disk"></i> حفظ إعدادات Binance</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i class="fas fa-mobile-screen"></i> آسياسيل - تحويل رصيد تلقائي</h2>
            <span class="pill <?php echo ($asiacell && $asiacell['is_active']) ? 'pill-green' : 'pill-gray'; ?>"><?php echo ($asiacell && $asiacell['is_active']) ? 'مفعّلة' : 'موقوفة'; ?></span>
        </div>
        <div class="text-muted" style="font-size:12px;margin-bottom:14px">طريقة دفع تلقائي ثابتة في النظام - عبّئ الإعدادات أدناه ثم فعّلها. العميل يحوّل الرصيد مباشرة من رقمه عبر رمزَي تحقق SMS.</div>
        <?php if ($asiacell): ?>
        <form method="POST" action="admin.php?section=payments" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="pm_save_asiacell">
            <div class="field-grid-2">
                <div class="field-row"><label class="field-label">رقم آسياسيل المستقبل للتحويلات (رقمك الحقيقي الفعّال، وليس مثالاً)</label><input type="text" name="asiacell_receiver" class="text-input" dir="ltr" value="<?php echo e($asiacellExtras['receiver_msisdn'] ?? ''); ?>" placeholder="07xxxxxxxxx"></div>
                <div class="field-row"><label class="field-label">سعر الصرف (دينار عراقي مقابل 1 دولار)</label><input type="number" name="asiacell_exchange_rate" class="text-input" dir="ltr" value="<?php echo e($asiacellExtras['exchange_rate'] ?? ''); ?>" placeholder="1000" step="0.01"></div>
                <div class="field-row"><label class="field-label">الحد الأقصى لكل عملية تحويل (دينار عراقي)</label><input type="number" name="asiacell_max_transfer" class="text-input" dir="ltr" value="<?php echo e($asiacellExtras['max_transfer'] ?? '10000'); ?>" placeholder="10000" step="1000"></div>
                <div class="field-row">
                    <label class="field-label">الشعار (صورة، اختياري)</label>
                    <?php if (!empty($asiacell['logo_path'])): ?>
                    <div style="margin-bottom:6px"><img src="<?php echo e($asiacell['logo_path']); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--border-color)"></div>
                    <?php endif; ?>
                    <input type="file" name="logo" class="text-input" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>
            <div class="text-muted" style="font-size:11px;margin-bottom:10px">آسياسيل تحوّل فقط بمبالغ مضاعفة الألف (1000، 2000...)؛ إن تجاوز المبلغ المطلوب الحد الأقصى أعلاه يقسّمه الموقع تلقائياً على أكثر من عملية تحويل متتالية.</div>
            <div class="field-row"><label class="field-label">الوصف (يظهر للمستخدم)</label><textarea name="instructions" class="text-input" placeholder="تحويل رصيد آسياسيل مباشر وتلقائي"><?php echo e($asiacell['instructions']); ?></textarea></div>
            <div class="checkbox-row"><input type="checkbox" name="is_active" id="asiacellActive" <?php echo $asiacell['is_active'] ? 'checked' : ''; ?>><label for="asiacellActive">مفعّلة وتظهر للمستخدمين</label></div>
            <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-floppy-disk"></i> حفظ إعدادات آسياسيل</button>
        </form>
        <?php endif; ?>
    </div>

    <?php
    $asiacellDebug = json_decode(getSetting($pdo, 'asiacell_last_debug', ''), true);
    if ($asiacellDebug):
    ?>
    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-bug"></i> آخر خطأ من آسياسيل (تشخيص)</h2></div>
        <div class="text-muted" style="font-size:12px;margin-bottom:10px">
            يظهر هنا آخر رد فعلي من خادم آسياسيل عند فشل أي محاولة تحويل من أحد العملاء، ليساعد على معرفة سبب الرفض بالضبط.
            الخطوة: <strong><?php echo e($asiacellDebug['step'] ?? ''); ?></strong> - الوقت: <?php echo e($asiacellDebug['time'] ?? ''); ?>
        </div>
        <div style="font-size:11px;font-family:monospace;direction:ltr;text-align:left;background:var(--bg-card);padding:10px;border-radius:8px;max-height:220px;overflow:auto;word-break:break-all;border:1px solid var(--border-color)"><?php echo e($asiacellDebug['raw'] ?? ''); ?></div>
    </div>
    <?php endif; ?>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-plus"></i> إضافة طريقة دفع يدوية جديدة</h2></div>
        <div class="text-muted" style="font-size:12px;margin-bottom:14px">لطرق التحويل التي تُراجعها الإدارة يدوياً (زين كاش، تحويل بنكي...). تظهر فقط في قسم الفواتير لشحن الرصيد.</div>
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
                <div class="field-row"><label class="field-label">سعر الصرف بالدينار العراقي (اختياري، لعرض المبلغ بالدينار للعميل)</label><input type="number" name="exchange_rate" class="text-input" dir="ltr" placeholder="1450" step="0.01"></div>
            </div>
            <div class="field-row"><label class="field-label">تعليمات الدفع</label><textarea name="instructions" class="text-input" placeholder="حوّل المبلغ إلى الرقم أعلاه ثم ارفع صورة الإيصال."></textarea></div>
            <div class="field-row"><label class="field-label">شعار (صورة، اختياري)</label><input type="file" name="logo" class="text-input" accept="image/png,image/jpeg,image/webp"></div>
            <div class="checkbox-row"><input type="checkbox" name="is_active" id="newPmActive" checked><label for="newPmActive">مفعّلة وتظهر للمستخدمين</label></div>
            <button type="submit" class="btn btn-accent"><i class="fas fa-plus"></i> إضافة طريقة الدفع</button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-credit-card"></i> طرق الدفع اليدوية (<?php echo count($manualMethods); ?>)</h2></div>
        <?php if (!$manualMethods): ?>
        <div class="text-muted text-center" style="padding:20px 0">لا توجد طرق دفع يدوية بعد</div>
        <?php endif; ?>
        <?php foreach ($manualMethods as $pm): ?>
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
                    <?php $pmExtras = json_decode($pm['method_extras'] ?? '{}', true) ?: []; ?>
                    <div class="field-row"><label class="field-label">سعر الصرف بالدينار العراقي (اختياري)</label><input type="number" name="exchange_rate" class="text-input" dir="ltr" value="<?php echo e($pmExtras['exchange_rate'] ?? ''); ?>" placeholder="1450" step="0.01"></div>
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
    <div class="settings-subtabs">
        <button type="button" class="subtab-btn active" onclick="showSettingsPanel(this, 'site')"><i class="fas fa-shop"></i> الموقع</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'referral')"><i class="fas fa-share-nodes"></i> الدعوات</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'ai')"><i class="fas fa-robot"></i> الذكاء الاصطناعي</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'google')"><i class="fab fa-google"></i> Google</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'policies')"><i class="fas fa-file-contract"></i> السياسات</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'currencies')"><i class="fas fa-coins"></i> العملات</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'notify')"><i class="fas fa-bullhorn"></i> إشعار جماعي</button>
    </div>

    <form method="POST" action="admin.php?section=settings" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="settings_save">

        <div class="settings-panel" data-panel="site">
            <div class="admin-card">
                <div class="admin-card-header"><h2><i class="fas fa-shop"></i> اسم الموقع والشعار</h2></div>
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
                <div class="field-row">
                    <label class="field-label">رقم واتساب الدعم الفني</label>
                    <input type="text" name="support_whatsapp" class="text-input" dir="ltr" value="<?php echo e($s['support_whatsapp'] ?? ''); ?>" placeholder="9647701234567">
                    <p style="font-size:11px;color:var(--text-muted);margin-top:4px">بصيغة دولية بدون + أو أصفار في البداية (مثال: 9647701234567). زر واتساب في تطبيق العملاء يفتح محادثة مباشرة مع هذا الرقم.</p>
                </div>
            </div>
        </div>

        <div class="settings-panel hidden" data-panel="referral">
            <div class="admin-card">
                <div class="admin-card-header"><h2><i class="fas fa-share-nodes"></i> رابط المشاركة (الإحالة)</h2></div>
                <div class="field-row">
                    <label class="field-label">نسبة خصم رابط المشاركة (%)</label>
                    <input type="number" name="referral_discount_pct" class="text-input" min="0" max="100" step="1" value="<?php echo e($s['referral_discount_pct'] ?? '10'); ?>" dir="ltr">
                    <p style="font-size:11px;color:var(--text-muted);margin-top:4px">كل مستخدم لديه رابط دعوة خاص من الرئيسية. من يسجّل عبره يحصل على هذه النسبة كخصم على أول طلب VPS له. ضع 0 لتعطيل الميزة.</p>
                </div>
            </div>
        </div>

        <div class="settings-panel hidden" data-panel="ai">
            <div class="admin-card">
                <div class="admin-card-header"><h2><i class="fas fa-robot"></i> المساعد الذكي (NVIDIA API)</h2></div>
                <div class="field-row"><label class="field-label">مفتاح API</label><input type="text" name="nvidia_api_key" class="text-input" value="<?php echo e($s['nvidia_api_key'] ?? ''); ?>" dir="ltr" placeholder="nvapi-..."></div>
                <div class="field-row"><label class="field-label">اسم النموذج (Model)</label><input type="text" name="nvidia_model" class="text-input" value="<?php echo e($s['nvidia_model'] ?? ''); ?>" dir="ltr"></div>
                <div class="field-row">
                    <label class="field-label">شعار المساعد الذكي (اختياري)</label>
                    <?php if (!empty($s['ai_logo'])): ?>
                        <div style="margin-bottom:8px"><img src="<?php echo e($s['ai_logo']); ?>" alt="" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:1px solid var(--border-color)"></div>
                    <?php endif; ?>
                    <input type="file" name="ai_logo" class="text-input" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>
        </div>

        <div class="settings-panel hidden" data-panel="google">
            <div class="admin-card">
                <div class="admin-card-header"><h2><i class="fab fa-google"></i> تسجيل الدخول عبر Google</h2></div>
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
            </div>
        </div>

        <div class="settings-panel hidden" data-panel="policies">
            <div class="admin-card">
                <div class="admin-card-header"><h2><i class="fas fa-file-contract"></i> الشروط والسياسات</h2></div>
                <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">تظهر هذه النصوص للمستخدمين من قسم الإعدادات، وعند إنشاء حساب جديد.</p>
                <div class="field-row"><label class="field-label">الشروط والأحكام</label><textarea name="site_terms" class="text-input" rows="6"><?php echo e($s['site_terms'] ?? ''); ?></textarea></div>
                <div class="field-row"><label class="field-label">سياسة الخصوصية</label><textarea name="site_privacy" class="text-input" rows="6"><?php echo e($s['site_privacy'] ?? ''); ?></textarea></div>
            </div>
        </div>

        <button type="submit" class="btn btn-accent btn-block"><i class="fas fa-floppy-disk"></i> حفظ الإعدادات</button>
    </form>

    <div class="settings-panel hidden" data-panel="currencies">
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
    </div>

    <div class="settings-panel hidden" data-panel="notify">
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
    </div>
    <?php
}

// ============================================================
// قسم: نسخ احتياطي
// ============================================================
function renderAdminBackups(PDO $pdo) {
    $s = getAllSettings($pdo);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $cronSecret = getOrCreateCronSecret($pdo);
    $backupCronUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/backup_cron.php?key=' . $cronSecret;
    $renewalCronUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/cron.php?key=' . $cronSecret;
    $lastRun = $s['backup_last_run'] ?? '';
    $lastStatus = $s['backup_last_status'] ?? '';
    ?>
    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-key"></i> روابط مهام Cron الخاصة بالنظام</h2></div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px;line-height:1.8">
            هذان الرابطان خاصان بحسابك فقط، ولا يظهران لغيرك. أضفهما كمهام Cron Job من لوحة استضافتك ليعملا تلقائياً.
        </p>
        <div class="field-row">
            <label class="field-label">تجديد الاستضافات تلقائياً (يومياً)</label>
            <div class="text-input" style="direction:ltr;text-align:left;font-family:monospace;font-size:12px;user-select:all;word-break:break-all"><?php echo e($renewalCronUrl); ?></div>
        </div>
        <div class="field-row" style="margin-bottom:0">
            <label class="field-label">النسخ الاحتياطي عبر تيليجرام (كل 6 ساعات)</label>
            <div class="text-input" style="direction:ltr;text-align:left;font-family:monospace;font-size:12px;user-select:all;word-break:break-all"><?php echo e($backupCronUrl); ?></div>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fab fa-telegram"></i> بوت تيليجرام للنسخ الاحتياطي</h2></div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.8">
            أنشئ بوتاً جديداً عبر <a href="https://t.me/BotFather" target="_blank" style="color:var(--accent);font-weight:700">BotFather@</a> واحصل على التوكن،
            ثم ابدأ محادثة مع بوتك واحصل على معرف حسابك (Chat ID) عبر بوت مثل <a href="https://t.me/userinfobot" target="_blank" style="color:var(--accent);font-weight:700">userinfobot@</a>.
            كل 6 ساعات سيتم إرسال نسخة كاملة من بيانات الموقع (المستخدمون، الطلبات، الاستضافات، الفواتير، الإعدادات...) كملف إلى محادثتك مع البوت.
        </p>
        <form method="POST" action="admin.php?section=backups">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="backup_settings_save">
            <div class="field-grid-2">
                <div class="field-row">
                    <label class="field-label">توكن البوت (Bot Token)</label>
                    <input type="text" name="telegram_bot_token" class="text-input" dir="ltr" placeholder="<?php echo !empty($s['telegram_bot_token']) ? '•••••••• (محفوظ - اتركه فارغاً للإبقاء عليه)' : '123456:ABC-...'; ?>">
                </div>
                <div class="field-row">
                    <label class="field-label">معرف المحادثة (Chat ID)</label>
                    <input type="text" name="telegram_chat_id" class="text-input" dir="ltr" value="<?php echo e($s['telegram_chat_id'] ?? ''); ?>" placeholder="123456789">
                </div>
            </div>
            <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-floppy-disk"></i> حفظ إعدادات تيليجرام</button>
        </form>
    </div>

    <div class="admin-card">
        <div class="admin-card-header">
            <h2><i class="fas fa-clock-rotate-left"></i> جدولة النسخ التلقائي</h2>
            <span class="pill <?php echo (!empty($s['telegram_bot_token']) && !empty($s['telegram_chat_id'])) ? 'pill-green' : 'pill-gray'; ?>">
                <?php echo (!empty($s['telegram_bot_token']) && !empty($s['telegram_chat_id'])) ? 'مُعدّ' : 'غير مُعدّ بعد'; ?>
            </span>
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;line-height:1.8">
            رابط مهمة الـ Cron موجود أعلاه في بطاقة "روابط مهام Cron". أضفه من لوحة استضافتك ليعمل كل 6 ساعات - مثال جدولة شائع: <span dir="ltr" style="font-family:monospace">0 */6 * * *</span>.
        </p>
        <?php if ($lastRun): ?>
        <p style="font-size:12px;margin-top:10px">
            آخر محاولة نسخ: <strong><?php echo e($lastRun); ?></strong> -
            <?php if ($lastStatus === 'ok'): ?>
                <span style="color:#2e9e5b;font-weight:700">نجحت ✓</span>
            <?php else: ?>
                <span style="color:#d9534f;font-weight:700">فشلت: <?php echo e($lastStatus); ?></span>
            <?php endif; ?>
        </p>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-hand-pointer"></i> نسخة احتياطية يدوية الآن</h2></div>
        <div class="order-actions">
            <form method="POST" action="admin.php?section=backups" style="display:inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="backup_send_telegram">
                <button type="submit" class="btn btn-accent btn-sm"><i class="fab fa-telegram"></i> إرسال نسخة عبر تيليجرام الآن</button>
            </form>
            <form method="POST" action="admin.php?section=backups" style="display:inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="backup_download">
                <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> تنزيل نسخة على جهازي</button>
            </form>
        </div>
    </div>

    <div class="admin-card">
        <div class="admin-card-header"><h2><i class="fas fa-triangle-exclamation"></i> استعادة نسخة احتياطية</h2></div>
        <p style="font-size:12px;color:#d9534f;margin-bottom:14px;line-height:1.8">
            تحذير: هذا الإجراء يستبدل <strong>كل</strong> بيانات الموقع الحالية (المستخدمون، الأرصدة، الطلبات، الاستضافات، الفواتير، الإعدادات...)
            بمحتوى الملف المرفوع، ولا يمكن التراجع عنه بعد التنفيذ. استخدمه فقط لاستعادة الموقع بعد فقدان بياناته.
            ارفع ملف JSON الذي استلمته سابقاً عبر تيليجرام أو نزّلته يدوياً.
        </p>
        <form method="POST" action="admin.php?section=backups" enctype="multipart/form-data" onsubmit="return confirmAndSubmit(this, 'تحذير: سيتم استبدال جميع بيانات الموقع الحالية بالكامل بمحتوى هذا الملف نهائياً. هل أنت متأكد؟')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="backup_restore">
            <div class="field-row">
                <label class="field-label">ملف النسخة الاحتياطية (JSON)</label>
                <input type="file" name="backup_file" class="text-input" accept="application/json,.json" required>
            </div>
            <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-clock-rotate-left"></i> استعادة البيانات من هذا الملف</button>
        </form>
    </div>
    <?php
}
