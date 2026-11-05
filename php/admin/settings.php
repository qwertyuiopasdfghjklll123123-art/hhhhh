<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

$allowed = ['site_name', 'support_whatsapp', 'default_profit_pct', 'daily_bonus_enabled', 'daily_bonus_amount', 'auto_import_enabled', 'auto_import_profit_pct'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($allowed as $k) {
        if (isset($_POST[$k])) set_setting($k, trim($_POST[$k]));
    }
    set_setting('daily_bonus_enabled', isset($_POST['daily_bonus_enabled']) ? '1' : '0');
    set_setting('auto_import_enabled', isset($_POST['auto_import_enabled']) ? '1' : '0');
    $_SESSION['flash'] = ['ok' => true, 'msg' => 'تم الحفظ'];
    header('Location: settings.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$vals = [];
foreach ($allowed as $k) $vals[$k] = get_setting($k, '');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>إعدادات الموقع — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>إعدادات الموقع</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>
  <?php if ($flash): ?><div class="card ok"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
  <div class="card">
    <form method="post">
      <label>اسم الموقع</label>
      <input type="text" name="site_name" value="<?= htmlspecialchars($vals['site_name'] ?: 'fastcrand') ?>">
      <label>واتساب الدعم</label>
      <input type="text" name="support_whatsapp" value="<?= htmlspecialchars($vals['support_whatsapp']) ?>">
      <label>نسبة الربح الافتراضية % (لأي خدمة بدون نسبة مخصّصة)</label>
      <input type="number" step="0.1" name="default_profit_pct" value="<?= htmlspecialchars($vals['default_profit_pct'] ?: '20') ?>">
      <label><input type="checkbox" name="daily_bonus_enabled" <?= $vals['daily_bonus_enabled'] === '1' ? 'checked' : '' ?> style="width:auto;display:inline"> تفعيل المكافأة اليومية</label>
      <label>مبلغ المكافأة اليومية ($)</label>
      <input type="number" step="0.01" name="daily_bonus_amount" value="<?= htmlspecialchars($vals['daily_bonus_amount'] ?: '0.5') ?>">
      <label><input type="checkbox" name="auto_import_enabled" <?= $vals['auto_import_enabled'] === '1' ? 'checked' : '' ?> style="width:auto;display:inline"> تفعيل استيراد الخدمات الجديدة تلقائياً</label>
      <label>نسبة ربح الخدمات المستوردة تلقائياً %</label>
      <input type="number" step="0.1" name="auto_import_profit_pct" value="<?= htmlspecialchars($vals['auto_import_profit_pct'] ?: '20') ?>">
      <button type="submit">حفظ</button>
    </form>
  </div>
</div>
</body>
</html>
