<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read'])) {
    db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$user['id']]);
    header('Location: notifications');
    exit;
}

$stmt = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$user['id']]);
$notifs = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'الإشعارات — fastcrand'; $assetBase = ''; include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>الإشعارات</h2>
    <a class="btn secondary" href="dashboard">رجوع</a>
  </div>
  <?php if ($notifs): ?>
  <form method="post" class="card">
    <button type="submit" name="mark_read" value="1">تعليم الكل كمقروء</button>
  </form>
  <?php endif; ?>
  <?php if (empty($notifs)): ?>
    <div class="card muted">لا توجد إشعارات.</div>
  <?php else: foreach ($notifs as $n): ?>
    <div class="card" style="<?= $n['is_read'] ? 'opacity:.6' : '' ?>">
      <div class="row">
        <strong><?= htmlspecialchars($n['title']) ?></strong>
        <span class="muted"><?= date('Y-m-d H:i', (int)$n['created_at']) ?></span>
      </div>
      <div><?= htmlspecialchars($n['text']) ?></div>
    </div>
  <?php endforeach; endif; ?>
</div>
</body>
</html>
