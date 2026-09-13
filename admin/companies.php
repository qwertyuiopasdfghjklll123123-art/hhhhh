<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $id = trim($_POST['id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $categoryId = trim($_POST['category_id'] ?? '');
        if ($name === '' || $categoryId === '') {
            admin_flash('error', 'اسم الشركة والفئة مطلوبان.');
        } else {
            $newLogo = handleFileUpload($_FILES['logo'] ?? null, 'logo');
            if ($id === '') {
                db_create_company($pdo, $categoryId, $name, $newLogo);
                admin_flash('success', 'تمت إضافة الشركة بنجاح.');
            } else {
                db_update_company_fields($pdo, $id, $categoryId, $name, $newLogo);
                admin_flash('success', 'تم تحديث الشركة بنجاح.');
            }
        }
    } elseif ($action === 'hide') {
        $ok = db_hide_company($pdo, $_POST['category_id'] ?? '', $_POST['id'] ?? '') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إخفاء الشركة.' : 'تعذّر العثور على الشركة.');
    } elseif ($action === 'restore') {
        $ok = db_restore_company($pdo, $_POST['category_id'] ?? '', $_POST['id'] ?? '') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إظهار الشركة.' : 'تعذّر العثور على الشركة.');
    } elseif ($action === 'delete') {
        $name = db_delete_company_permanent($pdo, $_POST['category_id'] ?? '', $_POST['id'] ?? '');
        admin_flash($name !== null ? 'success' : 'error', $name !== null ? "تم حذف الشركة \"$name\" نهائياً مع كل منتجاتها." : 'تعذّر حذف الشركة.');
    }

    header('Location: companies.php');
    exit;
}

require_once __DIR__ . '/includes/layout.php';

$categories = db_list_categories_admin($pdo);
$companies = db_list_companies_admin($pdo);

$editId = $_GET['edit'] ?? null;
$editRow = null;
if ($editId) {
    foreach ($companies as $c) {
        if ($c['id'] === $editId) { $editRow = $c; break; }
    }
}

admin_header('الشركات', 'companies.php', 'الشركات المصنّفة تحت كل فئة');
?>

<div class="card">
    <h2><i class="fas fa-<?php echo $editRow ? 'pen' : 'plus'; ?>"></i> <?php echo $editRow ? 'تعديل شركة' : 'إضافة شركة جديدة'; ?></h2>
    <?php if (empty($categories)): ?>
        <div class="alert alert-info">أضف فئة أولاً من صفحة "الفئات" قبل إضافة شركة.</div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="save">
        <input type="hidden" name="id" value="<?php echo e($editRow['id'] ?? ''); ?>">
        <div class="form-row">
            <div>
                <label>الفئة</label>
                <select name="category_id" required>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo e($cat['id']); ?>" <?php echo (($editRow['category_id'] ?? '') === $cat['id']) ? 'selected' : ''; ?>><?php echo e($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>اسم الشركة</label>
                <input type="text" name="name" required value="<?php echo e($editRow['name'] ?? ''); ?>">
            </div>
            <div>
                <label>شعار الشركة (اختياري)</label>
                <input type="file" name="logo" accept="image/*">
            </div>
        </div>
        <div style="margin-top:18px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> حفظ</button>
            <?php if ($editRow): ?><a href="companies.php" class="btn btn-outline">إلغاء</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2><i class="fas fa-list"></i> كل الشركات (<?php echo count($companies); ?>)</h2>
    <?php if (empty($companies)): ?>
        <div class="empty-state"><i class="fas fa-building"></i><div>لا توجد شركات بعد</div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>الشعار</th><th>الاسم</th><th>الفئة</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($companies as $c): $hidden = $c['deleted_card'] === 'ok'; ?>
                <tr>
                    <td><img class="cell-img" src="<?php echo $c['logo'] ? '../uploads/' . e($c['logo']) : 'https://iili.io/CKP5shF.jpg'; ?>" alt=""></td>
                    <td><?php echo e($c['name']); ?></td>
                    <td><?php echo e($c['category_name']); ?></td>
                    <td><span class="badge <?php echo $hidden ? 'badge-off' : 'badge-ok'; ?>"><?php echo $hidden ? 'مخفية' : 'ظاهرة'; ?></span></td>
                    <td class="actions-cell">
                        <a class="btn btn-sm btn-outline" href="companies.php?edit=<?php echo urlencode($c['id']); ?>"><i class="fas fa-pen"></i></a>
                        <form method="post" style="display:inline;">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($c['category_id']); ?>">
                            <input type="hidden" name="form_action" value="<?php echo $hidden ? 'restore' : 'hide'; ?>">
                            <button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-eye<?php echo $hidden ? '' : '-slash'; ?>"></i></button>
                        </form>
                        <form method="post" style="display:inline;" data-confirm="حذف الشركة نهائياً مع كل منتجاتها؟ لا يمكن التراجع.">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($c['category_id']); ?>">
                            <input type="hidden" name="form_action" value="delete">
                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>
