<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $id = trim($_POST['id'] ?? '');
        $categoryId = trim($_POST['category_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $fields = [
            'name' => $name,
            'color' => trim($_POST['color'] ?? ''),
            'notes' => trim($_POST['notes'] ?? ''),
            'available' => isset($_POST['available']),
        ];
        if ($name === '' || $categoryId === '') {
            admin_flash('error', 'اسم الخدمة والفئة مطلوبان.');
        } else {
            $newImg = handleFileUpload($_FILES['img'] ?? null, 'service');
            if ($id === '') {
                db_create_service($pdo, $categoryId, $fields, $newImg);
                admin_flash('success', 'تمت إضافة الخدمة بنجاح.');
            } else {
                db_update_service_fields($pdo, $id, $categoryId, $fields, $newImg);
                admin_flash('success', 'تم تحديث الخدمة بنجاح.');
            }
        }
    } elseif ($action === 'hide') {
        $ok = db_set_service_deleted_card($pdo, $_POST['category_id'] ?? '', $_POST['id'] ?? '', 'ok') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إخفاء الخدمة.' : 'تعذّر العثور على الخدمة.');
    } elseif ($action === 'restore') {
        $ok = db_set_service_deleted_card($pdo, $_POST['category_id'] ?? '', $_POST['id'] ?? '', 'no') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إظهار الخدمة.' : 'تعذّر العثور على الخدمة.');
    } elseif ($action === 'toggle') {
        $ok = db_toggle_service_status($pdo, $_POST['category_id'] ?? '', $_POST['id'] ?? '') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم تحديث حالة التوفر.' : 'تعذّر العثور على الخدمة.');
    } elseif ($action === 'delete') {
        $name = db_delete_service_permanent($pdo, $_POST['category_id'] ?? '', $_POST['id'] ?? '');
        admin_flash($name !== null ? 'success' : 'error', $name !== null ? "تم حذف الخدمة \"$name\" نهائياً." : 'تعذّر حذف الخدمة.');
    }

    header('Location: services.php');
    exit;
}

require_once __DIR__ . '/includes/layout.php';

$categories = db_list_categories_admin($pdo);
$services = db_list_services_admin($pdo);

$editId = $_GET['edit'] ?? null;
$editRow = null;
if ($editId) {
    foreach ($services as $s) {
        if ($s['id'] === $editId) { $editRow = $s; break; }
    }
}

admin_header('الخدمات', 'services.php', 'الخدمات المرتبطة مباشرة بكل فئة');
?>

<div class="card">
    <h2><i class="fas fa-<?php echo $editRow ? 'pen' : 'plus'; ?>"></i> <?php echo $editRow ? 'تعديل خدمة' : 'إضافة خدمة جديدة'; ?></h2>
    <?php if (empty($categories)): ?>
        <div class="alert alert-info">أضف فئة أولاً قبل إضافة خدمة.</div>
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
                <label>اسم الخدمة</label>
                <input type="text" name="name" required value="<?php echo e($editRow['name'] ?? ''); ?>">
            </div>
            <div>
                <label>اللون / التصنيف اللوني</label>
                <input type="text" name="color" value="<?php echo e($editRow['color'] ?? ''); ?>">
            </div>
        </div>
        <label>ملاحظات / وصف الخدمة</label>
        <textarea name="notes"><?php echo e($editRow['notes'] ?? ''); ?></textarea>
        <label>صورة الخدمة (اختياري)</label>
        <input type="file" name="img" accept="image/*">
        <?php $availableChecked = $editRow ? !empty($editRow['available']) : true; ?>
        <div class="checkbox-row">
            <input type="checkbox" id="available" name="available" <?php echo $availableChecked ? 'checked' : ''; ?>>
            <label for="available">متوفرة</label>
        </div>
        <div style="margin-top:18px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> حفظ</button>
            <?php if ($editRow): ?><a href="services.php" class="btn btn-outline">إلغاء</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2><i class="fas fa-list"></i> كل الخدمات (<?php echo count($services); ?>)</h2>
    <?php if (empty($services)): ?>
        <div class="empty-state"><i class="fas fa-concierge-bell"></i><div>لا توجد خدمات بعد</div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>الصورة</th><th>الاسم</th><th>الفئة</th><th>التوفر</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($services as $s): $hidden = $s['deleted_card'] === 'ok'; ?>
                <tr>
                    <td><img class="cell-img" src="<?php echo $s['img'] ? '../uploads/' . e($s['img']) : 'https://iili.io/CKP5shF.jpg'; ?>" alt=""></td>
                    <td><?php echo e($s['name']); ?></td>
                    <td><?php echo e($s['category_name']); ?></td>
                    <td>
                        <form method="post">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($s['id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($s['category_id']); ?>">
                            <input type="hidden" name="form_action" value="toggle">
                            <button type="submit" class="badge <?php echo $s['available'] ? 'badge-ok' : 'badge-off'; ?>" style="border:none;cursor:pointer;">
                                <?php echo $s['available'] ? 'متوفرة' : 'غير متوفرة'; ?>
                            </button>
                        </form>
                    </td>
                    <td><span class="badge <?php echo $hidden ? 'badge-off' : 'badge-muted'; ?>"><?php echo $hidden ? 'مخفية' : 'ظاهرة'; ?></span></td>
                    <td class="actions-cell">
                        <a class="btn btn-sm btn-outline" href="services.php?edit=<?php echo urlencode($s['id']); ?>"><i class="fas fa-pen"></i></a>
                        <form method="post" style="display:inline;">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($s['id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($s['category_id']); ?>">
                            <input type="hidden" name="form_action" value="<?php echo $hidden ? 'restore' : 'hide'; ?>">
                            <button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-eye<?php echo $hidden ? '' : '-slash'; ?>"></i></button>
                        </form>
                        <form method="post" style="display:inline;" data-confirm="حذف الخدمة نهائياً؟ لا يمكن التراجع.">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($s['id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($s['category_id']); ?>">
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
