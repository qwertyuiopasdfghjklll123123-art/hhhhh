<?php
/* ======================================================================
   تسجيل الدخول — ملف واحد شامل (منطق + واجهة)
   ====================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if ($user) {
    redirect(role_home($user['role']));
}

$error = null;

if (is_post()) {
    csrf_verify();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'الرجاء إدخال بيانات الدخول كاملة.';
    } else {
        $result = login_attempt(db(), $username, $password);
        if ($result) {
            redirect(role_home($result['role']));
        }
        $error = 'بيانات الدخول غير صحيحة.';
    }
}

/* ======================================================================
   الواجهة (HTML / CSS View)
   ====================================================================== */
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول — شركة الصوى للصرافة</title>
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-logo">✥</div>
        <div class="auth-title">شركة الصوى للصرافة</div>
        <div class="auth-subtitle">نظام إدارة الموارد البشرية والفروع</div>

        <div class="role-tabs">
            <div class="role-tab active" data-label="البريد الإلكتروني">الموارد البشرية</div>
            <div class="role-tab" data-label="رقم الموظف">مدير فرع</div>
            <div class="role-tab" data-label="رقم التوظيف">موظف</div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login.php">
            <?= csrf_field() ?>
            <div class="form-group">
                <label id="username-label">البريد الإلكتروني / رقم الموظف</label>
                <input type="text" name="username" class="form-control" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>كلمة المرور</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">تسجيل الدخول</button>
        </form>
        <div class="auth-subtitle" style="margin-top:16px;">يتم تزويد بيانات الدخول من الإدارة العليا أو الموارد البشرية</div>
    </div>
</div>
<script>
document.querySelectorAll('.role-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.role-tab').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        document.getElementById('username-label').textContent = tab.dataset.label;
    });
});
</script>
</body>
</html>
