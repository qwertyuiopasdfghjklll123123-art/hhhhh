<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

$methods = db()->query("SELECT * FROM payment_methods WHERE active=1 ORDER BY sort_order ASC")->fetchAll();
$history = db()->prepare('SELECT * FROM recharge_requests WHERE user_id=? ORDER BY id DESC LIMIT 20');
$history->execute([$user['id']]);
$myHistory = $history->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$binance = null;
$asiacell = null;
$tgStars = null;
$manualMethods = [];
foreach ($methods as $m) {
    if ($m['method_type'] === 'binance') $binance = $m;
    elseif ($m['method_type'] === 'asiacell') $asiacell = $m;
    elseif ($m['method_type'] === 'telegram_stars') $tgStars = $m;
    else $manualMethods[] = $m;
}
$statusLabels = ['pending' => 'قيد المراجعة', 'approved' => 'مقبول', 'rejected' => 'مرفوض'];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>المحفظة — fastcrand</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <h2>المحفظة</h2>
    <a class="btn secondary" href="dashboard.php">رجوع</a>
  </div>
  <div class="card">الرصيد الحالي: <strong>$<?= number_format((float)$user['balance'], 2) ?></strong></div>

  <?php if ($flash): ?><div class="card <?= $flash['ok'] ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

  <?php if ($binance): ?>
  <div class="card">
    <h3>💳 Binance Pay (تحقق فوري)</h3>
    <p class="muted">حوّل المبلغ بالدولار إلى Binance Pay ID: <strong><?= htmlspecialchars($binance['number']) ?></strong>، ثم أدخل رقم العملية (Order ID) أدناه ليُضاف رصيدك تلقائياً.</p>
    <form method="post" action="api/recharge_binance_verify.php">
      <label>المبلغ (USD)</label>
      <input type="number" step="0.01" name="amount" required min="0.01">
      <label>رقم عملية Binance (Order ID)</label>
      <input type="text" name="order_id" required>
      <button type="submit">تحقق وأضف الرصيد</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($manualMethods): ?>
  <div class="card">
    <h3>💵 شحن يدوي</h3>
    <form method="post" action="api/recharge_submit.php" enctype="multipart/form-data">
      <label>طريقة الدفع</label>
      <select name="method" required>
        <?php foreach ($manualMethods as $m): ?>
          <option value="<?= htmlspecialchars($m['name']) ?>">
            <?= htmlspecialchars($m['name']) ?> — <?= htmlspecialchars($m['number']) ?> (<?= htmlspecialchars($m['currency']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <label>المبلغ (USD)</label>
      <input type="number" step="0.01" name="amount" required min="0.01">
      <label>صورة إثبات الدفع</label>
      <input type="file" name="receipt" accept="image/*" required>
      <button type="submit">إرسال طلب الشحن</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!$binance && !$manualMethods && !$tgStars): ?>
    <div class="card muted">لا توجد طرق دفع فعّالة حالياً — فعّلها من لوحة الإدارة.</div>
  <?php endif; ?>

  <div class="card">
    <h3>🎟️ كوبون هدية</h3>
    <form method="post" action="api/coupon_redeem.php">
      <label>كود الكوبون</label>
      <input type="text" name="code" required>
      <button type="submit">استبدال</button>
    </form>
  </div>

  <div class="card">
    <h3>سجل عمليات الشحن</h3>
    <?php if (empty($myHistory)): ?>
      <p class="muted">لا توجد عمليات شحن بعد.</p>
    <?php else: ?>
      <table>
        <tr><th>المبلغ</th><th>الطريقة</th><th>الحالة</th><th>التاريخ</th></tr>
        <?php foreach ($myHistory as $h): ?>
          <tr>
            <td>$<?= number_format((float)$h['amount'], 2) ?></td>
            <td><?= htmlspecialchars($h['method']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($statusLabels[$h['status']] ?? $h['status']) ?></span></td>
            <td><?= date('Y-m-d H:i', (int)$h['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
