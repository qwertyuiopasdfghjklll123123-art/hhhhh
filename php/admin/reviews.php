<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        if (isset($_POST['approve'])) {
            db()->prepare('UPDATE reviews SET approved=1 WHERE id=?')->execute([$id]);
        } elseif (isset($_POST['hide'])) {
            db()->prepare('UPDATE reviews SET approved=0 WHERE id=?')->execute([$id]);
        } elseif (isset($_POST['delete'])) {
            db()->prepare('DELETE FROM reviews WHERE id=?')->execute([$id]);
        }
    }
    header('Location: reviews.php');
    exit;
}

$reviews = db()->query('SELECT * FROM reviews ORDER BY id DESC')->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>التقييمات — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>التقييمات</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>
  <?php foreach ($reviews as $r): ?>
    <div class="card">
      <div class="row">
        <strong><?= htmlspecialchars($r['user_name']) ?></strong>
        <span class="badge"><?= $r['approved'] ? 'منشور' : 'قيد المراجعة' ?></span>
      </div>
      <div><?= str_repeat('⭐', (int)$r['stars']) ?></div>
      <p><?= htmlspecialchars($r['text']) ?></p>
      <form method="post" style="display:flex;gap:6px">
        <input type="hidden" name="id" value="<?= $r['id'] ?>">
        <?php if (!$r['approved']): ?>
          <button type="submit" name="approve" value="1">نشر</button>
        <?php else: ?>
          <button type="submit" name="hide" value="1" class="secondary">إخفاء</button>
        <?php endif; ?>
        <button type="submit" name="delete" value="1" class="secondary" onclick="return confirm('حذف هذا التقييم؟')">حذف</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (empty($reviews)): ?><div class="card muted">لا توجد تقييمات.</div><?php endif; ?>
</div>
</body>
</html>
