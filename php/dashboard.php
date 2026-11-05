<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/smm.php';
require_login();

$user = current_user();
$provider = get_default_provider();
$catalog = $provider ? build_services_catalog($provider) : [];

$orders = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$orders->execute([$user['id']]);
$myOrders = $orders->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>لوحتي — fastcrand</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <div>
      <h2>أهلاً، <?= htmlspecialchars($user['name']) ?></h2>
      <div class="muted">الرصيد: <strong><?= number_format((float)$user['balance'], 2) ?>$</strong></div>
    </div>
    <div>
      <?php if ($user['is_admin']): ?><a class="btn secondary" href="admin/index.php">لوحة الإدارة</a><?php endif; ?>
      <a class="btn secondary" href="logout.php">خروج</a>
    </div>
  </div>

  <?php if ($flash): ?><div class="card <?= $flash['ok'] ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <h3>طلب خدمة جديدة</h3>
    <?php if (!$provider): ?>
      <p class="error">لا يوجد مزوّد SMM فعّال حالياً — فعّله من لوحة الإدارة.</p>
    <?php elseif (empty($catalog)): ?>
      <p class="muted">تعذّر جلب الخدمات من المزوّد حالياً (أو لا توجد خدمات). حاول تحديث الصفحة بعد قليل.</p>
    <?php else: ?>
      <form method="post" action="api/order.php">
        <label>الخدمة</label>
        <select name="service" required>
          <?php foreach ($catalog as $category => $items): ?>
            <optgroup label="<?= htmlspecialchars($category) ?>">
              <?php foreach ($items as $it): ?>
                <option value="<?= htmlspecialchars($it['id']) ?>" data-name="<?= htmlspecialchars($it['name']) ?>">
                  <?= htmlspecialchars($it['name']) ?> — $<?= number_format($it['rate'], 4) ?> / 1000
                  (<?= $it['min'] ?>-<?= $it['max'] ?>)
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <label>الرابط</label>
        <input type="url" name="link" required placeholder="https://...">
        <label>الكمية</label>
        <input type="number" name="quantity" required min="1">
        <button type="submit">إرسال الطلب</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>آخر طلباتي</h3>
    <?php if (empty($myOrders)): ?>
      <p class="muted">لا توجد طلبات بعد.</p>
    <?php else: ?>
      <table>
        <tr><th>#</th><th>الخدمة</th><th>الكمية</th><th>السعر</th><th>الحالة</th></tr>
        <?php foreach ($myOrders as $o): ?>
          <tr>
            <td>#<?= htmlspecialchars($o['display_id'] ?: $o['id']) ?></td>
            <td><?= htmlspecialchars($o['service_name']) ?></td>
            <td><?= (int)$o['quantity'] ?></td>
            <td>$<?= number_format((float)$o['site_charge'], 2) ?></td>
            <td><span class="badge"><?= htmlspecialchars($o['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
