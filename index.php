<?php
// ============================================================
// منصة استضافتي - نظام متكامل (تسجيل حقيقي + طلبات + لوحة أدمن)
// ============================================================

require_once __DIR__ . '/includes/bootstrap.php';

if (isset($_GET['ajax'])) {
    markAjaxRequest();
}

function safeNextUrl($raw) {
    $raw = (string)($raw ?? '');
    if ($raw === '') return null;
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) return null;
    if (strpos($raw, '//') === 0) return null;
    return $raw;
}

// رابط نظيف للتطبيق بعد تسجيل الدخول (domain/app) عبر إعادة كتابة .htaccess،
// مع الإبقاء على index.php?app=1 يعمل دائماً كخيار احتياطي إن تعذّرت الإعادة.
function appUrl($queryString = '') {
    return 'app' . ($queryString !== '' ? '?' . $queryString : '');
}

// ============================================================
// التسجيل وتسجيل الدخول
// ============================================================

function handleRegister(PDO $pdo) {
    csrfCheck();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $captchaInput = trim($_POST['captcha'] ?? '');
    $refCode = trim($_POST['ref'] ?? '') ?: trim($_COOKIE['ref_code'] ?? '');

    $expectedCaptcha = $_SESSION['captcha_code'] ?? null;
    unset($_SESSION['captcha_code']);
    if (!$expectedCaptcha || strcasecmp($captchaInput, $expectedCaptcha) !== 0) {
        return 'كود التحقق غير صحيح، حاول مرة أخرى.';
    }

    if ($name === '') {
        return 'الرجاء إدخال الاسم الكامل.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'البريد الإلكتروني غير صحيح.';
    }
    if (strlen($password) < 6) {
        return 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.';
    }
    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        return 'هذا البريد الإلكتروني مسجل مسبقاً.';
    }

    $referredBy = null;
    if ($refCode !== '') {
        $refStmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ?');
        $refStmt->execute([$refCode]);
        $refUser = $refStmt->fetch(PDO::FETCH_ASSOC);
        if ($refUser) $referredBy = (int)$refUser['id'];
    }

    $pdo->prepare('INSERT INTO users (name, email, password_hash, referral_code, referred_by) VALUES (?,?,?,?,?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), generateReferralCode(), $referredBy]);

    $_SESSION['user_id'] = (int)$pdo->lastInsertId();
    return null;
}

function handleLogin(PDO $pdo) {
    csrfCheck();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'الرجاء إدخال بريد إلكتروني صحيح.';
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['password_hash']) {
        return 'لا يوجد حساب بهذا البريد الإلكتروني (أو أنه مسجّل عبر Google، جرّب الدخول بواسطته).';
    }
    if (!password_verify($password, $user['password_hash'])) {
        return 'كلمة المرور غير صحيحة.';
    }

    $_SESSION['user_id'] = (int)$user['id'];
    return null;
}

// ============================================================
// تسجيل الدخول عبر Google
// ============================================================

function googleRedirectUri() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/index.php?action=google_callback';
}

function handleGoogleLoginRedirect(PDO $pdo) {
    $clientId = getSetting($pdo, 'google_client_id', '');
    if ($clientId === '') {
        header('Location: index.php?page=login&err=' . urlencode('تسجيل الدخول عبر Google غير مفعّل حالياً.'));
        exit;
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    $_SESSION['google_oauth_next'] = safeNextUrl($_GET['next'] ?? '') ?: '';

    $params = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => googleRedirectUri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

function handleGoogleCallback(PDO $pdo) {
    $clientId = getSetting($pdo, 'google_client_id', '');
    $clientSecret = getSetting($pdo, 'google_client_secret', '');
    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $storedState = $_SESSION['google_oauth_state'] ?? '';
    unset($_SESSION['google_oauth_state']);

    if ($clientId === '' || $clientSecret === '' || $code === '' || !hash_equals($storedState, $state)) {
        header('Location: index.php?page=login&err=' . urlencode('تعذر تسجيل الدخول عبر Google، حاول مجدداً.'));
        exit;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => googleRedirectUri(),
            'grant_type' => 'authorization_code',
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $tokenResponse = curl_exec($ch);
    $tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $tokenData = json_decode((string)$tokenResponse, true);

    if ($tokenHttpCode !== 200 || empty($tokenData['access_token'])) {
        header('Location: index.php?page=login&err=' . urlencode('تعذر تسجيل الدخول عبر Google.'));
        exit;
    }

    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenData['access_token']],
        CURLOPT_TIMEOUT => 20,
    ]);
    $userInfoResponse = curl_exec($ch);
    curl_close($ch);
    $googleUser = json_decode((string)$userInfoResponse, true);

    $email = strtolower(trim($googleUser['email'] ?? ''));
    $name = trim($googleUser['name'] ?? '') ?: $email;
    $googleId = $googleUser['sub'] ?? null;

    if ($email === '') {
        header('Location: index.php?page=login&err=' . urlencode('تعذر الحصول على بريدك الإلكتروني من Google.'));
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        if ($googleId && !$user['google_id']) {
            $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$googleId, $user['id']]);
        }
        $_SESSION['user_id'] = (int)$user['id'];
    } else {
        $pdo->prepare('INSERT INTO users (name, email, google_id) VALUES (?,?,?)')->execute([$name, $email, $googleId]);
        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
    }

    $next = $_SESSION['google_oauth_next'] ?? '';
    unset($_SESSION['google_oauth_next']);
    header('Location: ' . ($next ?: appUrl()));
    exit;
}

$registerError = null;
$loginError = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] === 'google_login') {
        handleGoogleLoginRedirect($pdo);
    }
    if ($_GET['action'] === 'google_callback') {
        handleGoogleCallback($pdo);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $next = safeNextUrl($_POST['next'] ?? '');

    if ($_POST['action'] === 'register') {
        $registerError = handleRegister($pdo);
        if (!$registerError) {
            header('Location: ' . ($next ?: appUrl()));
            exit;
        }
    } elseif ($_POST['action'] === 'login') {
        $loginError = handleLogin($pdo);
        if (!$loginError) {
            header('Location: ' . ($next ?: appUrl()));
            exit;
        }
    } elseif ($_POST['action'] === 'submit_order') {
        requireLogin();
        $planId = (int)($_POST['plan_id'] ?? 0);
        [$newOrderId, $orderError] = handleSubmitOrder($pdo);
        if ($orderError) {
            header('Location: ' . appUrl('buy=' . $planId . '&order_error=' . urlencode($orderError)));
        } else {
            header('Location: ' . appUrl('ordered=1&order_id=' . $newOrderId));
        }
        exit;
    } elseif ($_POST['action'] === 'top_up') {
        requireLogin();
        $topUpError = handleTopUpBalance($pdo);
        header('Location: ' . appUrl($topUpError ? 'topup_error=' . urlencode($topUpError) : 'topup=1'));
        exit;
    } elseif (in_array($_POST['action'], ['plan_save', 'plan_delete', 'pm_save', 'pm_save_binance', 'pm_save_asiacell', 'pm_delete', 'currency_save', 'currency_delete', 'coupon_save', 'coupon_delete', 'broadcast_notification', 'order_fulfill', 'order_fulfill_renewal', 'order_reject', 'topup_approve', 'topup_reject', 'settings_save', 'backup_settings_save', 'backup_send_telegram', 'backup_download', 'backup_restore'], true)) {
        requireAdmin($pdo);
        $action = $_POST['action'];
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

    if ($action === 'coupon_save') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $discountPct = (float)($_POST['discount_pct'] ?? 0);
        $expiresAt = trim($_POST['expires_at'] ?? '');
        $expiresTs = $expiresAt !== '' ? strtotime($expiresAt) : false;

        if ($code === '' || $discountPct <= 0 || $discountPct > 100 || !$expiresTs) {
            adminRedirect('settings', null, 'الرجاء تعبئة جميع الحقول بشكل صحيح (نسبة خصم بين 0 و100، وتاريخ انتهاء صالح).');
        }
        if ($expiresTs <= time()) {
            adminRedirect('settings', null, 'لا يمكن إنشاء كوبون بتاريخ انتهاء صلاحية في الماضي.');
        }

        $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM coupons WHERE code = ?');
        $dupStmt->execute([$code]);
        if ((int)$dupStmt->fetchColumn() > 0) {
            adminRedirect('settings', null, 'يوجد كوبون بنفس هذا الكود مسبقاً، استخدم كوداً آخر أو احذف القديم أولاً.');
        }

        $admin = currentUser($pdo);
        $pdo->prepare('INSERT INTO coupons (code, discount_pct, expires_at, is_active, created_by) VALUES (?,?,?,1,?)')
            ->execute([$code, $discountPct, date('Y-m-d H:i:s', $expiresTs), (int)$admin['id']]);

        $pctDisplay = rtrim(rtrim(number_format($discountPct, 2), '0'), '.');
        $userIds = $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($userIds as $uid) {
            notifyUser($pdo, (int)$uid, '🏷️ كوبون خصم جديد: ' . $code, 'استخدم الكود "' . $code . '" للحصول على خصم ' . $pctDisplay . '% عند طلب باقة VPS جديدة، حتى ' . date('Y-m-d', $expiresTs) . '.', 'coupon');
        }

        adminRedirect('settings', 'تم إنشاء الكوبون وإرسال إشعار إلى ' . count($userIds) . ' مستخدم.');
    }

    if ($action === 'coupon_delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$id]);
        adminRedirect('settings', 'تم حذف الكوبون.');
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
        notifyUser($pdo, $order['user_id'], ' تم قبول طلبك', 'تم تفعيل استضافتك (' . $hostName . ') وهي جاهزة الآن ضمن "سيرفراتي".', 'order_approved');
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
        notifyUser($pdo, $order['user_id'], ' تم تجديد الاستضافة', 'تم تجديد استضافتك (' . $hosting['name'] . ') بنجاح حتى ' . $newExpiry . '.', 'order_approved');
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
            notifyUser($pdo, $order['user_id'], ' تم رفض طلبك', $rejectMsg, 'order_rejected');
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
            notifyUser($pdo, $inv['user_id'], ' تم شحن رصيدك', 'تم إضافة $' . money($inv['amount']) . ' إلى رصيد حسابك.', 'topup_approved');
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
            notifyUser($pdo, $inv['user_id'], ' تم رفض طلب الشحن', 'لم نتمكن من تأكيد إيصال التحويل الخاص بك. تواصل مع الدعم الفني.', 'topup_rejected');
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
            // لوجو أصغر من 192×192 يجعل Chrome يرفض استخدامه كأيقونة تثبيت (PWA) ويستبدله
            // بأيقونة افتراضية مختلفة تماماً - وهو السبب المتكرر وراء "أيقونة التطبيق ليست نفس الشعار"
            $logoDims = @getimagesize(BASE_DIR . '/' . $logoPath);
            if ($logoDims && ($logoDims[0] < 192 || $logoDims[1] < 192)) {
                @unlink(BASE_DIR . '/' . $logoPath);
                adminRedirect('settings', null, 'شعار الموقع صغير جداً (' . $logoDims[0] . '×' . $logoDims[1] . ' بكسل). لضمان ظهوره بشكل صحيح كأيقونة تثبيت للتطبيق على الهاتف، ارفع صورة مربعة بحجم 512×512 بكسل على الأقل.');
            }
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

    adminRedirect('orders');

    }
}

// ============================================================
// المساعد الذكي - نقطة اتصال AJAX
// ============================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'ai_chat' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $section = (string)($body['section'] ?? 'home');
    $userMessage = trim((string)($body['message'] ?? ''));
    $history = is_array($body['history'] ?? null) ? array_slice($body['history'], -12) : [];

    if ($userMessage === '') {
        echo json_encode(['error' => 'الرسالة فارغة.']);
        exit;
    }

    $systemPrompts = [
        'home' => 'أنت "المساعد الذكي" داخل تطبيق استضافة خوادم VPS. تساعد المستخدمين في كل ما يخص تنصيب وإدارة وحل مشاكل خوادم VPS وتثبيت البرمجيات والمكتبات اللازمة وتعليمهم خطوة بخطوة، وكل ما يخص استخدام منصتنا (الباقات، الطلبات، الفواتير، الدفع).',
        'explain' => 'أنت خبير أوامر لينكس وVPS. اشرح أي أمر يرسله المستخدم: ماذا يفعل، متى يُستخدم، ومثال عملي عليه. إن لم يرسل أمراً بل سؤالاً عاماً عن VPS أو لينكس أجب عليه بنفس الروح، وضع الأوامر داخل أسطر كود.',
        'suggestions' => 'أنت مساعد ذكي متخصص باستضافة المواقع وخوادم VPS، تقدّم اقتراحات ذكية ومفيدة لإدارة السيرفر وتحسين تجربة المستخدم على منصتنا.',
    ];
    $systemPrompt = $systemPrompts[$section] ?? $systemPrompts['home'];
    // قيود ثابتة على كل الأقسام: الإيجاز (لسرعة الرد) والاقتصار على مواضيع VPS/لينكس/منصتنا فقط
    $systemPrompt .= ' أجب بالعربية دائماً، بإيجاز شديد ووضوح (فقرة أو نقاط قصيرة، بدون حشو)، إلا إذا طلب المستخدم صراحة تفصيلاً أكبر.'
        . ' اقتصر حصرياً على مواضيع استضافة السيرفرات (VPS)، إدارة لينكس والسيرفرات، واستخدام منصتنا (الباقات، الطلبات، الفواتير، الدفع، الحساب). '
        . 'إن سألك المستخدم عن أي موضوع آخر لا علاقة له بذلك، اعتذر بلطف بجملة واحدة ووضّح أنك مخصص فقط لمواضيع الاستضافة والسيرفرات، ولا تجب عن السؤال خارج هذا النطاق مهما كان.';
    $systemPrompt .= aiAccountStatusContext($pdo, (int)$_SESSION['user_id']);

    $messages = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($history as $h) {
        if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'], true)) {
            $messages[] = ['role' => (string)$h['role'], 'content' => (string)$h['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    [$reply, $aiError] = callAiApi($pdo, $messages);
    echo json_encode($aiError ? ['error' => $aiError] : ['reply' => $reply]);
    exit;
}

// ============================================================
// تحديد الإشعارات كمقروءة
// ============================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_notifications_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
        ->execute([(int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_notification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')
        ->execute([(int)($body['id'] ?? 0), (int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_all_notifications' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $pdo->prepare('DELETE FROM notifications WHERE user_id = ?')->execute([(int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'update_auto_renew' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $enabled = !empty($body['enabled']) ? 1 : 0;
    $pdo->prepare('UPDATE users SET auto_renew = ? WHERE id = ?')->execute([$enabled, (int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true, 'enabled' => (bool)$enabled]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'complete_onboarding' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $enabled = !empty($body['auto_renew']) ? 1 : 0;
    $pdo->prepare('UPDATE users SET auto_renew = ?, onboarding_done = 1 WHERE id = ?')->execute([$enabled, (int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'request_renewal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $hostingStmt = $pdo->prepare('SELECT * FROM hosting WHERE id = ? AND user_id = ?');
    $hostingStmt->execute([(int)($body['hosting_id'] ?? 0), (int)$_SESSION['user_id']]);
    $hosting = $hostingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$hosting) {
        http_response_code(404);
        echo json_encode(['error' => 'الاستضافة غير موجودة.']);
        exit;
    }

    [$ok, $msg] = createRenewalRequest($pdo, $hosting);
    if (!$ok) {
        http_response_code(400);
        echo json_encode(['error' => $msg]);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => $msg]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'verify_binance_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $planId = (int)($body['plan_id'] ?? 0);
    $paymentMethodId = (int)($body['payment_method_id'] ?? 0);
    $binanceOrderId = trim((string)($body['binance_order_id'] ?? ''));

    $planStmt = $pdo->prepare('SELECT * FROM vps_plans WHERE id = ? AND is_active = 1');
    $planStmt->execute([$planId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        http_response_code(400);
        echo json_encode(['error' => 'الباقة المختارة غير متاحة.']);
        exit;
    }

    $pmStmt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND is_active = 1 AND method_type = 'binance'");
    $pmStmt->execute([$paymentMethodId]);
    $pm = $pmStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pm) {
        http_response_code(400);
        echo json_encode(['error' => 'طريقة الدفع غير متاحة.']);
        exit;
    }

    if ($binanceOrderId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'الرجاء إدخال رقم عملية Binance (Order ID).']);
        exit;
    }
    $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE binance_order_id = ?');
    $dupStmt->execute([$binanceOrderId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'رقم عملية Binance هذا مستخدم مسبقاً لطلب أو شحن رصيد آخر.']);
        exit;
    }

    $user = currentUser($pdo);
    $referralDiscountPct = 0.0;
    if (!empty($user['referred_by'])) {
        $priorOrdersStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
        $priorOrdersStmt->execute([$userId]);
        if ((int)$priorOrdersStmt->fetchColumn() === 0) {
            $referralDiscountPct = (float)getSetting($pdo, 'referral_discount_pct', 0);
        }
    }
    $amount = (float)$plan['price'];
    if ($referralDiscountPct > 0) {
        $amount = round($amount * (1 - $referralDiscountPct / 100), 2);
    }

    $couponCode = strtoupper(trim((string)($body['coupon_code'] ?? '')));
    $couponPct = validCouponDiscountPct($pdo, $couponCode);
    if ($couponPct !== null) {
        $amount = round($amount * (1 - $couponPct / 100), 2);
    } else {
        $couponCode = null;
    }

    [$verified, $result] = verifyBinanceOrder($pm, $binanceOrderId, $amount);
    if (!$verified) {
        http_response_code(400);
        echo json_encode(['error' => $result]);
        exit;
    }

    $billingCycle = ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $pdo->prepare('INSERT INTO orders (user_id, plan_id, payment_method_id, amount, billing_cycle, status, coupon_code) VALUES (?,?,?,?,?,?,?)')
        ->execute([$userId, $planId, $paymentMethodId, $amount, $billingCycle, 'pending', $couponCode]);
    $orderId = (int)$pdo->lastInsertId();

    $cycleLabel = $billingCycle === 'yearly' ? 'سنوي' : 'شهري';
    $invDescription = 'اشتراك باقة ' . $plan['name'] . ' (' . $cycleLabel . ') - Binance Pay';
    if ($couponPct !== null) {
        $invDescription .= ' - كوبون ' . $couponCode . ' (خصم ' . rtrim(rtrim(number_format($couponPct, 2), '0'), '.') . '%)';
    }
    try {
        $pdo->prepare('INSERT INTO invoices (user_id, order_id, invoice_number, amount, status, description, binance_order_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId, $orderId, nextInvoiceNumber($pdo), $amount, 'paid', $invDescription, $binanceOrderId]);
    } catch (PDOException $e) {
        // فهرس التفرّد منع إدخال رقم عملية مستخدم مسبقاً (حالة تسابق: طلبان متزامنان بنفس الرقم)
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$orderId]);
        http_response_code(400);
        echo json_encode(['error' => 'رقم عملية Binance هذا مستخدم مسبقاً لطلب أو شحن رصيد آخر.']);
        exit;
    }

    notifyAdmins($pdo, ' طلب اشتراك جديد (Binance)', 'قدّم ' . $user['name'] . ' طلب اشتراك في باقة "' . $plan['name'] . '" بمبلغ $' . money($amount) . ' عبر Binance Pay (تم التحقق تلقائياً). راجع الطلب لتفعيل الاستضافة.', 'system');

    echo json_encode(['ok' => true, 'order_id' => $orderId]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'verify_binance_topup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $paymentMethodId = (int)($body['payment_method_id'] ?? 0);
    $amount = (float)($body['amount'] ?? 0);
    $binanceOrderId = trim((string)($body['binance_order_id'] ?? ''));

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'الرجاء إدخال مبلغ صحيح.']);
        exit;
    }

    $pmStmt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND is_active = 1 AND method_type = 'binance'");
    $pmStmt->execute([$paymentMethodId]);
    $pm = $pmStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pm) {
        http_response_code(400);
        echo json_encode(['error' => 'طريقة الدفع غير متاحة.']);
        exit;
    }

    if ($binanceOrderId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'الرجاء إدخال رقم عملية Binance (Order ID).']);
        exit;
    }
    $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE binance_order_id = ?');
    $dupStmt->execute([$binanceOrderId]);
    if ((int)$dupStmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'رقم عملية Binance هذا مستخدم مسبقاً لطلب أو شحن رصيد آخر.']);
        exit;
    }

    [$verified, $result] = verifyBinanceOrder($pm, $binanceOrderId, $amount);
    if (!$verified) {
        http_response_code(400);
        echo json_encode(['error' => $result]);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$amount, $userId]);
        $pdo->prepare('INSERT INTO invoices (user_id, invoice_number, amount, status, description, binance_order_id) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, nextInvoiceNumber($pdo), $amount, 'paid', 'شحن رصيد عبر Binance Pay', $binanceOrderId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        // فهرس التفرّد منع إدخال رقم عملية مستخدم مسبقاً (حالة تسابق: طلبان متزامنان بنفس الرقم)
        http_response_code(400);
        echo json_encode(['error' => 'رقم عملية Binance هذا مستخدم مسبقاً لطلب أو شحن رصيد آخر.']);
        exit;
    }

    $user = currentUser($pdo);
    notifyUser($pdo, $userId, ' تم شحن رصيدك', 'تم إضافة $' . money($amount) . ' إلى رصيد حسابك تلقائياً عبر Binance Pay.', 'topup_approved');

    echo json_encode(['ok' => true, 'balance' => (float)$user['balance']]);
    exit;
}

// ============================================================
// آسياسيل - تحويل رصيد تلقائي (تدفق ثلاث خطوات عبر AJAX)
// الحالة العابرة لعملية آسياسيل تُخزَّن في $_SESSION['asiacell_flow'] فقط (ليست في قاعدة البيانات ولا في ملفات)
// ============================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'asiacell_start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $context = ($body['context'] ?? '') === 'topup' ? 'topup' : 'order';
    $paymentMethodId = (int)($body['payment_method_id'] ?? 0);
    $phone = trim((string)($body['phone'] ?? ''));

    // يمنع فتح عملية جديدة إن كان هناك مبلغ سبق تحويله فعلياً ولم يُستكمل أو يُلغَ بعد (المال لا يُترك بلا حساب)
    $existingFlow = $_SESSION['asiacell_flow'] ?? null;
    if ($existingFlow && (float)($existingFlow['amount_iqd_paid'] ?? 0) > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'لديك عملية دفع آسياسيل غير مكتملة، أكملها أو ألغِها أولاً.', 'has_pending' => true]);
        exit;
    }

    if (!preg_match('/^(077|078|079)\d{8}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'رقم هاتف آسياسيل غير صحيح، يجب أن يكون بصيغة 07xxxxxxxxx.']);
        exit;
    }

    $pmStmt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND is_active = 1 AND method_type = 'asiacell'");
    $pmStmt->execute([$paymentMethodId]);
    $pm = $pmStmt->fetch(PDO::FETCH_ASSOC);
    if (!$pm) {
        http_response_code(400);
        echo json_encode(['error' => 'طريقة الدفع غير متاحة.']);
        exit;
    }
    $extras = json_decode($pm['method_extras'] ?? '{}', true) ?: [];
    $receiverMsisdn = trim((string)($extras['receiver_msisdn'] ?? ''));
    $exchangeRate = (float)($extras['exchange_rate'] ?? 0);
    $maxTransfer = (int)($extras['max_transfer'] ?? 10000);
    if ($maxTransfer < 1000) $maxTransfer = 10000;
    if ($receiverMsisdn === '' || $exchangeRate <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'لم يتم إعداد آسياسيل من الإدارة بعد.']);
        exit;
    }

    $planId = 0;
    $billingCycle = 'monthly';
    if ($context === 'order') {
        $planId = (int)($body['plan_id'] ?? 0);
        $planStmt = $pdo->prepare('SELECT * FROM vps_plans WHERE id = ? AND is_active = 1');
        $planStmt->execute([$planId]);
        $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            http_response_code(400);
            echo json_encode(['error' => 'الباقة المختارة غير متاحة.']);
            exit;
        }
        $user = currentUser($pdo);
        $referralDiscountPct = 0.0;
        if (!empty($user['referred_by'])) {
            $priorOrdersStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
            $priorOrdersStmt->execute([$userId]);
            if ((int)$priorOrdersStmt->fetchColumn() === 0) {
                $referralDiscountPct = (float)getSetting($pdo, 'referral_discount_pct', 0);
            }
        }
        $amountUsd = (float)$plan['price'];
        if ($referralDiscountPct > 0) {
            $amountUsd = round($amountUsd * (1 - $referralDiscountPct / 100), 2);
        }
        $couponCode = strtoupper(trim((string)($body['coupon_code'] ?? '')));
        $couponPct = validCouponDiscountPct($pdo, $couponCode);
        if ($couponPct !== null) {
            $amountUsd = round($amountUsd * (1 - $couponPct / 100), 2);
        } else {
            $couponCode = null;
        }
        $billingCycle = ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    } else {
        $couponCode = null;
        $amountUsd = (float)($body['amount'] ?? 0);
        if ($amountUsd <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'الرجاء إدخال مبلغ صحيح.']);
            exit;
        }
    }

    // آسياسيل يحوّل فقط بمضاعفات الألف دينار (1000، 2000...)؛ الفرق الناتج عن التقريب للأعلى يُضاف كرصيد إضافي للحساب
    // نقرّب أولاً لمنزلتين عشريتين لإزالة شوائب الفاصلة العائمة (مثال: 2000.0000000002 يجب ألا تصبح 3000)
    $amountIqdExact = round($amountUsd * $exchangeRate, 2);
    $amountIqdTotal = (int)(ceil($amountIqdExact / 1000) * 1000);
    $overpayUsd = $exchangeRate > 0 ? round(($amountIqdTotal - $amountIqdExact) / $exchangeRate, 2) : 0;

    $api = new AsiaCellAPI();
    [$success, $result] = $api->login($phone);
    if (!$success) {
        logAsiacellDebug($pdo, 'login', $api);
        http_response_code(400);
        echo json_encode(['error' => $result]);
        exit;
    }

    $_SESSION['asiacell_flow'] = [
        'api' => $api->getState(),
        'phone' => $phone,
        'context' => $context,
        'plan_id' => $planId,
        'payment_method_id' => $paymentMethodId,
        'billing_cycle' => $billingCycle,
        'coupon_code' => $couponCode,
        'amount_usd' => $amountUsd,
        'overpay_usd' => $overpayUsd,
        'exchange_rate' => $exchangeRate,
        'amount_iqd_total' => $amountIqdTotal,
        'amount_iqd_paid' => 0,
        'current_chunk' => 0,
        'max_transfer' => $maxTransfer,
        'receiver_msisdn' => $receiverMsisdn,
        'step' => 'sms',
    ];

    echo json_encode(['ok' => true, 'amount_iqd_total' => $amountIqdTotal]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'asiacell_verify_sms' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $flow = $_SESSION['asiacell_flow'] ?? null;
    if (!$flow || ($flow['step'] ?? '') !== 'sms') {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية العملية، ابدأ من جديد.']);
        exit;
    }

    $code = trim((string)($body['code'] ?? ''));
    if (!preg_match('/^\d{4,6}$/', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'رمز التحقق يجب أن يكون من 4 إلى 6 أرقام.']);
        exit;
    }

    $api = new AsiaCellAPI($flow['api']);
    [$success, $result] = $api->verifySms($code);
    if (!$success) {
        logAsiacellDebug($pdo, 'verifySms', $api);
        http_response_code(400);
        echo json_encode(['error' => $result]);
        exit;
    }

    $chunk = min($flow['max_transfer'], $flow['amount_iqd_total'] - $flow['amount_iqd_paid']);
    [$success2, $result2] = $api->startTransfer($chunk, $flow['receiver_msisdn']);
    if (!$success2) {
        logAsiacellDebug($pdo, 'startTransfer', $api);
        http_response_code(400);
        echo json_encode(['error' => $result2]);
        exit;
    }

    $flow['api'] = $api->getState();
    $flow['current_chunk'] = $chunk;
    $flow['step'] = 'confirm';
    $_SESSION['asiacell_flow'] = $flow;

    echo json_encode([
        'ok' => true,
        'chunk_amount' => $chunk,
        'total' => $flow['amount_iqd_total'],
        'remaining_after' => $flow['amount_iqd_total'] - $flow['amount_iqd_paid'] - $chunk,
    ]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'asiacell_confirm_transfer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $flow = $_SESSION['asiacell_flow'] ?? null;
    if (!$flow || ($flow['step'] ?? '') !== 'confirm') {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية العملية، ابدأ من جديد.']);
        exit;
    }

    $code = trim((string)($body['code'] ?? ''));
    if (!preg_match('/^\d{4,6}$/', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'رمز التأكيد يجب أن يكون من 4 إلى 6 أرقام.']);
        exit;
    }

    $api = new AsiaCellAPI($flow['api']);
    [$success, $result] = $api->confirmTransfer($code);
    if (!$success) {
        logAsiacellDebug($pdo, 'confirmTransfer', $api);
        http_response_code(400);
        echo json_encode(['error' => $result]);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $flow['amount_iqd_paid'] = (int)$flow['amount_iqd_paid'] + (int)$flow['current_chunk'];
    $fullyPaid = $flow['amount_iqd_paid'] >= $flow['amount_iqd_total'];

    if (!$fullyPaid) {
        // بقي جزء آخر - نبدأ فوراً تحويل الجزء التالي (التوكن ما زال صالحاً، لا حاجة لتسجيل دخول جديد)
        $nextChunk = min($flow['max_transfer'], $flow['amount_iqd_total'] - $flow['amount_iqd_paid']);
        [$success2, $result2] = $api->startTransfer($nextChunk, $flow['receiver_msisdn']);
        if (!$success2) {
            // فشل بدء الجزء التالي رغم أن الجزء السابق تحوّل فعلاً؛ نضيف ما تم دفعه كرصيد بدل أن يضيع
            logAsiacellDebug($pdo, 'startTransfer_chunk', $api);
            $creditedUsd = $flow['exchange_rate'] > 0 ? round($flow['amount_iqd_paid'] / $flow['exchange_rate'], 2) : 0;
            if ($creditedUsd > 0) {
                $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$creditedUsd, $userId]);
                $pdo->prepare('INSERT INTO invoices (user_id, invoice_number, amount, status, description) VALUES (?,?,?,?,?)')
                    ->execute([$userId, nextInvoiceNumber($pdo), $creditedUsd, 'paid', 'رصيد مسترد من تحويل آسياسيل غير مكتمل']);
                notifyUser($pdo, $userId, '⚠️ توقف تحويل آسياسيل جزئياً', 'تم تحويل ' . number_format($flow['amount_iqd_paid']) . ' د.ع بنجاح، لكن تعذر إكمال المبلغ المتبقي. أضفنا $' . money($creditedUsd) . ' كرصيد إلى حسابك.', 'system');
            }
            unset($_SESSION['asiacell_flow']);
            http_response_code(400);
            echo json_encode(['error' => 'تم تحويل جزء من المبلغ لكن تعذر إكمال الباقي: ' . $result2, 'partial_stopped' => true, 'credited_usd' => $creditedUsd]);
            exit;
        }

        $flow['api'] = $api->getState();
        $flow['current_chunk'] = $nextChunk;
        $_SESSION['asiacell_flow'] = $flow;

        echo json_encode([
            'ok' => true,
            'done' => false,
            'paid' => $flow['amount_iqd_paid'],
            'total' => $flow['amount_iqd_total'],
            'next_chunk' => $nextChunk,
        ]);
        exit;
    }

    // اكتمل الدفع بالكامل - يُنجز الطلب/الشحن بالمبلغ الأصلي بالدولار، وفرق التقريب (إن وُجد) يُضاف كرصيد
    $amountUsd = (float)$flow['amount_usd'];
    $overpayUsd = round((float)($flow['overpay_usd'] ?? 0), 2);

    if ($flow['context'] === 'order') {
        $couponCode = $flow['coupon_code'] ?? null;
        $pdo->prepare('INSERT INTO orders (user_id, plan_id, payment_method_id, amount, billing_cycle, status, coupon_code) VALUES (?,?,?,?,?,?,?)')
            ->execute([$userId, $flow['plan_id'], $flow['payment_method_id'], $amountUsd, $flow['billing_cycle'], 'pending', $couponCode]);
        $orderId = (int)$pdo->lastInsertId();

        $planStmt = $pdo->prepare('SELECT name FROM vps_plans WHERE id = ?');
        $planStmt->execute([$flow['plan_id']]);
        $planName = $planStmt->fetchColumn() ?: '-';

        $cycleLabel = $flow['billing_cycle'] === 'yearly' ? 'سنوي' : 'شهري';
        $invDescription = 'اشتراك باقة ' . $planName . ' (' . $cycleLabel . ') - آسياسيل';
        if ($couponCode) {
            $invDescription .= ' - كوبون ' . $couponCode;
        }
        $pdo->prepare('INSERT INTO invoices (user_id, order_id, invoice_number, amount, status, description) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $orderId, nextInvoiceNumber($pdo), $amountUsd, 'paid', $invDescription]);

        if ($overpayUsd > 0.01) {
            $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$overpayUsd, $userId]);
            $pdo->prepare('INSERT INTO invoices (user_id, invoice_number, amount, status, description) VALUES (?,?,?,?,?)')
                ->execute([$userId, nextInvoiceNumber($pdo), $overpayUsd, 'paid', 'فرق تقريب تحويل آسياسيل (للمضاعف الأقرب)']);
        }

        $user = currentUser($pdo);
        notifyAdmins($pdo, ' طلب اشتراك جديد (آسياسيل)', 'قدّم ' . $user['name'] . ' طلب اشتراك في باقة "' . $planName . '" بمبلغ $' . money($amountUsd) . ' عبر آسياسيل (تم التحقق تلقائياً). راجع الطلب لتفعيل الاستضافة.', 'system');

        unset($_SESSION['asiacell_flow']);
        echo json_encode(['ok' => true, 'done' => true, 'order_id' => $orderId, 'overpay_credited' => $overpayUsd]);
        exit;
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$amountUsd + $overpayUsd, $userId]);
    $pdo->prepare('INSERT INTO invoices (user_id, invoice_number, amount, status, description) VALUES (?,?,?,?,?)')
        ->execute([$userId, nextInvoiceNumber($pdo), $amountUsd, 'paid', 'شحن رصيد عبر آسياسيل']);
    if ($overpayUsd > 0.01) {
        $pdo->prepare('INSERT INTO invoices (user_id, invoice_number, amount, status, description) VALUES (?,?,?,?,?)')
            ->execute([$userId, nextInvoiceNumber($pdo), $overpayUsd, 'paid', 'فرق تقريب تحويل آسياسيل (للمضاعف الأقرب)']);
    }
    $pdo->commit();

    notifyUser($pdo, $userId, ' تم شحن رصيدك', 'تم إضافة $' . money($amountUsd + $overpayUsd) . ' إلى رصيد حسابك تلقائياً عبر آسياسيل.', 'topup_approved');

    unset($_SESSION['asiacell_flow']);
    $user = currentUser($pdo);
    echo json_encode(['ok' => true, 'done' => true, 'balance' => (float)$user['balance'], 'overpay_credited' => $overpayUsd]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'asiacell_cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة.']);
        exit;
    }
    $flow = $_SESSION['asiacell_flow'] ?? null;
    $creditedUsd = 0;
    if ($flow && (float)($flow['amount_iqd_paid'] ?? 0) > 0 && (float)($flow['exchange_rate'] ?? 0) > 0) {
        $userId = (int)$_SESSION['user_id'];
        $creditedUsd = round($flow['amount_iqd_paid'] / $flow['exchange_rate'], 2);
        $pdo->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$creditedUsd, $userId]);
        $pdo->prepare('INSERT INTO invoices (user_id, invoice_number, amount, status, description) VALUES (?,?,?,?,?)')
            ->execute([$userId, nextInvoiceNumber($pdo), $creditedUsd, 'paid', 'رصيد مسترد من تحويل آسياسيل تم إلغاؤه بعد دفع جزئي']);
        notifyUser($pdo, $userId, ' تم استرداد رصيدك', 'تم إلغاء عملية آسياسيل بعد تحويل ' . number_format($flow['amount_iqd_paid']) . ' د.ع، وأضفنا $' . money($creditedUsd) . ' كرصيد إلى حسابك.', 'system');
    }
    unset($_SESSION['asiacell_flow']);
    echo json_encode(['ok' => true, 'credited_usd' => $creditedUsd]);
    exit;
}

// ============================================================
// التحقق من كوبون خصم (معاينة فورية أثناء إنشاء الطلب) - التطبيق
// الفعلي والنهائي للخصم يُعاد اشتقاقه دائماً من الخادم عند إنشاء الطلب
// نفسه (بغض النظر عن طريقة الدفع)، فلا يُعتمد هنا إلا لعرض المعاينة
// ============================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'apply_coupon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'يجب تسجيل الدخول.']);
        exit;
    }

    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($body['csrf_token'] ?? ''))) {
        http_response_code(400);
        echo json_encode(['error' => 'انتهت صلاحية الجلسة، أعد تحميل الصفحة.']);
        exit;
    }

    $code = strtoupper(trim((string)($body['code'] ?? '')));
    if ($code === '') {
        http_response_code(400);
        echo json_encode(['error' => 'الرجاء إدخال كود الكوبون.']);
        exit;
    }

    $pct = validCouponDiscountPct($pdo, $code);
    if ($pct === null) {
        http_response_code(400);
        echo json_encode(['error' => 'كود الكوبون غير صحيح أو منتهي الصلاحية.']);
        exit;
    }

    echo json_encode(['ok' => true, 'code' => $code, 'discount_pct' => $pct]);
    exit;
}

// ============================================================
// تسجيل الخروج
// ============================================================

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: .');
    exit;
}

// ============================================================
// التقاط رمز رابط المشاركة (إحالة صديق)
// ============================================================

if (!empty($_GET['ref'])) {
    $refCandidate = preg_replace('/[^A-Za-z0-9]/', '', (string)$_GET['ref']);
    if ($refCandidate !== '') {
        setcookie('ref_code', $refCandidate, time() + 60 * 60 * 24 * 30, '/');
        $_COOKIE['ref_code'] = $refCandidate;
    }
}

// ============================================================
// ============================================================
// لوحة تحكم الأدمن (كانت سابقاً في admin.php منفصل، دُمجت هنا بالكامل)
// ============================================================

// ============================================================
// قسم: الطلبات
// ============================================================
// يُستخدم من داخل معالجات إجراءات لوحة التحكم (كلها الآن جزء من نفس صفحة /app؛
// لا رابط/طلب منفصل لعرضها) - يعيد التوجيه لنفس صفحة التطبيق مع تلميح بالقسم
// والرسالة، تقرأه واجهة JS لإظهار تبويب لوحة التحكم الصحيح والرسالة بعد التحميل
function adminRedirect($section, $msg = null, $err = null) {
    $qs = 'admin_section=' . urlencode($section);
    if ($msg) $qs .= '&admin_msg=' . urlencode($msg);
    if ($err) $qs .= '&admin_err=' . urlencode($err);
    header('Location: ' . appUrl($qs));
    exit;
}

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
            $statusLabel = ['pending' => ' قيد المراجعة', 'approved' => ' مقبول', 'rejected' => 'ملغى'][$o['status']] ?? $o['status'];
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
                <form method="POST" style="display:inline" onsubmit="return confirmAndSubmit(this, 'تأكيد الموافقة على تجديد هذه الاستضافة؟')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="order_fulfill_renewal">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                    <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-check"></i> الموافقة على التجديد</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirmAndSubmit(this, 'هل أنت متأكد من رفض طلب التجديد؟ سيتم إعادة المبلغ إلى رصيد المستخدم.')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="order_reject">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-xmark"></i> رفض</button>
                </form>
            </div>
            <?php elseif ($o['status'] === 'pending'): ?>
            <div class="order-actions" style="margin-top:12px">
                <button type="button" class="btn btn-accent btn-sm" onclick="toggleFulfillForm(<?php echo (int)$o['id']; ?>)"><i class="fas fa-check"></i> قبول وتفعيل الاستضافة</button>
                <form method="POST" style="display:inline" onsubmit="return confirmAndSubmit(this, 'هل أنت متأكد من رفض هذا الطلب؟')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="order_reject">
                    <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-xmark"></i> رفض</button>
                </form>
            </div>

            <div class="fulfill-form hidden" id="fulfill-<?php echo (int)$o['id']; ?>">
                <form method="POST">
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
            $statusLabel = ['pending' => ' قيد المراجعة', 'paid' => ' تم الشحن', 'rejected' => 'ملغى'][$inv['status']] ?? $inv['status'];
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
                <form method="POST" style="display:inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="topup_approve">
                    <input type="hidden" name="invoice_id" value="<?php echo (int)$inv['id']; ?>">
                    <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-check"></i> تأكيد الاستلام وإضافة الرصيد</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirmAndSubmit(this, 'هل أنت متأكد من رفض طلب الشحن؟')">
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
        <form method="POST" enctype="multipart/form-data">
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
            <form method="POST" enctype="multipart/form-data" style="margin-top:14px">
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
            <form method="POST" style="margin-top:8px" onsubmit="return confirmAndSubmit(this, 'حذف هذه الباقة نهائياً؟')">
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
        <form method="POST" enctype="multipart/form-data">
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
        <form method="POST" enctype="multipart/form-data">
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
        <form method="POST" enctype="multipart/form-data">
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
            <form method="POST" enctype="multipart/form-data" style="margin-top:14px">
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
            <form method="POST" style="margin-top:8px" onsubmit="return confirmAndSubmit(this, 'حذف طريقة الدفع هذه نهائياً؟')">
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
    $coupons = $pdo->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="settings-subtabs">
        <button type="button" class="subtab-btn active" onclick="showSettingsPanel(this, 'site')"><i class="fas fa-shop"></i> الموقع</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'referral')"><i class="fas fa-share-nodes"></i> الدعوات</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'ai')"><i class="fas fa-robot"></i> الذكاء الاصطناعي</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'google')"><i class="fab fa-google"></i> Google</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'policies')"><i class="fas fa-file-contract"></i> السياسات</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'currencies')"><i class="fas fa-coins"></i> العملات</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'notify')"><i class="fas fa-bullhorn"></i> إشعار جماعي</button>
        <button type="button" class="subtab-btn" onclick="showSettingsPanel(this, 'coupons')"><i class="fas fa-tag"></i> كوبونات الخصم</button>
    </div>

    <form method="POST" enctype="multipart/form-data">
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
            <form method="POST">
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
                <form method="POST" style="width:100%">
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
                <form method="POST" style="margin-top:8px" onsubmit="return confirmAndSubmit(this, 'حذف هذه العملة؟')">
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
            <form method="POST" onsubmit="return confirmAndSubmit(this, 'إرسال هذا الإشعار لجميع المستخدمين؟')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="broadcast_notification">
                <div class="field-row"><label class="field-label">عنوان الإشعار</label><input type="text" name="title" class="text-input" placeholder="📢 تحديث جديد" required></div>
                <div class="field-row"><label class="field-label">نص الإشعار (اختياري)</label><textarea name="body" class="text-input" placeholder="تفاصيل الإشعار..."></textarea></div>
                <button type="submit" class="btn btn-accent"><i class="fas fa-paper-plane"></i> إرسال للجميع</button>
            </form>
        </div>
    </div>

    <div class="settings-panel hidden" data-panel="coupons">
        <div class="admin-card">
            <div class="admin-card-header"><h2><i class="fas fa-tag"></i> إنشاء كوبون خصم جديد</h2></div>
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.8">
                يُطبَّق خصم الكوبون على سعر أي باقة عند إدخاله في صفحة إنشاء الطلب (فوق خصم الباقة وخصم الدعوة إن وُجدا). عند الإنشاء يصل إشعار لجميع المستخدمين وتظهر بطاقة الكوبون في الصفحة الرئيسية حتى انتهاء صلاحيته.
            </p>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="coupon_save">
                <div class="field-grid-2">
                    <div class="field-row"><label class="field-label">كود الكوبون</label><input type="text" name="code" class="text-input" placeholder="SALE25" maxlength="32" dir="ltr" style="text-transform:uppercase" required></div>
                    <div class="field-row"><label class="field-label">نسبة الخصم (%)</label><input type="number" step="0.01" min="0.01" max="100" name="discount_pct" class="text-input" placeholder="25" required></div>
                </div>
                <div class="field-row"><label class="field-label">تاريخ انتهاء الصلاحية</label><input type="datetime-local" name="expires_at" class="text-input" min="<?php echo e(date('Y-m-d\TH:i')); ?>" required></div>
                <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-plus"></i> إنشاء الكوبون وإرسال إشعار للجميع</button>
            </form>
        </div>

        <?php if ($coupons): ?>
        <div class="admin-card" style="margin-top:14px">
            <div class="admin-card-header"><h2><i class="fas fa-list"></i> الكوبونات الحالية</h2></div>
            <?php foreach ($coupons as $cp):
                $isExpired = strtotime($cp['expires_at']) <= time();
                $statusLabel = !$cp['is_active'] ? 'معطّل' : ($isExpired ? 'منتهي الصلاحية' : 'نشط');
                $statusPill = !$cp['is_active'] ? 'pill-gray' : ($isExpired ? 'pill-red' : 'pill-green');
                $pctDisplay = rtrim(rtrim(number_format((float)$cp['discount_pct'], 2), '0'), '.');
            ?>
            <div class="settings-item" style="display:block;margin-top:14px;padding-top:14px;border-top:1px solid var(--border-color)">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                    <strong style="direction:ltr"><?php echo e($cp['code']); ?></strong>
                    <span class="pill <?php echo $statusPill; ?>"><?php echo $statusLabel; ?></span>
                </div>
                <div class="text-muted" style="font-size:12px;margin-bottom:10px">
                    خصم <?php echo $pctDisplay; ?>% - ينتهي في <?php echo e(date('Y-m-d H:i', strtotime($cp['expires_at']))); ?>
                </div>
                <form method="POST" onsubmit="return confirmAndSubmit(this, 'حذف هذا الكوبون؟')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="coupon_delete">
                    <input type="hidden" name="id" value="<?php echo (int)$cp['id']; ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> حذف</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
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
        <form method="POST">
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
            <form method="POST" style="display:inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="backup_send_telegram">
                <button type="submit" class="btn btn-accent btn-sm"><i class="fab fa-telegram"></i> إرسال نسخة عبر تيليجرام الآن</button>
            </form>
            <form method="POST" style="display:inline">
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
        <form method="POST" enctype="multipart/form-data" onsubmit="return confirmAndSubmit(this, 'تحذير: سيتم استبدال جميع بيانات الموقع الحالية بالكامل بمحتوى هذا الملف نهائياً. هل أنت متأكد؟')">
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


// التوجيه
// ============================================================

if (isset($_GET['app'])) {
    if (isLoggedIn()) {
        includeAppPage($pdo);
        exit;
    }
    header('Location: index.php');
    exit;
}

$page = $_GET['page'] ?? 'landing';

if (isLoggedIn() && in_array($page, ['landing', 'login', 'register'], true)) {
    header('Location: ' . appUrl());
    exit;
}

if ($page === 'buy') {
    $target = appUrl('buy=' . (int)($_GET['plan'] ?? 0));
    if (!isLoggedIn()) {
        header('Location: index.php?page=login&next=' . urlencode($target));
    } else {
        header('Location: ' . $target);
    }
    exit;
}

if ($page === 'plans') {
    includePlansPage($pdo);
    exit;
}

if ($page === 'login') {
    includeLoginPage($pdo, $loginError ?: ($_GET['err'] ?? null));
    exit;
}

if ($page === 'register') {
    includeRegisterPage($pdo, $registerError);
    exit;
}

if ($page === 'policies') {
    includePoliciesPage($pdo, ($_GET['type'] ?? 'terms') === 'privacy' ? 'privacy' : 'terms');
    exit;
}

// ============================================================
// صفحة الهبوط (Landing)
// ============================================================

function includeLandingPage(PDO $pdo) {
    $siteName = getSetting($pdo, 'site_name', 'استضافتي');
    $siteTagline = getSetting($pdo, 'site_tagline', 'استضافة سريعة وآمنة');
    $siteLogo = getSetting($pdo, 'site_logo', '');
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script>
            if (window.location.search || /index\.php$/.test(window.location.pathname)) {
                history.replaceState(null, '', window.location.pathname.replace(/index\.php$/, ''));
            }
        </script>
        <title><?php echo e($siteName); ?> - استضافة VPS سريعة وآمنة</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/theme.css')); ?>">
        <script src="<?php echo e(assetUrl('assets/js/i18n.js')); ?>"></script>
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/public.css')); ?>">
        <style>
        /* تجعل الصفحة الترحيبية على الجوال تملأ الشاشة دون الحاجة للتمرير */
        @media (max-width: 600px) {
            #landingPage { height: 100vh; height: 100dvh; overflow: hidden; display: flex; flex-direction: column; }
            #landingPage .hero { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 6px 20px; overflow: hidden; }
            #landingPage .hero-illustration { transform: scale(.68); margin: -16px 0; }
            #landingPage .hero-badges-grid, #landingPage .feature-grid-4 { display: none; }
            #landingPage .headline { font-size: 22px; margin-bottom: 4px; }
            #landingPage .sub-headline { font-size: 12px; margin-bottom: 10px; }
            #landingPage .eyebrow { margin-bottom: 8px; }
            #landingPage .cta-row { margin: 8px 0; }
            #landingPage .trust-row { margin-top: 8px; gap: 8px; font-size: 10px; flex-wrap: wrap; justify-content: center; }
            #landingPage .site-footer { padding: 6px; font-size: 10px; }
        }
        </style>
    </head>
    <body id="landingPage">
        <header class="site-header site-header-plain">
            <div class="brand">
                <div class="logo-mark"><?php echo $siteLogo ? '<img src="' . e($siteLogo) . '" alt="">' : '<i class="fas fa-server"></i>'; ?></div>
                <div>
                    <div class="brand-name"><?php echo e($siteName); ?></div>
                    <div class="brand-tag"><?php echo e($siteTagline); ?></div>
                </div>
            </div>
            <button type="button" class="lang-toggle-btn" onclick="toggleLanguage()"><i class="fas fa-globe"></i> <span class="lang-toggle-label">EN</span></button>
        </header>

        <section class="hero">
            <div class="hero-illustration">
                <div class="cloud-base"></div>
                <div class="server-stack">
                    <div class="server-unit"><span class="led"></span><span class="slit"></span></div>
                    <div class="server-unit"><span class="led"></span><span class="slit"></span></div>
                    <div class="server-unit"><span class="led"></span><span class="slit"></span></div>
                </div>
                <div class="shield-badge"><i class="fas fa-check"></i></div>
            </div>

            <div class="hero-badges-grid">
                <div class="floating-badge"><div class="badge-icon"><i class="fas fa-gauge-high"></i></div><div><strong data-i18n="super_speed">سرعة فائقة</strong><span>NVMe SSD</span></div></div>
                <div class="floating-badge"><div class="badge-icon"><i class="fas fa-shield-halved"></i></div><div><strong data-i18n="advanced_security">أمان متطور</strong><span data-i18n="advanced_protection">حماية متقدمة</span></div></div>
                <div class="floating-badge"><div class="badge-icon"><i class="fas fa-headset"></i></div><div><strong data-i18n="support_247">دعم فني 24/7</strong><span data-i18n="pro_team">فريق محترف</span></div></div>
                <div class="floating-badge"><div class="badge-icon"><i class="fas fa-rocket"></i></div><div><strong data-i18n="uptime_badge">جاهزية 99.99%</strong><span data-i18n="uptime_label">وقت تشغيل</span></div></div>
            </div>

            <div class="eyebrow"><span class="dot"></span> <span data-i18n="welcome_to">مرحباً بك في</span> <?php echo e($siteName); ?></div>
            <h1 class="headline"><span data-i18n="reliable_hosting">استضافة</span> <span class="accent-text" data-i18n="trusted">موثوقة</span> <span data-i18n="for_uninterrupted">لأداء لا ينقطع</span></h1>
            <p class="sub-headline" data-i18n="hero_sub">نوفر لك أفضل خدمات الاستضافة بأعلى سرعة وأمان، لموقعك وتطبيقاتك لتنمو بدون حدود.</p>

            <div class="feature-grid-4">
                <div class="feature-chip"><div class="chip-icon"><i class="fas fa-globe"></i></div><strong data-i18n="free_domain">نطاق مجاني</strong><span data-i18n="with_every_plan">مع كل خطة</span></div>
                <div class="feature-chip"><div class="chip-icon"><i class="fas fa-database"></i></div><strong data-i18n="daily_backup">نسخ احتياطي يومي</strong><span data-i18n="to_protect_data">لحفظ بياناتك</span></div>
                <div class="feature-chip"><div class="chip-icon"><i class="fas fa-gauge"></i></div><strong data-i18n="easy_dashboard">لوحة تحكم سهلة</strong><span>cPanel متكاملة</span></div>
                <div class="feature-chip"><div class="chip-icon"><i class="fas fa-tags"></i></div><strong data-i18n="competitive_prices">أسعار تنافسية</strong><span data-i18n="quality_best_price">جودة بأفضل سعر</span></div>
            </div>

            <div class="cta-row">
                <a href="index.php?page=login" class="btn-primary-lg"><i class="fas fa-arrow-left"></i> <span data-i18n="start_now">ابدأ الآن</span></a>
                <a href="index.php?page=plans" class="btn-outline-lg"><i class="fas fa-list"></i> <span data-i18n="browse_plans">تصفح الخطط والأسعار</span></a>
            </div>

            <div class="trust-row">
                <span><i class="fas fa-circle-check"></i> <span data-i18n="no_hidden_fees">بدون رسوم خفية</span></span>
                <span><i class="fas fa-shield"></i> <span data-i18n="refund_guarantee">ضمان استرداد 30 يوم</span></span>
                <span><i class="fas fa-bolt"></i> <span data-i18n="instant_activation">تفعيل فوري</span></span>
            </div>
        </section>

        <footer class="site-footer">© <?php echo date('Y'); ?> <?php echo e($siteName); ?>. <span data-i18n="all_rights_reserved">جميع الحقوق محفوظة.</span></footer>
    </body>
    </html>
    <?php
}

// ============================================================
// صفحة الخطط والأسعار (عامة)
// ============================================================

function includePlansPage(PDO $pdo) {
    $plans = $pdo->query('SELECT * FROM vps_plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $siteName = getSetting($pdo, 'site_name', 'استضافتي');
    $siteTagline = getSetting($pdo, 'site_tagline', 'استضافة سريعة وآمنة');
    $siteLogo = getSetting($pdo, 'site_logo', '');
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script>
            if (window.location.search || /index\.php$/.test(window.location.pathname)) {
                history.replaceState(null, '', window.location.pathname.replace(/index\.php$/, ''));
            }
        </script>
        <title>الخطط والأسعار - <?php echo e($siteName); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/theme.css')); ?>">
        <script src="<?php echo e(assetUrl('assets/js/i18n.js')); ?>"></script>
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/public.css')); ?>">
    </head>
    <body>
        <header class="site-header">
            <div class="brand">
                <div class="logo-mark"><?php echo $siteLogo ? '<img src="' . e($siteLogo) . '" alt="">' : '<i class="fas fa-server"></i>'; ?></div>
                <div>
                    <div class="brand-name"><?php echo e($siteName); ?></div>
                    <div class="brand-tag"><?php echo e($siteTagline); ?></div>
                </div>
            </div>
            <nav class="site-nav">
                <a href="."><i class="fas fa-arrow-right"></i> <span data-i18n="back">رجوع</span></a>
                <button type="button" class="lang-toggle-btn" onclick="toggleLanguage()"><i class="fas fa-globe"></i> <span class="lang-toggle-label">EN</span></button>
            </nav>
        </header>

        <div class="page-title-block">
            <h1 data-i18n="plans_and_pricing">الخطط والأسعار</h1>
            <p data-i18n="choose_your_plan">اختر الباقة التي تناسب احتياجاتك، بدون أي رسوم خفية.</p>
        </div>

        <div class="plans-public-grid">
            <?php foreach ($plans as $plan): $discountPct = planDiscountPct($plan); ?>
            <div class="plan-public-card">
                <?php if ($discountPct): ?><span class="discount-ribbon">خصم <?php echo $discountPct; ?>%</span><?php endif; ?>
                <div class="plan-public-icon"><?php echo planIconHtml($plan['icon'], $plan['icon_image'] ?? null, 40); ?></div>
                <h3><?php echo e($plan['name']); ?></h3>
                <?php if (!empty($plan['badge'])): ?><span class="pill pill-gold"><?php echo e($plan['badge']); ?></span><?php endif; ?>
                <div class="plan-public-price">
                    <?php if ($discountPct): ?><s class="price-original" data-usd="<?php echo (float)$plan['original_price']; ?>">$<?php echo (int)$plan['original_price']; ?></s><?php endif; ?>
                    <span data-usd="<?php echo (float)$plan['price']; ?>"><?php echo (int)$plan['price']; ?>$</span><small>/<?php echo ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'سنة' : 'شهر'; ?></small>
                </div>
                <ul class="plan-specs-list">
                    <li><i class="fas fa-microchip"></i> معالج <?php echo e($plan['cpu']); ?></li>
                    <li><i class="fas fa-memory"></i> ذاكرة <?php echo e($plan['ram']); ?></li>
                    <li><i class="fas fa-hard-drive"></i> تخزين <?php echo e($plan['storage']); ?></li>
                    <li><i class="fas fa-network-wired"></i> باندويث <?php echo e($plan['bandwidth']); ?></li>
                </ul>
                <a href="index.php?page=buy&plan=<?php echo (int)$plan['id']; ?>" class="btn-primary-lg" style="width:100%" data-i18n="subscribe_now">اشتراك الآن</a>
            </div>
            <?php endforeach; ?>
            <?php if (!$plans): ?>
            <p class="text-muted text-center" data-i18n="no_plans_available">لا توجد باقات متاحة حالياً.</p>
            <?php endif; ?>
        </div>

        <footer class="site-footer">© <?php echo date('Y'); ?> <?php echo e($siteName); ?>. <span data-i18n="all_rights_reserved">جميع الحقوق محفوظة.</span></footer>
        <?php echo currencyJsSnippet($pdo); ?>
    </body>
    </html>
    <?php
}

// ============================================================
// الشروط والأحكام / سياسة الخصوصية
// ============================================================

function includePoliciesPage(PDO $pdo, $type) {
    $siteName = getSetting($pdo, 'site_name', 'استضافتي');
    $isPrivacy = $type === 'privacy';
    $title = $isPrivacy ? 'سياسة الخصوصية' : 'الشروط والأحكام';
    $content = getSetting($pdo, $isPrivacy ? 'site_privacy' : 'site_terms', '');
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script>
            if (window.location.search || /index\.php$/.test(window.location.pathname)) {
                history.replaceState(null, '', window.location.pathname.replace(/index\.php$/, ''));
            }
        </script>
        <title><?php echo e($title); ?> - <?php echo e($siteName); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/theme.css')); ?>">
        <script src="<?php echo e(assetUrl('assets/js/i18n.js')); ?>"></script>
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/public.css')); ?>">
        <style>
        .policy-content { max-width: 720px; margin: 0 auto; padding: 8px 20px 60px; font-size: 14px; line-height: 2.1; color: var(--text-secondary); white-space: pre-line; }
        </style>
    </head>
    <body>
        <header class="site-header">
            <div class="brand">
                <div class="logo-mark"><i class="fas fa-server"></i></div>
                <div>
                    <div class="brand-name"><?php echo e($siteName); ?></div>
                </div>
            </div>
            <nav class="site-nav">
                <a href="javascript:history.back()"><i class="fas fa-arrow-right"></i> <span data-i18n="back">رجوع</span></a>
                <button type="button" class="lang-toggle-btn" onclick="toggleLanguage()"><i class="fas fa-globe"></i> <span class="lang-toggle-label">EN</span></button>
            </nav>
        </header>

        <div class="page-title-block">
            <h1><?php echo e($title); ?></h1>
        </div>

        <div class="policy-content"><?php echo e($content ?: 'لا يوجد محتوى بعد.'); ?></div>

        <footer class="site-footer">© <?php echo date('Y'); ?> <?php echo e($siteName); ?>. <span data-i18n="all_rights_reserved">جميع الحقوق محفوظة.</span></footer>
    </body>
    </html>
    <?php
}

// ============================================================
// تسجيل الدخول
// ============================================================

function includeLoginPage(PDO $pdo, $error) {
    $next = $_GET['next'] ?? '';
    $siteName = getSetting($pdo, 'site_name', 'استضافتي');
    $googleEnabled = getSetting($pdo, 'google_client_id', '') !== '';
    $googleUrl = 'index.php?action=google_login' . ($next ? '&next=' . urlencode($next) : '');
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script>
            if (window.location.search || /index\.php$/.test(window.location.pathname)) {
                history.replaceState(null, '', window.location.pathname.replace(/index\.php$/, ''));
            }
        </script>
        <title>تسجيل الدخول - <?php echo e($siteName); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/theme.css')); ?>">
        <script src="<?php echo e(assetUrl('assets/js/i18n.js')); ?>"></script>
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/public.css')); ?>">
    </head>
    <body>
        <div class="auth-lang-toggle-wrap">
            <button type="button" class="lang-toggle-btn" onclick="toggleLanguage()"><i class="fas fa-globe"></i> <span class="lang-toggle-label">EN</span></button>
        </div>
        <div class="auth-wrap">
            <div class="auth-card">
                <div class="auth-logo"><i class="fas fa-server"></i></div>
                <h1 data-i18n="login">تسجيل الدخول</h1>
                <p class="auth-sub" data-i18n="login_welcome">مرحباً بعودتك! سجّل الدخول لمتابعة إدارة استضافتك.</p>

                <?php if ($error): ?><div class="form-alert"><?php echo e($error); ?></div><?php endif; ?>

                <?php if ($googleEnabled): ?>
                <a href="<?php echo e($googleUrl); ?>" class="btn-google"><i class="fab fa-google"></i> <span data-i18n="continue_with_google">متابعة عبر Google</span></a>
                <div class="auth-divider"><span data-i18n="or_via_email">أو عبر البريد الإلكتروني</span></div>
                <?php endif; ?>

                <form method="POST" action="index.php?page=login<?php echo $next ? '&next=' . urlencode($next) : ''; ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="next" value="<?php echo e($next); ?>">

                    <label class="field-label" data-i18n="email">البريد الإلكتروني</label>
                    <input type="email" name="email" class="text-input" placeholder="example@mail.com" required dir="ltr" autofocus>

                    <label class="field-label" data-i18n="password">كلمة المرور</label>
                    <input type="password" name="password" class="text-input" placeholder="••••••••" required dir="ltr">

                    <button type="submit" class="btn-primary-lg" style="width:100%;margin-top:16px">
                        <i class="fas fa-right-to-bracket"></i> <span data-i18n="sign_in">دخول</span>
                    </button>
                </form>

                <p class="auth-switch"><span data-i18n="no_account">ليس لديك حساب؟</span> <a href="index.php?page=register<?php echo $next ? '&next=' . urlencode($next) : ''; ?>" data-i18n="create_new_account">إنشاء حساب جديد</a></p>
                <p class="auth-switch"><a href="." data-i18n="back_home">« العودة للرئيسية</a></p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// ============================================================
// إنشاء حساب
// ============================================================

function includeRegisterPage(PDO $pdo, $error) {
    $next = $_GET['next'] ?? '';
    $ref = trim($_GET['ref'] ?? '') ?: trim($_COOKIE['ref_code'] ?? '');
    $referralPct = (float)getSetting($pdo, 'referral_discount_pct', 0);
    $siteName = getSetting($pdo, 'site_name', 'استضافتي');
    $googleEnabled = getSetting($pdo, 'google_client_id', '') !== '';
    $googleUrl = 'index.php?action=google_login' . ($next ? '&next=' . urlencode($next) : '');
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script>
            if (window.location.search || /index\.php$/.test(window.location.pathname)) {
                history.replaceState(null, '', window.location.pathname.replace(/index\.php$/, ''));
            }
        </script>
        <title>إنشاء حساب - <?php echo e($siteName); ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/theme.css')); ?>">
        <script src="<?php echo e(assetUrl('assets/js/i18n.js')); ?>"></script>
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/public.css')); ?>">
    </head>
    <body>
        <div class="auth-lang-toggle-wrap">
            <button type="button" class="lang-toggle-btn" onclick="toggleLanguage()"><i class="fas fa-globe"></i> <span class="lang-toggle-label">EN</span></button>
        </div>
        <div class="auth-wrap">
            <div class="auth-card">
                <div class="auth-logo"><i class="fas fa-server"></i></div>
                <h1 data-i18n="create_account">إنشاء حساب جديد</h1>
                <p class="auth-sub"><span data-i18n="join_us_prefix">انضم إلى</span> <?php echo e($siteName); ?> <span data-i18n="join_us">وابدأ باستضافة مشاريعك اليوم.</span></p>

                <?php if ($error): ?><div class="form-alert"><?php echo e($error); ?></div><?php endif; ?>

                <?php if ($ref && $referralPct > 0): ?><div class="form-alert" style="background:rgba(16,185,129,.1);color:#059669"><i class="fas fa-gift"></i> ستحصل على خصم <?php echo (int)$referralPct; ?>% على أول طلب لك عبر دعوة صديق!</div><?php endif; ?>

                <?php if ($googleEnabled): ?>
                <a href="<?php echo e($googleUrl); ?>" class="btn-google"><i class="fab fa-google"></i> <span data-i18n="signup_with_google">إنشاء حساب عبر Google</span></a>
                <div class="auth-divider"><span data-i18n="or_via_email">أو عبر البريد الإلكتروني</span></div>
                <?php endif; ?>

                <form method="POST" action="index.php?page=register<?php echo $next ? '&next=' . urlencode($next) : ''; ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="next" value="<?php echo e($next); ?>">
                    <input type="hidden" name="ref" value="<?php echo e($ref); ?>">

                    <label class="field-label" data-i18n="full_name">الاسم الكامل</label>
                    <input type="text" name="name" class="text-input" value="<?php echo e($_POST['name'] ?? ''); ?>" required>

                    <label class="field-label" data-i18n="email">البريد الإلكتروني</label>
                    <input type="email" name="email" class="text-input" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="example@mail.com" required dir="ltr">

                    <label class="field-label" data-i18n="password">كلمة المرور</label>
                    <input type="password" name="password" class="text-input" placeholder="6 أحرف على الأقل" data-i18n-placeholder="min_6_chars" required dir="ltr">

                    <label class="field-label" data-i18n="verification_code">كود التحقق</label>
                    <div class="captcha-row">
                        <img id="captchaImg" src="captcha.php" alt="كود التحقق" class="captcha-img" draggable="false" oncontextmenu="return false">
                        <button type="button" class="captcha-refresh" onclick="document.getElementById('captchaImg').src='captcha.php?t='+Date.now()" title="تحديث الكود"><i class="fas fa-rotate"></i></button>
                    </div>
                    <input type="text" name="captcha" class="text-input" placeholder="اكتب الكود الظاهر في الصورة" data-i18n-placeholder="type_code_shown" required autocomplete="off" autocapitalize="characters" dir="ltr">

                    <label class="auth-terms-check">
                        <input type="checkbox" required>
                        <span><span data-i18n="agree_to">أوافق على</span> <a href="index.php?page=policies&amp;type=terms" target="_blank" data-i18n="terms_and_conditions">الشروط والأحكام</a> <span data-i18n="and_word">و</span><a href="index.php?page=policies&amp;type=privacy" target="_blank" data-i18n="privacy_policy">سياسة الخصوصية</a></span>
                    </label>

                    <button type="submit" class="btn-primary-lg" style="width:100%;margin-top:16px">
                        <i class="fas fa-user-plus"></i> <span data-i18n="create_account">إنشاء الحساب</span>
                    </button>
                </form>

                <p class="auth-switch"><span data-i18n="have_account">لديك حساب مسبقاً؟</span> <a href="index.php?page=login<?php echo $next ? '&next=' . urlencode($next) : ''; ?>" data-i18n="login">تسجيل الدخول</a></p>
                <p class="auth-switch"><a href="." data-i18n="back_home">« العودة للرئيسية</a></p>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// ============================================================
// صفحة لوحة التحكم
// ============================================================

function handleSubmitOrder(PDO $pdo) {
    csrfCheck();
    $userId = (int)$_SESSION['user_id'];
    $planId = (int)($_POST['plan_id'] ?? 0);
    $paymentChoice = trim($_POST['payment_method_id'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM vps_plans WHERE id = ? AND is_active = 1');
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        return [null, 'الباقة المختارة غير متاحة.'];
    }
    $billingCycle = ($plan['billing_cycle'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    $amount = (float)$plan['price'];

    $user = currentUser($pdo);
    $referralDiscountPct = 0.0;
    if (!empty($user['referred_by'])) {
        $priorOrdersStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
        $priorOrdersStmt->execute([$userId]);
        if ((int)$priorOrdersStmt->fetchColumn() === 0) {
            $referralDiscountPct = (float)getSetting($pdo, 'referral_discount_pct', 0);
        }
    }
    if ($referralDiscountPct > 0) {
        $amount = round($amount * (1 - $referralDiscountPct / 100), 2);
    }

    $couponCode = strtoupper(trim($_POST['coupon_code'] ?? ''));
    $couponPct = validCouponDiscountPct($pdo, $couponCode);
    if ($couponPct !== null) {
        $amount = round($amount * (1 - $couponPct / 100), 2);
    } else {
        $couponCode = null;
    }

    $paymentMethodId = null;
    $proofPath = null;

    if ($paymentChoice === 'balance') {
        if ((float)$user['balance'] < $amount) {
            return [null, 'رصيدك الحالي غير كافٍ لإتمام هذا الطلب.'];
        }
        $pdo->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')->execute([$amount, $userId]);
    } else {
        $paymentMethodId = (int)$paymentChoice;
        $pm = $pdo->prepare('SELECT id FROM payment_methods WHERE id = ? AND is_active = 1');
        $pm->execute([$paymentMethodId]);
        if (!$pm->fetch()) {
            return [null, 'طريقة الدفع المختارة غير متاحة.'];
        }
        [$proofPath, $uploadError] = handleImageUpload('proof_image', PROOFS_DIR, 'uploads/proofs');
        if ($uploadError) {
            return [null, $uploadError];
        }
        if (!$proofPath) {
            return [null, 'الرجاء إرفاق صورة إيصال التحويل.'];
        }
    }

    $pdo->prepare('INSERT INTO orders (user_id, plan_id, payment_method_id, amount, billing_cycle, proof_image, status, coupon_code) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$userId, $planId, $paymentMethodId, $amount, $billingCycle, $proofPath, 'pending', $couponCode]);
    $orderId = (int)$pdo->lastInsertId();

    $cycleLabel = $billingCycle === 'yearly' ? 'سنوي' : 'شهري';
    $invDescription = 'اشتراك باقة ' . $plan['name'] . ' (' . $cycleLabel . ')';
    if ($referralDiscountPct > 0) {
        $invDescription .= ' - يشمل خصم دعوة ' . (int)$referralDiscountPct . '%';
    }
    if ($couponPct !== null) {
        $invDescription .= ' - كوبون ' . $couponCode . ' (خصم ' . rtrim(rtrim(number_format($couponPct, 2), '0'), '.') . '%)';
    }
    $pdo->prepare('INSERT INTO invoices (user_id, order_id, invoice_number, amount, status, description) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, $orderId, nextInvoiceNumber($pdo), $amount, $paymentChoice === 'balance' ? 'paid' : 'pending', $invDescription]);

    notifyAdmins($pdo, ' طلب اشتراك جديد', 'قدّم ' . $user['name'] . ' طلب اشتراك في باقة "' . $plan['name'] . '" بمبلغ $' . money($amount) . '. راجع الطلب من لوحة التحكم.', 'system');

    return [$orderId, null];
}

function handleTopUpBalance(PDO $pdo) {
    csrfCheck();
    $userId = (int)$_SESSION['user_id'];
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);

    if ($amount <= 0) {
        return 'الرجاء إدخال مبلغ صحيح.';
    }
    $pm = $pdo->prepare('SELECT id FROM payment_methods WHERE id = ? AND is_active = 1');
    $pm->execute([$paymentMethodId]);
    if (!$pm->fetch()) {
        return 'طريقة الدفع المختارة غير متاحة.';
    }
    [$proofPath, $uploadError] = handleImageUpload('proof_image', PROOFS_DIR, 'uploads/proofs');
    if ($uploadError) {
        return $uploadError;
    }
    if (!$proofPath) {
        return 'الرجاء إرفاق صورة إيصال التحويل.';
    }

    $pdo->prepare('INSERT INTO invoices (user_id, invoice_number, amount, status, description, proof_image) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, nextInvoiceNumber($pdo), $amount, 'pending', 'طلب شحن رصيد', $proofPath]);

    $user = currentUser($pdo);
    notifyAdmins($pdo, '💳 طلب شحن رصيد جديد', 'طلب ' . $user['name'] . ' شحن رصيد بمبلغ $' . money($amount) . '. راجع الطلب من لوحة التحكم.', 'system');

    return null;
}

// ============================================================
// صفحة لوحة التحكم
// ============================================================

function includeAppPage(PDO $pdo) {
    $user = currentUser($pdo);
    $user_name = $user['name'] ?? 'مستخدم';
    $balance = (float)($user['balance'] ?? 0);
    $userId = (int)$user['id'];
    $isAdmin = (int)($user['is_admin'] ?? 0) === 1;
    if ($isAdmin) {
        $adminPendingOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
        $adminPendingTopupsCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status = 'pending' AND order_id IS NULL")->fetchColumn();
        $adminUsersCount = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 0')->fetchColumn();
        $adminActiveHostingCount = (int)$pdo->query("SELECT COUNT(*) FROM hosting WHERE status = 'active'")->fetchColumn();
    }
    $siteName = getSetting($pdo, 'site_name', 'استضافتي');
    $siteLogo = getSetting($pdo, 'site_logo', '');
    $aiLogo = getSetting($pdo, 'ai_logo', '');
    $supportWhatsapp = getSetting($pdo, 'support_whatsapp', '');
    $referralDiscountPct = (float)getSetting($pdo, 'referral_discount_pct', 0);
    $myReferralCode = getOrCreateReferralCode($pdo, $user);
    $referralScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $referralLink = $referralScheme . '://' . $_SERVER['HTTP_HOST'] . '/r/' . $myReferralCode;

    // عملية آسياسيل غير مكتملة (تم تحويل جزء منها فعلياً) تبقى في الجلسة حتى يُكملها العميل أو يُلغيها صراحةً
    $asiacellPending = null;
    if (!empty($_SESSION['asiacell_flow']) && (float)($_SESSION['asiacell_flow']['amount_iqd_paid'] ?? 0) > 0) {
        $af = $_SESSION['asiacell_flow'];
        $asiacellPending = [
            'context' => $af['context'],
            'plan_id' => (int)($af['plan_id'] ?? 0),
            'payment_method_id' => (int)($af['payment_method_id'] ?? 0),
            'amount_usd' => (float)($af['amount_usd'] ?? 0),
            'paid' => (int)$af['amount_iqd_paid'],
            'total' => (int)$af['amount_iqd_total'],
            'remaining' => (int)$af['amount_iqd_total'] - (int)$af['amount_iqd_paid'],
        ];
    }

    $notifStmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
    $notifStmt->execute([$userId]);
    $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    $unreadNotifCount = 0;
    foreach ($notifications as $n) {
        if (!(int)$n['is_read']) $unreadNotifCount++;
    }

    $hostingStmt = $pdo->prepare("
        SELECT h.*,
               EXISTS(SELECT 1 FROM orders ro WHERE ro.renewal_hosting_id = h.id AND ro.status = 'pending') AS pending_renewal
        FROM hosting h WHERE h.user_id = ? ORDER BY h.created_at DESC
    ");
    $hostingStmt->execute([$userId]);
    $hosting = $hostingStmt->fetchAll(PDO::FETCH_ASSOC);

    $invoicesStmt = $pdo->prepare('SELECT id, invoice_number AS number, amount, status, due_date, description FROM invoices WHERE user_id = ? ORDER BY created_at DESC');
    $invoicesStmt->execute([$userId]);
    $invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

    $ordersStmt = $pdo->prepare("
        SELECT o.*, p.name AS plan_name, p.icon AS plan_icon, p.icon_image AS plan_icon_image
        FROM orders o JOIN vps_plans p ON p.id = o.plan_id
        WHERE o.user_id = ? ORDER BY o.created_at DESC
    ");
    $ordersStmt->execute([$userId]);
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
    $referralEligible = $referralDiscountPct > 0 && !empty($user['referred_by']) && count($orders) === 0;

    $payment_methods = $pdo->query('SELECT id, name, icon, account_number, instructions, currency_code, method_type, logo_path, sort_order, method_extras FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $pmColors = ['blue', 'purple', 'gold', 'green'];
    foreach ($payment_methods as $i => &$pmRow) {
        $pmRow['color'] = $pmColors[$i % count($pmColors)];
        // فقط الحقول غير الحساسة تصل للمتصفح؛ لا يُرسل مطلقاً api_key أو api_secret
        $extras = json_decode($pmRow['method_extras'] ?? '{}', true) ?: [];
        $pmRow['binance_id'] = $extras['binance_id'] ?? '';
        $pmRow['qr_code'] = $extras['qr_code'] ?? '';
        // سعر الصرف فقط (غير حساس، يُستخدم لعرض المكافئ بالدينار)؛ رقم آسياسيل المستقبل يبقى في الخادم فقط
        $pmRow['exchange_rate'] = (float)($extras['exchange_rate'] ?? 0);
        unset($pmRow['method_extras']);
    }
    unset($pmRow);

    $vps_plans = $pdo->query('SELECT * FROM vps_plans WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);

    $activeCoupon = $pdo->query("SELECT * FROM coupons WHERE is_active = 1 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $buyPlanId = (int)($_GET['buy'] ?? 0);
    $orderedFlag = isset($_GET['ordered']);
    $orderedId = (int)($_GET['order_id'] ?? 0);
    $orderErrorMsg = $_GET['order_error'] ?? null;
    $adminSectionHint = $isAdmin ? (string)($_GET['admin_section'] ?? '') : '';
    $adminMsgHint = $isAdmin ? (string)($_GET['admin_msg'] ?? '') : '';
    $adminErrHint = $isAdmin ? (string)($_GET['admin_err'] ?? '') : '';

    // بيانات المساعد الذكي (تجريبية)
    $ai_tools = [
        ['icon' => 'fa-gauge-high', 'color' => 'gold', 'title' => 'تحسين حالة السيرفر', 'sub' => 'فحص أداء جميع خدمات السيرفر'],
        ['icon' => 'fa-bolt', 'color' => 'blue', 'title' => 'اختبار السرعة', 'sub' => 'اختبار سرعة الشبكة والاتصال'],
        ['icon' => 'fa-shield-halved', 'color' => 'green', 'title' => 'فحص الأمان', 'sub' => 'تدقيق إعدادات أمان السيرفر'],
        ['icon' => 'fa-database', 'color' => 'purple', 'title' => 'نسخ احتياطي ذكي', 'sub' => 'إنشاء نسخة احتياطية فورية'],
    ];
    $ai_conversations = [
        ['title' => 'تحسين أداء السيرفر', 'preview' => 'شكراً، الخطوات وضحت المشكلة', 'time' => '10:30 ص · اليوم'],
        ['title' => 'مشكلة في الاتصال بالسيرفر', 'preview' => 'جرب إعادة تشغيل خدمة الشبكة', 'time' => '4:15 م · أمس'],
        ['title' => 'شرح أمر sudo apt update', 'preview' => 'يقوم هذا الأمر بتحديث قائمة الحزم', 'time' => '9:45 ص · منذ يومين'],
    ];

    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo e(getSetting($pdo, 'site_name', 'استضافتي')); ?> - لوحة التحكم</title>
        <link rel="manifest" href="manifest.php">
        <meta name="theme-color" content="#ff7a1a">
        <link rel="apple-touch-icon" href="<?php echo e($siteLogo !== '' ? $siteLogo : 'assets/icons/icon-192.png'); ?>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/app.css')); ?>">
        <script src="<?php echo e(assetUrl('assets/js/i18n.js')); ?>"></script>
    </head>
    <body>
        <!-- ============================================================
        بطاقة تأكيد تسجيل الخروج - FastCrand
        ============================================================ -->
        <div class="logout-overlay" id="logoutOverlay">
            <div class="logout-sheet">
                <div class="icon-box">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h3 data-i18n="logout_confirm_title">تسجيل الخروج</h3>
                <p data-i18n="logout_confirm_body">هل أنت متأكد من رغبتك في تسجيل الخروج من حسابك؟</p>
                <div class="actions">
                    <button class="btn-cancel" onclick="closeLogoutSheet()" data-i18n="cancel">إلغاء</button>
                    <button class="btn-confirm" onclick="confirmLogout()" data-i18n="confirm_logout">تأكيد الخروج</button>
                </div>
            </div>
        </div>

        <!-- ============================================================
        بطاقة اختيار العملة
        ============================================================ -->
        <div class="picker-overlay" id="currencyPickerOverlay" onclick="if (event.target === this) closeCurrencyPicker()">
            <div class="picker-sheet">
                <div class="picker-sheet-handle"></div>
                <h3><i class="fas fa-coins"></i> <span data-i18n="choose_display_currency">اختر عملة العرض</span></h3>
                <div class="picker-search">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="currencySearchInput" placeholder="ابحث عن عملة أو رمز..." data-i18n-placeholder="search_currency" oninput="filterCurrencyOptions()">
                </div>
                <div class="picker-list" id="currencyOptionsList"></div>
            </div>
        </div>

        <!-- ============================================================
        بطاقة اختيار اللغة
        ============================================================ -->
        <div class="picker-overlay" id="languagePickerOverlay" onclick="if (event.target === this) closeLanguagePicker()">
            <div class="picker-sheet">
                <div class="picker-sheet-handle"></div>
                <h3><i class="fas fa-language"></i> <span data-i18n="choose_language">اختر اللغة</span></h3>
                <div class="picker-list">
                    <div class="picker-option" onclick="chooseLanguage('ar')">
                        <div class="picker-option-symbol">ع</div>
                        <div class="picker-option-main"><strong>العربية</strong><span>Arabic</span></div>
                        <i class="fas fa-check picker-option-check" id="langCheckAr"></i>
                    </div>
                    <div class="picker-option" onclick="chooseLanguage('en')">
                        <div class="picker-option-symbol">A</div>
                        <div class="picker-option-main"><strong>English</strong><span>الإنجليزية</span></div>
                        <i class="fas fa-check picker-option-check" id="langCheckEn"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
        الهيدر
        ============================================================ -->
        <header class="header">
            <div class="brand">
                <div class="logo"><?php echo $siteLogo ? '<img src="' . e($siteLogo) . '" alt="">' : '<i class="fas fa-server"></i>'; ?></div>
                <span><?php echo e($siteName); ?></span>
            </div>
            <div class="header-actions">
                <button class="header-theme-toggle" id="headerNotifBtn" onclick="showSection('notifications')">
                    <i class="fas fa-bell"></i>
                    <?php if ($unreadNotifCount > 0): ?>
                    <span class="notif-badge"><?php echo $unreadNotifCount > 9 ? '9+' : $unreadNotifCount; ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </header>

        <!-- بانر عملية آسياسيل غير مكتملة (تم تحويل جزء منها فعلياً) -->
        <div id="asiacellPendingBanner" class="hidden" style="margin:12px 16px 0;padding:14px 16px;border-radius:var(--radius-sm);background:rgba(251,191,36,.12);border:1.5px solid rgba(251,191,36,.4)">
            <div style="display:flex;align-items:center;gap:8px;font-weight:800;font-size:13px;margin-bottom:4px"><i class="fas fa-triangle-exclamation" style="color:#b45309"></i> لديك عملية دفع آسياسيل غير مكتملة</div>
            <div id="asiacellPendingText" style="font-size:12px;color:var(--text-muted);margin-bottom:10px"></div>
            <div style="display:flex;gap:8px">
                <button class="btn-gold" style="padding:8px 14px;font-size:12px;width:auto" onclick="resumeAsiacellPending()"><i class="fas fa-play"></i> متابعة الدفع</button>
                <button class="btn-back" style="font-size:12px" onclick="cancelAsiacellPending()">إلغاء واسترداد كرصيد</button>
            </div>
        </div>

        <!-- ============================================================
        المحتوى
        ============================================================ -->
        <div class="container" id="appContent">
            <!-- ============================================================
            القسم: الرئيسية - استضافاتي النشطة
            ============================================================ -->
            <div id="section-home" class="section-content">
                <div class="card onboard-card hidden" id="onboardCurrencyCard">
                    <div class="onboard-icon"><i class="fas fa-coins"></i></div>
                    <div class="onboard-title" data-i18n="onboarding_welcome_title">أهلاً بك 👋</div>
                    <div class="onboard-sub" data-i18n="choose_display_currency">اختر عملة العرض</div>
                    <div class="onboard-currency-row" onclick="openCurrencyPicker()" style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--bg-body);border:1.5px solid var(--border-color);border-radius:var(--radius-sm);padding:12px 14px;margin-top:10px;cursor:pointer">
                        <span id="onboardCurrencyValue" style="font-weight:700;font-size:14px"></span>
                        <i class="fas fa-chevron-left" style="color:var(--text-muted);font-size:12px"></i>
                    </div>
                    <div class="text-muted" style="font-size:11px;margin-top:8px" data-i18n="onboarding_currency_hint">يمكنك تغييرها لاحقاً من الإعدادات</div>
                </div>

                <div class="card onboard-card hidden" id="onboardAutoRenewCard">
                    <div class="onboard-icon"><i class="fas fa-sync"></i></div>
                    <div class="onboard-title" data-i18n="auto_renew">التجديد التلقائي</div>
                    <div class="onboard-sub" data-i18n="auto_renew_sub">تجديد استضافاتك تلقائياً من رصيدك عند الانتهاء</div>
                    <label class="toggle-switch" style="margin:14px auto">
                        <input type="checkbox" id="onboardAutoRenewToggle">
                        <span class="slider"></span>
                    </label>
                    <button class="btn-gold onboard-cta" onclick="completeOnboarding()" data-i18n="onboarding_continue">متابعة</button>
                </div>

                <div class="card onboard-card hidden" id="pwaInstallCard">
                    <button class="onboard-dismiss" onclick="dismissOnboardCard('pwa')" title="إغلاق"><i class="fas fa-xmark"></i></button>
                    <div class="onboard-icon"><i class="fas fa-mobile-screen-button"></i></div>
                    <div class="onboard-title">ثبّت التطبيق على جهازك</div>
                    <div class="onboard-sub">وصول أسرع، وتجربة أشبه بتطبيق حقيقي، مباشرة من شاشتك الرئيسية.</div>
                    <button class="btn-gold onboard-cta" onclick="triggerPwaInstall()"><i class="fas fa-download"></i> تثبيت التطبيق</button>
                </div>

                <div class="card onboard-card hidden" id="notifPermCard">
                    <button class="onboard-dismiss" onclick="dismissOnboardCard('notif')" title="إغلاق"><i class="fas fa-xmark"></i></button>
                    <div class="onboard-icon"><i class="fas fa-bell"></i></div>
                    <div class="onboard-title">فعّل إشعارات المتصفح</div>
                    <div class="onboard-sub">لتصلك فوراً إشعارات شحن الرصيد، والموافقة على الطلبات، وتحديثات النظام.</div>
                    <button class="btn-gold onboard-cta" onclick="requestNotifPermission()"><i class="fas fa-bell"></i> تفعيل الإشعارات</button>
                </div>

                <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff">
                    <div style="display:flex;align-items:center;gap:14px">
                        <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:24px"> </div>
                        <div>
                            <h3 style="font-size:18px;font-weight:900">مرحباً بك في صار</h3>
                            <div style="font-size:12px;opacity:.8">استمتع بخدماتنا المتميزة</div>
                        </div>
                    </div>
                    <div style="margin-top:10px;font-size:13px;opacity:.7;display:flex;align-items:center;gap:8px">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo date('l, d F Y', strtotime('now')); ?>
                    </div>
                </div>
                
                <!-- إحصائيات جديدة -->
                <div class="stats-card">
                    <div class="stats-cell">
                        <div class="stats-cell-top">
                            <div class="stats-icon"><i class="fas fa-cube"></i></div>
                            <div class="num"><?php echo count(array_filter($hosting, function($h) { return $h['status'] === 'active'; })); ?></div>
                        </div>
                        <div class="label">مفعلة</div>
                    </div>
                    <div class="stats-cell">
                        <div class="stats-cell-top">
                            <div class="stats-icon"><i class="fas fa-server"></i></div>
                            <div class="num"><?php echo count($hosting); ?></div>
                        </div>
                        <div class="label">استضافات نشطة</div>
                    </div>
                    <div class="stats-cell">
                        <div class="stats-cell-top">
                            <div class="stats-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                            <div class="num"><?php echo count($invoices); ?></div>
                        </div>
                        <div class="label">فواتير</div>
                    </div>
                    <div class="stats-cell">
                        <div class="stats-cell-top">
                            <div class="stats-icon"><i class="fas fa-clock"></i></div>
                            <div class="num"><?php echo count(array_filter($hosting, function($h) { return $h['status'] === 'expired'; })); ?></div>
                        </div>
                        <div class="label">منتهية</div>
                    </div>
                </div>

                <?php if ($activeCoupon): ?>
                <div class="card coupon-promo-card" onclick="wizardApplyCouponFromHome('<?php echo e($activeCoupon['code']); ?>')">
                    <div class="coupon-promo-icon"><i class="fas fa-tag"></i></div>
                    <div class="coupon-promo-text">
                        <div class="coupon-promo-title">كوبون خصم <?php echo rtrim(rtrim(number_format((float)$activeCoupon['discount_pct'], 2), '0'), '.'); ?>%</div>
                        <div class="coupon-promo-sub">استخدم الكود <strong><?php echo e($activeCoupon['code']); ?></strong> عند طلب باقة VPS جديدة</div>
                    </div>
                    <i class="fas fa-chevron-left coupon-promo-chevron"></i>
                </div>
                <?php endif; ?>

                <div class="quick-grid">
                    <button class="quick-btn" onclick="showSection('servers')"><i class="fas fa-server"></i>سيرفراتي</button>
                    <button class="quick-btn" onclick="showSection('invoices')"><i class="fas fa-receipt"></i>فواتير</button>
                    <button class="quick-btn" onclick="showSection('orders')"><i class="fas fa-list"></i>طلباتي</button>
                    <button class="quick-btn" onclick="showSection('settings')"><i class="fas fa-gear"></i>إعدادات</button>
                </div>

                <!-- المساعد الذكي -->
                <button type="button" class="promo-ai-card has-banner" onclick="enterAI()">
                    <img src="assets/images/ai-assistant-banner.webp" alt="المساعد الذكي" class="promo-ai-banner-img">
                </button>


                <!-- قائمة الاستضافات -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-server"></i> استضافاتي النشطة</h3>
                        <span style="font-size:11px;color:var(--text-muted);cursor:pointer" onclick="showSection('servers')">عرض الكل</span>
                    </div>
                    <?php if (!$hosting): ?>
                    <div class="text-muted text-center" style="padding:20px 0;font-size:12px">📭 ما عندك استضافات بعد</div>
                    <?php endif; ?>
                    <?php foreach ($hosting as $h): ?>
                    <div class="hosting-item" onclick="showHostingDetail(<?php echo $h['id']; ?>)">
                        <div class="info">
                            <div class="name"><?php echo e($h['name']); ?></div>
                            <div class="sub"><?php echo e($h['plan']); ?> · <?php echo e($h['ip']); ?></div>
                        </div>
                        <div class="status-badge">
                            <span class="pill <?php echo $h['status'] === 'active' ? 'pill-green' : 'pill-red'; ?>">
                                <?php echo $h['status'] === 'active' ? ' مفعل' : ' منتهي'; ?>
                            </span>
                            <div style="font-size:9px;color:var(--text-muted);margin-top:2px">
                                ينتهي: <?php echo e($h['expiry_date']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- آخر الفواتير -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-receipt"></i> آخر الفواتير</h3>
                        <span style="font-size:11px;color:var(--text-muted);cursor:pointer" onclick="showSection('invoices')">عرض الكل</span>
                    </div>
                    <?php if (!$invoices): ?>
                    <div class="text-muted text-center" style="padding:20px 0;font-size:12px">📭 لا توجد فواتير بعد</div>
                    <?php endif; ?>
                    <?php foreach (array_slice($invoices, 0, 3) as $inv):
                        $homeInvLabel = ['paid' => 'مكتمـل', 'pending' => ' معلق', 'rejected' => 'ملغى'][$inv['status']] ?? $inv['status'];
                        $homeInvPill = ['paid' => 'pill-green', 'pending' => 'pill-amber', 'rejected' => 'pill-red'][$inv['status']] ?? 'pill-amber';
                    ?>
                    <div class="invoice-item" onclick="showInvoiceDetail(<?php echo (int)$inv['id']; ?>)">
                        <div class="info">
                            <div class="number"><?php echo e($inv['number']); ?></div>
                            <div class="date"><?php echo $inv['due_date'] ? 'استحقاق: ' . e($inv['due_date']) : e($inv['description']); ?></div>
                        </div>
                        <div style="text-align:left">
                            <div class="amount" data-usd="<?php echo (float)$inv['amount']; ?>">$<?php echo money($inv['amount']); ?></div>
                            <span class="pill <?php echo $homeInvPill; ?>"><?php echo $homeInvLabel; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- شارك واحصل أصدقاؤك على خصم -->
                <?php if ($referralDiscountPct > 0): ?>
                <div class="card referral-share-card">
                    <div class="referral-share-head">
                        <div class="referral-share-icon"><i class="fas fa-gift"></i></div>
                        <div>
                            <h3 data-i18n="share_and_earn">شارك واحصل أصدقاؤك على خصم</h3>
                            <p>كل صديق يسجّل عبر رابطك يحصل على خصم <?php echo (int)$referralDiscountPct; ?>% على أول طلب VPS له.</p>
                        </div>
                    </div>
                    <div class="referral-link-row">
                        <input type="text" id="referralLinkInput" class="referral-link-input" readonly value="<?php echo e($referralLink); ?>" dir="ltr" onclick="this.select()">
                        <button type="button" id="referralCopyBtn" class="referral-copy-btn" onclick="copyReferralLink()" title="نسخ"><i class="fas fa-copy"></i></button>
                    </div>
                    <button type="button" class="btn-gold" style="width:100%;margin-top:10px" onclick="shareReferralLink()"><i class="fas fa-share-nodes"></i> <span data-i18n="share_link">مشاركة الرابط</span></button>
                </div>
                <?php endif; ?>
                
            </div>
            
            <!-- ============================================================
            القسم: سيرفراتي
            ============================================================ -->
            <div id="section-servers" class="section-content hidden">
                <div class="search-bar">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="serverSearchInput" placeholder="ابحث عن سيرفر..." oninput="filterServers()">
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-server"></i> سيرفراتي</h3>
                        <span style="font-size:11px;color:var(--text-muted)"><?php echo count($hosting); ?> سيرفر</span>
                    </div>
                    <div id="serversListContent">
                        <?php foreach ($hosting as $h): ?>
                        <div class="hosting-item server-list-item" data-name="<?php echo htmlspecialchars(mb_strtolower($h['name'])); ?>" onclick="showHostingDetail(<?php echo $h['id']; ?>)">
                            <div class="info">
                                <div class="name"><?php echo e($h['name']); ?> <span style="color:var(--text-muted);font-weight:600;font-size:11px">#<?php echo e($h['vps_id'] ?: $h['id']); ?></span></div>
                                <div class="sub"><?php echo e($h['plan']); ?> · <?php echo e($h['ip']); ?></div>
                            </div>
                            <div class="status-badge">
                                <span class="pill <?php echo $h['status'] === 'active' ? 'pill-green' : 'pill-red'; ?>">
                                    <?php echo $h['status'] === 'active' ? ' قيد التشغيل' : ' منتهي'; ?>
                                </span>
                                <div style="font-size:9px;color:var(--text-muted);margin-top:2px">
                                    ينتهي: <?php echo e($h['expiry_date']); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div id="noServerResults" class="text-muted text-center hidden" style="padding:24px 0">لا توجد نتائج مطابقة 🔍</div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
            القسم: تفاصيل الاستضافة
            ============================================================ -->
            <div id="section-hosting-detail" class="section-content hidden">
                <div class="card-header" style="margin-bottom:14px">
                    <h3><i class="fas fa-server"></i> تفاصيل السيرفر</h3>
                    <button class="btn-back" onclick="hideHostingDetail()">رجوع</button>
                </div>

                <div class="hosting-detail" id="hostingDetailContent">
                    <!-- يتم تعبئتها بواسطة JavaScript -->
                </div>
            </div>
            
            <!-- ============================================================
            القسم: خوادم VPS
            ============================================================ -->
            <div id="section-vps" class="section-content hidden">
                <!-- خطوة 1: اختيار الباقة -->
                <div class="wizard-step" id="vpsStepPlan">
                    <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff;text-align:center">
                        <h3 style="font-size:20px;font-weight:900">🚀 اختر الباقة المناسبة</h3>
                        <div style="font-size:13px;opacity:.8;margin-top:4px">اختر الباقة التي تناسب احتياجاتك</div>
                    </div>
                    <div class="form-alert-inline hidden" id="planUnavailableAlert" data-i18n="plan_unavailable_msg">الباقة التي اخترتها لم تعد متاحة، الرجاء اختيار باقة أخرى.</div>
                    <div class="tab-strip" style="margin-bottom:12px">
                        <button class="tab-btn active" id="billingTabMonthly" onclick="wizardSetBillingCycle('monthly')" data-i18n="monthly">شهري</button>
                        <button class="tab-btn" id="billingTabYearly" onclick="wizardSetBillingCycle('yearly')" data-i18n="yearly">سنوي</button>
                    </div>
                    <div id="planListContent"></div>
                    <button class="btn-gold" id="planContinueBtn" onclick="wizardGoTo('details')" disabled data-i18n="continue_btn">متابعة</button>
                </div>

                <!-- خطوة 2: تفاصيل الباقة -->
                <div class="wizard-step hidden" id="vpsStepDetails">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-server"></i> تفاصيل الباقة</h3>
                        <button class="btn-back" onclick="wizardGoTo('plan')">رجوع</button>
                    </div>
                    <div class="card" style="text-align:center">
                        <div style="font-size:42px;margin-bottom:4px" id="planDetailsIcon"></div>
                        <h3 id="planDetailsName" style="font-size:18px;font-weight:900"></h3>
                        <div class="price" id="planDetailsPrice" style="font-size:26px;margin:6px 0"></div>
                    </div>
                    <div class="hosting-detail" id="planDetailsSpecs"></div>
                    <button class="btn-gold" onclick="wizardGoTo('summary')" style="margin-top:14px">متابعة الطلب</button>
                </div>

                <!-- خطوة 3: ملخص الطلب -->
                <div class="wizard-step hidden" id="vpsStepSummary">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-clipboard-list"></i> ملخص الطلب</h3>
                        <button class="btn-back" onclick="wizardGoTo('details')">رجوع</button>
                    </div>
                    <div class="hosting-detail" id="orderSummaryContent"></div>
                    <button class="btn-gold" onclick="wizardGoTo('payment')" style="margin-top:14px">متابعة للدفع</button>
                </div>

                <!-- خطوة 4: طريقة الدفع -->
                <div class="wizard-step hidden" id="vpsStepPayment">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-credit-card"></i> طريقة الدفع</h3>
                        <button class="btn-back" onclick="wizardGoTo('summary')">رجوع</button>
                    </div>

                    <?php if (!empty($orderErrorMsg)): ?>
                        <div class="form-alert-inline"><?php echo e($orderErrorMsg); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="index.php" enctype="multipart/form-data" id="orderForm" onsubmit="return handleOrderFormSubmit(event)">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="submit_order">
                        <input type="hidden" name="plan_id" id="orderPlanId" value="">
                        <input type="hidden" name="billing_cycle" id="orderBillingCycle" value="monthly">
                        <input type="hidden" name="payment_method_id" id="orderPaymentMethodId" value="">

                        <div id="payOptionsContent"></div>

                        <div class="field-row" style="margin-top:10px">
                            <label class="field-label">لديك كوبون خصم؟ (اختياري)</label>
                            <div style="display:flex;gap:8px">
                                <input type="text" id="couponCodeInput" class="text-input" placeholder="أدخل كود الكوبون" style="flex:1;text-transform:uppercase" dir="ltr">
                                <button type="button" class="btn btn-outline btn-sm" onclick="applyCoupon()" style="white-space:nowrap">تطبيق</button>
                            </div>
                            <div id="couponFeedback" style="font-size:12px;margin-top:6px"></div>
                        </div>
                        <input type="hidden" name="coupon_code" id="orderCouponCode" value="">

                        <div id="proofUploadWrap" class="hidden">
                            <div class="hosting-detail" id="payInstructionsBox" style="margin-bottom:12px"></div>
                            <label class="field-label" style="display:block;text-align:right;font-size:13px;color:var(--text-muted);margin-bottom:6px">صورة إيصال التحويل</label>
                            <input type="file" name="proof_image" id="proofImageInput" accept="image/png,image/jpeg,image/webp" style="width:100%;padding:10px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:13px;font-family:inherit;margin-bottom:12px">
                        </div>

                        <div id="binanceOrderIdWrap" class="hidden">
                            <div id="binanceStepInfo">
                                <div id="binancePayInfo" style="margin-bottom:12px"></div>
                                <button type="button" class="btn-gold" onclick="binanceShowStep('order','confirm')"><i class="fas fa-check"></i> لقد أرسلت الدفعة، متابعة</button>
                            </div>
                            <div id="binanceStepConfirm" class="hidden">
                                <label class="field-label" style="display:block;text-align:right;font-size:13px;color:var(--text-muted);margin-bottom:6px">رقم عملية Binance (Order ID)</label>
                                <input type="text" id="binanceOrderIdInput" placeholder="Order ID" dir="ltr" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                <div class="form-alert-inline hidden" id="binanceOrderError"></div>
                                <button type="button" class="btn-gold" id="binanceOrderSubmitBtn" onclick="submitBinanceOrder()" style="margin-top:6px"><i class="fas fa-lock"></i> تحقق وإرسال الطلب</button>
                                <button type="button" class="btn-back" onclick="binanceShowStep('order','info')" style="margin-top:6px">رجوع</button>
                            </div>
                        </div>

                        <div id="asiacellWrap" class="hidden">
                            <div id="asiacellPayInfo" style="margin-bottom:12px"></div>
                            <div id="asiacellStepPhone">
                                <label class="field-label" style="display:block;text-align:right;font-size:13px;color:var(--text-muted);margin-bottom:6px">رقم هاتفك في آسياسيل</label>
                                <input type="text" id="asiacellPhoneInput" placeholder="07xxxxxxxxx" dir="ltr" inputmode="numeric" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                <div class="form-alert-inline hidden" id="asiacellPhoneError"></div>
                                <button type="button" class="btn-gold" id="asiacellSendCodeBtn" onclick="asiacellSendCode('order')" style="margin-top:6px"><i class="fas fa-paper-plane"></i> إرسال رمز التحقق</button>
                            </div>
                            <div id="asiacellStepSms1" class="hidden">
                                <label class="field-label" style="display:block;text-align:right;font-size:13px;color:var(--text-muted);margin-bottom:6px">رمز التحقق المُرسل إلى هاتفك (SMS)</label>
                                <input type="text" id="asiacellSms1Input" placeholder="12345" dir="ltr" inputmode="numeric" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                <div class="form-alert-inline hidden" id="asiacellSms1Error"></div>
                                <button type="button" class="btn-gold" id="asiacellVerifySmsBtn" onclick="asiacellVerifySms('order')" style="margin-top:6px"><i class="fas fa-check"></i> تحقق وابدأ التحويل</button>
                                <button type="button" class="btn-back" onclick="asiacellReset('order')" style="margin-top:6px">إلغاء والبدء من جديد</button>
                            </div>
                            <div id="asiacellStepSms2" class="hidden">
                                <label class="field-label" style="display:block;text-align:right;font-size:13px;color:var(--text-muted);margin-bottom:6px">رمز تأكيد التحويل (SMS ثانٍ)</label>
                                <input type="text" id="asiacellSms2Input" placeholder="12345" dir="ltr" inputmode="numeric" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                <div class="form-alert-inline hidden" id="asiacellSms2Error"></div>
                                <button type="button" class="btn-gold" id="asiacellConfirmBtn" onclick="asiacellConfirmTransfer('order')" style="margin-top:6px"><i class="fas fa-lock"></i> تأكيد التحويل وإرسال الطلب</button>
                                <button type="button" class="btn-back" onclick="asiacellReset('order')" style="margin-top:6px">إلغاء والبدء من جديد</button>
                            </div>
                        </div>

                        <div class="form-alert-inline hidden" id="balanceInsufficientWarning"><i class="fas fa-circle-exclamation"></i> رصيدك الحالي غير كافٍ لإتمام هذا الطلب. اختر طريقة دفع أخرى أو اشحن رصيدك أولاً.</div>

                        <div class="order-total-row">
                            <span data-i18n="total">الإجمالي</span>
                            <span class="amount" id="paymentTotalAmount"></span>
                        </div>
                        <button type="submit" class="btn-gold" id="orderSubmitBtn" style="margin-top:14px"><i class="fas fa-lock"></i> <span data-i18n="pay_now">إرسال الطلب</span></button>
                    </form>
                    <div class="text-muted text-center" style="margin-top:10px;font-size:12px">سيتم تجهيز سيرفرك بعد مراجعة طلبك من الإدارة.</div>
                </div>

                <!-- خطوة 5: نجاح الطلب -->
                <div class="wizard-step hidden" id="vpsStepSuccess">
                    <div class="success-screen">
                        <div class="icon-big"><i class="fas fa-check"></i></div>
                        <h2>تم إرسال طلبك بنجاح!</h2>
                        <p id="orderSuccessId" class="text-muted" style="font-weight:800;direction:ltr"></p>
                        <p>طلبك الآن قيد المراجعة من فريقنا، بعد الموافقة ستظهر تفاصيل سيرفرك في "سيرفراتي" وستصلك فاتورة بالحالة.</p>
                    </div>
                    <button class="btn-gold" onclick="showSection('orders')"><i class="fas fa-list"></i> الذهاب إلى طلباتي</button>
                </div>
            </div>
            
            <!-- ============================================================
            القسم: فواتير
            ============================================================ -->
            <div id="section-invoices" class="section-content hidden">
                <!-- الرصيد -->
                <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff;text-align:center">
                    <div style="font-size:14px;opacity:.8">الرصيد الحالي</div>
                    <div style="font-size:36px;font-weight:900" data-usd="<?php echo (float)$balance; ?>">$<?php echo number_format($balance, 2); ?></div>
                    <button class="btn-gold" style="padding:10px;font-size:14px;margin-top:8px;width:auto;display:inline-flex" onclick="showAddBalance()">
                        <i class="fas fa-plus-circle"></i> إضافة رصيد
                    </button>
                </div>
                
                <!-- طرق الدفع -->
                <div id="addBalanceSection" class="hidden">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-credit-card"></i> طرق الدفع</h3>
                            <button class="btn-back" onclick="hideAddBalance()">رجوع</button>
                        </div>
                        <div>
                            <?php foreach ($payment_methods as $pm): ?>
                            <div class="pay-option"
                                data-id="<?php echo (int)$pm['id']; ?>"
                                data-name="<?php echo e($pm['name']); ?>"
                                data-account="<?php echo e($pm['account_number']); ?>"
                                data-instructions="<?php echo e($pm['instructions']); ?>"
                                data-type="<?php echo e($pm['method_type'] ?? 'manual'); ?>"
                                data-binance-id="<?php echo e($pm['binance_id'] ?? ''); ?>"
                                data-qr-code="<?php echo e($pm['qr_code'] ?? ''); ?>"
                                data-exchange-rate="<?php echo e($pm['exchange_rate'] ?? ''); ?>"
                                onclick="showPaymentPage(this.dataset.id, this.dataset.name, this.dataset.account, this.dataset.instructions, this.dataset.type, this.dataset.binanceId, this.dataset.qrCode, this.dataset.exchangeRate)">
                                <?php if (!empty($pm['logo_path'])): ?>
                                <div class="pm-logo-wrap"><img src="<?php echo e($pm['logo_path']); ?>" alt=""></div>
                                <?php else: ?>
                                <div class="icon-wrap <?php echo e($pm['color']); ?>">
                                    <i class="fas <?php echo e($pm['icon']); ?>"></i>
                                </div>
                                <?php endif; ?>
                                <div style="flex:1">
                                    <div class="title"><?php echo e($pm['name']); ?></div>
                                    <div class="sub"><?php echo in_array($pm['method_type'] ?? 'manual', ['binance', 'asiacell'], true) ? 'تحقق تلقائي فوري' : 'تحويل يدوي'; ?></div>
                                </div>
                                <i class="fas fa-chevron-left" style="color:var(--text-muted);font-size:12px"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- صفحة الدفع -->
                <div id="paymentPage" class="hidden">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-credit-card"></i> <span id="paymentMethodName">الدفع</span></h3>
                            <button class="btn-back" onclick="hidePaymentPage()">رجوع</button>
                        </div>
                        <form method="POST" action="index.php" enctype="multipart/form-data" onsubmit="return handleTopUpFormSubmit(event)">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="top_up">
                            <input type="hidden" name="payment_method_id" id="topUpPaymentMethodId" value="">

                            <div id="topUpInstructions" class="hosting-detail" style="margin-bottom:12px"></div>

                            <div class="input-group" style="margin-bottom:12px">
                                <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:4px"><span data-i18n="amount_label">المبلغ</span> (<span id="topUpCurrencyLabel">$</span>)</label>
                                <input type="number" id="topUpAmountInput" min="0.01" step="0.01" placeholder="أدخل المبلغ" required oninput="syncTopUpAmountUsd()" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none">
                                <input type="hidden" name="amount" id="topUpAmountUsd" value="">
                                <div class="text-muted" id="topUpUsdHint" style="font-size:11px;margin-top:4px"></div>
                                <div class="text-muted" id="topUpManualIqdHint" style="font-size:11px;margin-top:4px;font-weight:700"></div>
                            </div>

                            <div id="topUpProofWrap">
                                <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:4px">صورة إيصال التحويل</label>
                                <input type="file" name="proof_image" id="topUpProofInput" accept="image/png,image/jpeg,image/webp" style="width:100%;padding:10px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:13px;font-family:inherit;margin-bottom:12px">
                            </div>

                            <div id="topUpBinanceWrap" class="hidden">
                                <div id="topUpBinanceStepInfo">
                                    <div id="topUpBinancePayInfo" style="margin-bottom:12px"></div>
                                    <div class="form-alert-inline hidden" id="topUpBinanceAmountError"></div>
                                    <button type="button" class="btn-gold" onclick="binanceGoToConfirm('topup')"><i class="fas fa-check"></i> لقد أرسلت الدفعة، متابعة</button>
                                </div>
                                <div id="topUpBinanceStepConfirm" class="hidden">
                                    <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:4px">رقم عملية Binance (Order ID)</label>
                                    <input type="text" id="topUpBinanceOrderIdInput" placeholder="Order ID" dir="ltr" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                    <div class="form-alert-inline hidden" id="topUpBinanceError"></div>
                                    <button type="button" class="btn-gold" id="topUpBinanceSubmitBtn" onclick="submitBinanceTopup()" style="margin-top:6px"><i class="fas fa-lock"></i> تحقق وشحن الرصيد</button>
                                    <button type="button" class="btn-back" onclick="binanceShowStep('topup','info')" style="margin-top:6px">رجوع</button>
                                </div>
                            </div>

                            <div id="topUpAsiacellWrap" class="hidden">
                                <div id="topUpAsiacellPayInfo" style="margin-bottom:12px"></div>
                                <div id="topUpAsiacellStepPhone">
                                    <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:4px">رقم هاتفك في آسياسيل</label>
                                    <input type="text" id="topUpAsiacellPhoneInput" placeholder="07xxxxxxxxx" dir="ltr" inputmode="numeric" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                    <div class="form-alert-inline hidden" id="topUpAsiacellPhoneError"></div>
                                    <button type="button" class="btn-gold" id="topUpAsiacellSendCodeBtn" onclick="asiacellSendCode('topup')" style="margin-top:6px"><i class="fas fa-paper-plane"></i> إرسال رمز التحقق</button>
                                </div>
                                <div id="topUpAsiacellStepSms1" class="hidden">
                                    <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:4px">رمز التحقق المُرسل إلى هاتفك (SMS)</label>
                                    <input type="text" id="topUpAsiacellSms1Input" placeholder="12345" dir="ltr" inputmode="numeric" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                    <div class="form-alert-inline hidden" id="topUpAsiacellSms1Error"></div>
                                    <button type="button" class="btn-gold" id="topUpAsiacellVerifySmsBtn" onclick="asiacellVerifySms('topup')" style="margin-top:6px"><i class="fas fa-check"></i> تحقق وابدأ التحويل</button>
                                    <button type="button" class="btn-back" onclick="asiacellReset('topup')" style="margin-top:6px">إلغاء والبدء من جديد</button>
                                </div>
                                <div id="topUpAsiacellStepSms2" class="hidden">
                                    <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:4px">رمز تأكيد التحويل (SMS ثانٍ)</label>
                                    <input type="text" id="topUpAsiacellSms2Input" placeholder="12345" dir="ltr" inputmode="numeric" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none;margin-bottom:8px">
                                    <div class="form-alert-inline hidden" id="topUpAsiacellSms2Error"></div>
                                    <button type="button" class="btn-gold" id="topUpAsiacellConfirmBtn" onclick="asiacellConfirmTransfer('topup')" style="margin-top:6px"><i class="fas fa-lock"></i> تأكيد التحويل وشحن الرصيد</button>
                                    <button type="button" class="btn-back" onclick="asiacellReset('topup')" style="margin-top:6px">إلغاء والبدء من جديد</button>
                                </div>
                            </div>

                            <?php if (!empty($_GET['topup_error'])): ?>
                                <div class="form-alert-inline"><?php echo e($_GET['topup_error']); ?></div>
                            <?php endif; ?>

                            <button type="submit" class="btn-pay">
                                <i class="fas fa-check"></i> إرسال طلب الشحن
                            </button>
                        </form>
                        <div class="text-muted text-center" style="margin-top:10px;font-size:12px" id="topUpFooterNote">سيتم إضافة الرصيد بعد مراجعة الإيصال من الإدارة.</div>
                    </div>
                </div>

                <!-- قائمة الفواتير -->
                <div id="invoicesList">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-receipt"></i> جميع الفواتير</h3>
                        </div>
                        <?php if (!$invoices): ?>
                        <div class="text-muted text-center" style="padding:24px 0">لا توجد فواتير بعد</div>
                        <?php endif; ?>
                        <?php foreach ($invoices as $inv):
                            $invStatusLabel = ['paid' => 'مكتمـل', 'pending' => ' معلق', 'rejected' => 'ملغى'][$inv['status']] ?? $inv['status'];
                            $invStatusPill = ['paid' => 'pill-green', 'pending' => 'pill-amber', 'rejected' => 'pill-red'][$inv['status']] ?? 'pill-amber';
                        ?>
                        <div class="invoice-item" onclick="showInvoiceDetail(<?php echo (int)$inv['id']; ?>)">
                            <div class="info">
                                <div class="number"><?php echo e($inv['number']); ?></div>
                                <div class="date"><?php echo $inv['due_date'] ? 'استحقاق: ' . e($inv['due_date']) : e($inv['description']); ?></div>
                            </div>
                            <div style="text-align:left">
                                <div class="amount" data-usd="<?php echo (float)$inv['amount']; ?>">$<?php echo money($inv['amount']); ?></div>
                                <span class="pill <?php echo $invStatusPill; ?>"><?php echo $invStatusLabel; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- تفاصيل الفاتورة -->
                <div id="invoiceDetail" class="hidden">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-file-invoice"></i> تفاصيل الفاتورة</h3>
                        <button class="btn-back" onclick="hideInvoiceDetail()">رجوع</button>
                    </div>
                    <div class="invoice-detail" id="invoiceDetailContent">
                        <!-- يتم تعبئتها بواسطة JavaScript -->
                    </div>
                </div>
            </div>
            
            <!-- ============================================================
            القسم: طلباتي
            ============================================================ -->
            <div id="section-orders" class="section-content hidden">
                <div id="ordersListCard">
                    <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff;text-align:center">
                        <h3 style="font-size:18px;font-weight:900">📋 طلباتي</h3>
                        <div style="font-size:13px;opacity:.8">إجمالي الطلبات: <?php echo count($orders); ?></div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-list"></i> جميع الطلبات</h3>
                            <button class="btn-outline btn-small" onclick="showSection('vps')"><i class="fas fa-plus"></i> طلب جديد</button>
                        </div>
                        <?php if (!$orders): ?>
                        <div class="text-muted text-center" style="padding:30px 0">
                            📭 ما عندك طلبات VPS بعد<br>
                            <span style="color:var(--accent);cursor:pointer" onclick="showSection('vps')">اطلب خادمك الآن</span>
                        </div>
                        <?php else: foreach ($orders as $o):
                            $statusLabel = ['pending' => ' قيد المراجعة', 'approved' => ' مقبول', 'rejected' => 'ملغى'][$o['status']] ?? $o['status'];
                            $statusPill = ['pending' => 'pill-amber', 'approved' => 'pill-green', 'rejected' => 'pill-red'][$o['status']] ?? 'pill-amber';
                        ?>
                        <div class="invoice-item" onclick="showOrderDetail(<?php echo (int)$o['id']; ?>)">
                            <div class="info">
                                <div class="number"><?php echo planIconHtml($o['plan_icon'], $o['plan_icon_image'] ?? null, 18); ?> خادم <?php echo e($o['plan_name']); ?></div>
                                <div class="date">تاريخ الطلب: <?php echo e(substr($o['created_at'], 0, 10)); ?></div>
                            </div>
                            <div style="text-align:left">
                                <div class="amount" data-usd="<?php echo (float)$o['amount']; ?>">$<?php echo money($o['amount']); ?></div>
                                <span class="pill <?php echo $statusPill; ?>"><?php echo $statusLabel; ?></span>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <div id="orderDetail" class="hidden">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-clipboard-list"></i> <span data-i18n="order_details">تفاصيل الطلب</span></h3>
                        <button class="btn-back" onclick="hideOrderDetail()" data-i18n="back">رجوع</button>
                    </div>
                    <div class="invoice-detail" id="orderDetailContent"></div>
                </div>
            </div>

            <!-- ============================================================
            القسم: الإشعارات
            ============================================================ -->
            <div id="section-notifications" class="section-content hidden">
                <div class="card-header" style="margin-bottom:14px">
                    <h3><i class="fas fa-bell"></i> الإشعارات</h3>
                    <button class="btn-back" onclick="showSection('home')">رجوع</button>
                </div>

                <?php if ($notifications): ?>
                <div class="notif-actions-row">
                    <button class="btn-outline btn-small" onclick="markNotificationsRead()"><i class="fas fa-check-double"></i> تحديد الكل كمقروء</button>
                    <button class="btn-outline btn-small" onclick="deleteAllNotifications()"><i class="fas fa-trash"></i> حذف الكل</button>
                </div>
                <div class="card" style="padding:6px 14px" id="notificationsListCard">
                    <?php foreach ($notifications as $n):
                        $nMeta = [
                            'topup_approved' => ['fa-wallet', 'green'],
                            'order_approved' => ['fa-circle-check', 'green'],
                            'order_rejected' => ['fa-circle-xmark', 'gold'],
                            'topup_rejected' => ['fa-circle-xmark', 'gold'],
                            'coupon' => ['fa-tag', 'gold'],
                        ][$n['type']] ?? ['fa-bullhorn', 'blue'];
                    ?>
                    <div class="notif-item<?php echo (int)$n['is_read'] ? '' : ' unread'; ?>" data-notif-id="<?php echo (int)$n['id']; ?>">
                        <div class="icon-wrap <?php echo $nMeta[1]; ?>"><i class="fas <?php echo $nMeta[0]; ?>"></i></div>
                        <div class="notif-text">
                            <div class="notif-title"><?php echo e($n['title']); ?></div>
                            <?php if ($n['body']): ?><div class="notif-body"><?php echo e($n['body']); ?></div><?php endif; ?>
                            <div class="notif-time"><?php echo e($n['created_at']); ?></div>
                        </div>
                        <button class="notif-delete-btn" onclick="deleteNotification(<?php echo (int)$n['id']; ?>)" title="حذف"><i class="fas fa-xmark"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-muted text-center" style="padding:40px 0" id="noNotificationsMsg">📭 لا توجد إشعارات حالياً</div>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
            <!-- ============================================================
            القسم: لوحة التحكم (مضمّنة)
            ============================================================ -->
            <div id="section-admin" class="section-content hidden">
                <div class="card-header" style="margin-bottom:14px">
                    <h3><i class="fas fa-gauge"></i> لوحة التحكم</h3>
                    <button class="btn-back" onclick="showSection('settings')">رجوع</button>
                </div>

                <link rel="stylesheet" href="<?php echo e(assetUrl('assets/css/admin.css')); ?>">

                <nav class="admin-tabs" id="adminTabsNav">
                    <button type="button" class="admin-tab active" style="border:none;cursor:pointer;font-family:inherit" data-admin-tab="orders" onclick="showAdminTab('orders')">
                        <i class="fas fa-clipboard-list"></i> الطلبات
                        <?php if ($adminPendingOrdersCount): ?><span class="tab-badge"><?php echo $adminPendingOrdersCount; ?></span><?php endif; ?>
                    </button>
                    <button type="button" class="admin-tab" style="border:none;cursor:pointer;font-family:inherit" data-admin-tab="topups" onclick="showAdminTab('topups')">
                        <i class="fas fa-wallet"></i> شحن الرصيد
                        <?php if ($adminPendingTopupsCount): ?><span class="tab-badge"><?php echo $adminPendingTopupsCount; ?></span><?php endif; ?>
                    </button>
                    <button type="button" class="admin-tab" style="border:none;cursor:pointer;font-family:inherit" data-admin-tab="plans" onclick="showAdminTab('plans')"><i class="fas fa-server"></i> الباقات</button>
                    <button type="button" class="admin-tab" style="border:none;cursor:pointer;font-family:inherit" data-admin-tab="payments" onclick="showAdminTab('payments')"><i class="fas fa-credit-card"></i> طرق الدفع</button>
                    <button type="button" class="admin-tab" style="border:none;cursor:pointer;font-family:inherit" data-admin-tab="settings" onclick="showAdminTab('settings')"><i class="fas fa-gear"></i> الإعدادات</button>
                    <button type="button" class="admin-tab" style="border:none;cursor:pointer;font-family:inherit" data-admin-tab="backups" onclick="showAdminTab('backups')"><i class="fas fa-database"></i> نسخ احتياطي</button>
                </nav>

                <div class="admin-container">
                    <div class="admin-hero" id="adminHeroCard">
                        <div class="admin-hero-top">
                            <div class="admin-hero-icon"><i class="fas fa-gauge-high"></i></div>
                            <div>
                                <h3>مرحباً، <?php echo e($user_name); ?> 👋</h3>
                                <div class="admin-hero-sub">إليك ملخص نشاط المنصة اليوم</div>
                            </div>
                        </div>
                        <div class="admin-hero-date"><i class="fas fa-calendar-alt"></i> <?php echo date('l, d F Y'); ?></div>
                    </div>

                    <div class="stats-row" id="adminStatsRow">
                        <div class="stat-tile"><div class="num"><?php echo $adminPendingOrdersCount; ?></div><div class="label">طلبات قيد المراجعة</div></div>
                        <div class="stat-tile"><div class="num"><?php echo $adminPendingTopupsCount; ?></div><div class="label">طلبات شحن معلقة</div></div>
                        <div class="stat-tile"><div class="num"><?php echo $adminUsersCount; ?></div><div class="label">إجمالي المستخدمين</div></div>
                        <div class="stat-tile"><div class="num"><?php echo $adminActiveHostingCount; ?></div><div class="label">استضافات نشطة</div></div>
                    </div>

                    <div id="adminFlashMsg" class="flash-msg hidden"><i class="fas fa-circle-check"></i> <span></span></div>
                    <div id="adminFlashErr" class="flash-err hidden"><i class="fas fa-triangle-exclamation"></i> <span></span></div>

                    <div class="admin-tab-panel" data-admin-panel="orders">
                        <?php renderAdminOrders($pdo); ?>
                    </div>
                    <div class="admin-tab-panel hidden" data-admin-panel="topups">
                        <?php renderAdminTopups($pdo); ?>
                    </div>
                    <div class="admin-tab-panel hidden" data-admin-panel="plans">
                        <?php renderAdminPlans($pdo); ?>
                    </div>
                    <div class="admin-tab-panel hidden" data-admin-panel="payments">
                        <?php renderAdminPayments($pdo); ?>
                    </div>
                    <div class="admin-tab-panel hidden" data-admin-panel="settings">
                        <?php renderAdminSettings($pdo); ?>
                    </div>
                    <div class="admin-tab-panel hidden" data-admin-panel="backups">
                        <?php renderAdminBackups($pdo); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================================
            القسم: إعدادات
            ============================================================ -->
            <div id="section-settings" class="section-content hidden">
                <div class="settings-container">
                    <div class="profile-card">
                        <div class="avatar-large"><?php echo mb_substr($user_name, 0, 1); ?></div>
                        <div class="info">
                            <h4><?php echo htmlspecialchars($user_name); ?></h4>
                            <div class="sub"><?php echo htmlspecialchars($user['email'] ?? ($user['phone'] ?? '')); ?></div>
                            <span class="badge"><?php echo $isAdmin ? '🛡️ مدير' : '👤 مستخدم'; ?></span>
                        </div>
                    </div>

                    <div class="card currency-card" onclick="openCurrencyPicker()">
                        <div class="currency-card-icon"><i class="fas fa-coins"></i></div>
                        <div class="currency-card-text">
                            <div class="currency-card-title" data-i18n="currency_display">عملة عرض الأسعار</div>
                            <div class="currency-card-sub" data-i18n="currency_display_sub">تُطبَّق على كل الأسعار المعروضة لك في التطبيق والموقع</div>
                            <div class="currency-card-value" id="currencyCardValue"></div>
                        </div>
                        <i class="fas fa-chevron-left currency-card-chevron"></i>
                    </div>

                    <div class="card currency-card" onclick="openLanguagePicker()">
                        <div class="currency-card-icon"><i class="fas fa-language"></i></div>
                        <div class="currency-card-text">
                            <div class="currency-card-title" data-i18n="language">اللغة</div>
                            <div class="currency-card-sub" data-i18n="language_sub">لغة عرض التطبيق</div>
                            <div class="currency-card-value" id="languageCardValue"></div>
                        </div>
                        <i class="fas fa-chevron-left currency-card-chevron"></i>
                    </div>

                    <?php if ($isAdmin): ?>
                    <div class="settings-group">
                        <div class="group-header">
                            <i class="fas fa-user-shield"></i> <span data-i18n="administration">الإدارة</span>
                        </div>
                        <div class="settings-item" onclick="showSection('admin')">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-gauge"></i></div>
                                <div class="text">
                                    <div class="title" data-i18n="admin_panel">لوحة التحكم</div>
                                    <div class="sub" data-i18n="admin_panel_sub">إدارة الموقع والطلبات والإعدادات</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="settings-group">
                        <div class="group-header">
                            <i class="fas fa-sliders-h"></i> <span data-i18n="general_settings">الإعدادات العامة</span>
                        </div>

                        <div class="settings-item" onclick="toggleTheme()">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-moon"></i></div>
                                <div class="text">
                                    <div class="title" data-i18n="dark_mode">المظهر الداكن</div>
                                    <div class="sub" data-i18n="dark_mode_sub">الوضع الليلي للتطبيق</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="darkModeToggle" checked onchange="toggleTheme()">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-sync"></i></div>
                                <div class="text">
                                    <div class="title" data-i18n="auto_renew">التجديد التلقائي</div>
                                    <div class="sub" data-i18n="auto_renew_sub">تجديد استضافاتك تلقائياً من رصيدك عند الانتهاء</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="autoRenewToggle" <?php echo !empty($user['auto_renew']) ? 'checked' : ''; ?> onchange="toggleAutoRenewSetting()">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="settings-item" onclick="showSection('notifications')">
                            <div class="left">
                                <div class="icon-wrap blue"><i class="fas fa-bell"></i></div>
                                <div class="text">
                                    <div class="title" data-i18n="notifications">الإشعارات</div>
                                    <div class="sub"><?php echo $unreadNotifCount > 0 ? $unreadNotifCount . ' إشعار غير مقروء' : 'لا توجد إشعارات جديدة'; ?></div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>

                

                    </div>

                    <div class="settings-group">
                        <div class="group-header">
                            <i class="fas fa-headset"></i> الدعم والتواصل
                        </div>
                        
                        <?php if ($supportWhatsapp !== ''): ?>
                        <div class="settings-item" onclick="window.open('https://wa.me/<?php echo e($supportWhatsapp); ?>', '_blank')">
                            <div class="left">
                                <div class="icon-wrap green"><i class="fab fa-whatsapp"></i></div>
                                <div class="text">
                                    <div class="title">واتساب الدعم الفني</div>
                                    <div class="sub">تواصل مباشر مع فريق الدعم</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="settings-group">
                        <div class="group-header">
                            <i class="fas fa-info-circle"></i> معلومات التطبيق
                        </div>
                        
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-code"></i></div>
                                <div class="text">
                                    <div class="title">الإصدار</div>
                                    <div class="sub">آخر تحديث</div>
                                </div>
                            </div>
                            <div class="right">
                                <span style="color:var(--text-secondary);font-weight:600;font-size:12px">v2.0.0</span>
                            </div>
                        </div>
                        
                        <div class="settings-item" onclick="location.href='index.php?page=policies&amp;type=privacy'">
                            <div class="left">
                                <div class="icon-wrap blue"><i class="fas fa-shield-alt"></i></div>
                                <div class="text">
                                    <div class="title">سياسة الخصوصية</div>
                                    <div class="sub">اطلع على سياسة الخصوصية</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>

                        <div class="settings-item" onclick="location.href='index.php?page=policies&amp;type=terms'">
                            <div class="left">
                                <div class="icon-wrap purple"><i class="fas fa-file-contract"></i></div>
                                <div class="text">
                                    <div class="title">الشروط والأحكام</div>
                                    <div class="sub">اطلع على شروط الاستخدام</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                    </div>
                    
                    <button class="btn-gold" onclick="showLogoutSheet()" style="margin-top:4px">
                        <i class="fas fa-sign-out-alt"></i> تسجيل الخروج
                    </button>
                    
                    <div class="text-center text-muted" style="font-size:11px;padding:12px 0">
                        <i class="fas fa-code"></i> منصة <?php echo e($siteName); ?> v2.0 · جميع الحقوق محفوظة
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================================
        القائمة السفلية
        ============================================================ -->
        <nav class="bottom-nav" id="mainBottomNav">
            <button class="nav-item active" data-section="home" onclick="showSection('home')">
                <i class="fas fa-house"></i>
                <span data-i18n="home">الرئيسية</span>
            </button>
            <button class="nav-item" data-section="servers" onclick="showSection('servers')">
                <i class="fas fa-server"></i>
                <span data-i18n="my_servers">سيرفراتي</span>
            </button>
            <button class="nav-item nav-item-fab" data-section="vps" onclick="showSection('vps')">
                <span class="fab-icon"><i class="fas fa-plus"></i></span>
                <span data-i18n="new_order">طلب جديد</span>
            </button>
            <button class="nav-item" data-section="invoices" onclick="showSection('invoices')">
                <i class="fas fa-receipt"></i>
                <span data-i18n="invoices">الفواتير</span>
            </button>
            <button class="nav-item" data-section="settings" onclick="showSection('settings')">
                <i class="fas fa-user"></i>
                <span data-i18n="account">الحساب</span>
            </button>
        </nav>

        <!-- ============================================================
        المساعد الذكي - تطبيق مصغر منفصل
        ============================================================ -->
        <div class="ai-screen hidden" id="section-ai">
            <div class="ai-header">
                <button class="back-btn" onclick="exitAI()"><i class="fas fa-arrow-right"></i></button>
                <div class="ai-avatar"><?php echo $aiLogo ? '<img src="' . e($aiLogo) . '" alt="">' : '<i class="fas fa-robot"></i>'; ?></div>
                <h2 id="aiHeaderTitle">المساعد الذكي</h2>
                <button class="back-btn" onclick="showAiView('conversations')" title="سجل محادثات سابقة"><i class="fas fa-clock-rotate-left"></i></button>
            </div>

            <div class="ai-body" id="aiBody">
                <!-- الرئيسية -->
                <div id="aiViewHome" class="ai-view">
                    <div class="ai-greeting-card">
                        <h3>مرحباً <?php echo htmlspecialchars($user_name); ?>! 👋</h3>
                        <p>كيف يمكنني مساعدتك اليوم؟</p>
                    </div>
                    <div class="ai-quick-grid">
                        <button class="ai-quick-card" onclick="showAiView('explain')"><i class="fas fa-terminal"></i><span>شرح أمر</span></button>
                        <button class="ai-quick-card" onclick="showAiView('suggestions')"><i class="fas fa-lightbulb"></i><span>اقتراحات ذكية</span></button>
                    </div>
                    <div class="chat-log" id="aiHomeChatLog"></div>
                </div>

                <!-- شرح أمر -->
                <div id="aiViewExplain" class="ai-view hidden">
                    <div class="chat-log" id="aiExplainChatLog"></div>
                </div>

                <!-- اقتراحات ذكية -->
                <div id="aiViewSuggestions" class="ai-view hidden">
                    <div class="chat-log" id="aiSuggestionsChatLog"></div>
                </div>

                <!-- الأدوات الذكية -->
                <div id="aiViewTools" class="ai-view hidden">
                    <div class="settings-group">
                        <div class="group-header"><i class="fas fa-wand-magic-sparkles"></i> الأدوات الذكية</div>
                        <?php foreach ($ai_tools as $tool): ?>
                        <div class="settings-item" onclick="alert('⚙️ جاري تشغيل: <?php echo htmlspecialchars($tool['title']); ?>')">
                            <div class="left">
                                <div class="icon-wrap <?php echo $tool['color']; ?>"><i class="fas <?php echo $tool['icon']; ?>"></i></div>
                                <div class="text">
                                    <div class="title"><?php echo htmlspecialchars($tool['title']); ?></div>
                                    <div class="sub"><?php echo htmlspecialchars($tool['sub']); ?></div>
                                </div>
                            </div>
                            <div class="right"><i class="fas fa-chevron-left chevron"></i></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- المحادثات -->
                <div id="aiViewConversations" class="ai-view hidden">
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-comments"></i> المحادثات السابقة</h3></div>
                        <div id="aiConversationsList">
                            <?php foreach ($ai_conversations as $c): ?>
                            <div class="invoice-item" data-title="<?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?>" onclick="openConversation(this.dataset.title)">
                                <div class="info">
                                    <div class="number"><?php echo htmlspecialchars($c['title']); ?></div>
                                    <div class="date"><?php echo htmlspecialchars($c['preview']); ?></div>
                                </div>
                                <div style="text-align:left;font-size:10px;color:var(--text-muted);white-space:nowrap"><?php echo htmlspecialchars($c['time']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- إعدادات المساعد -->
                <div id="aiViewSettings" class="ai-view hidden">
                    <div class="settings-group">
                        <div class="group-header"><i class="fas fa-sliders-h"></i> إعدادات المساعد</div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-brain"></i></div>
                                <div class="text">
                                    <div class="title">الوضع الذكي</div>
                                    <div class="sub">ردود آلية مخصصة لحالتك</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap blue"><i class="fas fa-comment-dots"></i></div>
                                <div class="text">
                                    <div class="title">الردود المقترحة</div>
                                    <div class="sub">عرض اقتراحات أثناء المحادثة</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap green"><i class="fas fa-language"></i></div>
                                <div class="text">
                                    <div class="title">لغة المساعد</div>
                                    <div class="sub">لغة الردود والشرح</div>
                                </div>
                            </div>
                            <div class="right">
                                <span style="color:var(--text-secondary);font-weight:600;font-size:12px">العربية</span>
                            </div>
                        </div>
                    </div>
                    <div class="settings-group">
                        <div class="group-header"><i class="fas fa-database"></i> حفظ المحادثات</div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap purple"><i class="fas fa-floppy-disk"></i></div>
                                <div class="text">
                                    <div class="title">حفظ سجل المحادثات</div>
                                    <div class="sub">للرجوع لها لاحقاً</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <button class="btn-outline" style="width:100%;color:#f87171;border-color:rgba(239,68,68,.3)" onclick="clearAiConversations()">
                        <i class="fas fa-trash"></i> مسح جميع المحادثات
                    </button>
                </div>
            </div>

            <div class="ai-input-bar" id="aiInputBar">
                <input type="text" id="aiInputField" placeholder="اكتب سؤالك هنا..." onkeydown="if(event.key==='Enter') sendAiMessage()">
                <button id="aiSendBtn" onclick="sendAiMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>

            <nav class="bottom-nav hidden" id="aiBottomNav">
                <button class="nav-item active" data-ai-view="home" onclick="showAiView('home')">
                    <i class="fas fa-house"></i>
                    <span>الرئيسية</span>
                </button>
                <button class="nav-item" data-ai-view="conversations" onclick="showAiView('conversations')">
                    <i class="fas fa-comments"></i>
                    <span>المحادثات</span>
                </button>
                <button class="nav-item nav-item-fab" onclick="showAiView('home')">
                    <span class="fab-icon"><i class="fas fa-plus"></i></span>
                    <span>محادثة جديدة</span>
                </button>
                <button class="nav-item" data-ai-view="tools" onclick="showAiView('tools')">
                    <i class="fas fa-wand-magic-sparkles"></i>
                    <span>الأدوات الذكية</span>
                </button>
                <button class="nav-item" data-ai-view="settings" onclick="showAiView('settings')">
                    <i class="fas fa-gear"></i>
                    <span>إعدادات</span>
                </button>
            </nav>
        </div>

        <?php echo currencyJsSnippet($pdo); ?>

        <script>
            // ============================================================
            // بيانات PHP
            // ============================================================
            const HOSTING = <?php echo json_encode($hosting); ?>;
            const INVOICES = <?php echo json_encode($invoices); ?>;
            const ORDERS = <?php echo json_encode($orders); ?>;
            const USER_BALANCE = <?php echo (float)$balance; ?>;
            const REFERRAL_ELIGIBLE = <?php echo $referralEligible ? 'true' : 'false'; ?>;
            const REFERRAL_DISCOUNT_PCT = <?php echo (float)$referralDiscountPct; ?>;
            const VPS_PLANS = <?php echo json_encode($vps_plans); ?>;
            const PAYMENT_METHODS = <?php echo json_encode($payment_methods); ?>;
            const ASIACELL_PENDING = <?php echo json_encode($asiacellPending); ?>;
            const AI_CONVERSATIONS = <?php echo json_encode($ai_conversations); ?>;
            const USER_NAME = <?php echo json_encode($user_name); ?>;
            const CSRF_TOKEN = <?php echo json_encode(csrfToken()); ?>;
            let NEEDS_ONBOARDING = <?php echo empty($user['onboarding_done']) ? 'true' : 'false'; ?>;
            const ROUTE_HINT = {
                buyPlanId: <?php echo (int)$buyPlanId; ?>,
                ordered: <?php echo $orderedFlag ? 'true' : 'false'; ?>,
                orderedId: <?php echo (int)$orderedId; ?>,
                hasOrderError: <?php echo !empty($orderErrorMsg) ? 'true' : 'false'; ?>,
                adminSection: <?php echo json_encode($adminSectionHint); ?>,
                adminMsg: <?php echo json_encode($adminMsgHint); ?>,
                adminErr: <?php echo json_encode($adminErrHint); ?>,
            };

            // إبقاء الرابط الظاهر دوماً domain/app دون أي معاملات إضافية
            if (window.location.search) {
                const cleanPath = window.location.pathname.endsWith('/app')
                    ? window.location.pathname
                    : window.location.pathname.replace(/index\.php$/, 'app');
                history.replaceState(null, '', cleanPath);
            }
        </script>
        <script src="<?php echo e(assetUrl('assets/js/app.js')); ?>"></script>
    </body>
    </html>
    <?php
}

// ============================================================
// بدء التشغيل
// ============================================================

includeLandingPage($pdo);
?>