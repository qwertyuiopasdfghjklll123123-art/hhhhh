<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

define('MARKUP_RATE', 1.5);

$row = db()->query('SELECT COUNT(*) AS cnt, COALESCE(SUM(CAST(charge AS DECIMAL(14,4))),0) AS rev FROM orders')->fetch();
$totalOrders = (int)$row['cnt'];
$totalRevenue = (float)$row['rev'];
$totalProvider = $totalRevenue > 0 ? round($totalRevenue / MARKUP_RATE, 4) : 0;
$profit = round($totalRevenue - $totalProvider, 2);
$margin = $totalRevenue > 0 ? round(($profit / $totalRevenue) * 100, 1) : 0;
$avgProfit = $totalOrders > 0 ? round($profit / $totalOrders, 2) : 0;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>الأرباح — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>الأرباح</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>
  <div class="card">
    <p>عدد الطلبات: <strong><?= $totalOrders ?></strong></p>
    <p>إجمالي الإيراد (من المزوّد): <strong>$<?= number_format($totalRevenue, 2) ?></strong></p>
    <p>تكلفة المزوّد التقديرية: <strong>$<?= number_format($totalProvider, 2) ?></strong></p>
    <p>الربح: <strong style="color:#4ade80">$<?= number_format($profit, 2) ?></strong></p>
    <p>نسبة الربح: <strong><?= $margin ?>%</strong></p>
    <p>متوسط الربح لكل طلب: <strong>$<?= number_format($avgProfit, 2) ?></strong></p>
    <p class="muted">ملاحظة: هذا يعتمد على عمود charge (تكلفة المزوّد الفعلية)، لا على site_charge (سعر البيع). الربح الحقيقي الأدق = site_charge - charge لكل طلب.</p>
  </div>
</div>
</body>
</html>
