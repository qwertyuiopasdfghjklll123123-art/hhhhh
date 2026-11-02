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
    } elseif ($action === 'import_sql_dump') {
        $file = $_FILES['sql_file'] ?? null;
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            admin_flash('error', 'يرجى اختيار ملف SQL صالح.');
        } else {
            $summary = import_raw_sql_dump($pdo, $file['tmp_name']);
            if ($summary['error']) {
                admin_flash('error', $summary['error']);
            } else {
                $tablesList = implode('، ', $summary['tables']);
                $msg = "تم تنفيذ {$summary['statements']} جملة SQL بنجاح.";
                if ($tablesList !== '') $msg .= " الجداول التي استُبدلت بالكامل بمحتوى الملف: $tablesList.";
                admin_flash('success', $msg);
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
    <a href="export?images=0" class="btn btn-outline" style="margin-right:8px;"><i class="fas fa-download"></i> نسخة خفيفة بلا صور</a>
    <p class="field-hint" style="margin-top:10px;">
        إن توقف تحميل النسخة الكاملة أو ظهر خطأ أثناءه (شائع على بعض الاستضافات المشتركة عند وجود
        صور كثيرة/كبيرة)، جرّب "نسخة خفيفة بلا صور" - تحفظ كل بياناتك النصية (الأقسام، الشركات،
        المنتجات، الخدمات، المستخدمين، الإعدادات) بحجم صغير جداً مضمون التحميل، فقط بدون محتوى
        الصور نفسها.
    </p>
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

<div class="card">
    <h2><i class="fas fa-database"></i> استيراد ملف SQL خام (نسخة phpMyAdmin / mysqldump)</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        لاستيراد ملف <code>.sql</code> مُصدَّر مباشرة من phpMyAdmin أو <code>mysqldump</code> - بدل
        ملف JSON. يُعالَج الملف تلقائياً حتى لا تظهر رسالة "الجدول موجود مسبقاً" مهما كانت حالة
        قاعدة بياناتك الحالية.
    </p>
    <p class="field-hint" style="margin-bottom:14px; color:var(--rd,#e74c3c);">
        ⚠️ <strong>تنبيه:</strong> أي جدول مذكور داخل هذا الملف (مثل <code>products</code> أو
        <code>categories</code>) <strong>يُستبدَل بالكامل</strong> بمحتوى الملف - بياناته الحالية في
        هذا الجدول تُحذف ويحل محلها ما في الملف. الجداول غير المذكورة في الملف لا تتأثر إطلاقاً.
        استخدمه فقط إن كنت متأكداً أن هذا الملف يحتوي كل ما تحتاجه.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_sql_dump">
        <label>ملف SQL</label>
        <input type="file" name="sql_file" accept=".sql" required>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد</button>
    </form>
</div>

<?php admin_footer(); ?>
