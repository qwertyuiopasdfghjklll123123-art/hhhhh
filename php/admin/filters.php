<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/smm.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = trim($_POST['service_id'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($serviceId) {
        $existing = db()->prepare("SELECT * FROM service_filters WHERE filter_type='service' AND filter_key=?");
        $existing->execute([$serviceId]);
        $row = $existing->fetch();
        if ($action === 'hide' || $action === 'show') {
            $hidden = $action === 'hide' ? 1 : 0;
            if ($row) {
                db()->prepare("UPDATE service_filters SET hidden=? WHERE filter_type='service' AND filter_key=?")->execute([$hidden, $serviceId]);
            } else {
                db()->prepare("INSERT INTO service_filters (filter_type, filter_key, hidden) VALUES ('service', ?, ?)")->execute([$serviceId, $hidden]);
            }
        } elseif ($action === 'set_profit') {
            $profit = ($_POST['profit_pct'] === '') ? null : (float)$_POST['profit_pct'];
            if ($row) {
                db()->prepare("UPDATE service_filters SET profit_pct=? WHERE filter_type='service' AND filter_key=?")->execute([$profit, $serviceId]);
            } else {
                db()->prepare("INSERT INTO service_filters (filter_type, filter_key, profit_pct) VALUES ('service', ?, ?)")->execute([$serviceId, $profit]);
            }
        }
        invalidate_services_cache();
    }
    header('Location: filters');
    exit;
}

$filters = db()->query("SELECT * FROM service_filters WHERE filter_type='service' ORDER BY id DESC LIMIT 200")->fetchAll();
$defaultProfit = get_setting('default_profit_pct', '20');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'فلاتر الخدمات — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>فلاتر الخدمات</h2>
    <a class="btn secondary" href="index">رجوع</a>
  </div>
  <div class="card">
    <p class="muted">نسبة الربح الافتراضية الحالية لأي خدمة بدون نسبة مخصّصة: <strong><?= htmlspecialchars($defaultProfit) ?>%</strong> (عدّلها من إعدادات الموقع)</p>
    <h3>إخفاء/تعديل ربح خدمة معيّنة</h3>
    <form method="post">
      <label>رقم الخدمة (service id)</label>
      <input type="text" name="service_id" required>
      <label>نسبة الربح % (اتركه فاضي لاستخدام الافتراضي)</label>
      <input type="number" step="0.1" name="profit_pct">
      <button type="submit" name="action" value="set_profit">حفظ نسبة الربح</button>
      <button type="submit" name="action" value="hide" class="secondary">إخفاء من المتجر</button>
      <button type="submit" name="action" value="show" class="secondary">إظهار بالمتجر</button>
    </form>
  </div>
  <div class="card">
    <h3>الفلاتر الحالية</h3>
    <table>
      <tr><th>رقم الخدمة</th><th>مخفية؟</th><th>نسبة الربح</th></tr>
      <?php foreach ($filters as $f): ?>
        <tr>
          <td><?= htmlspecialchars($f['filter_key']) ?></td>
          <td><?= $f['hidden'] ? 'نعم' : 'لا' ?></td>
          <td><?= $f['profit_pct'] !== null ? htmlspecialchars($f['profit_pct']) . '%' : '(افتراضي)' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
