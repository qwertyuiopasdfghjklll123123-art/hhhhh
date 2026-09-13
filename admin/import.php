<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();
require_once __DIR__ . '/../includes/import.php';

// يعرض نتيجة مزامنة الصور (تخزين داخل MySQL + مطابقة) كرسالة واحدة واضحة للمستخدم
function admin_flash_image_sync_result(array $summary): void {
    if ($summary['error']) {
        admin_flash('error', $summary['error']);
        return;
    }
    $msg = "تم تخزين {$summary['stored']} صورة داخل قاعدة البيانات مباشرة. ";
    $msg .= "الفئات/الشركات/المنتجات/الخدمات الحالية تحتاج {$summary['referenced_total']} صورة، ";
    $msg .= "المتوفر منها الآن {$summary['matched']}";
    if ($summary['unmatched'] > 0) {
        $msg .= "، وما زال {$summary['unmatched']} ناقصاً (تأكد أن أسماء الملفات مطابقة تماماً).";
        admin_flash('error', $msg);
    } else {
        $msg .= " (اكتملت كل الصور المطلوبة).";
        admin_flash('success', $msg);
    }
}

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
    } elseif ($action === 'import_images_upload') {
        $categoryName = trim($_POST['images_category'] ?? '') ?: 'منتجات مستوردة';
        $companyName = trim($_POST['images_company'] ?? '') ?: 'عام';

        $names = $_FILES['images']['name'] ?? [];
        $tmpNames = $_FILES['images']['tmp_name'] ?? [];
        $errorsArr = $_FILES['images']['error'] ?? [];

        $files = [];
        foreach ($names as $i => $originalName) {
            if (($errorsArr[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmpNames[$i])) continue;
            $files[$originalName] = $tmpNames[$i];
        }

        if (empty($files)) {
            admin_flash('error', 'يرجى اختيار صورة واحدة على الأقل.');
        } else {
            $summary = import_images_as_products($pdo, '', $categoryName, $companyName, $files);
            if ($summary['error']) {
                admin_flash('error', $summary['error']);
            } else {
                admin_flash('success', "تم استيراد {$summary['products']} منتج (صورة) ضمن فئة \"$categoryName\" / شركة \"$companyName\".");
            }
        }
    } elseif ($action === 'import_images_folder') {
        $folderName = basename($_POST['folder_name'] ?? '');
        $categoryName = trim($_POST['images_category'] ?? '') ?: $folderName;
        $companyName = trim($_POST['images_company'] ?? '') ?: $folderName;
        $legacyImportsDir = __DIR__ . '/../logs/legacy-imports';
        $folderPath = $legacyImportsDir . '/' . $folderName;

        $validFolders = array_column(find_legacy_image_folders($legacyImportsDir), 'name');
        if (!in_array($folderName, $validFolders, true)) {
            admin_flash('error', 'المجلد غير موجود.');
        } else {
            $summary = import_images_as_products($pdo, $folderPath, $categoryName, $companyName);
            if ($summary['error']) {
                admin_flash('error', $summary['error']);
            } else {
                admin_flash('success', "تم استيراد {$summary['products']} منتج (صورة) من مجلد \"$folderName\".");
            }
        }
    } elseif ($action === 'sync_images_folder') {
        $folderName = basename($_POST['folder_name'] ?? '');
        $legacyImportsDir = __DIR__ . '/../logs/legacy-imports';
        $folderPath = $legacyImportsDir . '/' . $folderName;

        $validFolders = array_column(find_legacy_image_folders($legacyImportsDir), 'name');
        if (!in_array($folderName, $validFolders, true)) {
            admin_flash('error', 'المجلد غير موجود.');
        } else {
            $summary = import_images_into_mysql($pdo, $folderPath);
            admin_flash_image_sync_result($summary);
        }
    } elseif ($action === 'sync_images_zip_folder') {
        $zipName = basename($_POST['zip_name'] ?? '');
        $legacyImportsDir = __DIR__ . '/../logs/legacy-imports';
        $zipPath = $legacyImportsDir . '/' . $zipName;

        $validZips = array_column(find_legacy_image_zips($legacyImportsDir), 'name');
        if (!in_array($zipName, $validZips, true)) {
            admin_flash('error', 'ملف ZIP غير موجود.');
        } elseif (!class_exists('ZipArchive')) {
            admin_flash('error', 'إضافة PHP Zip غير مفعّلة على السيرفر، لا يمكن فك الضغط تلقائياً.');
        } else {
            $summary = import_images_zip_into_mysql($pdo, $zipPath);
            admin_flash_image_sync_result($summary);
        }
    } elseif ($action === 'sync_images_upload') {
        $names = $_FILES['sync_images']['name'] ?? [];
        $tmpNames = $_FILES['sync_images']['tmp_name'] ?? [];
        $errorsArr = $_FILES['sync_images']['error'] ?? [];

        $uploaded = [];
        foreach ($names as $i => $originalName) {
            if (($errorsArr[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            if (!is_uploaded_file($tmpNames[$i])) continue;
            $uploaded[$originalName] = $tmpNames[$i];
        }

        if (empty($uploaded)) {
            admin_flash('error', 'يرجى اختيار صورة واحدة أو ملف ZIP واحد على الأقل.');
        } elseif (count($uploaded) === 1 && strtolower(pathinfo(array_key_first($uploaded), PATHINFO_EXTENSION)) === 'zip') {
            if (!class_exists('ZipArchive')) {
                admin_flash('error', 'إضافة PHP Zip غير مفعّلة على السيرفر، لا يمكن فك الضغط تلقائياً.');
            } else {
                $summary = import_images_zip_into_mysql($pdo, reset($uploaded));
                admin_flash_image_sync_result($summary);
            }
        } else {
            $summary = import_images_into_mysql($pdo, '', $uploaded);
            admin_flash_image_sync_result($summary);
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
$legacyImportsDir = __DIR__ . '/../logs/legacy-imports';
$imageFolders = find_legacy_image_folders($legacyImportsDir);
$imageZips = find_legacy_image_zips($legacyImportsDir);

admin_header('استيراد بيانات', 'import.php', 'استيراد بيانات من نسخة سابقة من التطبيق أو من نظام آخر');
?>

<div class="card">
    <h2><i class="fas fa-wand-magic-sparkles"></i> مزامنة تلقائية للصور</h2>
    <p class="field-hint">
        ضع أي مجلد صور أو ملف ZIP يحتوي صوراً (مثل مجلد باسم <code>ali</code>) مباشرة داخل
        <code>logs/legacy-imports/</code> على استضافتك (عبر مدير الملفات أو FTP) - بلا حاجة لرفعه هنا
        أو إرساله لأي جهة. سيتم اكتشافه ومزامنته تلقائياً مع أول دخول للوحة التحكم (بمطابقة اسم كل
        صورة مع الاسم المخزَّن لفئة/شركة/منتج/خدمة موجودة مسبقاً)، دون أي زر يدوي، ثم يُنقل تلقائياً
        إلى <code>logs/legacy-imports/_processed/</code> حتى لا تتكرر معالجته. ستظهر رسالة بالنتيجة
        أعلى أي صفحة في اللوحة عند حدوث ذلك.
    </p>
</div>

<?php foreach ($imageFolders as $folder): ?>
<div class="card">
    <h2><i class="fas fa-images"></i> مجلد صور تم اكتشافه: "<?php echo e($folder['name']); ?>"</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        كل صورة داخل هذا المجلد (<code><?php echo e('logs/legacy-imports/' . $folder['name']); ?></code>) ستصبح منتجاً مستقلاً،
        واسم الملف يصبح اسم المنتج.
    </p>
    <form method="post">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_images_folder">
        <input type="hidden" name="folder_name" value="<?php echo e($folder['name']); ?>">
        <div class="form-row">
            <div>
                <label>اسم الفئة الجديدة</label>
                <input type="text" name="images_category" value="<?php echo e($folder['name']); ?>">
            </div>
            <div>
                <label>اسم الشركة الجديدة</label>
                <input type="text" name="images_company" value="<?php echo e($folder['name']); ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد صور هذا المجلد</button>
    </form>
    <hr style="border-color:var(--border,#333);margin:18px 0;">
    <p class="field-hint" style="margin-bottom:14px;">
        أو، إن كانت هذه الصور تخص منتجات/خدمات مستوردة مسبقاً من <code>database.json</code> وتحتاج فقط
        استكمال محتواها الفعلي (لا تريد إنشاء منتجات جديدة): استخدم الزر التالي، سيتم تخزين كل صورة
        داخل قاعدة البيانات مباشرة حسب اسمها فقط.
    </p>
    <form method="post">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="sync_images_folder">
        <input type="hidden" name="folder_name" value="<?php echo e($folder['name']); ?>">
        <button type="submit" class="btn btn-outline"><i class="fas fa-rotate"></i> نسخ صور هذا المجلد لمنتجات موجودة مسبقاً</button>
    </form>
</div>
<?php endforeach; ?>

<?php foreach ($imageZips as $zipInfo): ?>
<div class="card">
    <h2><i class="fas fa-file-zipper"></i> ملف ZIP تم اكتشافه: "<?php echo e($zipInfo['name']); ?>"</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        سيتم فك ضغط <code><?php echo e('logs/legacy-imports/' . $zipInfo['name']); ?></code> وتخزين كل صورة بداخله
        (بأي عمق مجلدات فرعية) داخل قاعدة البيانات مباشرة حسب اسمها، لإكمال صور منتجات/خدمات مستوردة مسبقاً.
        لا يتم إنشاء أي منتج جديد.
    </p>
    <form method="post">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="sync_images_zip_folder">
        <input type="hidden" name="zip_name" value="<?php echo e($zipInfo['name']); ?>">
        <button type="submit" class="btn btn-primary"><i class="fas fa-file-import"></i> فك الضغط ونسخ الصور</button>
    </form>
</div>
<?php endforeach; ?>

<div class="card">
    <h2><i class="fas fa-images"></i> مزامنة صور لمنتجات موجودة مسبقاً (رفع مباشر)</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        لإكمال صور منتجات أو خدمات مستوردة مسبقاً من <code>database.json</code> وتشير للصور بالاسم فقط.
        اختر عدة صور دفعة واحدة، أو ملف <strong>ZIP واحد</strong> يحتوي كل الصور (حتى لو داخل مجلدات فرعية) —
        سيتم التعرف عليه تلقائياً وفك ضغطه. كل صورة تُخزَّن داخل قاعدة البيانات مباشرة حسب اسمها، دون إنشاء أي منتج جديد.
        بعد الانتهاء ستظهر رسالة توضح كم صورة ما زالت ناقصة إن وُجدت.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="sync_images_upload">
        <label>الصور أو ملف ZIP</label>
        <input type="file" name="sync_images[]" accept="image/*,.zip" multiple required>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> نسخ ومزامنة</button>
    </form>
</div>

<div class="card">
    <h2><i class="fas fa-images"></i> استيراد صور كمنتجات (رفع مباشر)</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        اختر عدة صور دفعة واحدة؛ كل صورة تصبح منتجاً مستقلاً واسم الملف (بدون الامتداد) يصبح اسم المنتج.
        مفيد عندما يكون لديك فقط صور بأسماء تدل على المنتجات، بلا قاعدة بيانات مرافقة.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_images_upload">
        <label>الصور</label>
        <input type="file" name="images[]" accept="image/*" multiple required>
        <div class="form-row">
            <div>
                <label>اسم الفئة الجديدة</label>
                <input type="text" name="images_category" value="منتجات مستوردة">
            </div>
            <div>
                <label>اسم الشركة الجديدة</label>
                <input type="text" name="images_company" value="عام">
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد الصور</button>
    </form>
</div>

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
