<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $maxUses = max(1, (int)($_POST['max_uses'] ?? 1));
        $expiresAt = trim($_POST['expires_at'] ?? '');
        if ($code && $amount > 0) {
            $id = 'cp_' . bin2hex(random_bytes(5));
            db()->prepare('INSERT INTO coupons (id, code, amount, max_uses, expires_at, active, created_at) VALUES (?,?,?,?,?,1,?)')
                ->execute([$id, $code, $amount, $maxUses, $expiresAt, time()]);
        }
    } elseif (isset($_POST['delete'])) {
        db()->prepare('DELETE FROM coupons WHERE id=?')->execute([$_POST['id']]);
    } elseif (isset($_POST['toggle'])) {
        $stmt = db()->prepare('SELECT active FROM coupons WHERE id=?');
        $stmt->execute([$_POST['id']]);
        $active = (int)($stmt->fetch()['active'] ?? 0);
        db()->prepare('UPDATE coupons SET active=? WHERE id=?')->execute([$active ? 0 : 1, $_POST['id']]);
    }
    header('Location: coupons.php');
    exit;
}

$coupons = db()->query('SELECT * FROM coupons ORDER BY created_at DESC')->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>الكوبونات — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>الكوبونات</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>
  <div class="card">
    <h3>إنشاء كوبون جديد</h3>
    <form method="post">
      <label>الكود</label>
      <input type="text" name="code" required style="text-transform:uppercase">
      <label>المبلغ ($)</label>
      <input type="number" step="0.01" name="amount" required min="0.01">
      <label>أقصى عدد استخدام</label>
      <input type="number" name="max_uses" value="1" min="1">
      <label>تاريخ انتهاء (اختياري، YYYY-MM-DD)</label>
      <input type="text" name="expires_at" placeholder="2026-12-31">
      <button type="submit" name="create" value="1">إنشاء</button>
    </form>
  </div>
  <div class="card">
    <table>
      <tr><th>الكود</th><th>المبلغ</th><th>الاستخدام</th><th>الحالة</th><th></th></tr>
      <?php foreach ($coupons as $c): ?>
        <tr>
          <td><?= htmlspecialchars($c['code']) ?></td>
          <td>$<?= number_format((float)$c['amount'], 2) ?></td>
          <td><?= (int)$c['used'] ?> / <?= (int)$c['max_uses'] ?></td>
          <td><span class="badge"><?= $c['active'] ? 'فعّال' : 'معطّل' ?></span></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
              <button type="submit" name="toggle" value="1" class="secondary"><?= $c['active'] ? 'تعطيل' : 'تفعيل' ?></button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="id" value="<?= htmlspecialchars($c['id']) ?>">
              <button type="submit" name="delete" value="1" class="secondary">حذف</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
</body>
</html>
