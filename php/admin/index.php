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
        (SELECT COUNT(*) FROM tickets WHERE status != 'closed') AS open_tickets,
        (SELECT COUNT(*) FROM reviews WHERE approved=0) AS pending_reviews"
)->fetch();

$recentOrders = db()->query('SELECT o.*, u.email FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id DESC LIMIT 15')->fetchAll();

// كل قسم = اسم + رابط (path) ثابت، معروض بوضوح تحت كل بطاقة — هذا هو الدليل/الفهرس الكامل لكل أقسام لوحة الإدارة
$sections = [
    'المستخدمون والطلبات' => [
        ['name' => '👥 المستخدمون', 'path' => 'users.php'],
        ['name' => '📦 الطلبات', 'path' => 'orders.php'],
    ],
    'المزوّدون والخدمات' => [
        ['name' => '🔌 مزودو SMM', 'path' => 'providers.php'],
        ['name' => '🧩 فلاتر الخدمات', 'path' => 'filters.php'],
    ],
    'المحفظة والمدفوعات' => [
        ['name' => '💳 طرق الدفع', 'path' => 'payment_methods.php'],
        ['name' => '💵 طلبات الشحن', 'path' => 'recharges.php'],
        ['name' => '🎟️ الكوبونات', 'path' => 'coupons.php'],
        ['name' => '📊 الأرباح', 'path' => 'profits.php'],
    ],
    'التفاعل والمحتوى' => [
        ['name' => '⭐ التقييمات', 'path' => 'reviews.php'],
        ['name' => '📢 الإعلانات', 'path' => 'announcements.php'],
        ['name' => '🔗 برنامج الإحالة', 'path' => 'referrals.php'],
        ['name' => '🎫 التذاكر', 'path' => 'tickets.php'],
    ],
    'النظام والإعدادات' => [
        ['name' => '🏆 المستويات', 'path' => 'levels.php'],
        ['name' => '🤖 المساعد الذكي', 'path' => 'ai_settings.php'],
        ['name' => '⚙️ إعدادات الموقع', 'path' => 'settings.php'],
    ],
];
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
    <div class="row" style="flex-wrap:wrap">
      <div>👥 المستخدمون: <strong><?= (int)$stats['users_count'] ?></strong></div>
      <div>📦 الطلبات: <strong><?= (int)$stats['orders_count'] ?></strong></div>
      <div>⏳ نشطة: <strong><?= (int)$stats['active_orders'] ?></strong></div>
      <div>💰 الإيراد: <strong>$<?= number_format((float)$stats['revenue'], 2) ?></strong></div>
      <div>💳 شحن بانتظار: <strong><?= (int)$stats['pending_recharges'] ?></strong></div>
      <div>🎫 تذاكر مفتوحة: <strong><?= (int)$stats['open_tickets'] ?></strong></div>
      <div>⭐ تقييمات بانتظار: <strong><?= (int)$stats['pending_reviews'] ?></strong></div>
    </div>
  </div>

  <?php foreach ($sections as $groupTitle => $items): ?>
  <div class="card">
    <p class="admin-group-title"><?= htmlspecialchars($groupTitle) ?></p>
    <div class="admin-nav-grid">
      <?php foreach ($items as $it): ?>
        <a class="admin-nav-item" href="<?= htmlspecialchars($it['path']) ?>">
          <span class="name"><?= htmlspecialchars($it['name']) ?></span>
          <span class="path">/admin/<?= htmlspecialchars($it['path']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

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
