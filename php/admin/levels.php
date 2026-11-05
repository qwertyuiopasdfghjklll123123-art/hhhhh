<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        db()->prepare('UPDATE levels SET min_spent=?, discount=?, active=? WHERE id=?')
            ->execute([(float)($_POST['min_spent'] ?? 0), (float)($_POST['discount'] ?? 0), isset($_POST['active']) ? 1 : 0, $id]);
    }
    header('Location: levels');
    exit;
}

$levels = db()->query('SELECT * FROM levels ORDER BY sort_order ASC')->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'المستويات — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>مستويات الخصم</h2>
    <a class="btn secondary" href="index">رجوع</a>
  </div>
  <?php foreach ($levels as $lv): ?>
    <form method="post" class="card row">
      <input type="hidden" name="id" value="<?= $lv['id'] ?>">
      <div style="color:<?= htmlspecialchars($lv['color']) ?>"><strong><?= htmlspecialchars($lv['name']) ?></strong></div>
      <label>الحد الأدنى للصرف ($)<input type="number" step="0.01" name="min_spent" value="<?= htmlspecialchars($lv['min_spent']) ?>"></label>
      <label>الخصم (%)<input type="number" step="0.1" name="discount" value="<?= htmlspecialchars($lv['discount']) ?>"></label>
      <label><input type="checkbox" name="active" <?= $lv['active'] ? 'checked' : '' ?> style="width:auto;display:inline"> فعّال</label>
      <button type="submit">حفظ</button>
    </form>
  <?php endforeach; ?>
</div>
</body>
</html>
