<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();
require_once __DIR__ . '/../includes/import.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'import_json') {
        $file = $_FILES['json_file'] ?? null;
        ini_set('memory_limit', '512M');
        set_time_limit(300);
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            admin_flash('error', 'يرجى اختيار ملف database.json صالح.');
        } else {
            $raw = file_get_contents($file['tmp_name']);
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                admin_flash('error', 'الملف ليس بصيغة JSON صالحة.');
            } else {
                try {
                    $summary = import_legacy_json($pdo, $json);
                    $msg = "تم الاستيراد: {$summary['users']} مستخدم، {$summary['categories']} فئة.";
                    $cov = db_count_stored_images($pdo);
                    if ($cov['total'] > 0) {
                        $msg .= " الصور: {$cov['stored']} من أصل {$cov['total']} مخزَّنة فعلياً"
                            . ($cov['missing'] > 0 ? "، {$cov['missing']} ناقصة (الملف المستورَد لا يحتوي محتواها الفعلي)." : ' (اكتملت كلها).');
                    }
                    admin_flash('success', $msg);
                } catch (Throwable $e) {
                    db_import_progress_finish($pdo, false, 'فشل الاستيراد: ' . $e->getMessage());
                    admin_flash('error', 'فشل الاستيراد: ' . $e->getMessage());
                }
            }
        }
    } elseif ($action === 'export_telegram') {
        $telegramSettings = db_get_telegram_settings($pdo);
        $botToken = $telegramSettings['botToken'] ?? '';
        $chatId = $telegramSettings['chatId'] ?? '';
        if ($botToken === '' || $chatId === '') {
            admin_flash('error', 'يرجى تعبئة رمز البوت ومعرّف المحادثة في صفحة الإعدادات أولاً.');
        } else {
            ini_set('memory_limit', '512M');
            set_time_limit(120);
            // نسخة خفيفة بلا صور: تتفادى حد حجم ملفات تيليجرام (50 ميجابايت) وحد حجم استعلام
            // MySQL على استضافات مشتركة عند وجود صور كثيرة/كبيرة
            $data = db_export_full_json($pdo, false);
            $tmpFile = tempnam(sys_get_temp_dir(), 'almulla_export_');
            file_put_contents($tmpFile, json_encode($data, JSON_UNESCAPED_UNICODE));
            $filename = 'almulla_export_' . date('Y-m-d_His') . '_no_images.json';
            $result = telegram_send_document($botToken, $chatId, $tmpFile, $filename, 'نسخة احتياطية من ' . ($data['settings']['appName'] ?? 'Almulla'));
            @unlink($tmpFile);
            admin_flash($result['success'] ? 'success' : 'error', $result['message']);
        }
    }

    header('Location: import');
    exit;
}

require_once __DIR__ . '/includes/layout.php';
$telegramSettings = db_get_telegram_settings($pdo);
$telegramConfigured = !empty($telegramSettings['botToken']) && !empty($telegramSettings['chatId']);
$imageCoverage = db_count_stored_images($pdo);

admin_header('الاستيراد والتصدير', 'import', 'سحب نسخة احتياطية كاملة أو استعادتها');
?>

<div class="card">
    <h2><i class="fas fa-images"></i> حالة الصور في قاعدة البيانات</h2>
    <?php if ($imageCoverage['total'] === 0): ?>
        <p class="field-hint">لا توجد عناصر (فئات/شركات/منتجات/خدمات) تشير لأي صورة حالياً.</p>
    <?php else: ?>
        <p class="field-hint">
            <?php echo $imageCoverage['stored']; ?> من أصل <?php echo $imageCoverage['total']; ?>
            صورة مخزَّنة فعلياً داخل قاعدة البيانات (وستُضمَّن عند التصدير الكامل).
            <?php if ($imageCoverage['missing'] > 0): ?>
                <strong style="color:var(--danger);">
                    <?php echo $imageCoverage['missing']; ?> صورة لا تزال ناقصة</strong> - أي عنصر
                يشير لاسم ملف صورة لكن بلا محتوى فعلي مخزَّن سيُصدَّر بلا صورة (وبالتالي يُستورَد
                لاحقاً بلا صورة أيضاً، وليس خللاً في الاستيراد نفسه). لإكمالها: ضع مجلد الصور
                الأصلي (أو ملف ZIP يحتويه) داخل <code>logs/legacy-imports/</code> على استضافتك
                مباشرة، وستتم مزامنته تلقائياً مع أول دخول للوحة التحكم.
            <?php else: ?>
                <span style="color:var(--accent2);">كل الصور المرجَّعة مكتملة.</span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

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
    <?php if ($telegramConfigured): ?>
    <form method="post" style="display:inline; margin-right:8px;">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="export_telegram">
        <button type="submit" class="btn btn-outline"><i class="fab fa-telegram"></i> إرسال عبر تيليجرام</button>
    </form>
    <?php else: ?>
    <p class="field-hint" style="margin-top:10px;">
        لإرسال النسخة الاحتياطية مباشرة كملف إلى تيليجرام بدل التحميل من المتصفح، أدخل رمز البوت
        ومعرّف المحادثة في <a href="settings">صفحة الإعدادات</a> أولاً.
    </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2><i class="fas fa-file-code"></i> استيراد نسخة احتياطية</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        يستورد المستخدمين، الفئات، الشركات، المنتجات، الخدمات، الإعدادات والإحصائيات (والصور المضمّنة
        إن وُجدت) من ملف JSON. يقبل نفس الملف الذي تحمّله من قسم "تصدير نسخة احتياطية" أعلاه
        لاستعادته لاحقاً هنا أو في موقع آخر.
    </p>
    <form method="post" enctype="multipart/form-data" id="importJsonForm" data-progress-url="import_progress">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_json">
        <label>ملف النسخة الاحتياطية (JSON)</label>
        <input type="file" name="json_file" accept="application/json,.json" required>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد</button>
    </form>
    <div id="importProgressBox" style="display:none; margin-top:16px;">
        <div style="background:var(--bg2); border-radius:10px; height:22px; overflow:hidden;">
            <div id="importProgressBar" style="background:var(--accent); height:100%; width:0%; transition:width 0.3s;"></div>
        </div>
        <p id="importProgressLabel" class="field-hint" style="margin-top:8px;"></p>
    </div>
</div>

<?php admin_footer(); ?>
