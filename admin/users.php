<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $target = db_get_user_by_id($pdo, $id);

    if (!$target) {
        admin_flash('error', 'المستخدم غير موجود.');
    } elseif ($action === 'delete') {
        if ($id === (int)$_SESSION['user_id']) {
            admin_flash('error', 'لا يمكنك حذف حسابك الحالي من هنا.');
        } elseif ($target['is_admin'] && db_count_admins($pdo) <= 1) {
            admin_flash('error', 'لا يمكن حذف آخر حساب مدير في النظام.');
        } else {
            db_delete_user($pdo, $id);
            admin_flash('success', 'تم حذف المستخدم بنجاح.');
        }
    } elseif ($action === 'toggle_admin') {
        if ($id === (int)$_SESSION['user_id']) {
            admin_flash('error', 'لا يمكنك تغيير صلاحيتك الخاصة.');
        } elseif ($target['is_admin'] && db_count_admins($pdo) <= 1) {
            admin_flash('error', 'لا يمكن إزالة صلاحية آخر مدير في النظام.');
        } else {
            db_update_user($pdo, $id, ['is_admin' => $target['is_admin'] ? 0 : 1]);
            admin_flash('success', 'تم تحديث صلاحية المستخدم.');
        }
    }

    header('Location: users.php');
    exit;
}

require_once __DIR__ . '/includes/layout.php';
$users = db_list_users($pdo);

admin_header('المستخدمون', 'users.php', 'حسابات العملاء والمدراء');
?>

<div class="card">
    <h2><i class="fas fa-list"></i> كل المستخدمين (<?php echo count($users); ?>)</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>الاسم</th><th>البريد الإلكتروني</th><th>الصلاحية</th><th>تاريخ التسجيل</th><th>آخر دخول</th><th>الإجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $isSelf = $u['id'] === (int)$_SESSION['user_id']; ?>
                <tr>
                    <td><?php echo e($u['fullname']); ?><?php if ($isSelf): ?> <span class="badge badge-muted">أنت</span><?php endif; ?></td>
                    <td><?php echo e($u['email']); ?></td>
                    <td><span class="badge <?php echo $u['isAdmin'] ? 'badge-ok' : 'badge-muted'; ?>"><?php echo $u['isAdmin'] ? 'مدير' : 'عميل'; ?></span></td>
                    <td><?php echo e($u['created_at']); ?></td>
                    <td><?php echo e($u['last_login'] ?: '—'); ?></td>
                    <td class="actions-cell">
                        <form method="post" style="display:inline;" data-confirm="<?php echo $u['isAdmin'] ? 'إزالة صلاحية المدير من هذا الحساب؟' : 'ترقية هذا الحساب إلى مدير؟'; ?>">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="form_action" value="toggle_admin">
                            <button type="submit" class="btn btn-sm btn-outline" <?php echo $isSelf ? 'disabled' : ''; ?>>
                                <i class="fas fa-user-shield"></i> <?php echo $u['isAdmin'] ? 'إزالة الإدارة' : 'ترقية لمدير'; ?>
                            </button>
                        </form>
                        <form method="post" style="display:inline;" data-confirm="حذف هذا المستخدم نهائياً؟">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                            <input type="hidden" name="form_action" value="delete">
                            <button type="submit" class="btn btn-sm btn-danger" <?php echo $isSelf ? 'disabled' : ''; ?>><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); ?>
