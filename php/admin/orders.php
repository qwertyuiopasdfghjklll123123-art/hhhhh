<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

$validStatuses = ['Completed', 'Processing', 'Pending', 'In progress', 'Canceled', 'Partial', 'Error', 'Queued'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oid = (int)($_POST['id'] ?? 0);
    $newStatus = trim($_POST['status'] ?? '');
    $refundAmount = round((float)($_POST['refund_amount'] ?? 0), 4);
    if ($oid && in_array($newStatus, $validStatuses)) {
        $stmt = db()->prepare('SELECT * FROM orders WHERE id=?');
        $stmt->execute([$oid]);
        $row = $stmt->fetch();
        if ($row) {
            $oldStatus = $row['status'];
            db()->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$newStatus, $oid]);
            $displayRef = $row['display_id'] ?: ($row['order_id'] ?: $oid);
            if ($refundAmount > 0 && !$row['refunded']) {
                db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$refundAmount, $row['user_id']]);
                db()->prepare('UPDATE orders SET refunded=1 WHERE id=?')->execute([$oid]);
                push_user_notif($row['user_id'], 'system', sprintf('استرجاع $%.2f', $refundAmount), "طلب #$displayRef — تم إرجاع المبلغ إلى رصيدك", 'wallet', 'wallet.php');
            }
            if ($newStatus === 'Completed' && $oldStatus !== 'Completed') {
                push_user_notif($row['user_id'], 'order', "طلب #$displayRef اكتمل", $row['service_name'], 'done', "dashboard.php");
            }
        }
    }
    header('Location: orders.php' . (!empty($_GET['status']) ? '?status=' . urlencode($_GET['status']) : ''));
    exit;
}

$filterStatus = trim($_GET['status'] ?? '');
if ($filterStatus) {
    $stmt = db()->prepare('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id WHERE o.status=? ORDER BY o.id DESC LIMIT 200');
    $stmt->execute([$filterStatus]);
} else {
    $stmt = db()->query('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 200');
}
$orders = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>الطلبات — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <h2>الطلبات</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>
  <div class="card">
    <form method="get">
      <select name="status" onchange="this.form.submit()">
        <option value="">كل الحالات</option>
        <?php foreach ($validStatuses as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card">
    <table>
      <tr><th>#</th><th>المستخدم</th><th>الخدمة</th><th>الكمية</th><th>السعر</th><th>الحالة</th><th>تغيير</th></tr>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td>#<?= htmlspecialchars($o['display_id'] ?: $o['id']) ?></td>
          <td><?= htmlspecialchars($o['email']) ?></td>
          <td><?= htmlspecialchars($o['service_name']) ?></td>
          <td><?= (int)$o['quantity'] ?></td>
          <td>$<?= number_format((float)$o['site_charge'], 2) ?></td>
          <td><span class="badge"><?= htmlspecialchars($o['status']) ?></span></td>
          <td>
            <form method="post" style="display:flex;gap:4px;align-items:center">
              <input type="hidden" name="id" value="<?= $o['id'] ?>">
              <select name="status" style="width:110px">
                <?php foreach ($validStatuses as $s): ?>
                  <option value="<?= $s ?>" <?= $o['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
              <input type="number" name="refund_amount" step="0.01" min="0" placeholder="استرجاع $" style="width:80px" <?= $o['refunded'] ? 'disabled' : '' ?>>
              <button type="submit">حفظ</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
