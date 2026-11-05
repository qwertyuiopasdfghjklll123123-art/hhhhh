<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/smm.php';
require_login();

$user = current_user();
$catalog = build_services_catalog();

$orders = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10');
$orders->execute([$user['id']]);
$myOrders = $orders->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$unreadCount = db()->prepare('SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0');
$unreadCount->execute([$user['id']]);
$unread = (int)$unreadCount->fetch()['c'];

$dailyBonusEnabled = get_setting('daily_bonus_enabled', '0') === '1';
$dailyBonusAmount = (float)(get_setting('daily_bonus_amount', '0.5') ?: 0.5);
$lastBonus = (float)($user['last_daily_bonus'] ?? 0);
$canClaimBonus = $dailyBonusEnabled && (time() - $lastBonus >= 86400);

$level = get_user_level($user['id']);
$announcements = db()->query('SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5')->fetchAll();
$annTypeLabels = ['info' => 'ℹ️', 'offer' => '🎉', 'warning' => '⚠️', 'update' => '🆕'];
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
      <div class="muted">الرصيد: <strong><?= number_format((float)$user['balance'], 2) ?>$</strong>
        <?php if ($level): ?> — المستوى: <strong style="color:<?= htmlspecialchars($level['color']) ?>"><?= htmlspecialchars($level['name']) ?></strong> (خصم <?= (float)$level['discount'] ?>%)<?php endif; ?>
      </div>
    </div>
    <div>
      <a class="btn secondary" href="notifications.php">🔔 الإشعارات<?= $unread ? " ($unread)" : '' ?></a>
      <a class="btn secondary" href="wallet.php">💳 المحفظة</a>
      <a class="btn secondary" href="tickets.php">🎫 الدعم</a>
      <a class="btn secondary" href="ai.php">🤖 المساعد الذكي</a>
      <a class="btn secondary" href="favorites.php">⭐ المفضّلة</a>
      <a class="btn secondary" href="referral.php">🔗 الإحالة</a>
      <a class="btn secondary" href="reviews.php">⭐ التقييمات</a>
      <?php if ($user['is_admin']): ?><a class="btn secondary" href="admin/index.php">لوحة الإدارة</a><?php endif; ?>
      <a class="btn secondary" href="logout.php">خروج</a>
    </div>
  </div>

  <?php if ($flash): ?><div class="card <?= $flash['ok'] ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

  <?php foreach ($announcements as $a): ?>
    <div class="card" style="background:#1e3a5f">
      <?= htmlspecialchars($annTypeLabels[$a['type']] ?? '📢') ?> <?= htmlspecialchars($a['text']) ?>
    </div>
  <?php endforeach; ?>

  <?php if ($dailyBonusEnabled): ?>
  <div class="card row">
    <div>🎁 المكافأة اليومية: $<?= number_format($dailyBonusAmount, 2) ?></div>
    <?php if ($canClaimBonus): ?>
      <form method="post" action="api/daily_bonus_claim.php"><button type="submit">استلم الآن</button></form>
    <?php else: ?>
      <span class="muted">استلمتها اليوم، عد بعد 24 ساعة</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>طلب خدمة جديدة</h3>
    <?php if (empty($catalog)): ?>
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
