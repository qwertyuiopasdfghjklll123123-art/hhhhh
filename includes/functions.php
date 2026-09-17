<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/**
 * تنظيف/تشفير المخرجات لمنع ثغرات XSS. يُستخدم حول كل خرج نصي في القوالب.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** هل تم تثبيت النظام؟ (وجود ملف الإعداد المولَّد من install.php) */
function is_installed(): bool
{
    return file_exists(APP_ROOT . '/config/config.php');
}

/** يحمّل ويُخزّن إعدادات التطبيق (اتصال قاعدة البيانات + مفتاح التشفير...) */
function app_config(): array
{
    static $config = null;
    if ($config === null) {
        if (!is_installed()) {
            header('Location: ' . base_url('install.php'));
            exit;
        }
        $config = require APP_ROOT . '/config/config.php';
    }
    return $config;
}

/** رابط نسبي لجذر التطبيق، يبني مسارات صحيحة بغض النظر عن المجلد الفرعي للتثبيت */
function base_url(string $path = ''): string
{
    $config = null;
    if (is_installed()) {
        $config = require APP_ROOT . '/config/config.php';
    }
    $base = rtrim($config['app']['url'] ?? '', '/');
    if ($base === '') {
        return $path;
    }
    return $base . '/' . ltrim($path, '/');
}

/* ------------------------------------------------------------------ */
/* الجلسات الآمنة                                                      */
/* ------------------------------------------------------------------ */

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('pmdash_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; " .
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
        "script-src 'self'; " .
        "img-src 'self' data: https:; " .
        "connect-src 'self'");
}

/* ------------------------------------------------------------------ */
/* حماية CSRF                                                          */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(419);
        if (is_ajax_request()) {
            json_response(['success' => false, 'error' => 'رمز الحماية (CSRF) غير صالح أو منتهي. أعد تحميل الصفحة.'], 419);
        }
        die('رمز الحماية (CSRF) غير صالح أو منتهي الصلاحية. الرجاء تحديث الصفحة والمحاولة مجدداً.');
    }
}

function is_ajax_request(): bool
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($contentType, 'application/json');
}

/* ------------------------------------------------------------------ */
/* أدوات عامة                                                          */
/* ------------------------------------------------------------------ */

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** رسائل فلاش قصيرة العمر عبر الجلسة (نجاح/خطأ) */
function flash(string $key, ?string $message = null)
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    if (!empty($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** يقرأ جسم الطلب كـ JSON ويعيده كمصفوفة (مصفوفة فارغة إن فشل التحليل) */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function truncate(string $text, int $length = 60): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $length) {
        return mb_substr($text, 0, $length, 'UTF-8') . '…';
    }
    return $text;
}

function format_date(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '—';
    }
    return date('Y-m-d H:i', $ts);
}

function log_activity(?int $userId, string $action, string $description = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $action, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('activity_log insert failed: ' . $e->getMessage());
    }
}
