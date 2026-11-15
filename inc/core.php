<?php
/**
 * واصل — النواة المشتركة لكل ملفات النظام.
 *
 * فيها: إعدادات ثابتة (ومنها بيانات دخول الأدمن)، الاتصال بقاعدة البيانات
 * وإنشاء جداولها وترقيتها، الجلسات (محفوظة بقاعدة البيانات حتى ما تضيع على
 * الاستضافات المشتركة)، الصلاحيات، وأدوات الأرقام والإشعارات.
 *
 * هذا المجلد (inc) محمي بملف .htaccess فما يُفتح مباشرة من المتصفح.
 * الكود متوافق مع PHP 7.4 فما فوق.
 */

define('WASEL_ROOT', dirname(__DIR__));
define('WASEL_VERSION', '3.2.0');
define('WASEL_SCHEMA_VERSION', 5);

// =====================================================================
// بيانات دخول أدمن المنصة — ثابتة بالكود عمداً.
// الأدمن هو الوحيد اللي عنده لوحة تحكم ويقدر ينشئ حسابات الفروع، وما فيه
// أي تسجيل حساب جديد من الموقع. لتغيير بيانات الأدمن عدّل السطرين التاليين.
// =====================================================================
define('ADMIN_EMAIL', 'fastcrand@gmail.com');
define('ADMIN_PASSWORD', 'fastcrand1');
define('ADMIN_DEFAULT_NAME', 'أدمن المنصة');

define('DEFAULT_SITE_NAME', 'واصل');
define('DEFAULT_COUNTRY_CODE', '964');      // العراق — يُضاف تلقائياً لأي رقم ما يبدأ فيه
define('DEFAULT_MESSAGE', 'هلوو');
define('DEFAULT_DELAY', 10);                // مهلة (ثواني) بين رسالة والثانية من نفس الرقم — تحمي الرقم من الحظر
define('MIN_DELAY', 0);
define('DEFAULT_SPLIT_TIME', '10:00');
define('MAX_NUMBERS_PER_CAMPAIGN', 5000);
define('SEND_LOCK_SECONDS', 120);           // رسالة محجوزة (قيد الإرسال) تتحرر بعد هالمدة إذا التشغيل انقطع
define('SESSION_REMEMBER_SECONDS', 30 * 86400);
define('SESSION_IDLE_SECONDS', 12 * 3600);
define('LOGO_MAX_BYTES', 2 * 1024 * 1024);

// الإرسال التلقائي عبر البوابة الخاصة على استضافتك (بدون أي اشتراك)
define('AUTO_MIN_DELAY', 5);                // أقل مهلة (ثواني) بين رسالة والثانية بنفس الرقم مهما كانت مهلة الحملة
define('AUTO_MAX_ATTEMPTS', 3);             // محاولات الرسالة الوحدة إذا الخدمة ما ردّت
define('AUTO_TICK_BUDGET', 20);             // ثواني إرسال لكل طلب من المتصفح (لما ما فيه Cron)
define('AUTO_CRON_BUDGET', 55);             // ثواني إرسال لكل تشغيل Cron (كل دقيقة)
define('AUTO_URL_BUDGET', 25);              // ثواني إرسال لكل تشغيل عن طريق رابط cron.php?key=

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// --------------------------------------------------------------- الإعدادات

/** يقرأ config.php (يكتبه install.php) — بيانات قاعدة البيانات والمنطقة الزمنية. */
function cfg($key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = WASEL_ROOT . '/config.php';
        $cfg = is_file($file) ? (require $file) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
    }
    if ($key === null) {
        return $cfg;
    }
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

function wasel_installed()
{
    return is_file(WASEL_ROOT . '/config.php');
}

// --------------------------------------------------------------- قاعدة البيانات

function db_connect($host, $name, $user, $pass)
{
    $host = trim((string) $host);
    $port = '';
    if (preg_match('/^([^:]+):(\d+)$/', $host, $m)) {
        $host = $m[1];
        $port = $m[2];
    }
    $dsn = 'mysql:host=' . ($host === '' ? 'localhost' : $host)
        . ($port !== '' ? ';port=' . $port : '')
        . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** اتصال واحد مشترك بكل الطلب. install.php يمرّر اتصاله الخاص قبل وجود config.php. */
function db($set = null)
{
    static $pdo = null;
    if ($set instanceof PDO) {
        $pdo = $set;
    }
    if ($pdo === null) {
        $pdo = db_connect(cfg('db_host', 'localhost'), cfg('db_name', ''), cfg('db_user', ''), cfg('db_pass', ''));
    }
    return $pdo;
}

function q($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function q_row($sql, array $params = [])
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_all($sql, array $params = [])
{
    return q($sql, $params)->fetchAll();
}

function q_val($sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function now_dt($offsetSeconds = 0)
{
    return date('Y-m-d H:i:s', time() + $offsetSeconds);
}

/** جداول النظام — CREATE TABLE IF NOT EXISTS آمنة للتكرار، متوافقة مع MySQL 5.6+ و MariaDB. */
function wasel_schema()
{
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(190) NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            parent_id INT UNSIGNED NULL,
            session_version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_email (email),
            KEY idx_parent (parent_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS site_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            site_name VARCHAR(190) NOT NULL,
            logo_file VARCHAR(190) NULL,
            logo_icons TINYINT(1) NOT NULL DEFAULT 0,
            schema_version INT UNSIGNED NOT NULL DEFAULT 0,
            cron_key VARCHAR(64) NULL,
            cron_last_run DATETIME NULL,
            gateway_url VARCHAR(255) NULL,
            gateway_key VARCHAR(64) NULL,
            updated_at DATETIME NULL
        ) $t",
        "CREATE TABLE IF NOT EXISTS wa_accounts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            phone VARCHAR(20) NULL,
            provider VARCHAR(16) NOT NULL DEFAULT 'self',
            api_url VARCHAR(190) NULL,
            api_instance VARCHAR(64) NULL,
            api_token VARCHAR(190) NULL,
            api_state VARCHAR(16) NULL,
            api_note VARCHAR(255) NULL,
            api_checked_at DATETIME NULL,
            api_phone VARCHAR(20) NULL,
            next_send_at DATETIME NULL,
            sync_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_owner (owner_id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS campaigns (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id INT UNSIGNED NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            body_text TEXT NOT NULL,
            link VARCHAR(1000) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            delay_seconds INT UNSIGNED NOT NULL DEFAULT 15,
            mode VARCHAR(10) NOT NULL DEFAULT 'now',
            total INT UNSIGNED NOT NULL DEFAULT 0,
            days INT UNSIGNED NOT NULL DEFAULT 1,
            per_day INT UNSIGNED NOT NULL DEFAULT 0,
            start_at DATETIME NOT NULL,
            send_time CHAR(5) NULL,
            country_code VARCHAR(4) NOT NULL DEFAULT '',
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            paused TINYINT(1) NOT NULL DEFAULT 0,
            notified_day SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            KEY idx_owner (owner_id, id),
            KEY idx_account (account_id, status)
        ) $t",
        "CREATE TABLE IF NOT EXISTS campaign_messages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT UNSIGNED NOT NULL,
            account_id INT UNSIGNED NOT NULL,
            phone VARCHAR(20) NOT NULL,
            day_index SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            scheduled_at DATETIME NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            error VARCHAR(255) NULL,
            locked_at DATETIME NULL,
            locked_by VARCHAR(40) NULL,
            skips TINYINT UNSIGNED NOT NULL DEFAULT 0,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            provider_msg_id VARCHAR(100) NULL,
            sent_at DATETIME NULL,
            KEY idx_queue (account_id, status, scheduled_at),
            KEY idx_campaign (campaign_id, status, day_index),
            KEY idx_sent (account_id, sent_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            owner_id INT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            body VARCHAR(500) NOT NULL,
            account_name VARCHAR(190) NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'info',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_owner (owner_id, id)
        ) $t",
        "CREATE TABLE IF NOT EXISTS sessions (
            id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            data MEDIUMBLOB NOT NULL,
            updated_at INT UNSIGNED NOT NULL,
            lifetime INT UNSIGNED NOT NULL,
            KEY idx_updated (updated_at)
        ) $t",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            email VARCHAR(190) NOT NULL,
            attempted_at INT UNSIGNED NOT NULL,
            KEY idx_ip (ip, attempted_at)
        ) $t",
    ];
}

function column_exists($table, $column)
{
    return (int) q_val(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$table, $column]
    ) > 0;
}

function wasel_migrate()
{
    foreach (wasel_schema() as $sql) {
        db()->exec($sql);
    }
    // ترقية قواعد بيانات النسخ السابقة: 2.0 (إضافة كروم) ← 2.1 (الإرسال بالضغط) ← 3.x (الإرسال التلقائي)
    $columns = [
        ['wa_accounts', 'phone', 'VARCHAR(20) NULL AFTER name'],
        ['campaigns', 'notified_day', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER status'],
        ['campaign_messages', 'locked_by', 'VARCHAR(40) NULL AFTER locked_at'],
        ['campaign_messages', 'skips', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER locked_by'],
        ['wa_accounts', 'provider', "VARCHAR(16) NOT NULL DEFAULT 'self' AFTER phone"],
        ['wa_accounts', 'api_url', 'VARCHAR(190) NULL AFTER provider'],
        ['wa_accounts', 'api_instance', 'VARCHAR(64) NULL AFTER api_url'],
        ['wa_accounts', 'api_token', 'VARCHAR(190) NULL AFTER api_instance'],
        ['wa_accounts', 'api_state', 'VARCHAR(16) NULL AFTER api_token'],
        ['wa_accounts', 'api_note', 'VARCHAR(255) NULL AFTER api_state'],
        ['wa_accounts', 'api_checked_at', 'DATETIME NULL AFTER api_note'],
        ['wa_accounts', 'api_phone', 'VARCHAR(20) NULL AFTER api_checked_at'],
        ['wa_accounts', 'next_send_at', 'DATETIME NULL AFTER api_phone'],
        ['wa_accounts', 'sync_at', 'DATETIME NULL AFTER next_send_at'],
        ['campaigns', 'paused', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status'],
        ['campaign_messages', 'attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER skips'],
        ['campaign_messages', 'provider_msg_id', 'VARCHAR(100) NULL AFTER attempts'],
        ['site_settings', 'cron_key', 'VARCHAR(64) NULL AFTER schema_version'],
        ['site_settings', 'cron_last_run', 'DATETIME NULL AFTER cron_key'],
        ['site_settings', 'gateway_url', 'VARCHAR(255) NULL AFTER cron_last_run'],
        ['site_settings', 'gateway_key', 'VARCHAR(64) NULL AFTER gateway_url'],
    ];
    foreach ($columns as $c) {
        if (!column_exists($c[0], $c[1])) {
            db()->exec('ALTER TABLE `' . $c[0] . '` ADD COLUMN `' . $c[1] . '` ' . $c[2]);
        }
    }
    q("UPDATE campaign_messages SET status = 'pending', locked_at = NULL WHERE status = 'sending'");
    // 3.2: الإرسال صار عبر البوابة الخاصة بس (انحذف الإرسال اليدوي و Green-API و UltraMsg) —
    // كل رقم قديم يتحوّل للبوابة ويحتاج ربط (مسح QR) مرة وحدة
    db()->exec("ALTER TABLE wa_accounts ALTER COLUMN provider SET DEFAULT 'self'");
    foreach (q_all("SELECT id FROM wa_accounts WHERE provider <> 'self' OR api_instance IS NULL OR api_instance = ''") as $old) {
        q(
            "UPDATE wa_accounts SET provider = 'self', api_instance = ?, api_url = NULL, api_token = NULL, api_state = NULL,
             api_note = NULL, api_checked_at = NULL, api_phone = NULL, next_send_at = NULL, sync_at = NULL WHERE id = ?",
            ['acc' . (int) $old['id'] . '-' . bin2hex(random_bytes(4)), (int) $old['id']]
        );
    }
    // حجوزات أجهزة الإرسال اليدوي القديمة
    q("UPDATE campaign_messages SET locked_at = NULL, locked_by = NULL WHERE status = 'pending' AND locked_by IS NOT NULL AND locked_by <> 'auto'");
    q('INSERT IGNORE INTO site_settings (id, site_name, schema_version, updated_at) VALUES (1, ?, 0, ?)', [DEFAULT_SITE_NAME, now_dt()]);
    // مفتاح سري لتشغيل الإرسال التلقائي من رابط (cron.php?key=...) لمن ما يستخدم Cron الاستضافة
    q('UPDATE site_settings SET cron_key = ? WHERE id = 1 AND (cron_key IS NULL OR cron_key = \'\')', [bin2hex(random_bytes(16))]);
    // مفتاح البوابة الخاصة (تطبيق Node.js على نفس الاستضافة) — موقع واصل يكتبه بملف داخل مجلد البوابة
    q('UPDATE site_settings SET gateway_key = ? WHERE id = 1 AND (gateway_key IS NULL OR gateway_key = \'\')', [bin2hex(random_bytes(20))]);
    q('UPDATE site_settings SET schema_version = ? WHERE id = 1', [WASEL_SCHEMA_VERSION]);
    ensure_admin_user();
}

/** إعدادات الموقع (الاسم والشعار) — ويشغّل إنشاء/ترقية الجداول تلقائياً أول مرة. */
function site_settings($reload = false)
{
    static $row = null;
    if ($row !== null && !$reload) {
        return $row;
    }
    try {
        $row = q_row('SELECT * FROM site_settings WHERE id = 1');
    } catch (PDOException $e) {
        $row = null;   // الجداول ما انخلقت بعد
    }
    if (!$row || (int) $row['schema_version'] < WASEL_SCHEMA_VERSION) {
        wasel_migrate();
        $row = q_row('SELECT * FROM site_settings WHERE id = 1');
    }
    if (trim((string) $row['site_name']) === '') {
        $row['site_name'] = DEFAULT_SITE_NAME;
    }
    return $row;
}

function site_name()
{
    return site_settings()['site_name'];
}

function site_logo_url()
{
    $s = site_settings();
    return !empty($s['logo_file']) ? 'uploads/' . $s['logo_file'] : null;
}

/**
 * يضمن وجود صف أدمن واحد بقاعدة البيانات (حتى تتسجّل باسمه حسابات الواتساب
 * والحملات). الدخول نفسه يتحقق من ADMIN_EMAIL/ADMIN_PASSWORD بالكود مباشرة.
 */
function ensure_admin_user()
{
    $email = strtolower(trim(ADMIN_EMAIL));
    $row = q_row('SELECT id, email FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
    if ($row) {
        if ($row['email'] !== $email && !q_val('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $row['id']])) {
            q('UPDATE users SET email = ? WHERE id = ?', [$email, $row['id']]);
        }
        return (int) $row['id'];
    }
    $existing = q_val('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        q('UPDATE users SET is_admin = 1, parent_id = NULL WHERE id = ?', [$existing]);
        return (int) $existing;
    }
    q(
        'INSERT INTO users (email, name, password_hash, is_admin, parent_id, created_at) VALUES (?, ?, ?, 1, NULL, ?)',
        [$email, ADMIN_DEFAULT_NAME, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), now_dt()]
    );
    return (int) db()->lastInsertId();
}

// --------------------------------------------------------------- الطلب والروابط

function is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** مسار مجلد الموقع على الدومين ("/" أو "/wasel/") — يدعم التثبيت داخل مجلد فرعي. */
function base_path()
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $dir = rtrim($dir, '/');
    return $dir . '/';
}

function base_url()
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https' : 'http') . '://' . $host . rtrim(base_path(), '/');
}

function client_ip()
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function str_cut($s, $len)
{
    $s = (string) $s;
    if (function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') > $len ? mb_substr($s, 0, $len, 'UTF-8') : $s;
    }
    return preg_match('/^.{0,' . (int) $len . '}/us', $s, $m) ? $m[0] : substr($s, 0, $len);
}

function json_out($data, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail($message, $status = 400)
{
    json_out(['ok' => false, 'error' => $message], $status);
}

/** قيمة من body بصيغة JSON أو من نموذج عادي. */
function input($key, $default = '')
{
    static $json = null;
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    if ($json === null) {
        $raw = file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : [];
        if (!is_array($json)) {
            $json = [];
        }
    }
    return array_key_exists($key, $json) ? $json[$key] : $default;
}

function input_str($key, $max = 1000)
{
    $v = input($key, '');
    return is_scalar($v) ? str_cut(trim((string) $v), $max) : '';
}

function input_int($key, $default = 0)
{
    $v = input($key, $default);
    return is_numeric($v) ? (int) $v : (int) $default;
}

// --------------------------------------------------------------- الجلسات

/**
 * جلسات محفوظة بجدول sessions بدل ملفات السيرفر، لأن الاستضافات المشتركة
 * تمسح ملفات الجلسات بعد ~24 دقيقة خمول، فيطلع المستخدم حتى لو اختار "تذكرني".
 */
class WaselSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public static $lifetime = SESSION_IDLE_SECONDS;
    private $readAt = 0;

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id)
    {
        $row = q_row('SELECT data, updated_at, lifetime FROM sessions WHERE id = ?', [$id]);
        if (!$row) {
            $this->readAt = 0;
            return '';
        }
        if ((int) $row['updated_at'] + (int) $row['lifetime'] < time()) {
            q('DELETE FROM sessions WHERE id = ?', [$id]);
            $this->readAt = 0;
            return '';
        }
        $this->readAt = (int) $row['updated_at'];
        return (string) $row['data'];
    }

    public function write($id, $data): bool
    {
        q(
            'INSERT INTO sessions (id, data, updated_at, lifetime) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at), lifetime = VALUES(lifetime)',
            [$id, $data, time(), (int) self::$lifetime]
        );
        $this->readAt = time();
        return true;
    }

    public function destroy($id): bool
    {
        q('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc($maxLifetime)
    {
        return q('DELETE FROM sessions WHERE updated_at + lifetime < ?', [time()])->rowCount();
    }

    public function validateId($id): bool
    {
        return q_val('SELECT 1 FROM sessions WHERE id = ?', [$id]) !== null;
    }

    public function updateTimestamp($id, $data): bool
    {
        // ما نحدّث الوقت بكل طلب (الواجهة تسأل السيرفر كل كم ثانية) — مرة كل دقيقة تكفي
        if (time() - $this->readAt >= 60) {
            q('UPDATE sessions SET updated_at = ?, lifetime = ? WHERE id = ?', [time(), (int) self::$lifetime, $id]);
        }
        return true;
    }
}

function session_cookie_options($expires = 0)
{
    return [
        'expires' => $expires,
        'path' => base_path(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function session_boot()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '200');
    ini_set('session.gc_maxlifetime', (string) SESSION_REMEMBER_SECONDS);
    session_name('WASELSID');
    $opts = session_cookie_options();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $opts['path'],
        'secure' => $opts['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_set_save_handler(new WaselSessionHandler(), true);
    session_start();
    WaselSessionHandler::$lifetime = !empty($_SESSION['remember']) ? SESSION_REMEMBER_SECONDS : SESSION_IDLE_SECONDS;
}

/** تهيئة كل طلب: المنطقة الزمنية، إخفاء الأخطاء عن الزائر، الجداول، والجلسة. */
function wasel_boot($withSession = true)
{
    date_default_timezone_set((string) cfg('timezone', 'Asia/Baghdad'));
    ini_set('display_errors', '0');
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }
    site_settings();
    if ($withSession) {
        session_boot();
    }
}

// --------------------------------------------------------------- المستخدم والصلاحيات

function current_user()
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $u = q_row('SELECT id, email, name, is_admin, parent_id, session_version FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
        // تغيير كلمة مرور الفرع من الأدمن يرفع session_version فيطلع من كل الأجهزة
        if ($u && (int) $u['session_version'] === (int) ($_SESSION['uv'] ?? 0)) {
            $u['id'] = (int) $u['id'];
            $u['is_admin'] = (bool) $u['is_admin'];
            $user = $u;
        } else {
            $_SESSION = [];
        }
    }
    return $user;
}

function login_user(array $user, $remember)
{
    session_regenerate_id(true);
    $_SESSION = [
        'uid' => (int) $user['id'],
        'uv' => (int) $user['session_version'],
        'remember' => $remember ? 1 : 0,
        'csrf' => bin2hex(random_bytes(32)),
        'login_at' => time(),
    ];
    WaselSessionHandler::$lifetime = $remember ? SESSION_REMEMBER_SECONDS : SESSION_IDLE_SECONDS;
    if ($remember) {
        setcookie(session_name(), session_id(), session_cookie_options(time() + SESSION_REMEMBER_SECONDS));
    }
}

function logout_user()
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    setcookie('WASELSID', '', session_cookie_options(time() - 3600));
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid($token)
{
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** حماية بسيطة من تخمين كلمات المرور: 5 محاولات خاطئة لنفس البريد أو 25 من نفس الجهاز خلال 15 دقيقة. */
function login_throttled($email)
{
    $since = time() - 900;
    $ip = client_ip();
    $byEmail = (int) q_val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND email = ? AND attempted_at > ?', [$ip, $email, $since]);
    $byIp = (int) q_val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?', [$ip, $since]);
    return $byEmail >= 5 || $byIp >= 25;
}

function login_failed($email)
{
    q('INSERT INTO login_attempts (ip, email, attempted_at) VALUES (?, ?, ?)', [client_ip(), str_cut($email, 190), time()]);
    q('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 86400]);
}

/**
 * يتحقق من بيانات الدخول: الأدمن من الثوابت أعلى هذا الملف، والفروع من جدول
 * users. يرجّع صف المستخدم أو null.
 */
function authenticate($email, $password)
{
    $email = strtolower(trim((string) $email));
    if ($email === strtolower(trim(ADMIN_EMAIL))) {
        if (!hash_equals(ADMIN_PASSWORD, (string) $password)) {
            return null;
        }
        $id = ensure_admin_user();
        return q_row('SELECT * FROM users WHERE id = ?', [$id]);
    }
    $user = q_row('SELECT * FROM users WHERE email = ? AND is_admin = 0', [$email]);
    if (!$user || !password_verify((string) $password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

function admin_user_id()
{
    return (int) q_val('SELECT id FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1');
}

// --------------------------------------------------------------- الأرقام

/** يحوّل الأرقام العربية/الفارسية (٠١٢…/۰۱۲…) لأرقام إنجليزية قبل التنظيف. */
function ascii_digits($s)
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        for ($i = 0; $i < 10; $i++) {
            $map[$arabic[$i]] = (string) $i;
            $map[$persian[$i]] = (string) $i;
        }
    }
    return strtr((string) $s, $map);
}

/**
 * يضيف رمز الدولة الافتراضي لأي رقم محلي (بعد حذف الصفر الأول) ما يبدأ
 * فيه أصلاً — فالمستخدم ما يحتاج يكتب رمز الدولة مع كل رقم. الرقم المكتوب
 * دولياً (يبدأ بـ + أو 00) يبقى برمز دولته كما هو.
 */
function normalize_number($digits, $countryCode, $international = false)
{
    if (strncmp($digits, '00', 2) === 0) {
        $digits = substr($digits, 2);
        $international = true;
    }
    if (!$international && $countryCode !== '' && strncmp($digits, $countryCode, strlen($countryCode)) !== 0) {
        $digits = $countryCode . ltrim($digits, '0');
    }
    return $digits;
}

/** رقم صالح بالصيغة الدولية بدون + (مثل 9647701234567)، أو null. */
function clean_number($raw, $countryCode)
{
    $raw = ascii_digits($raw);
    $international = (bool) preg_match('/^[^0-9]*\+/u', $raw) || (bool) preg_match('/^[^0-9]*00/', $raw);
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '' || $digits === null) {
        return null;
    }
    $digits = normalize_number($digits, $countryCode, $international);
    $len = strlen($digits);
    if ($len < 8 || $len > 15) {
        return null;
    }
    // الرقم المحلي نفسه (بدون رمز الدولة) لازم 7 أرقام على الأقل — حتى ما يمر رقم ناقص مثل 12345
    if (!$international && $countryCode !== '' && $len - strlen($countryCode) < 7) {
        return null;
    }
    return $digits;
}

/** أرقام من نص: رقم بكل سطر أو مفصولة بفواصل (وإذا سطر فيه أكثر من رقم بمسافات نفصلها). */
function numbers_from_text($text, $countryCode)
{
    $out = [];
    $parts = preg_split('/[,\n\r;،\t]+/u', ascii_digits($text));
    foreach ($parts ?: [] as $part) {
        $n = clean_number($part, $countryCode);
        if ($n !== null) {
            $out[] = $n;
            continue;
        }
        if (strlen(preg_replace('/\D+/', '', $part)) > 15) {
            foreach (preg_split('/\s+/', trim($part)) as $piece) {
                $n = clean_number($piece, $countryCode);
                if ($n !== null) {
                    $out[] = $n;
                }
            }
        }
    }
    return $out;
}

/** أرقام من ملف مرفوع: Excel (.xlsx) أو CSV أو TXT. */
function numbers_from_upload(array $file, $countryCode)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('فشل رفع ملف الأرقام — حاول مرة ثانية');
    }
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return numbers_from_xlsx($file['tmp_name'], $countryCode);
    }
    if ($ext === 'csv' || $ext === 'txt') {
        return numbers_from_text((string) file_get_contents($file['tmp_name']), $countryCode);
    }
    if ($ext === 'xls') {
        throw new RuntimeException('صيغة .xls القديمة غير مدعومة — احفظ الملف من Excel بصيغة .xlsx أو CSV');
    }
    throw new RuntimeException('صيغة الملف غير مدعومة — استخدم Excel (.xlsx) أو CSV أو TXT');
}

function xlsx_text($node)
{
    if ($node === null) {
        return '';
    }
    if (isset($node->t)) {
        $s = (string) $node->t;
        if (isset($node->r)) {
            foreach ($node->r as $r) {
                $s .= (string) $r->t;
            }
        }
        return $s;
    }
    $s = '';
    if (isset($node->r)) {
        foreach ($node->r as $r) {
            $s .= (string) $r->t;
        }
    }
    return $s;
}

function xlsx_read_entry(ZipArchive $zip, $name)
{
    $stat = $zip->statName($name);
    if ($stat === false) {
        return null;
    }
    if ($stat['size'] > 40 * 1024 * 1024) {
        throw new RuntimeException('ملف Excel كبير جداً');
    }
    $data = $zip->getFromName($name);
    return $data === false ? null : $data;
}

/** مسار أول ورقة عمل داخل ملف xlsx (حسب workbook.xml وعلاقاته). */
function xlsx_first_sheet(ZipArchive $zip)
{
    $wb = xlsx_read_entry($zip, 'xl/workbook.xml');
    $rels = xlsx_read_entry($zip, 'xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $wbx = @simplexml_load_string($wb);
        $relx = @simplexml_load_string($rels);
        if ($wbx && $relx && isset($wbx->sheets->sheet[0])) {
            $rid = (string) $wbx->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            foreach ($relx->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
    }
    if ($zip->statName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $n = $zip->getNameIndex($i);
        if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) {
            return $n;
        }
    }
    return null;
}

/**
 * يقرأ أول ورقة من ملف Excel بدون أي مكتبة خارجية (xlsx = ملف zip فيه XML)،
 * ويختار تلقائياً العمود اللي فيه أكثر أرقام صالحة (فما يهم إذا الأرقام
 * بالعمود الأول أو بعد عمود الأسماء).
 */
function numbers_from_xlsx($path, $countryCode)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('إضافة zip غير مفعّلة على الاستضافة — ارفع الأرقام كملف CSV أو اكتبها يدوياً');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('ملف Excel تالف أو غير صالح');
    }
    try {
        $shared = [];
        $ss = xlsx_read_entry($zip, 'xl/sharedStrings.xml');
        if ($ss) {
            $ssx = @simplexml_load_string($ss);
            if ($ssx) {
                foreach ($ssx->si as $si) {
                    $shared[] = xlsx_text($si);
                }
            }
        }
        $sheetName = xlsx_first_sheet($zip);
        $sheet = $sheetName ? xlsx_read_entry($zip, $sheetName) : null;
        if (!$sheet) {
            throw new RuntimeException('ما لقيت ورقة عمل داخل ملف Excel');
        }
        $sx = @simplexml_load_string($sheet);
        if (!$sx || !isset($sx->sheetData)) {
            throw new RuntimeException('تعذّرت قراءة ملف Excel');
        }
        $columns = [];
        foreach ($sx->sheetData->row as $row) {
            $pos = 0;
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                $col = $ref !== '' ? preg_replace('/\d+/', '', $ref) : 'P' . $pos;
                $pos++;
                $type = (string) $c['t'];
                if ($type === 's') {
                    $idx = (int) $c->v;
                    $value = isset($shared[$idx]) ? $shared[$idx] : '';
                } elseif ($type === 'inlineStr') {
                    $value = xlsx_text($c->is);
                } else {
                    $value = (string) $c->v;
                    if ($type === '' || $type === 'n') {
                        // أرقام Excel الكبيرة تنحفظ أحياناً بصيغة علمية مثل 9.647701234567E12
                        if (is_numeric($value) && stripos($value, 'e') !== false) {
                            $value = number_format((float) $value, 0, '', '');
                        } elseif (preg_match('/^(\d+)\.0+$/', $value, $m)) {
                            $value = $m[1];
                        }
                    }
                }
                $columns[$col][] = $value;
            }
        }
        $best = [];
        foreach ($columns as $values) {
            $nums = [];
            foreach ($values as $v) {
                $n = clean_number($v, $countryCode);
                if ($n !== null) {
                    $nums[] = $n;
                }
            }
            if (count($nums) > count($best)) {
                $best = $nums;
            }
        }
        return $best;
    } finally {
        $zip->close();
    }
}

// --------------------------------------------------------------- الإشعارات

function notify($ownerId, $title, $body, $kind = 'info', $accountName = null)
{
    q(
        'INSERT INTO notifications (owner_id, title, body, account_name, kind, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, ?)',
        [(int) $ownerId, str_cut($title, 190), str_cut($body, 500), $accountName === null ? null : str_cut($accountName, 190), $kind, now_dt()]
    );
    if (mt_rand(1, 25) === 1) {
        // نحتفظ بآخر 200 إشعار لكل مستخدم فقط
        q(
            'DELETE FROM notifications WHERE owner_id = ? AND id < (SELECT id FROM (SELECT id FROM notifications WHERE owner_id = ? ORDER BY id DESC LIMIT 1 OFFSET 199) t)',
            [(int) $ownerId, (int) $ownerId]
        );
    }
}

// --------------------------------------------------------------- حسابات واتساب

/** حساب واتساب يملكه المستخدم (أو أي حساب للأدمن إذا $allowAdmin). */
function owned_account($user, $accountId, $allowAdmin = false)
{
    $acc = q_row('SELECT * FROM wa_accounts WHERE id = ?', [(int) $accountId]);
    if (!$acc) {
        return null;
    }
    if ((int) $acc['owner_id'] === (int) $user['id'] || ($allowAdmin && !empty($user['is_admin']))) {
        return $acc;
    }
    return null;
}

