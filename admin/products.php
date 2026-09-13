<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'save') {
        $id = trim($_POST['id'] ?? '');
        $companyId = trim($_POST['company_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $fields = [
            'name' => $name,
            'code' => trim($_POST['code'] ?? ''),
            'color' => trim($_POST['color'] ?? ''),
            'price' => trim($_POST['price'] ?? ''),
            'image_url' => trim($_POST['image_url'] ?? ''),
            'available' => isset($_POST['available']),
        ];
        if ($name === '' || $companyId === '') {
            admin_flash('error', 'اسم المنتج والشركة مطلوبان.');
        } else {
            $newImg = handleFileUpload($_FILES['img'] ?? null, 'prod');
            if ($id === '') {
                db_create_product($pdo, $companyId, $fields, $newImg);
                admin_flash('success', 'تمت إضافة المنتج بنجاح.');
            } else {
                db_update_product_fields($pdo, $id, $companyId, $fields, $newImg);
                admin_flash('success', 'تم تحديث المنتج بنجاح.');
            }
        }
    } elseif ($action === 'hide') {
        $ok = db_set_product_deleted_card($pdo, $_POST['category_id'] ?? '', $_POST['company_id'] ?? '', $_POST['id'] ?? '', 'ok') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إخفاء المنتج.' : 'تعذّر العثور على المنتج.');
    } elseif ($action === 'restore') {
        $ok = db_set_product_deleted_card($pdo, $_POST['category_id'] ?? '', $_POST['company_id'] ?? '', $_POST['id'] ?? '', 'no') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم إظهار المنتج.' : 'تعذّر العثور على المنتج.');
    } elseif ($action === 'toggle') {
        $ok = db_toggle_product_status($pdo, $_POST['category_id'] ?? '', $_POST['company_id'] ?? '', $_POST['id'] ?? '') !== null;
        admin_flash($ok ? 'success' : 'error', $ok ? 'تم تحديث حالة التوفر.' : 'تعذّر العثور على المنتج.');
    } elseif ($action === 'delete') {
        $name = db_delete_product_permanent($pdo, $_POST['category_id'] ?? '', $_POST['company_id'] ?? '', $_POST['id'] ?? '');
        admin_flash($name !== null ? 'success' : 'error', $name !== null ? "تم حذف المنتج \"$name\" نهائياً." : 'تعذّر حذف المنتج.');
    }

    header('Location: products.php');
    exit;
}

require_once __DIR__ . '/includes/layout.php';

$companies = db_list_companies_admin($pdo);
$products = db_list_products_admin($pdo);

$editId = $_GET['edit'] ?? null;
$editRow = null;
if ($editId) {
    foreach ($products as $p) {
        if ($p['id'] === $editId) { $editRow = $p; break; }
    }
}

admin_header('المنتجات', 'products.php', 'كل المنتجات عبر جميع الشركات والفئات');
?>

<div class="card">
    <h2><i class="fas fa-<?php echo $editRow ? 'pen' : 'plus'; ?>"></i> <?php echo $editRow ? 'تعديل منتج' : 'إضافة منتج جديد'; ?></h2>
    <?php if (empty($companies)): ?>
        <div class="alert alert-info">أضف فئة وشركة أولاً قبل إضافة منتج.</div>
    <?php else: ?>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="save">
        <input type="hidden" name="id" value="<?php echo e($editRow['id'] ?? ''); ?>">
        <div class="form-row">
            <div>
                <label>الشركة</label>
                <select name="company_id" required>
                    <?php foreach ($companies as $comp): ?>
                        <option value="<?php echo e($comp['id']); ?>" <?php echo (($editRow['company_id'] ?? '') === $comp['id']) ? 'selected' : ''; ?>>
                            <?php echo e($comp['category_name'] . ' / ' . $comp['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>اسم المنتج</label>
                <input type="text" name="name" required value="<?php echo e($editRow['name'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div>
                <label>الكود</label>
                <input type="text" name="code" value="<?php echo e($editRow['code'] ?? ''); ?>">
            </div>
            <div>
                <label>اللون</label>
                <input type="text" name="color" value="<?php echo e($editRow['color'] ?? ''); ?>">
            </div>
            <div>
                <label>السعر</label>
                <input type="text" name="price" value="<?php echo e($editRow['price'] ?? ''); ?>" placeholder="مثال: 4,999">
            </div>
        </div>
        <div class="form-row">
            <div>
                <label>صورة المنتج (اختياري)</label>
                <input type="file" name="img" accept="image/*">
            </div>
            <div>
                <label>رابط صورة خارجي (اختياري)</label>
                <input type="text" name="image_url" value="<?php echo e($editRow['image_url'] ?? ''); ?>" placeholder="https://...">
            </div>
        </div>
        <?php $availableChecked = $editRow ? !empty($editRow['available']) : true; ?>
        <div class="checkbox-row">
            <input type="checkbox" id="available" name="available" <?php echo $availableChecked ? 'checked' : ''; ?>>
            <label for="available">متوفر للبيع</label>
        </div>
        <div style="margin-top:18px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> حفظ</button>
            <?php if ($editRow): ?><a href="products.php" class="btn btn-outline">إلغاء</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2><i class="fas fa-list"></i> كل المنتجات (<?php echo count($products); ?>)</h2>
    <?php if (empty($products)): ?>
        <div class="empty-state"><i class="fas fa-box-open"></i><div>لا توجد منتجات بعد</div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>الصورة</th><th>الاسم</th><th>الشركة / الفئة</th><th>السعر</th><th>التوفر</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): $hidden = $p['deleted_card'] === 'ok'; ?>
                <tr>
                    <td><img class="cell-img" src="<?php echo $p['img'] ? '../uploads/' . e($p['img']) : ($p['image_url'] ?: 'https://iili.io/CKP5shF.jpg'); ?>" alt=""></td>
                    <td><?php echo e($p['name']); ?><?php if ($p['code']): ?><br><small style="color:var(--muted)"><?php echo e($p['code']); ?></small><?php endif; ?></td>
                    <td><?php echo e($p['company_name'] . ' / ' . $p['category_name']); ?></td>
                    <td><?php echo e($p['price'] ?: '—'); ?></td>
                    <td>
                        <form method="post">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($p['id']); ?>">
                            <input type="hidden" name="company_id" value="<?php echo e($p['company_id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($p['category_id']); ?>">
                            <input type="hidden" name="form_action" value="toggle">
                            <button type="submit" class="badge <?php echo $p['available'] ? 'badge-ok' : 'badge-off'; ?>" style="border:none;cursor:pointer;">
                                <?php echo $p['available'] ? 'متوفر' : 'غير متوفر'; ?>
                            </button>
                        </form>
                    </td>
                    <td><span class="badge <?php echo $hidden ? 'badge-off' : 'badge-muted'; ?>"><?php echo $hidden ? 'مخفي' : 'ظاهر'; ?></span></td>
                    <td class="actions-cell">
                        <a class="btn btn-sm btn-outline" href="products.php?edit=<?php echo urlencode($p['id']); ?>"><i class="fas fa-pen"></i></a>
                        <form method="post" style="display:inline;">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($p['id']); ?>">
                            <input type="hidden" name="company_id" value="<?php echo e($p['company_id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($p['category_id']); ?>">
                            <input type="hidden" name="form_action" value="<?php echo $hidden ? 'restore' : 'hide'; ?>">
                            <button type="submit" class="btn btn-sm btn-outline"><i class="fas fa-eye<?php echo $hidden ? '' : '-slash'; ?>"></i></button>
                        </form>
                        <form method="post" style="display:inline;" data-confirm="حذف المنتج نهائياً؟ لا يمكن التراجع.">
                            <?php echo admin_csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo e($p['id']); ?>">
                            <input type="hidden" name="company_id" value="<?php echo e($p['company_id']); ?>">
                            <input type="hidden" name="category_id" value="<?php echo e($p['category_id']); ?>">
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
