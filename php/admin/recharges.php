<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $req = null;
    if ($rid && in_array($action, ['approve', 'reject'])) {
        $stmt = db()->prepare('SELECT * FROM recharge_requests WHERE id = ?');
        $stmt->execute([$rid]);
        $req = $stmt->fetch();
    }
    if ($req && $req['status'] === 'pending') {
        if ($action === 'approve') {
            db()->prepare("UPDATE recharge_requests SET status='approved' WHERE id=?")->execute([$rid]);
            db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$req['amount'], $req['user_id']]);
            push_user_notif($req['user_id'], 'recharge', sprintf('تم شحن $%.2f', $req['amount']), 'تمت الموافقة على طلب الشحن وإضافة الرصيد', 'check', 'wallet');
        } else {
            db()->prepare("UPDATE recharge_requests SET status='rejected' WHERE id=?")->execute([$rid]);
            push_user_notif($req['user_id'], 'recharge', 'تم رفض طلب الشحن', sprintf('طلب شحن $%.2f عبر %s تم رفضه', $req['amount'], $req['method']), 'xmark', 'wallet');
        }
    }
    header('Location: recharges');
    exit;
}

$requests = db()->query(
    'SELECT r.*, u.name AS user_name, u.email AS user_email FROM recharge_requests r
     LEFT JOIN users u ON u.id = r.user_id ORDER BY r.created_at DESC LIMIT 100'
)->fetchAll();
$statusLabels = ['pending' => 'قيد المراجعة', 'approved' => 'مقبول', 'rejected' => 'مرفوض'];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'طلبات الشحن — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <h2>طلبات الشحن</h2>
    <a class="btn secondary" href="index">رجوع للوحة الإدارة</a>
  </div>
  <div class="card">
    <table>
      <tr><th>المستخدم</th><th>المبلغ</th><th>الطريقة</th><th>الحالة</th><th>الإثبات</th><th>إجراء</th></tr>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['user_name']) ?><br><span class="muted"><?= htmlspecialchars($r['user_email']) ?></span></td>
          <td>$<?= number_format((float)$r['amount'], 2) ?></td>
          <td><?= htmlspecialchars($r['method']) ?><?= $r['auto'] ? ' (تلقائي)' : '' ?></td>
          <td><span class="badge"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
          <td><?php if ($r['receipt']): ?><a href="../uploads/receipts/<?= htmlspecialchars($r['receipt']) ?>" target="_blank">عرض</a><?php else: ?>—<?php endif; ?></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" name="action" value="approve">قبول</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" name="action" value="reject" class="secondary">رفض</button>
              </form>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
