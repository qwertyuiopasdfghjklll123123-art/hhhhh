<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $id = trim($_POST['id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            admin_flash('error', 'اسم الفئة مطلوب.');
        } else {
            $newImage = handleFileUpload($_FILES['image'] ?? null, 'cat');
            if ($id === '') {
                db_create_category($pdo, $name, $newImage);
                admin_flash('success', 'تمت إضافة الفئة بنجاح.');
            } else {
                db_update_category_fields($pdo, $id, $name, $newImage);
                admin_flash('success', 'تم تحديث الفئة بنجاح.');
            }
        }
    } elseif ($action === 'hide') {
        $ok = db_hide_category($pdo, $_POST['id'] ?? '') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إخفاء الفئة.' : 'تعذّر العثور على الفئة.');
    } elseif ($action === 'restore') {
        $ok = db_restore_category($pdo, $_POST['id'] ?? '') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إظهار الفئة.' : 'تعذّر العثور على الفئة.');
    } elseif ($action === 'delete') {
        $name = db_delete_category_permanent($pdo, $_POST['id'] ?? '');
        admin_flash($name !== null ? 'success' : 'error', $name !== null ? "تم حذف الفئة \"$name\" نهائياً مع كل الشركات والمنتجات والخدمات التابعة لها." : 'تعذّر حذف الفئة.');
    }

    header('Location: categories.php');
    exit;
}

require_once __DIR__ . '/includes/layout.php';

$editId = $_GET['edit'] ?? null;
$editRow = null;
if ($editId) {
    foreach (db_list_categories_admin($pdo) as $c) {
        if ($c['id'] === $editId) { $editRow = $c; break; }
    }
}

$categories = db_list_categories_admin($pdo);

admin_header('الفئات', 'categories.php', 'إدارة أقسام الكتالوج الرئيسية');
?>

<div class="card">
    <h2><i class="fas fa-<?php echo $editRow ? 'pen' : 'plus'; ?>"></i> <?php echo $editRow ? 'تعديل فئة' : 'إضافة فئة جديدة'; ?></h2>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="save">
        <input type="hidden" name="id" value="<?php echo e($editRow['id'] ?? ''); ?>">
        <div class="form-row">
            <div>
                <label>اسم الفئة</label>
                <input type="text" name="name" required value="<?php echo e($editRow['name'] ?? ''); ?>">
            </div>
            <div>
                <label>صورة الفئة (اختياري)</label>
                <input type="file" name="image" accept="image/*">
                <?php if (!empty($editRow['image'])): ?>
                    <div class="field-hint">الصورة الحالية محفوظة، اختر ملفاً جديداً فقط إذا أردت استبدالها.</div>
                <?php endif; ?>
            </div>
        </div>
        <div style="margin-top:18px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> حفظ</button>
            <?php if ($editRow): ?><a href="categories.php" class="btn btn-outline">إلغاء</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2><i class="fas fa-list"></i> كل الفئات (<?php echo count($categories); ?>)</h2>
    <?php if (empty($categories)): ?>
        <div class="empty-state"><i class="fas fa-folder-open"></i><div>لا توجد فئات بعد</div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>الصورة</th><th>الاسم</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): $hidden = $c['deleted_card'] === 'ok'; ?>
                <tr>
                    <td><img class="cell-img" src="<?php echo $c['image'] ? '../uploads/' . e($c['image']) : 'https://iili.io/CKP5shF.jpg'; ?>" alt=""></td>
                    <td><?php echo e($c['name']); ?></td>
                    <td><span class="badge <?php echo $hidden ? 'badge-off' : 'badge-ok'; ?>"><?php echo $hidden ? 'مخفية' : 'ظاهرة'; ?></span></td>
                    <td class="actions-cell">
                        <a class="btn btn-sm btn-outline" href="categories.php?edit=<?php echo urlencode($c['id']); ?>"><i class="fas fa-pen"></i></a>
                        <form method="post" style="display:inline;">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
                            <input type="hidden" name="form_action" value="<?php echo $hidden ? 'restore' : 'hide'; ?>">
                            <button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-eye<?php echo $hidden ? '' : '-slash'; ?>"></i></button>
                        </form>
                        <form method="post" style="display:inline;" data-confirm="حذف الفئة نهائياً مع كل ما بداخلها؟ لا يمكن التراجع.">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($c['id']); ?>">
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
