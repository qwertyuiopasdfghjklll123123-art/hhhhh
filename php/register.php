<?php
require_once __DIR__ . '/includes/functions.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'جميع الحقول مطلوبة';
    } elseif (strlen($password) < 6) {
        $error = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'هذا البريد مستخدم مسبقاً';
        } else {
            $ins = db()->prepare(
                'INSERT INTO users (name, email, password, verified, created_at) VALUES (?, ?, ?, 1, ?)'
            );
            $ins->execute([$name, $email, hash_pw($password), time()]);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            header('Location: /php/dashboard');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'إنشاء حساب — fastcrand'; $assetBase = ''; include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>إنشاء حساب جديد</h1>
    <form method="post">
      <label>الاسم</label>
      <input type="text" name="name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      <label>البريد الإلكتروني</label>
      <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      <label>كلمة المرور</label>
      <input type="password" name="password" required>
      <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <button type="submit">إنشاء الحساب</button>
    </form>
    <p class="muted">عندك حساب؟ <a href="login">سجّل دخول</a></p>
  </div>
</div>
</body>
</html>
