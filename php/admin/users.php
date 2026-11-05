<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int)($_POST['uid'] ?? 0);
    if (isset($_POST['balance_action']) && $uid) {
        $amount = (float)($_POST['amount'] ?? 0);
        $action = $_POST['balance_action'];
        if ($amount > 0) {
            $stmt = db()->prepare('SELECT balance FROM users WHERE id=?');
            $stmt->execute([$uid]);
            $current = (float)($stmt->fetch()['balance'] ?? 0);
            $newBal = $action === 'add' ? $current + $amount : max(0, $current - $amount);
            db()->prepare('UPDATE users SET balance=? WHERE id=?')->execute([$newBal, $uid]);
            if ($action === 'add') {
                push_user_notif($uid, 'recharge', sprintf('تم إضافة $%.2f لرصيدك', $amount), sprintf('رصيدك الجديد: $%.2f', $newBal), 'wallet');
            } else {
                push_user_notif($uid, 'system', sprintf('تم خصم $%.2f من رصيدك', $amount), sprintf('رصيدك الجديد: $%.2f', $newBal), 'wallet');
            }
        }
    } elseif (isset($_POST['toggle_ban']) && $uid) {
        $stmt = db()->prepare('SELECT banned FROM users WHERE id=?');
        $stmt->execute([$uid]);
        $banned = (int)($stmt->fetch()['banned'] ?? 0);
        $newVal = $banned ? 0 : 1;
        db()->prepare('UPDATE users SET banned=? WHERE id=?')->execute([$newVal, $uid]);
        if ($newVal) {
            push_user_notif($uid, 'system', 'تم حظر حسابك ⛔', 'تم حظر حسابك من قبل الإدارة.', 'lock');
        } else {
            push_user_notif($uid, 'system', 'تم إلغاء حظر حسابك', 'يمكنك الآن استخدام حسابك بشكل طبيعي.', 'check');
        }
    } elseif (isset($_POST['toggle_admin']) && $uid) {
        $stmt = db()->prepare('SELECT is_admin FROM users WHERE id=?');
        $stmt->execute([$uid]);
        $isAdmin = (int)($stmt->fetch()['is_admin'] ?? 0);
        db()->prepare('UPDATE users SET is_admin=? WHERE id=?')->execute([$isAdmin ? 0 : 1, $uid]);
    }
    header('Location: users.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
if ($search) {
    $stmt = db()->prepare("SELECT * FROM users WHERE name LIKE ? OR email LIKE ? ORDER BY created_at DESC LIMIT 200");
    $like = "%$search%";
    $stmt->execute([$like, $like]);
} else {
    $stmt = db()->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 200');
}
$users = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>المستخدمون — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <h2>المستخدمون</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>
  <div class="card">
    <form method="get">
      <input type="text" name="q" placeholder="بحث بالاسم أو البريد..." value="<?= htmlspecialchars($search) ?>">
      <button type="submit">بحث</button>
    </form>
  </div>
  <div class="card">
    <table>
      <tr><th>الاسم</th><th>البريد</th><th>الرصيد</th><th>الحالة</th><th>إجراءات</th></tr>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['name']) ?><?= $u['is_admin'] ? ' <span class="badge">أدمن</span>' : '' ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td>$<?= number_format((float)$u['balance'], 2) ?></td>
          <td><?= $u['banned'] ? '<span class="badge" style="background:#7f1d1d">محظور</span>' : '<span class="badge" style="background:#14532d">فعّال</span>' ?></td>
          <td>
            <form method="post" style="display:inline-flex;gap:4px">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <input type="number" name="amount" step="0.01" min="0.01" style="width:70px" placeholder="$">
              <button type="submit" name="balance_action" value="add" title="إضافة">+</button>
              <button type="submit" name="balance_action" value="sub" title="خصم" class="secondary">-</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" name="toggle_ban" value="1" class="secondary"><?= $u['banned'] ? 'إلغاء الحظر' : 'حظر' ?></button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="uid" value="<?= $u['id'] ?>">
              <button type="submit" name="toggle_admin" value="1" class="secondary"><?= $u['is_admin'] ? 'إزالة أدمن' : 'جعله أدمن' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
