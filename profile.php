<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash('error', 'الاسم مطلوب.');
        } else {
            db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $user['id']]);
            log_activity((int) $user['id'], 'user_update', 'تحديث البيانات الشخصية');
            flash('success', 'تم تحديث بياناتك بنجاح.');
        }
        redirect('profile.php');
    }

    if ($formAction === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, $hash)) {
            flash('error', 'كلمة المرور الحالية غير صحيحة.');
        } elseif (strlen($new) < 8) {
            flash('error', 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل.');
        } elseif ($new !== $confirm) {
            flash('error', 'كلمتا المرور الجديدتان غير متطابقتين.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            log_activity((int) $user['id'], 'user_update', 'تغيير كلمة المرور الشخصية');
            flash('success', 'تم تغيير كلمة المرور بنجاح.');
        }
        redirect('profile.php');
    }
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$fullUser = $stmt->fetch();

$pageTitle = 'حسابي';
$activeNav = 'profile';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="content-grid-2">
  <section class="card">
    <div class="card-header"><h2><i class="fa-solid fa-id-card"></i> البيانات الشخصية</h2></div>
    <div class="card-body">
      <form method="post" action="profile.php" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="update_profile">
        <div class="form-group">
          <label class="form-label" for="name">الاسم الكامل</label>
          <input class="form-control" type="text" id="name" name="name" value="<?= e($fullUser['name']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">البريد الإلكتروني</label>
          <input class="form-control" type="email" value="<?= e($fullUser['email']) ?>" disabled>
          <p class="form-hint">لتغيير البريد الإلكتروني، تواصل مع مسؤول النظام.</p>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <span class="form-label">الدور</span>
            <div><span class="badge <?= $fullUser['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>"><?= $fullUser['role'] === 'admin' ? 'مسؤول' : 'مستخدم' ?></span></div>
          </div>
          <div class="form-group">
            <span class="form-label">عضو منذ</span>
            <div class="form-static"><?= e(format_date($fullUser['created_at'])) ?></div>
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> حفظ التغييرات</button>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-header"><h2><i class="fa-solid fa-lock"></i> تغيير كلمة المرور</h2></div>
    <div class="card-body">
      <form method="post" action="profile.php" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="change_password">
        <div class="form-group">
          <label class="form-label" for="current_password">كلمة المرور الحالية</label>
          <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
          <label class="form-label" for="new_password">كلمة المرور الجديدة</label>
          <input class="form-control" type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
        </div>
        <div class="form-group">
          <label class="form-label" for="new_password_confirm">تأكيد كلمة المرور الجديدة</label>
          <input class="form-control" type="password" id="new_password_confirm" name="new_password_confirm" minlength="8" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-key"></i> تحديث كلمة المرور</button>
      </form>
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
