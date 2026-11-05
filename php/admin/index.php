<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

$stats = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM users) AS users_count,
        (SELECT COUNT(*) FROM orders) AS orders_count,
        (SELECT COALESCE(SUM(site_charge),0) FROM orders) AS revenue,
        (SELECT COUNT(*) FROM orders WHERE status NOT IN ('Completed','Canceled','Partial')) AS active_orders,
        (SELECT COUNT(*) FROM recharge_requests WHERE status='pending') AS pending_recharges,
        (SELECT COUNT(*) FROM tickets WHERE status != 'closed') AS open_tickets"
)->fetch();

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

  <div class="card">
    <h3>إحصائيات سريعة</h3>
    <div class="row">
      <div>👥 المستخدمون: <strong><?= (int)$stats['users_count'] ?></strong></div>
      <div>📦 الطلبات: <strong><?= (int)$stats['orders_count'] ?></strong></div>
      <div>⏳ نشطة: <strong><?= (int)$stats['active_orders'] ?></strong></div>
      <div>💰 الإيراد: <strong>$<?= number_format((float)$stats['revenue'], 2) ?></strong></div>
      <div>💳 شحن بانتظار الموافقة: <strong><?= (int)$stats['pending_recharges'] ?></strong></div>
      <div>🎫 تذاكر مفتوحة: <strong><?= (int)$stats['open_tickets'] ?></strong></div>
    </div>
  </div>

  <div class="card">
    <h3>الإدارة</h3>
    <div class="row" style="flex-wrap:wrap; justify-content:flex-start; gap:10px">
      <a class="btn secondary" href="users.php">👥 المستخدمون</a>
      <a class="btn secondary" href="orders.php">📦 الطلبات</a>
      <a class="btn secondary" href="providers.php">🔌 مزودو SMM</a>
      <a class="btn secondary" href="filters.php">🧩 فلاتر الخدمات</a>
      <a class="btn secondary" href="payment_methods.php">💳 طرق الدفع</a>
      <a class="btn secondary" href="recharges.php">💵 طلبات الشحن</a>
      <a class="btn secondary" href="coupons.php">🎟️ الكوبونات</a>
      <a class="btn secondary" href="levels.php">🏆 المستويات</a>
      <a class="btn secondary" href="tickets.php">🎫 التذاكر</a>
      <a class="btn secondary" href="ai_settings.php">🤖 المساعد الذكي</a>
      <a class="btn secondary" href="settings.php">⚙️ إعدادات الموقع</a>
    </div>
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
