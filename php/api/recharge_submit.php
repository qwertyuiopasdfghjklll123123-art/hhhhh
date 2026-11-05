<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();

function back($ok, $msg) {
    $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: ../wallet.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back(false, 'طريقة غير مسموحة');

$user = current_user();
$amount = (float)($_POST['amount'] ?? 0);
$method = trim($_POST['method'] ?? '');

if ($amount <= 0) back(false, 'أدخل مبلغ صحيح');
if (!$method) back(false, 'اختر طريقة الدفع');
if (empty($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    back(false, 'يرجى إرفاق صورة إثبات الدفع');
}

$ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
    back(false, 'صيغة الصورة غير مدعومة');
}
$dest = 'receipt_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = __DIR__ . '/../uploads/receipts/' . $dest;
if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $destPath)) {
    back(false, 'تعذّر حفظ الصورة');
}

db()->prepare('INSERT INTO recharge_requests (user_id, amount, method, status, receipt, created_at) VALUES (?,?,?,?,?,?)')
    ->execute([$user['id'], $amount, $method, 'pending', $dest, time()]);

push_user_notif($user['id'], 'recharge', sprintf('طلب شحن $%.2f', $amount), "طلب شحن عبر $method بانتظار الموافقة", 'bolt');

$admins = db()->query('SELECT id FROM users WHERE is_admin=1')->fetchAll();
foreach ($admins as $a) {
    push_user_notif($a['id'], 'admin', sprintf('طلب شحن جديد $%.2f', $amount), "من {$user['name']} عبر $method", 'wallet');
}

back(true, 'تم إرسال طلب الشحن بنجاح! سيتم مراجعته قريباً');
