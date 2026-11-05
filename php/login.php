<?php
require_once __DIR__ . '/includes/functions.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $user['password'] !== hash_pw($password)) {
        $error = 'البريد أو كلمة المرور غير صحيحة';
    } elseif ($user['banned']) {
        $error = 'هذا الحساب محظور';
    } else {
        $_SESSION['user_id'] = (int)$user['id'];
        db()->prepare('UPDATE users SET last_seen = ? WHERE id = ?')->execute([time(), $user['id']]);
        header('Location: /php/dashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>تسجيل الدخول — fastcrand</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>تسجيل الدخول</h1>
    <form method="post">
      <label>البريد الإلكتروني</label>
      <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      <label>كلمة المرور</label>
      <input type="password" name="password" required>
      <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <button type="submit">دخول</button>
    </form>
    <p class="muted">ما عندك حساب؟ <a href="register.php">سجّل حساب جديد</a></p>
  </div>
</div>
</body>
</html>
