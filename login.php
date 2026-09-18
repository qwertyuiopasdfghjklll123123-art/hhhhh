<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error   = null;
$emailOld = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $emailOld = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($emailOld === '' || $password === '') {
        $error = 'الرجاء إدخال البريد الإلكتروني وكلمة المرور.';
    } else {
        $result = attempt_login($emailOld, $password);
        if ($result['success']) {
            redirect('dashboard.php');
        }
        $error = $result['message'];
    }
}

$flashSuccess = flash('success');
$flashError   = flash('error');
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول · لوحة إدارة المشاريع</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<div class="auth-shell">
  <div class="auth-card">
    <div class="auth-logo"><i class="fa-solid fa-diagram-project"></i></div>
    <h1 class="auth-title">تسجيل الدخول</h1>
    <p class="auth-sub">لوحة إدارة المشاريع الذكية</p>

    <?php if ($flashSuccess): ?>
      <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><span><?= e($flashSuccess) ?></span></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
      <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i><span><?= e($flashError) ?></span></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <form method="post" action="login.php" class="stack-form">
      <?= csrf_field() ?>
      <div class="form-group">
        <label class="form-label" for="email">البريد الإلكتروني</label>
        <input class="form-control" type="email" id="email" name="email" value="<?= e($emailOld) ?>" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label" for="password">كلمة المرور</label>
        <div class="input-with-icon">
          <input class="form-control" type="password" id="password" name="password" required>
          <button type="button" class="input-icon-btn" data-action="toggle-visibility" data-target="password" aria-label="إظهار كلمة المرور">
            <i class="fa-solid fa-eye"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block btn-lg">
        <i class="fa-solid fa-right-to-bracket"></i> دخول
      </button>
    </form>

    <p class="auth-footnote">لا تمتلك حساباً؟ <a href="register.php">أنشئ حساباً جديداً</a></p>
  </div>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
