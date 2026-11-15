<?php
/**
 * واصل — المثبّت التلقائي.
 *
 * افتحه مرة وحدة بعد رفع الملفات: يتأكد من متطلبات الاستضافة، يتصل بقاعدة
 * بيانات MySQL اللي أنشأتها من cPanel، ينشئ كل الجداول، ويكتب config.php.
 * بعد نجاح التثبيت يرفض يشتغل مرة ثانية (لإعادة التثبيت احذف config.php).
 * يُفضّل حذف هذا الملف من الاستضافة بعد التثبيت.
 */

require __DIR__ . '/inc/core.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Baghdad');
ini_set('display_errors', '0');

$timezones = [
    'Asia/Baghdad' => 'العراق — بغداد (GMT+3)',
    'Asia/Riyadh' => 'السعودية — الرياض (GMT+3)',
    'Asia/Kuwait' => 'الكويت (GMT+3)',
    'Asia/Qatar' => 'قطر (GMT+3)',
    'Asia/Amman' => 'الأردن — عمّان (GMT+3)',
    'Asia/Damascus' => 'سوريا — دمشق (GMT+3)',
    'Europe/Istanbul' => 'تركيا — إسطنبول (GMT+3)',
    'Asia/Dubai' => 'الإمارات — دبي (GMT+4)',
    'Asia/Muscat' => 'عُمان — مسقط (GMT+4)',
    'Africa/Cairo' => 'مصر — القاهرة',
    'UTC' => 'UTC',
];

function requirement_rows()
{
    $writableRoot = is_writable(WASEL_ROOT);
    $uploads = WASEL_ROOT . '/uploads';
    if (!is_dir($uploads)) {
        @mkdir($uploads, 0755, true);
    }
    return [
        ['إصدار PHP 7.4 أو أحدث (الحالي ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '7.4.0', '>='), true],
        ['إضافة PDO MySQL (pdo_mysql)', extension_loaded('pdo_mysql'), true],
        ['إضافة JSON', function_exists('json_encode'), true],
        ['إضافة cURL (الإرسال التلقائي عبر خدمة واتساب API)', function_exists('curl_init'), false],
        ['المجلد الرئيسي قابل للكتابة (لإنشاء config.php)', $writableRoot, true],
        ['مجلد uploads قابل للكتابة (شعار الموقع)', is_dir($uploads) && is_writable($uploads), false],
        ['إضافة zip (قراءة ملفات Excel)', class_exists('ZipArchive'), false],
        ['إضافة mbstring', extension_loaded('mbstring'), false],
        ['إضافة GD (أيقونات التطبيق من الشعار)', function_exists('imagecreatetruecolor'), false],
    ];
}

$error = '';
$done = false;
$values = [
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'timezone' => 'Asia/Baghdad',
];

if (wasel_installed()) {
    $state = 'installed';
} else {
    $state = 'form';
    $rows = requirement_rows();
    $blocking = array_filter($rows, function ($r) {
        return $r[2] && !$r[1];
    });
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($values as $k => $v) {
            $values[$k] = trim((string) ($_POST[$k] ?? $v));
        }
        $values['db_pass'] = (string) ($_POST['db_pass'] ?? '');
        if (!isset($timezones[$values['timezone']])) {
            $values['timezone'] = 'Asia/Baghdad';
        }
        if ($blocking) {
            $error = 'الاستضافة ما تلبّي المتطلبات الأساسية بالأعلى — فعّلها من cPanel ثم حاول مرة ثانية.';
        } elseif ($values['db_name'] === '' || $values['db_user'] === '') {
            $error = 'أدخل اسم قاعدة البيانات واسم المستخدم.';
        } else {
            try {
                date_default_timezone_set($values['timezone']);
                $pdo = db_connect($values['db_host'], $values['db_name'], $values['db_user'], $values['db_pass']);
                db($pdo);
                wasel_migrate();
                $config = $values + ['installed_at' => date('c'), 'version' => WASEL_VERSION];
                $php = "<?php\n// أُنشئ تلقائياً بواسطة install.php — فيه بيانات قاعدة البيانات، لا تشاركه مع أحد.\nreturn "
                    . var_export($config, true) . ";\n";
                if (@file_put_contents(WASEL_ROOT . '/config.php', $php, LOCK_EX) === false) {
                    $error = 'تم إنشاء الجداول، لكن تعذّر كتابة ملف config.php — تأكد إن صلاحيات المجلد الرئيسي 755.';
                } else {
                    @chmod(WASEL_ROOT . '/config.php', 0644);
                    $done = true;
                    $state = 'done';
                }
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'Access denied') !== false) {
                    $error = 'رفض الدخول لقاعدة البيانات — تأكد من اسم المستخدم وكلمة المرور، وإن المستخدم مضاف للقاعدة بكل الصلاحيات (cPanel ← MySQL Databases ← Add User To Database).';
                } elseif (stripos($msg, 'Unknown database') !== false) {
                    $error = 'قاعدة البيانات غير موجودة — أنشئها أولاً من cPanel ← MySQL Databases (انتبه إن الاسم يبدأ باسم حسابك مثل user_wasel).';
                } elseif (stripos($msg, 'getaddrinfo') !== false || stripos($msg, 'connect') !== false) {
                    $error = 'تعذّر الوصول لسيرفر قاعدة البيانات — بأغلب استضافات cPanel يكون العنوان localhost.';
                } else {
                    $error = 'خطأ بقاعدة البيانات: ' . $msg;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>تثبيت واصل</title>
<link rel="stylesheet" href="assets/vendor/fonts/plex.css">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'IBM Plex Sans Arabic',system-ui,sans-serif; color:#1A2E35; min-height:100vh;
  background:linear-gradient(135deg,#004b52 0%,#006b73 100%); display:flex; align-items:center; justify-content:center; padding:24px; }
.card { background:#fff; border-radius:20px; padding:36px 32px; max-width:560px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,.25); }
h1 { font-size:21px; font-weight:700; text-align:center; }
.sub { text-align:center; color:#8AA0B0; font-size:13px; margin:4px 0 22px; }
.logo { width:60px; height:60px; margin:0 auto 12px; border-radius:18px; background:linear-gradient(135deg,#006b73,#0A8A94);
  display:flex; align-items:center; justify-content:center; color:#fff; font-size:26px; font-weight:700; box-shadow:0 8px 32px rgba(0,107,115,.35); }
h3 { font-size:14px; color:#006b73; margin:18px 0 8px; }
.req { list-style:none; font-size:13px; }
.req li { display:flex; justify-content:space-between; gap:10px; padding:7px 0; border-bottom:1px solid rgba(0,107,115,.06); }
.ok { color:#059669; font-weight:700; } .bad { color:#DC2626; font-weight:700; } .warn { color:#D97706; font-weight:700; }
label { display:block; font-size:13px; font-weight:700; color:#4A6A78; margin:14px 0 6px; }
input, select { width:100%; height:46px; padding:0 14px; border:2px solid rgba(0,107,115,.08); border-radius:10px; background:#F0F4F8;
  font:inherit; font-size:14px; color:#1A2E35; outline:none; }
input:focus, select:focus { border-color:#006b73; }
.hint { font-size:11.5px; color:#8AA0B0; margin-top:4px; line-height:1.7; }
button, .btn { display:flex; width:100%; height:48px; margin-top:22px; border:0; border-radius:12px; cursor:pointer; text-decoration:none;
  background:linear-gradient(135deg,#006b73,#0A8A94); color:#fff; font:inherit; font-size:15px; font-weight:700;
  align-items:center; justify-content:center; box-shadow:0 4px 16px rgba(0,107,115,.3); }
.error { background:rgba(239,68,68,.08); color:#DC2626; border:1px solid rgba(239,68,68,.25); border-radius:10px; padding:10px 14px; font-size:13px; margin-bottom:10px; line-height:1.7; }
.success { background:rgba(16,185,129,.08); color:#047857; border:1px solid rgba(16,185,129,.25); border-radius:10px; padding:12px 14px; font-size:13px; line-height:1.9; }
code { background:#F0F4F8; padding:1px 6px; border-radius:6px; direction:ltr; display:inline-block; }
ol { padding-right:18px; font-size:13px; line-height:1.9; color:#4A6A78; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">و</div>
  <h1>تثبيت منصة واصل</h1>
<?php if ($state === 'installed'): ?>
  <p class="sub">النظام مثبّت مسبقاً</p>
  <div class="success">ملف <code>config.php</code> موجود، يعني التثبيت تم. لإعادة التثبيت من الصفر احذف <code>config.php</code> من مدير الملفات ثم افتح هذه الصفحة من جديد.<br>للأمان يُفضّل تحذف ملف <code>install.php</code> من الاستضافة.</div>
  <a class="btn" href="index.php">الذهاب لتسجيل الدخول</a>
<?php elseif ($state === 'done'): ?>
  <p class="sub">تم التثبيت بنجاح</p>
  <div class="success">
    تم إنشاء الجداول وكتابة ملف الإعدادات.<br>
    سجّل الدخول بحساب الأدمن الثابت: <code><?= h(ADMIN_EMAIL) ?></code> وكلمة المرور المحددة بملف <code>inc/core.php</code>.
  </div>
  <h3>الخطوات الجاية</h3>
  <ol>
    <li>احذف ملف <code>install.php</code> من الاستضافة (للأمان).</li>
    <li>من لوحة الأدمن ← "الفروع": أنشئ حساب دخول لكل فرع.</li>
    <li>للإرسال التلقائي بدون أي اشتراك: لوحة التحكم ← "البوابة الخاصة" ← اتبع الخطوات (cPanel ← Setup Node.js App — مرة وحدة).</li>
    <li>لوحة التحكم ← "الإرسال التلقائي بالخلفية" ← انسخ الأمر لـ cPanel ← Cron Jobs (كل دقيقة).</li>
    <li>كل فرع يدخل بحسابه ← "حسابي" ← يضيف رقم الواتساب ← "تفعيل الإرسال التلقائي" ← يمسح رمز QR مرة وحدة، وبعدها حملاته تنرسل بنفسها.</li>
  </ol>
  <a class="btn" href="index.php">الذهاب لتسجيل الدخول</a>
<?php else: ?>
  <p class="sub">خطوة وحدة: اربط الموقع بقاعدة بيانات MySQL</p>
  <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <h3>متطلبات الاستضافة</h3>
  <ul class="req">
    <?php foreach ($rows as $r): ?>
      <li><span><?= h($r[0]) ?></span>
        <?php if ($r[1]): ?><span class="ok">✓ متوفر</span>
        <?php elseif ($r[2]): ?><span class="bad">✗ مطلوب</span>
        <?php else: ?><span class="warn">! يُفضّل تفعيله</span><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <form method="post" autocomplete="off">
    <h3>قاعدة البيانات</h3>
    <p class="hint">أنشئها أولاً من cPanel ← MySQL Databases: قاعدة بيانات + مستخدم، ثم "Add User To Database" مع كل الصلاحيات.</p>
    <label>عنوان السيرفر</label>
    <input name="db_host" value="<?= h($values['db_host']) ?>" dir="ltr">
    <p class="hint">بأغلب استضافات cPanel يبقى <code>localhost</code>.</p>
    <label>اسم قاعدة البيانات</label>
    <input name="db_name" value="<?= h($values['db_name']) ?>" dir="ltr" placeholder="cpaneluser_wasel" required>
    <label>اسم المستخدم</label>
    <input name="db_user" value="<?= h($values['db_user']) ?>" dir="ltr" placeholder="cpaneluser_wasel" required>
    <label>كلمة المرور</label>
    <input name="db_pass" type="password" value="" dir="ltr">
    <label>المنطقة الزمنية (لمواعيد الحملات المجدولة)</label>
    <select name="timezone">
      <?php foreach ($timezones as $tz => $label): ?>
        <option value="<?= h($tz) ?>"<?= $tz === $values['timezone'] ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">تثبيت النظام الآن</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
