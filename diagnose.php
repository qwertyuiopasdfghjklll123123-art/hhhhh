<?php
/* ======================================================================
   أداة تشخيص شاملة — للقراءة فقط، لا تعدّل أي شيء في قاعدة البيانات أو
   الملفات إطلاقاً. تفحص السيرفر وقاعدة البيانات والملفات وتقارن اكتمالها
   مع ما يحتاجه النظام، وتُظهر تقريراً واضحاً بكل خلل موجود وسبب حدوثه.
   آمنة للتشغيل في أي وقت وبأي عدد من المرات على موقع مباشر (Production).
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/** كل عنصر: ['group' => قسم, 'label' => وصف عربي, 'status' => ok|warn|fail, 'detail' => تفصيل] */
$results = [];
$add = function (string $group, string $label, string $status, string $detail = '') use (&$results) {
    $results[] = ['group' => $group, 'label' => $label, 'status' => $status, 'detail' => $detail];
};

/* ---------------------- 1) متطلبات PHP ---------------------- */
$add('متطلبات السيرفر', 'إصدار PHP (يلزم 8.0 فأعلى)', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'fail', 'الإصدار الحالي: ' . PHP_VERSION);
$add('متطلبات السيرفر', 'امتداد PDO', extension_loaded('pdo') ? 'ok' : 'fail');
$add('متطلبات السيرفر', 'امتداد PDO MySQL', extension_loaded('pdo_mysql') ? 'ok' : 'fail');
$add('متطلبات السيرفر', 'امتداد GD (لأيقونات PWA)', extension_loaded('gd') ? 'ok' : 'warn', extension_loaded('gd') ? '' : 'غير إلزامي — الأيقونات الحالية جاهزة مسبقاً في مجلد icons، لن يحتاجها النظام إلا لو أردت توليد أيقونات جديدة');
$add('متطلبات السيرفر', 'صلاحية الكتابة في المجلد الحالي', is_writable(__DIR__) ? 'ok' : 'fail', 'مطلوبة لإنشاء config.php ومجلد uploads');

/* ---------------------- 2) ملفات النظام ---------------------- */
$expectedFiles = [
    'index.php' => 'نافذة الموظف',
    'hr.php' => 'لوحة الموارد البشرية',
    'branch.php' => 'لوحة مدير الفرع',
    'general.php' => 'لوحة المسؤول العام',
    'install.php' => 'المثبّت',
    'migrate.php' => 'أداة تحديث قاعدة البيانات',
    'manifest.php' => 'ملف تثبيت PWA',
    'sw.js' => 'خدمة العامل (PWA)',
    '.htaccess' => 'الروابط النظيفة (/hr /branch /general)',
    'icons/icon-192.png' => 'أيقونة PWA الافتراضية (192)',
    'icons/icon-512.png' => 'أيقونة PWA الافتراضية (512)',
];
foreach ($expectedFiles as $file => $desc) {
    $exists = file_exists(__DIR__ . '/' . $file);
    $add('ملفات النظام', $file . ' — ' . $desc, $exists ? 'ok' : 'fail', $exists ? '' : 'الملف غير موجود على السيرفر، تأكد من رفعه بنفس اسمه ومساره');
}

/* ---------------------- 3) config.php والاتصال بقاعدة البيانات ---------------------- */
$configExists = file_exists(__DIR__ . '/config.php');
$add('قاعدة البيانات', 'وجود ملف config.php', $configExists ? 'ok' : 'fail', $configExists ? '' : 'لم يتم تثبيت النظام بعد — افتح install.php أولاً');

$pdo = null;
if ($configExists) {
    require_once __DIR__ . '/config.php';
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $add('قاعدة البيانات', 'الاتصال بقاعدة البيانات', 'ok', 'DB_NAME: ' . DB_NAME . ' — DB_HOST: ' . DB_HOST);
    } catch (Throwable $e) {
        $add('قاعدة البيانات', 'الاتصال بقاعدة البيانات', 'fail', 'تعذر الاتصال: ' . $e->getMessage() . ' — تحقق من بيانات config.php');
    }
}

/* ---------------------- 4) اكتمال الجداول والأعمدة ---------------------- */
if ($pdo) {
    function column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
    function table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    $coreTables = ['settings', 'branches', 'employees', 'users', 'attendance', 'payroll', 'requests',
        'daily_ledger', 'delegations', 'daily_briefs', 'exchange_rate_history', 'notifications',
        'payroll_windows', 'payroll_adjustments'];
    foreach ($coreTables as $t) {
        $exists = table_exists($pdo, $t);
        $add('جداول قاعدة البيانات', 'جدول ' . $t, $exists ? 'ok' : 'fail', $exists ? '' : 'الجدول غير موجود — شغّل /migrate لإنشائه');
    }

    // نفس قائمة التحديثات المعتمدة في migrate.php — لكن هنا للعرض فقط بدون أي تنفيذ
    $pendingChecks = [
        ['label' => 'اسم الأم لجدول الموظفين', 'ok' => fn() => column_exists($pdo, 'employees', 'mother_name')],
        ['label' => 'أعمدة الشفت (النوع، البداية، النهاية) لجدول الموظفين', 'ok' => fn() => column_exists($pdo, 'employees', 'shift_type')],
        ['label' => 'دور "المسؤول العام" في جدول المستخدمين', 'ok' => function () use ($pdo) {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            return $col && strpos($col['Type'], 'general_manager') !== false;
        }],
        ['label' => 'مرحلة موافقة مدير الفرع على الطلبات', 'ok' => fn() => column_exists($pdo, 'requests', 'branch_reviewed_by')],
        ['label' => 'أعمدة السلفة (الخصم الشهري والرصيد المتبقي)', 'ok' => fn() => column_exists($pdo, 'requests', 'approved_monthly_deduction')],
        ['label' => 'عدد المسافرين ومرحلة اعتماد المسؤول العام للإيجاز', 'ok' => fn() => column_exists($pdo, 'daily_briefs', 'travelers_count')],
        ['label' => 'إعدادات خصم التأخير', 'ok' => fn() => column_exists($pdo, 'settings', 'late_grace_minutes')],
        ['label' => 'جدول سجل تعديلات الرواتب', 'ok' => fn() => table_exists($pdo, 'payroll_adjustments')],
        ['label' => 'رقم هاتف الموظف', 'ok' => fn() => column_exists($pdo, 'employees', 'phone_number')],
        ['label' => 'صورة الفرع', 'ok' => fn() => column_exists($pdo, 'branches', 'photo')],
        ['label' => 'دور "المساهم" واسم العرض في جدول المستخدمين', 'ok' => function () use ($pdo) {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            return $col && strpos($col['Type'], 'shareholder') !== false && column_exists($pdo, 'users', 'display_name');
        }],
        ['label' => 'جدول نوافذ صرف الرواتب الشهرية', 'ok' => fn() => table_exists($pdo, 'payroll_windows')],
        ['label' => 'شعار الشركة (company_logo)', 'ok' => fn() => column_exists($pdo, 'settings', 'company_logo')],
    ];
    $pendingCount = 0;
    foreach ($pendingChecks as $check) {
        try {
            $ok = (bool) $check['ok']();
        } catch (Throwable $e) {
            $ok = false;
        }
        if (!$ok) $pendingCount++;
        $add('اكتمال هيكل قاعدة البيانات', $check['label'], $ok ? 'ok' : 'warn', $ok ? '' : 'تحديث معلّق — شغّل /migrate لإضافته تلقائياً وبأمان');
    }

    /* ---------------------- 5) سلامة البيانات الأساسية ---------------------- */
    try {
        $hrCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='hr' AND status='active'")->fetchColumn();
        $add('سلامة البيانات', 'وجود حساب موارد بشرية (HR) فعّال واحد على الأقل', $hrCount > 0 ? 'ok' : 'fail', $hrCount > 0 ? "العدد: $hrCount" : 'لا يوجد أي حساب HR فعّال — لن يتمكن أحد من الدخول لإدارة النظام');
    } catch (Throwable $e) {}
    try {
        $gmCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='general_manager' AND status='active'")->fetchColumn();
        $add('سلامة البيانات', 'وجود حساب مسؤول عام فعّال واحد على الأقل', $gmCount > 0 ? 'ok' : 'warn', $gmCount > 0 ? "العدد: $gmCount" : 'لا يوجد حساب مسؤول عام فعّال — لن تعمل صلاحيات اعتماد الإيجاز والرواتب النهائية');
    } catch (Throwable $e) {}
    try {
        $branchCount = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE status='active'")->fetchColumn();
        $add('سلامة البيانات', 'وجود فرع فعّال واحد على الأقل', $branchCount > 0 ? 'ok' : 'warn', "العدد: $branchCount");
    } catch (Throwable $e) {}
    try {
        $settingsCount = (int) $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        $add('سلامة البيانات', 'وجود صف إعدادات الشركة (settings)', $settingsCount > 0 ? 'ok' : 'fail', $settingsCount > 0 ? '' : 'بدون هذا الصف ستفشل شاشات كثيرة تعتمد على وقت الدوام وسعر الصرف');
    } catch (Throwable $e) {}
    try {
        $orphanEmp = (int) $pdo->query("SELECT COUNT(*) FROM employees e LEFT JOIN branches b ON b.id=e.branch_id WHERE b.id IS NULL")->fetchColumn();
        $add('سلامة البيانات', 'عدم وجود موظفين مرتبطين بفرع محذوف', $orphanEmp === 0 ? 'ok' : 'fail', $orphanEmp === 0 ? '' : "$orphanEmp موظف مرتبط بفرع غير موجود — سبب محتمل لأخطاء عند فتح ملفهم");
    } catch (Throwable $e) {}
    try {
        $orphanUser = (int) $pdo->query("SELECT COUNT(*) FROM users u WHERE u.employee_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM employees e WHERE e.id=u.employee_id)")->fetchColumn();
        $add('سلامة البيانات', 'عدم وجود حسابات دخول مرتبطة بموظف محذوف', $orphanUser === 0 ? 'ok' : 'fail', $orphanUser === 0 ? '' : "$orphanUser حساب دخول بلا موظف مرتبط — سيفشل تسجيل الدخول لهذه الحسابات");
    } catch (Throwable $e) {}
}

/* ---------------------- 6) مجلدات الرفع ---------------------- */
$uploadDirs = ['uploads', 'uploads/photos', 'uploads/documents', 'uploads/ledger', 'uploads/branding', 'uploads/sessions'];
foreach ($uploadDirs as $dir) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        $add('مجلدات الرفع', $dir, 'warn', 'غير موجود بعد — يُنشأ تلقائياً عند أول عملية رفع، لا حاجة لإنشائه يدوياً إن كان المجلد الجذر قابلاً للكتابة');
        continue;
    }
    $add('مجلدات الرفع', $dir, is_writable($path) ? 'ok' : 'fail', is_writable($path) ? '' : 'المجلد موجود لكن غير قابل للكتابة — لن تُحفظ الصور والمرفقات الجديدة');
}

/* ---------------------- الملخص ---------------------- */
$failCount = count(array_filter($results, fn($r) => $r['status'] === 'fail'));
$warnCount = count(array_filter($results, fn($r) => $r['status'] === 'warn'));
$okCount = count(array_filter($results, fn($r) => $r['status'] === 'ok'));

$grouped = [];
foreach ($results as $r) {
    $grouped[$r['group']][] = $r;
}

/* ---------------------- سجل الأخطاء الأخيرة (نظام تتبع المشاكل) ---------------------- */
$recentErrors = [];
$appLabels = ['employee' => 'نافذة الموظف', 'hr' => 'الموارد البشرية', 'branch' => 'مدير الفرع', 'general' => 'المسؤول العام'];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT app, action, user_role, message, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS date
            FROM error_log ORDER BY created_at DESC LIMIT 30");
        $recentErrors = $stmt->fetchAll();
    } catch (Throwable $e) {
        // جدول error_log غير موجود بعد (لم يُشغَّل migrate.php) — لا داعي لإظهار هذا كخطأ منفصل، القسم أعلاه يغطيه
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تشخيص النظام - شركة الصوى للصرافة</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, 'Segoe UI', Tahoma, Arial, sans-serif;
        background: #f0f4f4;
        color: #1a2e2e;
        padding: 24px 16px 60px;
    }
    .wrap { max-width: 780px; margin: 0 auto; }
    h1 { font-size: 22px; margin-bottom: 4px; }
    .sub { color: #5a7373; font-size: 13px; margin-bottom: 20px; }
    .summary {
        display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap;
    }
    .summary .box {
        flex: 1; min-width: 140px; background: #fff; border-radius: 14px; padding: 16px;
        text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        border-top: 4px solid #ccc;
    }
    .summary .box.ok { border-top-color: #059669; }
    .summary .box.warn { border-top-color: #D97706; }
    .summary .box.fail { border-top-color: #DC2626; }
    .summary .box .num { font-size: 28px; font-weight: 800; }
    .summary .box .label { font-size: 12px; color: #5a7373; margin-top: 4px; }
    .banner {
        padding: 14px 18px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; font-weight: 700;
    }
    .banner.good { background: rgba(5,150,105,0.1); color: #059669; }
    .banner.bad { background: rgba(220,38,38,0.1); color: #DC2626; }
    .banner.mid { background: rgba(217,119,6,0.1); color: #D97706; }
    .group { background: #fff; border-radius: 14px; margin-bottom: 16px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
    .group h2 { font-size: 14px; padding: 12px 16px; background: #006b73; color: #fff; }
    .row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 16px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
    .row:last-child { border-bottom: none; }
    .row .icon { flex-shrink: 0; width: 20px; text-align: center; font-weight: 900; }
    .row.ok .icon { color: #059669; }
    .row.warn .icon { color: #D97706; }
    .row.fail .icon { color: #DC2626; }
    .row .content { flex: 1; }
    .row .label { font-weight: 600; }
    .row .detail { color: #5a7373; font-size: 11.5px; margin-top: 2px; }
    .links { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }
    .links a {
        background: #006b73; color: #fff; text-decoration: none; padding: 10px 20px;
        border-radius: 999px; font-size: 13px; font-weight: 700;
    }
    .links a.secondary { background: #c99a3d; }
    .refresh { background: none; border: 1.5px solid #006b73; color: #006b73; }
</style>
</head>
<body>
<div class="wrap">
    <h1>🩺 تشخيص النظام</h1>
    <div class="sub">فحص للقراءة فقط — لا يعدّل أو يحذف أي شيء. يمكنك تشغيله في أي وقت.</div>

    <div class="summary">
        <div class="box ok"><div class="num"><?= $okCount ?></div><div class="label">سليم</div></div>
        <div class="box warn"><div class="num"><?= $warnCount ?></div><div class="label">تنبيه</div></div>
        <div class="box fail"><div class="num"><?= $failCount ?></div><div class="label">مشكلة</div></div>
    </div>

    <?php if ($failCount > 0): ?>
        <div class="banner bad">⚠️ توجد <?= $failCount ?> مشكلة يجب حلها — راجع البنود الحمراء أدناه.</div>
    <?php elseif ($warnCount > 0): ?>
        <div class="banner mid">ℹ️ لا توجد مشاكل حرجة، لكن توجد <?= $warnCount ?> تحديثات/تنبيهات معلّقة — راجعها أدناه (غالباً يكفي تشغيل /migrate).</div>
    <?php else: ?>
        <div class="banner good">✅ النظام سليم بالكامل، لا توجد أي مشاكل.</div>
    <?php endif; ?>

    <?php foreach ($grouped as $groupName => $items): ?>
        <div class="group">
            <h2><?= h($groupName) ?></h2>
            <?php foreach ($items as $item): ?>
                <div class="row <?= $item['status'] ?>">
                    <div class="icon"><?= $item['status'] === 'ok' ? '✓' : ($item['status'] === 'warn' ? '!' : '✕') ?></div>
                    <div class="content">
                        <div class="label"><?= h($item['label']) ?></div>
                        <?php if ($item['detail']): ?><div class="detail"><?= h($item['detail']) ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="group">
        <h2>🕵️ سجل الأخطاء الأخيرة (نظام تتبع المشاكل)</h2>
        <?php if (empty($recentErrors)): ?>
            <div class="row ok"><div class="icon">✓</div><div class="content"><div class="label">لا توجد أي أخطاء مسجّلة</div><div class="detail">كل عملية فاشلة (زر لا يستجيب، اعتماد فشل، إلخ) تُسجَّل هنا تلقائياً فور حدوثها</div></div></div>
        <?php else: ?>
            <?php foreach ($recentErrors as $err): ?>
                <div class="row fail">
                    <div class="icon">✕</div>
                    <div class="content">
                        <div class="label"><?= h($appLabels[$err['app']] ?? $err['app']) ?> — <?= h($err['action']) ?> <span style="font-weight:400;color:#5a7373;">(<?= h($err['user_role'] ?? '-') ?>)</span></div>
                        <div class="detail"><?= h($err['date']) ?> — <?= h($err['message']) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="links">
        <a href="/diagnose" class="refresh">🔄 إعادة الفحص</a>
        <a href="/migrate" class="secondary">تحديث قاعدة البيانات</a>
        <a href="/hr">لوحة HR</a>
    </div>
</div>
</body>
</html>
