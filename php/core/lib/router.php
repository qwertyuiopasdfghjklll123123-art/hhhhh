<?php
/**
 * مسارات التطبيق (مثل @app.route بفلاسك) + واجهة الطلب (مثل freq)
 */

/* ------------------------------ الطلب (freq) ------------------------------ */

final class Req {
    public static ?string $rawBody = null;
    public static $json = false;      // false = لم يُحلَّل بعد
    public static bool $jsonValid = false;
    public static string $jsonKind = '';   // أول محرف من الجسم: '{' قاموس أو '[' قائمة
    public static function body(): string {
        if (self::$rawBody === null) self::$rawBody = (string)file_get_contents('php://input');
        return self::$rawBody;
    }
}

function req_method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

function req_path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $p = parse_url($uri, PHP_URL_PATH);
    return rawurldecode($p === null || $p === false || $p === '' ? '/' : $p);
}

function req_args(string $k, $default = null) { return $_GET[$k] ?? $default; }
function req_form(string $k, $default = null) { return $_POST[$k] ?? $default; }

function req_header(string $name, string $default = ''): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
    if (strtolower($name) === 'content-type' && isset($_SERVER['CONTENT_TYPE'])) return (string)$_SERVER['CONTENT_TYPE'];
    return $default;
}

function req_remote_addr(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }

function req_scheme(): string {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return 'https';
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return 'https';
    return 'http';
}
function req_host(): string { return (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost')); }
function req_host_url(): string { return req_scheme() . '://' . req_host() . '/'; }

function req_is_json(): bool {
    $ct = strtolower(req_header('Content-Type'));
    return $ct === 'application/json' || (strpos($ct, 'application/') === 0 && substr($ct, -5) === '+json') || strpos($ct, 'application/json') === 0;
}

/**
 * freq.get_json(): يحلّل جسم الطلب كـ JSON.
 * بدون silent: جسم/نوع غير صالح → 400/415 مثل فلاسك. مع silent: يُرجع null.
 */
function req_json(bool $force = false, bool $silent = false) {
    if (Req::$json === false) {
        $body = Req::body();
        $trim = ltrim($body);
        $v = json_decode($body, false, 512, JSON_BIGINT_AS_STRING);
        Req::$jsonValid = ($body !== '' && json_last_error() === JSON_ERROR_NONE);
        Req::$json = Req::$jsonValid ? _json_to_php($v) : null;
        Req::$jsonKind = $trim === '' ? '' : $trim[0];
    }
    if (!$force && !req_is_json()) {
        if ($silent) return null;
        abort_response(http_error_page(415));
    }
    if (!Req::$jsonValid) {
        if ($silent) return null;
        abort_response(http_error_page(400));
    }
    return Req::$json;
}

/**
 * data = freq.get_json() or {}  ثم data.get(...):
 * جسم JSON فارغ/null/[]/{} → [] ؛ وجسم قائمة/نص/رقم غير فارغ يرمي PyTypeError (→ 500 مثل AttributeError ببايثون)
 */
function req_data(): array {
    $d = req_json();
    if ($d instanceof stdClass) return [];
    if (!py_truthy($d)) return [];
    if (!is_array($d) || Req::$jsonKind !== '{') throw new PyTypeError("object has no attribute 'get'");
    return $d;
}

/** data = freq.get_json(force=True, silent=True) or {}  ثم data.get(...): JSON غير صالح → {} ؛ وغير القاموس الفارغ → 500 */
function req_data_force(): array {
    $d = req_json(true, true);
    if ($d instanceof stdClass || !py_truthy($d)) return [];
    if (!is_array($d) || Req::$jsonKind !== '{') throw new PyTypeError("'" . py_type_name($d) . "' object has no attribute 'get'");
    return $d;
}

/** data = freq.get_json() ثم data.get(...) مباشرة (بدون or {}): null أو غير قاموس → 500 */
function req_data_strict(): array {
    $d = req_json();
    if ($d instanceof stdClass) return [];
    if (!is_array($d) || Req::$jsonKind !== '{') throw new PyTypeError("'NoneType' object has no attribute 'get'");
    return $d;
}

/* ------------------------------- المسارات -------------------------------- */

$GLOBALS['__routes'] = [];

/**
 * تسجيل مسار. $rule مثل '/api/x' أو '/api/admin/users/<int:uid>/ban' أو '/api/avatars/<fname>'
 */
function route(string $rule, callable $fn, array $methods = ['GET']): void {
    $names = [];
    $regex = preg_replace_callback('/<(?:(int|string|path):)?([a-zA-Z_][a-zA-Z0-9_]*)>/', function ($m) use (&$names) {
        $names[] = ['name' => $m[2], 'type' => $m[1] ?: 'string'];
        if ($m[1] === 'int') return '(\d+)';
        if ($m[1] === 'path') return '(.+)';
        return '([^/]+)';
    }, $rule);
    $GLOBALS['__routes'][] = [
        'rule' => $rule,
        'regex' => '#^' . $regex . '$#u',
        'names' => $names,
        'static' => empty($names),
        'methods' => array_map('strtoupper', $methods),
        'fn' => $fn,
    ];
}

/** يطابق المسار: يرجع [route, params] أو ['method_not_allowed'=>true] أو null */
function match_route(string $method, string $path): ?array {
    $pathMatched = false;
    // الثابتة أولاً ثم الديناميكية (مثل أولوية فلاسك للقواعد الأكثر تحديداً)
    foreach ([true, false] as $wantStatic) {
        foreach ($GLOBALS['__routes'] as $r) {
            if ($r['static'] !== $wantStatic) continue;
            if (!preg_match($r['regex'], $path, $m)) continue;
            $pathMatched = true;
            $allowed = $r['methods'];
            if (in_array('GET', $allowed, true)) $allowed[] = 'HEAD';
            if (!in_array($method, $allowed, true)) continue;
            $params = [];
            foreach ($r['names'] as $i => $n) {
                $v = $m[$i + 1];
                $params[] = $n['type'] === 'int' ? (int)$v : $v;
            }
            return ['route' => $r, 'params' => $params];
        }
    }
    return $pathMatched ? ['method_not_allowed' => true] : null;
}

/** تنفيذ الطلب كاملاً: hooks ثم المسار ثم الإرسال */
function dispatch(): void {
    $method = req_method();
    $path = req_path();
    try {
        $resp = run_before_request_hooks();
        if ($resp === null) {
            $m = match_route($method, $path);
            if ($m === null) $resp = http_error_page(404);
            elseif (isset($m['method_not_allowed'])) $resp = http_error_page(405);
            else {
                $out = ($m['route']['fn'])(...$m['params']);
                $resp = $out instanceof Response ? $out : new Response(is_string($out) ? $out : '');
            }
        }
    } catch (HttpResponseException $e) {
        $resp = $e->response;
    } catch (Throwable $e) {
        log_app_error('EXCEPTION', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $hint = fc_db_setup_hint($e);       // مشكلة إعداد قاعدة البيانات (كلمة مرور/قاعدة/جداول): رسالة عربية واضحة
        if ($hint !== null) { Conn::shutdown(); fc_setup_problem($hint[0], $hint[1]); }
        $resp = http_error_page(500);
    }
    Conn::shutdown();
    emit_response($resp);
    finish_request();
    run_deferred();
}
