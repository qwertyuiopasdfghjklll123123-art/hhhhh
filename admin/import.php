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
    }

    header('Location: import');
    exit;
}

require_once __DIR__ . '/includes/layout.php';

admin_header('الاستيراد والتصدير', 'import', 'سحب نسخة احتياطية كاملة أو استعادتها');
?>

<div class="card">
    <h2><i class="fas fa-file-export"></i> تصدير نسخة احتياطية</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        يحمّل ملف JSON يحتوي كل بيانات المتجر (المستخدمين، الفئات، الشركات، المنتجات، الخدمات،
        الإعدادات والإحصائيات) مع كل الصور الفعلية مضمَّنة بداخله. يمكنك رفعه لاحقاً من قسم الاستيراد
        أدناه لاستعادته - في هذا الموقع نفسه أو موقع آخر - في أي وقت تشاء.
    </p>
    <a href="export" class="btn btn-primary"><i class="fas fa-download"></i> تحميل نسخة احتياطية الآن</a>
</div>

<div class="card">
    <h2><i class="fas fa-file-code"></i> استيراد نسخة احتياطية</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        يستورد المستخدمين، الفئات، الشركات، المنتجات، الخدمات، الإعدادات والإحصائيات (والصور المضمّنة
        إن وُجدت) من ملف JSON. يقبل نفس الملف الذي تحمّله من قسم "تصدير نسخة احتياطية" أعلاه
        لاستعادته لاحقاً هنا أو في موقع آخر.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_json">
        <label>ملف النسخة الاحتياطية (JSON)</label>
        <input type="file" name="json_file" accept="application/json,.json" required>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد</button>
    </form>
</div>

<?php admin_footer(); ?>
