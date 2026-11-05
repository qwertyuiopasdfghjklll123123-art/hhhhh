<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

function back($ok, $msg) {
    $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: ../wallet.php');
    exit;
}

$code = strtoupper(trim($_POST['code'] ?? ''));
if (!$code) back(false, 'أدخل كود الكوبون');

$stmt = db()->prepare('SELECT * FROM coupons WHERE code=? AND active=1');
$stmt->execute([$code]);
$coupon = $stmt->fetch();
if (!$coupon) back(false, 'كود غير صالح أو منتهي');

if ($coupon['expires_at'] && $coupon['expires_at'] < date('Y-m-d')) {
    back(false, 'الكوبون منتهي الصلاحية');
}
if ((int)$coupon['used'] >= (int)$coupon['max_uses']) {
    back(false, 'تم استخدام الكوبون بالكامل');
}
$usedStmt = db()->prepare('SELECT id FROM coupon_uses WHERE coupon_id=? AND user_id=?');
$usedStmt->execute([$coupon['id'], $user['id']]);
if ($usedStmt->fetch()) {
    back(false, 'لقد استخدمت هذا الكوبون مسبقاً');
}

db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$coupon['amount'], $user['id']]);
db()->prepare('UPDATE coupons SET used = used + 1 WHERE id = ?')->execute([$coupon['id']]);
try {
    db()->prepare('INSERT INTO coupon_uses (coupon_id, user_id, created_at) VALUES (?,?,?)')
        ->execute([$coupon['id'], $user['id'], time()]);
} catch (PDOException $e) {
    back(false, 'لقد استخدمت هذا الكوبون مسبقاً');
}

push_user_notif($user['id'], 'system', 'كوبون هدية', sprintf('تم إضافة $%.2f لرصيدك بكوبون %s', $coupon['amount'], $code), 'wallet');
back(true, sprintf('تم إضافة $%.2f لرصيدك!', $coupon['amount']));
