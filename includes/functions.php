<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/* ------------------------------------------------------------------ */
/* معالجة الأخطاء الفادحة: صفحة خطأ واضحة بدل صفحة 500 فارغة من الاستضافة */
/* (display_errors مُعطَّل عمداً في .htaccess لأسباب أمنية، لذا بدون هذا   */
/* المعالِج لن يرى أحد أي تفاصيل عند حدوث خطأ في الإنتاج)                 */
/* ------------------------------------------------------------------ */

function diagnose_fatal_hint(string $message): string
{
    if (str_contains($message, 'ai_providers') || str_contains($message, 'app_settings') || str_contains($message, 'github_oauth')
        || str_contains($message, "doesn't exist") || str_contains($message, 'Unknown column')) {
        return 'يبدو أن قاعدة البيانات تحتاج تحديثاً. شغّل ملف database/migrate_global_providers_and_oauth.sql على قاعدة بياناتك ثم أعد المحاولة (خاص بمن ثبّت النظام قبل جعل مزوّدي الذكاء الاصطناعي عامّين وإضافة ربط GitHub عبر OAuth).';
    }
    if (preg_match('/\b(AiClient|GithubClient|NvidiaClient)\b/', $message)) {
        return 'أحد ملفات الأصناف البرمجية داخل مجلد services/ غير موجود على السيرفر. تأكد من رفع كل ملفات آخر نسخة كاملة (وأن services/AiClient.php موجود فعلاً).';
    }
    return '';
}

function render_fatal_error_page(string $hint = ''): void
{
    if (headers_sent()) {
        return;
    }
    http_response_code(500);

    if (function_exists('is_ajax_request') && is_ajax_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'حدث خطأ غير متوقع في الخادم.' . ($hint !== '' ? ' ' . $hint : ''),
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $debug = false;
    try {
        $debug = (bool) (app_config()['app']['debug'] ?? false);
    } catch (Throwable $e) {
        // تعذّر حتى قراءة الإعدادات؛ نكمل بدون تفعيل وضع التصحيح
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>خطأ في الخادم</title>'
        . '<style>body{background:#0a0a0b;color:#f1f0ee;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px}'
        . '.box{max-width:580px;background:#18181b;border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:32px}'
        . 'h1{font-size:1.15rem;margin:0 0 14px}p{color:#9c9ca3;line-height:1.85;margin:0 0 10px;font-size:.9rem}'
        . 'code{background:#202023;padding:2px 8px;border-radius:6px;font-size:.85em;direction:ltr;display:inline-block}'
        . 'pre{background:#0e0e10;padding:14px;border-radius:10px;overflow:auto;font-size:.76rem;direction:ltr;text-align:left;color:#f2a93c;white-space:pre-wrap}</style>'
        . '</head><body><div class="box">'
        . '<h1>⚠️ تعذّر تنفيذ هذا الطلب</h1>'
        . '<p>حدث خطأ غير متوقع في الخادم. تم تسجيل التفاصيل الكاملة في سجل أخطاء PHP (error_log) على السيرفر.</p>';

    if ($hint !== '') {
        echo '<p><strong>السبب المحتمل:</strong> ' . e($hint) . '</p>';
    } else {
        echo '<p>راجع سجل الأخطاء من لوحة تحكم الاستضافة (Error Log) لمعرفة التفاصيل الدقيقة.</p>';
    }

    if ($debug) {
        $err = error_get_last();
        if ($err) {
            echo '<pre>' . e($err['message'] . ' — ' . $err['file'] . ':' . $err['line']) . '</pre>';
        }
    }

    echo '</div></body></html>';
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }
    error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    render_fatal_error_page(diagnose_fatal_hint($error['message']));
});

set_exception_handler(static function (Throwable $e): void {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    render_fatal_error_page(diagnose_fatal_hint($e->getMessage()));
});

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

/**
 * رمز GitHub الفعّال لمشروع معيّن مع مستخدم معيّن: يُفضَّل رمز حساب المستخدم
 * المربوط عبر OAuth (زر "ربط GitHub" — الطريقة الموصى بها والوحيدة لأي اتصال
 * جديد)، مع دعم توافقي لـ Token يدوي محفوظ خصيصاً لسياق مشروع أُعدَّ قبل
 * إضافة الربط عبر OAuth.
 */
function resolve_github_token(array $context, array $user): ?string
{
    if (!empty($user['github_oauth_token'])) {
        $decoded = Crypto::decrypt($user['github_oauth_token']);
        if ($decoded !== null && $decoded !== '') {
            return $decoded;
        }
    }
    if (!empty($context['github_token'])) {
        return Crypto::decrypt($context['github_token']);
    }
    return null;
}
