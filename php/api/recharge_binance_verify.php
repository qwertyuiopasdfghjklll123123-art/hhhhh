<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/binance.php';
require_login();

function back($ok, $msg) {
    $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: ../wallet');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back(false, 'طريقة غير مسموحة');

$user = current_user();
$amount = (float)($_POST['amount'] ?? 0);
$orderId = trim($_POST['order_id'] ?? '');

if ($amount <= 0) back(false, 'أدخل مبلغ صحيح');
if (!$orderId) back(false, 'الرجاء إدخال رقم عملية Binance (Order ID).');

$pm = db()->query("SELECT * FROM payment_methods WHERE method_type='binance' AND active=1 LIMIT 1")->fetch();
if (!$pm) back(false, 'طريقة الدفع غير متاحة.');

$dupStmt = db()->prepare('SELECT COUNT(*) AS c FROM recharge_requests WHERE ext_ref = ?');
$dupStmt->execute([$orderId]);
if ((int)$dupStmt->fetch()['c'] > 0) {
    back(false, 'رقم عملية Binance هذا مستخدم مسبقاً لشحن رصيد آخر.');
}

[$verified, $result] = verify_binance_order($pm, $orderId, $amount);
if (!$verified) back(false, $result);

try {
    db()->prepare('INSERT INTO recharge_requests (user_id, amount, method, status, receipt, ext_ref, auto, created_at) VALUES (?,?,?,?,?,?,1,?)')
        ->execute([$user['id'], $amount, $pm['name'] ?: 'Binance Pay', 'approved', '', $orderId, time()]);
} catch (PDOException $e) {
    if ($e->getCode() == 23000) back(false, 'رقم عملية Binance هذا مستخدم مسبقاً لشحن رصيد آخر.');
    throw $e;
}
db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$amount, $user['id']]);

push_user_notif($user['id'], 'recharge', sprintf('تم شحن $%.2f', $amount), 'تم التحقق من عملية Binance Pay تلقائياً وإضافة الرصيد', 'check', 'wallet');

$admins = db()->query('SELECT id FROM users WHERE is_admin=1')->fetchAll();
foreach ($admins as $a) {
    push_user_notif($a['id'], 'admin', sprintf('شحن رصيد تلقائي $%.2f', $amount), "من {$user['name']} عبر Binance Pay (تحقق تلقائي)", 'wallet');
}

back(true, 'تم التحقق من الدفع وإضافة الرصيد بنجاح!');
