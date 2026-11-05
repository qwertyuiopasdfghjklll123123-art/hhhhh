<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $text = trim($_POST['text'] ?? '');
        $type = $_POST['type'] ?? 'info';
        if ($text) {
            $id = 'ann_' . time() . '_' . bin2hex(random_bytes(2));
            db()->prepare('INSERT INTO announcements (id, text, type, created_at) VALUES (?,?,?,?)')
                ->execute([$id, $text, $type, time()]);
        }
    } elseif (isset($_POST['delete'])) {
        db()->prepare('DELETE FROM announcements WHERE id=?')->execute([$_POST['id']]);
    }
    header('Location: announcements.php');
    exit;
}

$announcements = db()->query('SELECT * FROM announcements ORDER BY created_at DESC')->fetchAll();
$typeLabels = ['info' => 'ℹ️ معلومة', 'offer' => '🎉 عرض', 'warning' => '⚠️ تنبيه', 'update' => '🆕 تحديث'];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>الإعلانات — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>الإعلانات</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>
  <div class="card">
    <h3>إعلان جديد</h3>
    <form method="post">
      <label>النص</label>
      <textarea name="text" rows="2" required></textarea>
      <label>النوع</label>
      <select name="type">
        <?php foreach ($typeLabels as $k => $label): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" name="add" value="1">نشر</button>
    </form>
  </div>
  <div class="card">
    <?php foreach ($announcements as $a): ?>
      <div class="svc-item">
        <span><?= htmlspecialchars($typeLabels[$a['type']] ?? $a['type']) ?> — <?= htmlspecialchars($a['text']) ?>
          <span class="muted"><?= date('Y-m-d H:i', (int)$a['created_at']) ?></span>
        </span>
        <form method="post" style="display:inline">
          <input type="hidden" name="id" value="<?= htmlspecialchars($a['id']) ?>">
          <button type="submit" name="delete" value="1" class="secondary">حذف</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (empty($announcements)): ?><p class="muted">لا توجد إعلانات.</p><?php endif; ?>
  </div>
</div>
</body>
</html>
