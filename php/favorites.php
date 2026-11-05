<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/smm.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle'])) {
    $serviceId = trim($_POST['service_id'] ?? '');
    $stmt = db()->prepare('SELECT id FROM favorites WHERE user_id=? AND service_id=?');
    $stmt->execute([$user['id'], $serviceId]);
    $existing = $stmt->fetch();
    if ($existing) {
        db()->prepare('DELETE FROM favorites WHERE id=?')->execute([$existing['id']]);
    } else {
        $service = find_service($serviceId);
        if ($service) {
            try {
                db()->prepare(
                    'INSERT INTO favorites (user_id, service_id, service_name, platform, category, rate, min_qty, max_qty, created_at) VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $user['id'], $serviceId, mb_substr($service['name'] ?? '', 0, 200),
                    '', mb_substr($service['category'] ?? '', 0, 100), (string)($service['rate'] ?? '0'),
                    (int)($service['min'] ?? 0), (int)($service['max'] ?? 0), time(),
                ]);
            } catch (PDOException $e) { /* موجودة مسبقاً */ }
        }
    }
    header('Location: favorites.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM favorites WHERE user_id=? ORDER BY created_at DESC');
$stmt->execute([$user['id']]);
$favorites = $stmt->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>المفضّلة — fastcrand</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>⭐ المفضّلة</h2>
    <a class="btn secondary" href="dashboard.php">رجوع</a>
  </div>
  <div class="card">
    <h3>إضافة خدمة للمفضّلة</h3>
    <form method="post">
      <label>رقم الخدمة (من صفحة الطلب)</label>
      <input type="text" name="service_id" required>
      <button type="submit" name="toggle" value="1">إضافة/إزالة</button>
    </form>
  </div>
  <div class="card">
    <?php if (empty($favorites)): ?>
      <p class="muted">لا توجد خدمات مفضّلة بعد. أضفها من لوحتك الرئيسية.</p>
    <?php else: foreach ($favorites as $f): ?>
      <div class="svc-item">
        <span><?= htmlspecialchars($f['service_name']) ?> <span class="muted">(<?= htmlspecialchars($f['category']) ?>)</span></span>
        <form method="post" style="display:inline">
          <input type="hidden" name="service_id" value="<?= htmlspecialchars($f['service_id']) ?>">
          <button type="submit" name="toggle" value="1" class="secondary">إزالة</button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
</body>
</html>
