<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

$totals = db()->query(
    'SELECT COALESCE(SUM(ref_count),0) AS total_referrals, COALESCE(SUM(earnings),0) AS total_earnings,
     COALESCE(SUM(pending),0) AS total_pending, COUNT(*) AS total_users FROM referrals'
)->fetch();

$top = db()->query(
    "SELECT r.*, u.name AS user_name, u.email AS user_email FROM referrals r
     LEFT JOIN users u ON u.id = r.user_id WHERE r.ref_count > 0 ORDER BY r.ref_count DESC LIMIT 10"
)->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'برنامج الإحالة — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>برنامج الإحالة</h2>
    <a class="btn secondary" href="index">رجوع</a>
  </div>
  <div class="card">
    <p>نسبة العمولة: <strong><?= REFERRAL_COMMISSION ?>%</strong> — الحد الأدنى للسحب: <strong>$<?= REFERRAL_MIN_WITHDRAW ?></strong></p>
    <p>عدد المستخدمين المُحالين إجمالاً: <strong><?= (int)$totals['total_referrals'] ?></strong></p>
    <p>إجمالي الأرباح المسحوبة: <strong>$<?= number_format((float)$totals['total_earnings'], 2) ?></strong></p>
    <p>إجمالي قيد السحب: <strong>$<?= number_format((float)$totals['total_pending'], 2) ?></strong></p>
  </div>
  <div class="card">
    <h3>الأعلى إحالة</h3>
    <table>
      <tr><th>المستخدم</th><th>الكود</th><th>عدد المُحالين</th><th>الأرباح</th><th>قيد السحب</th></tr>
      <?php foreach ($top as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['user_name']) ?><br><span class="muted"><?= htmlspecialchars($t['user_email']) ?></span></td>
          <td><?= htmlspecialchars($t['code']) ?></td>
          <td><?= (int)$t['ref_count'] ?></td>
          <td>$<?= number_format((float)$t['earnings'], 2) ?></td>
          <td>$<?= number_format((float)$t['pending'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (empty($top)): ?><p class="muted">لا يوجد أي إحالات بعد.</p><?php endif; ?>
  </div>
</div>
</body>
</html>
