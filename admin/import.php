<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();
require_once __DIR__ . '/../includes/import.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();
    $action = $_POST['form_action'] ?? '';

    if ($action === 'import_json') {
        $file = $_FILES['json_file'] ?? null;
        // رفع كبير يحتوي مئات الصور الحقيقية يحتاج ذاكرة أكبر بكثير من حجم الملف نفسه عند
        // json_decode (تأكَّد تجريبياً أن ملفاً بحجم ~290 ميجابايت يحتاج ~600 ميجابايت ذاكرة
        // فعلياً) - راجع أيضاً admin/.user.ini لرفع حد حجم الرفع upload_max_filesize نفسه،
        // وهو إعداد لا يمكن لـ ini_set() هنا تغييره لأن الرفع يُعالَج قبل بدء تنفيذ السكربت
        ini_set('memory_limit', '1024M');
        set_time_limit(600);
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
    } elseif ($action === 'import_images_only') {
        $file = $_FILES['images_file'] ?? null;
        ini_set('memory_limit', '1024M');
        set_time_limit(600);
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            admin_flash('error', 'يرجى اختيار ملف الصور الناتج من "ملف الصور فقط" عند التصدير.');
        } else {
            $raw = file_get_contents($file['tmp_name']);
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                admin_flash('error', 'الملف ليس بصيغة JSON صالحة.');
            } else {
                try {
                    $summary = import_images_only_json($pdo, $json);
                    $total = array_sum($summary);
                    $msg = "تم تحديث $total صورة: {$summary['categories']} فئة، {$summary['companies']} شركة، "
                        . "{$summary['products']} منتج، {$summary['services']} خدمة"
                        . ($summary['settings'] ? '، وشعار التطبيق' : '') . '.';
                    admin_flash('success', $msg);
                } catch (Throwable $e) {
                    admin_flash('error', 'فشل استيراد الصور: ' . $e->getMessage());
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
            ini_set('memory_limit', '1024M');
            set_time_limit(300);

            // الملف الأول: نسخة خفيفة بلا صور (كل البيانات النصية) - حجم صغير مضمون الإرسال
            $data = db_export_full_json($pdo, false);
            $tmpFile = tempnam(sys_get_temp_dir(), 'almulla_export_');
            file_put_contents($tmpFile, json_encode($data, JSON_UNESCAPED_UNICODE));
            $filename = 'almulla_export_' . date('Y-m-d_His') . '_no_images.json';
            $appName = $data['settings']['appName'] ?? 'Almulla';
            $lightResult = telegram_send_document($botToken, $chatId, $tmpFile, $filename, "نسخة احتياطية من $appName (1/2: البيانات بلا صور)");
            @unlink($tmpFile);

            // الملف الثاني (أو عدة ملفات): الصور فقط، مجزَّأة بحد أقصى ~45 ميجابايت لكل جزء -
            // بوت تيليجرام يرفض أي ملف أكبر من 50 ميجابايت، وقد يتجاوز حجم كل الصور هذا الحد
            // بكثير في كتالوج حقيقي كبير
            $chunkFiles = db_export_images_only_chunks($pdo);
            $imagesOk = 0;
            $imagesFail = 0;
            $lastError = '';
            $total = count($chunkFiles);
            foreach ($chunkFiles as $i => $chunkFile) {
                $partLabel = $total > 1 ? ($i + 1) . '/' . $total : '1/1';
                $chunkFilename = 'almulla_images_' . date('Y-m-d_His') . '_part' . ($i + 1) . '.json';
                $chunkResult = telegram_send_document($botToken, $chatId, $chunkFile, $chunkFilename, "نسخة احتياطية من $appName (2/2: الصور - جزء $partLabel)");
                if ($chunkResult['success']) {
                    $imagesOk++;
                } else {
                    $imagesFail++;
                    $lastError = $chunkResult['message'];
                }
                @unlink($chunkFile);
            }

            if ($total === 0) {
                $msg = $lightResult['success']
                    ? 'تم إرسال نسخة البيانات بلا صور. لا توجد صور مخزَّنة حالياً لإرسالها.'
                    : 'فشل إرسال نسخة البيانات: ' . $lightResult['message'];
                admin_flash($lightResult['success'] ? 'success' : 'error', $msg);
            } elseif ($lightResult['success'] && $imagesFail === 0) {
                admin_flash('success', "تم إرسال الملفين بنجاح: نسخة البيانات بلا صور، و$imagesOk من $total جزء من ملف الصور.");
            } else {
                $msg = 'حدثت مشاكل أثناء الإرسال: ';
                $msg .= $lightResult['success'] ? 'نسخة البيانات أُرسلت بنجاح. ' : ('فشل إرسال نسخة البيانات: ' . $lightResult['message'] . ' ');
                $msg .= "أجزاء الصور: $imagesOk من $total نجحت" . ($imagesFail > 0 ? ('، آخر خطأ: ' . $lastError) : '.');
                admin_flash('error', $msg);
            }
        }
    }

    header('Location: import');
    exit;
}

require_once __DIR__ . '/includes/layout.php';
$telegramSettings = db_get_telegram_settings($pdo);
$telegramConfigured = !empty($telegramSettings['botToken']) && !empty($telegramSettings['chatId']);
$imageCoverage = db_count_stored_images($pdo);
$imageCoverageByType = db_count_stored_images_by_type($pdo);

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
        <table style="width:100%; margin-top:10px; font-size:0.85rem; border-collapse:collapse;">
            <thead>
                <tr style="text-align:right; border-bottom:1px solid var(--cardBd);">
                    <th style="padding:6px;">النوع</th>
                    <th style="padding:6px;">يشير لصورة</th>
                    <th style="padding:6px;">مخزَّنة فعلياً</th>
                    <th style="padding:6px;">ناقصة</th>
                </tr>
            </thead>
            <tbody>
                <?php $typeLabels = ['categories' => 'الفئات', 'companies' => 'الشركات', 'products' => 'المنتجات', 'services' => 'الخدمات']; ?>
                <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
                    <?php $t = $imageCoverageByType[$typeKey]; ?>
                    <tr style="border-bottom:1px solid var(--cardBd);">
                        <td style="padding:6px;"><?php echo $typeLabel; ?></td>
                        <td style="padding:6px;"><?php echo $t['total']; ?></td>
                        <td style="padding:6px;"><?php echo $t['stored']; ?></td>
                        <td style="padding:6px; <?php echo $t['missing'] > 0 ? 'color:var(--danger); font-weight:700;' : ''; ?>"><?php echo $t['missing']; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
    <a href="export?images_only=1" class="btn btn-outline" style="margin-right:8px;"><i class="fas fa-images"></i> ملف الصور فقط</a>
    <p class="field-hint" style="margin-top:10px;">
        إن توقف تحميل النسخة الكاملة أو ظهر خطأ أثناءه (شائع على بعض الاستضافات المشتركة عند وجود
        صور كثيرة/كبيرة)، نزّل الملفين التاليين بدلاً منها بدل ملف واحد ضخم، وارفعهما بالترتيب في
        قسم الاستيراد أدناه (أولاً "نسخة خفيفة بلا صور"، ثم "ملف الصور فقط"):
        <strong>"نسخة خفيفة بلا صور"</strong> تحفظ كل بياناتك النصية (الأقسام، الشركات، المنتجات،
        الخدمات، المستخدمين، الإعدادات) بحجم صغير جداً مضمون التحميل، و<strong>"ملف الصور
        فقط"</strong> يحتوي محتوى الصور وحدها بلا شيء آخر.
    </p>
    <?php if ($telegramConfigured): ?>
    <form method="post" style="display:inline; margin-right:8px;">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="export_telegram">
        <button type="submit" class="btn btn-outline"><i class="fab fa-telegram"></i> إرسال عبر تيليجرام</button>
    </form>
    <p class="field-hint" style="margin-top:10px;">
        يرسل نسختين إلى محادثتك: أولاً "نسخة بلا صور" (كل البيانات النصية)، ثم ملف "الصور فقط" -
        مقسَّماً تلقائياً لعدة أجزاء بحد أقصى ~45 ميجابايت لكل جزء إن لزم (حد تيليجرام للملفات 50
        ميجابايت). لاستعادتها: ارفع واستورد كل جزء وصلك بالترتيب في قسمي الاستيراد أدناه، كما لو
        نزّلتها من أزرار التصدير أعلاه مباشرة.
    </p>
    <?php else: ?>
    <p class="field-hint" style="margin-top:10px;">
        لإرسال النسخة الاحتياطية مباشرة كملفات إلى تيليجرام بدل التحميل من المتصفح، أدخل رمز البوت
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

<div class="card">
    <h2><i class="fas fa-images"></i> استيراد ملف الصور فقط</h2>
    <p class="field-hint" style="margin-bottom:14px;">
        الخطوة الثانية عند استخدام الملفين المنفصلين بدل ملف واحد ضخم: بعد استيراد "نسخة خفيفة بلا
        صور" أعلاه بنجاح، ارفع هنا ملف "ملف الصور فقط" الذي نزَّلته من قسم التصدير - يُطابَق كل
        صورة بمعرّف العنصر (الفئة/الشركة/المنتج/الخدمة) ويُحدَّث عمود صورته فقط، دون المساس بأي
        بيانات أو عنصر آخر.
    </p>
    <form method="post" enctype="multipart/form-data">
        <?php echo admin_csrf_field(); ?>
        <input type="hidden" name="form_action" value="import_images_only">
        <label>ملف الصور (JSON)</label>
        <input type="file" name="images_file" accept="application/json,.json" required>
        <button type="submit" class="btn btn-primary" style="margin-top:16px;"><i class="fas fa-file-import"></i> استيراد الصور</button>
    </form>
</div>

<?php admin_footer(); ?>
