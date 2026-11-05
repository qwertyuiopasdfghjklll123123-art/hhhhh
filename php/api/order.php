<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/smm.php';
require_login();

function back_with_flash($ok, $msg) {
    $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: ../dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_with_flash(false, 'طريقة غير مسموحة');
}

$user = current_user();
$serviceId = trim($_POST['service'] ?? '');
$link = trim($_POST['link'] ?? '');
$quantity = (int)($_POST['quantity'] ?? 0);

if ($serviceId === '' || $link === '' || $quantity <= 0) {
    back_with_flash(false, 'جميع الحقول مطلوبة');
}

$provider = get_default_provider();
if (!$provider) {
    back_with_flash(false, 'لا يوجد مزوّد SMM فعّال حالياً');
}

$service = find_service($provider, $serviceId);
if (!$service) {
    back_with_flash(false, 'الخدمة غير موجودة لدى المزوّد حالياً');
}

$min = (int)($service['min'] ?? 1);
$max = (int)($service['max'] ?? 1000000);
if ($quantity < $min) back_with_flash(false, "الحد الأدنى للكمية $min");
if ($quantity > $max) back_with_flash(false, "الحد الأقصى للكمية $max");

$filterStmt = db()->prepare("SELECT profit_pct FROM service_filters WHERE filter_type='service' AND filter_key=?");
$filterStmt->execute([$serviceId]);
$filterRow = $filterStmt->fetch();
$profitPct = ($filterRow && $filterRow['profit_pct'] !== null) ? (float)$filterRow['profit_pct'] : (float)get_setting('default_profit_pct', '20');
$rate = (float)($service['rate'] ?? 0);
$sitePrice = round(($rate * (1 + $profitPct / 100)) * $quantity / 1000, 4);

$balRow = db()->prepare('SELECT balance FROM users WHERE id = ?');
$balRow->execute([$user['id']]);
$balance = (float)$balRow->fetch()['balance'];

if (!$user['is_admin'] && $sitePrice > 0 && $balance < $sitePrice) {
    back_with_flash(false, sprintf('رصيد غير كافٍ. المطلوب: $%.2f — رصيدك: $%.2f', $sitePrice, $balance));
}

// نخصم الرصيد ونثبّته (commit فوري بوضع autocommit الافتراضي لـ PDO) قبل استدعاء
// API المزوّد البطيء شبكياً — نفس الدرس المستفاد من نسخة Python: لا نبقي
// أي قفل/معاملة مفتوحة أثناء انتظار رد خارجي.
if (!$user['is_admin'] && $sitePrice > 0) {
    db()->prepare('UPDATE users SET balance = balance - ? WHERE id = ?')->execute([$sitePrice, $user['id']]);
}

$result = smm_api_post($provider['api_url'], [
    'key' => $provider['api_key'],
    'action' => 'add',
    'service' => $serviceId,
    'link' => $link,
    'quantity' => $quantity,
]);

if (!is_array($result) || empty($result['order'])) {
    // فشل المزوّد — نرجّع المبلغ المخصوم فوراً
    if (!$user['is_admin'] && $sitePrice > 0) {
        db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$sitePrice, $user['id']]);
    }
    $reason = is_array($result) ? ($result['error'] ?? 'خطأ غير معروف من المزوّد') : 'تعذّر الاتصال بالمزوّد';
    back_with_flash(false, 'فشل إرسال الطلب للمزوّد: ' . $reason);
}

$displayId = next_display_id();
db()->prepare(
    'INSERT INTO orders (user_id, order_id, provider_id, service, service_name, link, quantity, charge, site_charge, status, display_id, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    $user['id'],
    (string)$result['order'],
    (int)$provider['id'],
    $serviceId,
    $service['name'] ?? '',
    $link,
    $quantity,
    (string)($result['charge'] ?? '0'),
    $sitePrice,
    'Processing',
    $displayId,
    time(),
]);

back_with_flash(true, "تم إرسال طلبك بنجاح — رقم الطلب #$displayId");
