<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
$user = current_user();

function back($ok, $msg) {
    $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg];
    header('Location: ../dashboard.php');
    exit;
}

if (get_setting('daily_bonus_enabled', '0') !== '1') {
    back(false, 'هذه الميزة غير مفعّلة حالياً');
}

$stmt = db()->prepare('SELECT last_daily_bonus, balance FROM users WHERE id=?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
$last = (float)($row['last_daily_bonus'] ?? 0);
if (time() - $last < 86400) {
    back(false, 'يمكنك استلام المكافأة مرة كل 24 ساعة فقط');
}

$amount = (float)(get_setting('daily_bonus_amount', '0.5') ?: 0.5);
db()->prepare('UPDATE users SET balance = balance + ?, last_daily_bonus = ? WHERE id = ?')
    ->execute([$amount, time(), $user['id']]);

push_user_notif($user['id'], 'system', 'مكافأتك اليومية 🎁', sprintf('حصلت على $%.2f', $amount), 'gift');
back(true, sprintf('حصلت على مكافأة $%.2f!', $amount));
