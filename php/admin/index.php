<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_provider'])) {
    $apiUrl = trim($_POST['api_url'] ?? '');
    $apiKey = trim($_POST['api_key'] ?? '');
    db()->prepare('UPDATE providers SET api_url = ?, api_key = ? WHERE is_default = 1')->execute([$apiUrl, $apiKey]);
    $_SESSION['flash'] = ['ok' => true, 'msg' => 'تم حفظ إعدادات المزوّد'];
    header('Location: index.php');
    exit;
}

$stats = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS users_count,
        (SELECT COUNT(*) FROM orders) AS orders_count,
        (SELECT COALESCE(SUM(site_charge),0) FROM orders) AS revenue,
        (SELECT COUNT(*) FROM orders WHERE status NOT IN ('Completed','Canceled','Partial')) AS active_orders"
)->fetch();

$provider = db()->query('SELECT * FROM providers WHERE is_default=1 LIMIT 1')->fetch();

$recentOrders = db()->query('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 15')->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>لوحة الإدارة — fastcrand</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <h2>لوحة الإدارة</h2>
    <a class="btn secondary" href="../dashboard.php">رجوع للوحتي</a>
  </div>

  <?php if ($flash): ?><div class="card ok"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h3>إحصائيات سريعة</h3>
    <div class="row">
      <div>👥 المستخدمون: <strong><?= (int)$stats['users_count'] ?></strong></div>
      <div>📦 الطلبات: <strong><?= (int)$stats['orders_count'] ?></strong></div>
      <div>⏳ نشطة: <strong><?= (int)$stats['active_orders'] ?></strong></div>
      <div>💰 الإيراد: <strong>$<?= number_format((float)$stats['revenue'], 2) ?></strong></div>
    </div>
  </div>

  <div class="card">
    <h3>إعدادات مزوّد SMM الافتراضي</h3>
    <form method="post">
      <label>رابط API</label>
      <input type="text" name="api_url" value="<?= htmlspecialchars($provider['api_url'] ?? '') ?>">
      <label>مفتاح API</label>
      <input type="text" name="api_key" value="<?= htmlspecialchars($provider['api_key'] ?? '') ?>">
      <button type="submit" name="save_provider" value="1">حفظ</button>
    </form>
  </div>

  <div class="card">
    <h3>آخر الطلبات</h3>
    <table>
      <tr><th>#</th><th>المستخدم</th><th>الخدمة</th><th>السعر</th><th>الحالة</th></tr>
      <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td>#<?= htmlspecialchars($o['display_id'] ?: $o['id']) ?></td>
          <td><?= htmlspecialchars($o['email']) ?></td>
          <td><?= htmlspecialchars($o['service_name']) ?></td>
          <td>$<?= number_format((float)$o['site_charge'], 2) ?></td>
          <td><span class="badge"><?= htmlspecialchars($o['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
