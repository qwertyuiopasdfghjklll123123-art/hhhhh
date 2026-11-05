<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stars = max(1, min(5, (int)($_POST['stars'] ?? 5)));
    $text = trim($_POST['text'] ?? '');
    if ($text) {
        db()->prepare('INSERT INTO reviews (user_id, user_name, stars, text, approved, created_at) VALUES (?,?,?,?,0,?)')
            ->execute([$user['id'], $user['name'], $stars, $text, time()]);
        $_SESSION['flash'] = ['ok' => true, 'msg' => 'تم إرسال تقييمك، سيظهر بعد مراجعة الإدارة'];
    }
    header('Location: reviews.php');
    exit;
}

$approved = db()->query('SELECT * FROM reviews WHERE approved=1 ORDER BY id DESC LIMIT 20')->fetchAll();
$stats = db()->query('SELECT COUNT(*) AS cnt, AVG(stars) AS avg_stars FROM reviews WHERE approved=1')->fetch();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>التقييمات — fastcrand</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>التقييمات</h2>
    <a class="btn secondary" href="dashboard.php">رجوع</a>
  </div>
  <?php if ($flash): ?><div class="card ok"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
  <div class="card">
    <p>عدد التقييمات: <strong><?= (int)$stats['cnt'] ?></strong> — المتوسط: <strong><?= $stats['avg_stars'] ? number_format($stats['avg_stars'], 1) : '—' ?> / 5</strong></p>
  </div>
  <div class="card">
    <h3>أضف تقييمك</h3>
    <form method="post">
      <label>التقييم (1-5)</label>
      <select name="stars">
        <option value="5">5 ⭐⭐⭐⭐⭐</option>
        <option value="4">4 ⭐⭐⭐⭐</option>
        <option value="3">3 ⭐⭐⭐</option>
        <option value="2">2 ⭐⭐</option>
        <option value="1">1 ⭐</option>
      </select>
      <label>رأيك</label>
      <textarea name="text" rows="3" required></textarea>
      <button type="submit">إرسال</button>
    </form>
  </div>
  <?php foreach ($approved as $r): ?>
    <div class="card">
      <strong><?= htmlspecialchars($r['user_name']) ?></strong> — <?= str_repeat('⭐', (int)$r['stars']) ?>
      <p><?= htmlspecialchars($r['text']) ?></p>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
