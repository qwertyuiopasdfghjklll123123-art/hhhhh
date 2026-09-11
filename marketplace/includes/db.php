<?php
declare(strict_types=1);

/* ============================================================
   طبقة التخزين المشتركة — MySQL اختياري، وإلا JSON تلقائياً
   ============================================================
   يشترك بهذا الملف index.php وinstall.php وmanifest.php حتى تبقى
   قراءة/كتابة البيانات (وبيانات اتصال MySQL نفسها) متطابقة تماماً
   من أي نقطة دخول شُغّلت. كل "مجموعة" (stores, products, users...)
   تبقى مصفوفة PHP عادية كما كانت دائماً — بقية كود التطبيق لا يعرف
   ولا يهمه أين تُخزَّن فعلياً؛ فقط db_read()/db_write() يعرفان ذلك. */

if (!defined('APP_NAME')) define('APP_NAME', 'سوق');
if (!defined('DATA_DIR')) define('DATA_DIR', dirname(__DIR__) . '/data');
if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);

/* بيانات اتصال MySQL تُقرأ من ملف مستقل مباشرة (لا عبر db_read) لأن db_read
   نفسها قد تعتمد على معرفة هذه البيانات أولاً — مشكلة "البيضة والدجاجة".
   تُضبط من install.php أو لوحة الأدمن. تركها فارغة يبقي التخزين على JSON
   كما كان دائماً، بلا أي تغيير. */
if (!defined('DB_CONFIG_FILE')) define('DB_CONFIG_FILE', DATA_DIR . '/db_config.json');
$__dbConfig = file_exists(DB_CONFIG_FILE) ? json_decode((string)file_get_contents(DB_CONFIG_FILE), true) : null;
$__dbConfig = is_array($__dbConfig) ? $__dbConfig : [];
if (!defined('DB_HOST')) define('DB_HOST', (string)($__dbConfig['host'] ?? ''));
if (!defined('DB_NAME')) define('DB_NAME', (string)($__dbConfig['name'] ?? ''));
if (!defined('DB_USER')) define('DB_USER', (string)($__dbConfig['user'] ?? ''));
if (!defined('DB_PASS')) define('DB_PASS', (string)($__dbConfig['pass'] ?? ''));
unset($__dbConfig);

function db_path(string $name): string { return DATA_DIR . '/' . $name . '.json'; }

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
    $c->query("CREATE TABLE IF NOT EXISTS kv_store (name VARCHAR(64) PRIMARY KEY, data LONGTEXT NOT NULL, updated_at INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn = $c;
    db_migrate_json_to_mysql($conn);
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

function db_read(string $name, array $default = []): array {
    $conn = db_connect();
    if ($conn) {
        $stmt = $conn->prepare("SELECT data FROM kv_store WHERE name = ?");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->bind_result($json);
        $found = $stmt->fetch();
        $stmt->close();
        if (!$found) return $default;
        $data = json_decode($json, true);
        return is_array($data) ? $data : $default;
    }
    $f = db_path($name);
    if (!file_exists($f)) return $default;
    $raw = file_get_contents($f);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function db_write(string $name, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $conn = db_connect();
    if ($conn) {
        $now = time();
        $stmt = $conn->prepare("INSERT INTO kv_store (name, data, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)");
        $stmt->bind_param('ssi', $name, $json, $now);
        $stmt->execute();
        $stmt->close();
        return;
    }
    file_put_contents(db_path($name), $json, LOCK_EX);
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
    $defaults = ['monthly_fee'=>0, 'categories'=>[], 'payment_methods'=>[], 'site_name'=>APP_NAME, 'site_logo'=>'', 'ai_api_key'=>'', 'coupons'=>[], 'telegram_bot_token'=>'', 'telegram_chat_id'=>'', 'last_backup_at'=>0];
    return db_read('settings', $defaults) + $defaults;
}
function get_categories(): array { return get_settings()['categories'] ?? []; }
function site_name(): string { $n = trim((string)(get_settings()['site_name'] ?? '')); return $n !== '' ? $n : APP_NAME; }
function site_logo_url(): ?string { $l = get_settings()['site_logo'] ?? ''; return $l ? $l : null; }
