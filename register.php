<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error    = null;
$nameOld  = '';
$emailOld = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $nameOld  = trim((string) ($_POST['name'] ?? ''));
    $emailOld = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if ($nameOld === '' || !filter_var($emailOld, FILTER_VALIDATE_EMAIL)) {
        $error = 'تحقق من البيانات: الاسم مطلوب، والبريد الإلكتروني يجب أن يكون صالحاً.';
    } elseif (strlen($password) < 8) {
        $error = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.';
    } elseif ($password !== $confirm) {
        $error = 'كلمتا المرور غير متطابقتين.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db()->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'user', 'active')")
                ->execute([$nameOld, $emailOld, $hash]);
            $newUserId = (int) db()->lastInsertId();

            session_regenerate_id(true);
            $_SESSION['user_id']       = $newUserId;
            $_SESSION['last_activity'] = time();
            db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$newUserId]);
            log_activity($newUserId, 'login', 'إنشاء حساب جديد وتسجيل دخول تلقائي');

            redirect('dashboard.php');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'هذا البريد الإلكتروني مستخدم بالفعل.' : 'تعذّر إنشاء الحساب.';
        }
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إنشاء حساب · <?= e(app_config()['app']['name'] ?? 'لوحة إدارة المشاريع') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<div class="auth-shell">
  <div class="auth-card">
    <div class="auth-logo"><i class="fa-solid fa-diagram-project"></i></div>
    <h1 class="auth-title">إنشاء حساب جديد</h1>
    <p class="auth-sub">لوحة إدارة المشاريع الذكية</p>

    <?php if ($error): ?>
      <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" action="register.php" class="stack-form">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="name">الاسم الكامل</label>
        <input class="form-control" type="text" id="name" name="name" value="<?= e($nameOld) ?>" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label" for="email">البريد الإلكتروني</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= e($emailOld) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="password">كلمة المرور</label>
        <div class="input-with-icon">
          <input class="form-control" type="password" id="password" name="password" minlength="8" required autocomplete="new-password">
          <button type="button" class="input-icon-btn" data-action="toggle-visibility" data-target="password" aria-label="إظهار كلمة المرور">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
        <p class="form-hint">8 أحرف على الأقل.</p>
      </div>
      <div class="form-group">
        <label class="form-label" for="password_confirm">تأكيد كلمة المرور</label>
        <input class="form-control" type="password" id="password_confirm" name="password_confirm" minlength="8" required autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">
        <i class="fa-solid fa-user-plus"></i> إنشاء الحساب
      </button>
    </form>

    <p class="auth-footnote">لديك حساب بالفعل؟ <a href="login.php">سجّل الدخول</a></p>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
