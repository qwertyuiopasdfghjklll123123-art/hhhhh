<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (isAdmin()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'انتهت صلاحية النموذج، حاول مرة أخرى.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = db_get_user_by_email($pdo, $email);

        if ($user && password_verify($password, $user['password']) && $user['is_admin']) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['fullname'];
            $_SESSION['is_admin'] = true;
            db_touch_last_login($pdo, $user['id']);
            header('Location: index.php');
            exit;
        } elseif ($user && !$user['is_admin']) {
            $error = 'هذا الحساب لا يملك صلاحية الدخول إلى لوحة التحكم.';
        } else {
            $error = 'البريد الإلكتروني أو كلمة المرور غير صحيحة.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تسجيل الدخول | لوحة التحكم</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bg-orb o1"></div>
<div class="bg-orb o2"></div>
<div class="login-wrap">
    <div class="login-card shimmer">
        <div class="brand"><i class="fas fa-bolt"></i> Almulla</div>
        <div class="sub">تسجيل الدخول إلى لوحة التحكم</div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <?php echo admin_csrf_field(); ?>
            <label>البريد الإلكتروني</label>
            <input type="email" name="email" required autofocus value="<?php echo e($_POST['email'] ?? ''); ?>">
            <label>كلمة المرور</label>
            <input type="password" name="password" required>
            <button type="submit" class="btn btn-primary" style="width:100%;margin-top:22px;">
                <i class="fas fa-right-to-bracket"></i> دخول
            </button>
        </form>
    </div>
</div>
</body>
</html>
