<?php
declare(strict_types=1);

/* ============================================================
   طبقة التخزين المشتركة — MySQL فقط (بلا رجوع لملفات JSON)
   ============================================================
   يشترك بهذا الملف index.php وinstall.php وmanifest.php حتى تبقى
   قراءة/كتابة البيانات (وبيانات اتصال MySQL نفسها) متطابقة تماماً
   من أي نقطة دخول شُغّلت. كل "مجموعة" (stores, products, users...)
   تبقى مصفوفة PHP عادية كما كانت دائماً — بقية كود التطبيق لا يعرف
   ولا يهمه أين تُخزَّن فعلياً؛ فقط db_read()/db_write() يعرفان ذلك.

   بطلب صريح من صاحب الموقع: لا يوجد رجوع صامت لملفات JSON إن انقطع
   اتصال MySQL أو لم يُضبط بعد — الموقع يعتمد على MySQL حصراً، ويظهر
   صفحة واضحة توجّه لإعداد الاتصال بدل العمل ببيانات قديمة أو التوقف
   بخطأ PHP غامض. ملفات JSON القديمة (إن وُجدت من نسخة سابقة) تُستورد
   مرة واحدة فقط عند أول اتصال ناجح، لكنها لا تُستخدم بعد ذلك أبداً. */

if (!defined('APP_NAME')) define('APP_NAME', 'سوق');
if (!defined('DATA_DIR')) define('DATA_DIR', dirname(__DIR__) . '/data');
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);

/* بيانات اتصال MySQL تُقرأ من ملف مستقل مباشرة (لا عبر db_read) لأن db_read
   نفسها تعتمد على معرفة هذه البيانات أولاً — مشكلة "البيضة والدجاجة".
   تُضبط من install.php أو لوحة الأدمن. */
if (!defined('DB_CONFIG_FILE')) define('DB_CONFIG_FILE', DATA_DIR . '/db_config.json');
$__dbConfig = file_exists(DB_CONFIG_FILE) ? json_decode((string)file_get_contents(DB_CONFIG_FILE), true) : null;
$__dbConfig = is_array($__dbConfig) ? $__dbConfig : [];
if (!defined('DB_HOST')) define('DB_HOST', (string)($__dbConfig['host'] ?? ''));
if (!defined('DB_NAME')) define('DB_NAME', (string)($__dbConfig['name'] ?? ''));
if (!defined('DB_USER')) define('DB_USER', (string)($__dbConfig['user'] ?? ''));
if (!defined('DB_PASS')) define('DB_PASS', (string)($__dbConfig['pass'] ?? ''));
unset($__dbConfig);

function db_connect(): ?mysqli {
    static $conn = null;
    static $tried = false;
    if ($tried) return $conn;
    $tried = true;
    if (DB_HOST === '' || DB_NAME === '' || DB_USER === '' || !class_exists('mysqli')) return null;
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$c) return null;
    $c->set_charset('utf8mb4');
    $conn = $c;
    /* CREATE TABLE IF NOT EXISTS وفحص الهجرة كلاهما استعلامان زائدان بلا
       فائدة بعد أول طلب ناجح بحياة الموقع (الجدول لن يختفي ولن يعود فارغاً).
       نتحقق من ملف محلي بدل تكرارهما مع كل طلب — يوفّر رحلة شبكة كاملة إلى
       MySQL في كل صفحة يفتحها أي زائر، وهذا محسوس خصوصاً إن كانت قاعدة
       البيانات على خادم منفصل (استضافة MySQL خارجية). */
    $readyFile = DATA_DIR . '/.mysql_ready';
    if (!file_exists($readyFile)) {
        $c->query("CREATE TABLE IF NOT EXISTS kv_store (name VARCHAR(64) PRIMARY KEY, data LONGTEXT NOT NULL, updated_at INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db_migrate_json_to_mysql($conn);
        @file_put_contents($readyFile, (string)time());
    }
    return $conn;
}

/* هجرة تلقائية لمرة واحدة: إن كان جدول kv_store فارغاً تماماً ووُجدت ملفات
   JSON محلية سابقة، تُستورد كلها حتى لا يخسر من يفعّل MySQL بيانات موقعه
   القائم. لا تُكرَّر بعد أول مرة لأن الجدول لن يعود فارغاً. */
function db_migrate_json_to_mysql(mysqli $conn): void {
    $res = $conn->query("SELECT COUNT(*) AS c FROM kv_store");
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row || (int)$row['c'] > 0) return;
    $files = glob(DATA_DIR . '/*.json');
    if (!$files) return;
    $stmt = $conn->prepare("INSERT INTO kv_store (name, data, updated_at) VALUES (?, ?, ?)");
    $now = time();
    foreach ($files as $f) {
        $name = basename($f, '.json');
        if ($name === 'db_config') continue; // بيانات اتصال، ليست مجموعة بيانات تطبيق
        $json = file_get_contents($f);
        if ($json === false || json_decode($json) === null) continue;
        $stmt->bind_param('ssi', $name, $json, $now);
        $stmt->execute();
    }
    $stmt->close();
}

/* يوقف الطلب بصفحة عربية واضحة بدل السماح لصفحة أن تُعرض ببيانات ناقصة أو
   بخطأ PHP خام — يُستدعى فقط حين يفشل db_connect() فعلاً (غير مضبوط أو
   الاتصال متعذّر)، أي أن db_read()/db_write() لا تصلان هنا إطلاقاً في
   التشغيل العادي بموقع مضبوط بشكل صحيح. */
function db_fail_no_connection(): void {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $showInstallLink = $script !== 'install.php';
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>قاعدة البيانات غير متصلة</title>
<style>
body{font-family:'Segoe UI',Tahoma,sans-serif;background:#f8f6f3;margin:0;padding:24px;min-height:100vh;display:flex;align-items:center;justify-content:center}
.box{max-width:420px;width:100%;background:#fff;border-radius:18px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.08);text-align:center}
h1{font-size:1.05rem;margin:0 0 12px}
p{font-size:.82rem;color:#666;line-height:1.9;margin:0 0 18px}
a.btn{display:inline-block;text-decoration:none;background:#f2b100;color:#1a1a2e;font-weight:800;padding:12px 24px;border-radius:10px;font-size:.85rem}
</style>
</head>
<body>
<div class="box">
<h1>⚠️ تعذّر الاتصال بقاعدة بيانات MySQL</h1>
<p>هذا الموقع يعتمد على MySQL حصراً لتخزين بياناته. تحقق من بيانات الاتصال (المضيف، اسم القاعدة، اسم المستخدم، كلمة المرور) من لوحة استضافتك ثم أعد ضبطها.</p>
<?php if ($showInstallLink): ?><a class="btn" href="install.php">إعداد الاتصال</a><?php endif; ?>
</div>
</body>
</html>
    <?php
    exit;
}

/* كثير من الدوال (find_store/find_product وغيرها) تنادي db_read() لنفس
   المجموعة مراراً بنفس الطلب (مثلاً لكل منتج بقائمة). تخزين مؤقت بالذاكرة
   لعمر الطلب فقط (لا يُحفظ بين الطلبات) يمنع تكرار الاستعلام/الترميز نفسه
   عشرات المرات، وهذا وحده أثّر بشكل ملموس على سرعة الصفحات المزدحمة
   بالمنتجات. db_write() يحدّث هذا التخزين فوراً فلا تُقرأ بيانات قديمة. */
function &db_cache(): array {
    static $cache = [];
    return $cache;
}

function db_read(string $name, array $default = []): array {
    $cache = &db_cache();
    if (array_key_exists($name, $cache)) return $cache[$name] ?? $default;
    $conn = db_connect();
    if (!$conn) db_fail_no_connection();
    $stmt = $conn->prepare("SELECT data FROM kv_store WHERE name = ?");
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $stmt->bind_result($json);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) { $cache[$name] = null; return $default; }
    $data = json_decode($json, true);
    $cache[$name] = is_array($data) ? $data : null;
    return $cache[$name] ?? $default;
}

function db_write(string $name, array $data): void {
    $conn = db_connect();
    if (!$conn) db_fail_no_connection();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $now = time();
    $stmt = $conn->prepare("INSERT INTO kv_store (name, data, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)");
    $stmt->bind_param('ssi', $name, $json, $now);
    $stmt->execute();
    $stmt->close();
    $cache = &db_cache();
    $cache[$name] = $data;
}

/* يقرأ كل مجموعات البيانات كما هي مخزَّنة فعلياً بـkv_store مباشرة (بلا
   الاعتماد على قائمة أسماء ثابتة بالكود) — تُستخدم للنسخ الاحتياطي الكامل. */
function export_all_data(): array {
    $conn = db_connect();
    if (!$conn) db_fail_no_connection();
    $out = [];
    $res = $conn->query("SELECT name, data FROM kv_store");
    while ($res && ($row = $res->fetch_assoc())) {
        $data = json_decode($row['data'], true);
        if (is_array($data)) $out[$row['name']] = $data;
    }
    return $out;
}

/* يستعيد نسخة احتياطية سابقة (من export_all_data) بالكامل — يستبدل كل
   مجموعة بيانات موجودة بما بالنسخة. يتحقق من صحة كل اسم مجموعة قبل الكتابة
   حتى لا يُكتب اسم مصفوفة غريب كصف بجدول kv_store. */
function import_all_data(array $collections): int {
    $count = 0;
    foreach ($collections as $name => $data) {
        if (!is_string($name) || !preg_match('/^[a-z_]{1,64}$/', $name)) continue;
        if (!is_array($data)) continue;
        db_write($name, $data);
        $count++;
    }
    return $count;
}

/* اختبار بيانات اتصال قبل حفظها فعلياً (من install.php أو لوحة الأدمن) —
   يمنع حفظ بيانات خاطئة تقفل صاحب الموقع عن بياناته. */
function db_test_connection(string $host, string $name, string $user, string $pass): ?string {
    if (!class_exists('mysqli')) return 'امتداد mysqli غير مفعّل على هذه الاستضافة';
    mysqli_report(MYSQLI_REPORT_OFF);
    $c = @mysqli_connect($host, $user, $pass, $name);
    if (!$c) return 'تعذّر الاتصال: ' . (mysqli_connect_error() ?: 'تحقق من بيانات الدخول');
    $c->close();
    return null;
}

/* إعدادات الموقع العامة (اسم/شعار/تصنيفات...) — تُقرأ من نفس طبقة التخزين
   أعلاه، لذا تبقى صحيحة سواء استُخدمت من index.php أو install.php أو
   manifest.php وسواء كان التخزين JSON أو MySQL. */
function get_settings(): array {
    $defaults = ['monthly_fee'=>0, 'categories'=>[], 'payment_methods'=>[], 'site_name'=>APP_NAME, 'site_logo'=>'', 'ai_api_key'=>'', 'google_client_id'=>'', 'coupons'=>[], 'telegram_bot_token'=>'', 'telegram_chat_id'=>'', 'last_backup_at'=>0];
    return db_read('settings', $defaults) + $defaults;
}
function get_categories(): array { return get_settings()['categories'] ?? []; }
function site_name(): string { $n = trim((string)(get_settings()['site_name'] ?? '')); return $n !== '' ? $n : APP_NAME; }
function site_logo_url(): ?string { $l = get_settings()['site_logo'] ?? ''; return $l ? $l : null; }
