<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_admin();

function active_admin_count(?int $excludeId = null): int
{
    if ($excludeId !== null) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active' AND id != ?");
        $stmt->execute([$excludeId]);
    } else {
        $stmt = db()->query("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'");
    }
    return (int) $stmt->fetchColumn();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    switch ($formAction) {
        case 'create_user':
            $name     = trim((string) ($_POST['name'] ?? ''));
            $email    = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
                flash('error', 'تحقق من البيانات: الاسم مطلوب، بريد إلكتروني صالح، وكلمة مرور 8 أحرف على الأقل.');
                break;
            }
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                db()->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, 'active')")
                    ->execute([$name, $email, $hash, $role]);
                log_activity((int) $user['id'], 'user_create', "إنشاء مستخدم جديد: {$email}");
                flash('success', 'تم إنشاء المستخدم بنجاح.');
            } catch (PDOException $e) {
                flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'هذا البريد الإلكتروني مستخدم بالفعل.' : 'تعذّر إنشاء المستخدم.');
            }
            break;

        case 'edit_user':
            $id    = (int) ($_POST['id'] ?? 0);
            $name  = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $role  = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

            if ($id <= 0 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'بيانات غير صالحة.');
                break;
            }
            $stmt = db()->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                flash('error', 'المستخدم غير موجود.');
                break;
            }
            if ($target['role'] === 'admin' && $role !== 'admin' && active_admin_count($id) < 1) {
                flash('error', 'لا يمكن إزالة صلاحية آخر مسؤول نشط في النظام.');
                break;
            }
            try {
                db()->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?')
                    ->execute([$name, $email, $role, $id]);
                log_activity((int) $user['id'], 'user_update', "تعديل بيانات المستخدم #{$id}");
                flash('success', 'تم تحديث بيانات المستخدم.');
            } catch (PDOException $e) {
                flash('error', str_contains($e->getMessage(), 'Duplicate') ? 'هذا البريد الإلكتروني مستخدم بالفعل.' : 'تعذّر تحديث المستخدم.');
            }
            break;

        case 'toggle_status':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $user['id']) {
                flash('error', 'لا يمكنك تعطيل حسابك الخاص.');
                break;
            }
            $stmt = db()->prepare('SELECT role, status FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                flash('error', 'المستخدم غير موجود.');
                break;
            }
            $newStatus = $target['status'] === 'active' ? 'disabled' : 'active';
            if ($target['role'] === 'admin' && $newStatus === 'disabled' && active_admin_count($id) < 1) {
                flash('error', 'لا يمكن تعطيل آخر مسؤول نشط في النظام.');
                break;
            }
            db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            log_activity((int) $user['id'], 'user_update', ($newStatus === 'active' ? 'تفعيل' : 'تعطيل') . " المستخدم #{$id}");
            flash('success', $newStatus === 'active' ? 'تم تفعيل الحساب.' : 'تم تعطيل الحساب.');
            break;

        case 'reset_password':
            $id          = (int) ($_POST['id'] ?? 0);
            $newPassword = (string) ($_POST['new_password'] ?? '');
            if ($id <= 0 || strlen($newPassword) < 8) {
                flash('error', 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل.');
                break;
            }
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            log_activity((int) $user['id'], 'user_update', "إعادة تعيين كلمة مرور المستخدم #{$id}");
            flash('success', 'تم تغيير كلمة المرور بنجاح.');
            break;

        case 'delete_user':
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $user['id']) {
                flash('error', 'لا يمكنك حذف حسابك الخاص.');
                break;
            }
            $stmt = db()->prepare('SELECT role, email FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                flash('error', 'المستخدم غير موجود.');
                break;
            }
            if ($target['role'] === 'admin' && active_admin_count($id) < 1) {
                flash('error', 'لا يمكن حذف آخر مسؤول في النظام.');
                break;
            }
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            log_activity((int) $user['id'], 'user_update', "حذف المستخدم: {$target['email']}");
            flash('success', 'تم حذف المستخدم. مشاريعه المُنشأة سابقاً تبقى محفوظة.');
            break;

        default:
            flash('error', 'إجراء غير معروف.');
    }

    redirect('users.php');
}

$allUsers = db()->query('SELECT id, name, email, role, status, last_login_at, created_at FROM users ORDER BY created_at ASC')->fetchAll();

$pageTitle     = 'إدارة المستخدمين';
$activeNav     = 'users';
$topbarActions = '<button type="button" class="btn btn-primary" data-action="open-modal" data-modal="modalCreateUser"><i class="fa-solid fa-user-plus"></i> مستخدم جديد</button>';
require __DIR__ . '/includes/layout_start.php';
?>

<section class="card">
  <div class="card-header">
    <h2><i class="fa-solid fa-users"></i> جميع المستخدمين (<?= count($allUsers) ?>)</h2>
  </div>
  <div class="card-body no-pad table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>المستخدم</th><th>البريد الإلكتروني</th><th>الدور</th><th>الحالة</th><th>آخر دخول</th><th>تاريخ الإنشاء</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($allUsers as $u): ?>
        <tr>
          <td>
            <div class="table-user">
              <span class="user-avatar user-avatar-sm"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
              <span><?= e($u['name']) ?></span>
              <?php if ((int) $u['id'] === (int) $user['id']): ?><span class="tag-you">أنت</span><?php endif; ?>
            </div>
          </td>
          <td><?= e($u['email']) ?></td>
          <td><span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>"><?= $u['role'] === 'admin' ? 'مسؤول' : 'مستخدم' ?></span></td>
          <td><span class="badge <?= $u['status'] === 'active' ? 'badge-active' : 'badge-disabled' ?>"><?= $u['status'] === 'active' ? 'مفعّل' : 'معطّل' ?></span></td>
          <td><?= e(format_date($u['last_login_at'])) ?></td>
          <td><?= e(format_date($u['created_at'])) ?></td>
          <td class="table-actions">
            <button type="button" class="btn-icon" title="تعديل"
              data-action="edit-user"
              data-id="<?= (int) $u['id'] ?>"
              data-name="<?= e($u['name']) ?>"
              data-email="<?= e($u['email']) ?>"
              data-role="<?= e($u['role']) ?>">
              <i class="fa-solid fa-pen"></i>
            </button>
            <button type="button" class="btn-icon" title="تغيير كلمة المرور"
              data-action="reset-password"
              data-id="<?= (int) $u['id'] ?>"
              data-name="<?= e($u['name']) ?>">
              <i class="fa-solid fa-key"></i>
            </button>
            <?php if ((int) $u['id'] !== (int) $user['id']): ?>
            <form method="post" action="users.php" class="inline-form" data-confirm="<?= $u['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?> حساب «<?= e($u['name']) ?>»؟">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="toggle_status">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn-icon" title="<?= $u['status'] === 'active' ? 'تعطيل' : 'تفعيل' ?>">
                <i class="fa-solid <?= $u['status'] === 'active' ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
              </button>
            </form>
            <form method="post" action="users.php" class="inline-form" data-confirm="حذف حساب «<?= e($u['name']) ?>» نهائياً؟ لا يمكن التراجع عن هذا الإجراء.">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="delete_user">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="btn-icon btn-icon-danger" title="حذف">
                <i class="fa-solid fa-trash"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="modal-backdrop" id="modalCreateUser">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-plus"></i> مستخدم جديد</h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="post" action="users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="create_user">
      <div class="modal-body stack-form">
        <div class="form-group">
          <label class="form-label">الاسم الكامل</label>
          <input class="form-control" type="text" name="name" required>
        </div>
        <div class="form-group">
          <label class="form-label">البريد الإلكتروني</label>
          <input class="form-control" type="email" name="email" required>
        </div>
        <div class="form-group">
          <label class="form-label">كلمة المرور</label>
          <input class="form-control" type="password" name="password" minlength="8" required autocomplete="new-password">
        </div>
        <div class="form-group">
          <label class="form-label">الدور</label>
          <select class="form-control" name="role">
            <option value="user">مستخدم</option>
            <option value="admin">مسؤول</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-action="close-modal">إلغاء</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> إنشاء</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="modalEditUser">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-user-pen"></i> تعديل المستخدم</h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="post" action="users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="edit_user">
      <input type="hidden" name="id" id="editUserId" value="">
      <div class="modal-body stack-form">
        <div class="form-group">
          <label class="form-label">الاسم الكامل</label>
          <input class="form-control" type="text" name="name" id="editUserName" required>
        </div>
        <div class="form-group">
          <label class="form-label">البريد الإلكتروني</label>
          <input class="form-control" type="email" name="email" id="editUserEmail" required>
        </div>
        <div class="form-group">
          <label class="form-label">الدور</label>
          <select class="form-control" name="role" id="editUserRole">
            <option value="user">مستخدم</option>
            <option value="admin">مسؤول</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-action="close-modal">إلغاء</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> حفظ التعديلات</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop" id="modalResetPassword">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-key"></i> تغيير كلمة المرور — <span id="resetPasswordName"></span></h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="post" action="users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="reset_password">
      <input type="hidden" name="id" id="resetPasswordId" value="">
      <div class="modal-body stack-form">
        <div class="form-group">
          <label class="form-label">كلمة المرور الجديدة</label>
          <input class="form-control" type="password" name="new_password" minlength="8" required autocomplete="new-password">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-action="close-modal">إلغاء</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> تحديث</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
