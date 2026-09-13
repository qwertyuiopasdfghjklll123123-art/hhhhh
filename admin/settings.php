<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();

    $data = [
        'appName' => trim($_POST['appName'] ?? ''),
        'whatsappNumber' => trim($_POST['whatsappNumber'] ?? ''),
        'officialWebsite' => trim($_POST['officialWebsite'] ?? ''),
        'hideMostRequested' => isset($_POST['hideMostRequested']),
        'welcomeCard' => [
            'enabled' => isset($_POST['welcomeEnabled']),
            'title' => trim($_POST['welcomeTitle'] ?? ''),
            'message' => trim($_POST['welcomeMessage'] ?? ''),
            'buttonText' => trim($_POST['welcomeBtnText'] ?? ''),
            'buttonLink' => trim($_POST['welcomeBtnLink'] ?? '#'),
            'animationSpeed' => (int)($_POST['welcomeSpeed'] ?? 30),
        ],
    ];

    $newLogo = handleFileUpload($_FILES['appLogoFile'] ?? null, 'logo');
    if ($newLogo) {
        $data['appLogo'] = 'uploads/' . $newLogo;
    } elseif (!empty(trim($_POST['appLogoUrl'] ?? ''))) {
        $data['appLogo'] = trim($_POST['appLogoUrl']);
    }

    db_save_settings($pdo, $data);
    admin_flash('success', 'تم حفظ الإعدادات بنجاح.');
    header('Location: settings.php');
    exit;
}

require_once __DIR__ . '/includes/layout.php';
$settings = db_get_settings($pdo);
$welcome = $settings['welcomeCard'] ?? [];

admin_header('الإعدادات', 'settings.php', 'إعدادات التطبيق العامة والبطاقة الترحيبية');
?>

<form method="post" enctype="multipart/form-data">
<?php echo admin_csrf_field(); ?>

<div class="card">
    <h2><i class="fas fa-gear"></i> إعدادات عامة</h2>
    <div class="form-row">
        <div>
            <label>اسم التطبيق</label>
            <input type="text" name="appName" value="<?php echo e($settings['appName'] ?? ''); ?>">
        </div>
        <div>
            <label>رقم واتساب التواصل</label>
            <input type="text" name="whatsappNumber" value="<?php echo e($settings['whatsappNumber'] ?? ''); ?>" placeholder="9665xxxxxxxx">
        </div>
        <div>
            <label>الموقع الرسمي</label>
            <input type="text" name="officialWebsite" value="<?php echo e($settings['officialWebsite'] ?? ''); ?>">
        </div>
    </div>
    <div class="form-row">
        <div>
            <label>رابط شعار التطبيق (اختياري)</label>
            <input type="text" name="appLogoUrl" value="<?php echo (strpos($settings['appLogo'] ?? '', 'uploads/') === 0) ? '' : e($settings['appLogo'] ?? ''); ?>" placeholder="https://...">
        </div>
        <div>
            <label>أو ارفع ملف شعار جديد</label>
            <input type="file" name="appLogoFile" accept="image/*">
        </div>
    </div>
    <div class="checkbox-row">
        <input type="checkbox" id="hideMostRequested" name="hideMostRequested" <?php echo !empty($settings['hideMostRequested']) ? 'checked' : ''; ?>>
        <label for="hideMostRequested">إخفاء بطاقة "الأكثر طلباً" من الصفحة الرئيسية</label>
    </div>
</div>

<div class="card">
    <h2><i class="fas fa-star"></i> البطاقة الترحيبية</h2>
    <div class="checkbox-row">
        <input type="checkbox" id="welcomeEnabled" name="welcomeEnabled" <?php echo !empty($welcome['enabled']) ? 'checked' : ''; ?>>
        <label for="welcomeEnabled">تفعيل البطاقة الترحيبية</label>
    </div>
    <div class="form-row">
        <div>
            <label>العنوان</label>
            <input type="text" name="welcomeTitle" value="<?php echo e($welcome['title'] ?? ''); ?>">
        </div>
        <div>
            <label>سرعة الحركة</label>
            <input type="number" name="welcomeSpeed" value="<?php echo e($welcome['animationSpeed'] ?? 30); ?>">
        </div>
    </div>
    <label>نص الرسالة</label>
    <textarea name="welcomeMessage"><?php echo e($welcome['message'] ?? ''); ?></textarea>
    <div class="form-row">
        <div>
            <label>نص الزر</label>
            <input type="text" name="welcomeBtnText" value="<?php echo e($welcome['buttonText'] ?? ''); ?>">
        </div>
        <div>
            <label>رابط الزر</label>
            <input type="text" name="welcomeBtnLink" value="<?php echo e($welcome['buttonLink'] ?? '#'); ?>">
        </div>
    </div>
</div>

<button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> حفظ الإعدادات</button>
</form>

<?php admin_footer(); ?>
