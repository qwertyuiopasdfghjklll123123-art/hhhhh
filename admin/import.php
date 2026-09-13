<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();
require_once __DIR__ . '/../includes/import.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'import_json') {
        $file = $_FILES['json_file'] ?? null;
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            admin_flash('error', 'يرجى اختيار ملف database.json صالح.');
        } else {
            $raw = file_get_contents($file['tmp_name']);
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                admin_flash('error', 'الملف ليس بصيغة JSON صالحة.');
            } else {
                $summary = import_legacy_json($pdo, $json);
                admin_flash('success', "تم الاستيراد: {$summary['users']} مستخدم، {$summary['categories']} فئة.");
            }
        }
    } elseif ($action === 'import_sqlite') {
        $file = $_FILES['sqlite_file'] ?? null;
        $categoryName = trim($_POST['sqlite_category'] ?? '') ?: 'منتجات مستوردة';
        $companyName = trim($_POST['sqlite_company'] ?? '') ?: 'عام';
        $importSettings = isset($_POST['import_settings']);

        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            admin_flash('error', 'يرجى اختيار ملف قاعدة بيانات (.db) صالح.');
        } else {
            $summary = import_flat_products_sqlite($pdo, $file['tmp_name'], $categoryName, $companyName, $importSettings);
            if ($summary['error']) {
                admin_flash('error', $summary['error']);
            } else {
                admin_flash('success', "تم استيراد {$summary['products']} منتج ضمن فئة \"$categoryName\" / شركة \"$companyName\".");
            }
        }
    }

    header('Location: import.php');
    exit;
}

require_once __DIR__ . '/includes/layout.php';
admin_header('استيراد بيانات', 'import.php', 'استيراد بيانات من نسخة سابقة من التطبيق أو من نظام آخر');
?>

<div class="card">
    <h2><i class="fas fa-file-code"></i> استيراد من نسخة Almulla القديمة (database.json)</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        يستورد المستخدمين، الفئات، الشركات، المنتجات، الخدمات، الإعدادات والإحصائيات من ملف <code>database.json</code>
        الذي كان يستخدمه التطبيق قبل الانتقال إلى MySQL.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_json">
        <label>ملف database.json</label>
        <input type="file" name="json_file" accept="application/json,.json" required>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد</button>
    </form>
</div>

<div class="card">
    <h2><i class="fas fa-database"></i> استيراد من قاعدة بيانات منتجات بسيطة (SQLite)</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        لاستيراد قاعدة بيانات SQLite تحتوي جدول منتجات مسطّح (بدون فئات أو شركات) من نظام آخر.
        سيتم إنشاء فئة وشركة جديدتين لاستقبال هذه المنتجات.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_sqlite">
        <label>ملف قاعدة البيانات (.db)</label>
        <input type="file" name="sqlite_file" accept=".db,.sqlite,.sqlite3" required>
        <div class="form-row">
            <div>
                <label>اسم الفئة الجديدة</label>
                <input type="text" name="sqlite_category" value="منتجات مستوردة">
            </div>
            <div>
                <label>اسم الشركة الجديدة</label>
                <input type="text" name="sqlite_company" value="عام">
            </div>
        </div>
        <div class="checkbox-row">
            <input type="checkbox" id="import_settings" name="import_settings" checked>
            <label for="import_settings">استيراد الإعدادات العامة أيضاً إن وُجدت (اسم التطبيق، الشعار، ...)</label>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد</button>
    </form>
</div>

<?php admin_footer(); ?>
