<?php
/**
 * استجابات HTTP: jsonify / make_response / redirect / send_file + gzip (مثل after_request بالأصل)
 */

final class Response {
    public int $status;
    public array $headers = [];
    public string $body;
    public ?string $file = null;     // مسار ملف يُبثّ مباشرة (send_file)
    public function __construct(string $body = '', int $status = 200, ?string $contentType = 'text/html; charset=utf-8') {
        $this->body = $body;
        $this->status = $status;
        if ($contentType !== null) $this->headers['Content-Type'] = $contentType;
    }
    public function header(string $k, string $v): self { $this->headers[$k] = $v; return $this; }
}

/** استثناء يحمل استجابة جاهزة (مثل abort() بفلاسك) — يلتقطه الـ router */
final class HttpResponseException extends Exception {
    public Response $response;
    public function __construct(Response $r) { parent::__construct('http response'); $this->response = $r; }
}

function abort_response(Response $r): void { throw new HttpResponseException($r); }

/** ترتيب مفاتيح القواميس تصاعدياً بشكل متداخل (Flask jsonify: sort_keys=True) — القوائم تبقى بترتيبها */
function json_sort_keys($v) {
    if ($v instanceof JsonSerializable) $v = $v->jsonSerialize();
    $isObj = false;
    if ($v instanceof stdClass) { $v = get_object_vars($v); $isObj = true; }
    if (!is_array($v)) return $v;
    $out = [];
    if (!$isObj && is_list_array($v)) {
        foreach ($v as $x) $out[] = json_sort_keys($x);
        return $out;
    }
    ksort($v, SORT_STRING);
    foreach ($v as $k => $x) $out[(string)$k] = json_sort_keys($x);
    return (object)$out;   // قاموس دائماً (حتى لو كانت مفاتيحه أرقاماً 0..n)
}

/** نفس مخرجات flask.jsonify: compact + sort_keys + ensure_ascii + "\n" بالنهاية */
function flask_json_encode($data): string {
    $json = json_encode(json_sort_keys($data), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    return ($json === false ? 'null' : $json) . "\n";
}

function jsonify($data = [], int $status = 200): Response {
    return new Response(flask_json_encode($data), $status, 'application/json');
}

function make_response(string $body = '', int $status = 200): Response {
    return new Response($body, $status);
}

function redirect(string $url, int $code = 302): Response {
    $h = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $h = str_replace('&#039;', '&#39;', $h);
    $r = new Response('<!doctype html>' . "\n" . '<html lang=en>' . "\n" . '<title>Redirecting...</title>' . "\n" . '<h1>Redirecting...</h1>' . "\n" . '<p>You should be redirected automatically to the target URL: <a href="' . $h . '">' . $h . '</a>. If not, click the link.' . "\n", $code);
    $r->headers['Location'] = $url;
    return $r;
}

function mime_for_path(string $path): string {
    static $map = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav', 'webm' => 'audio/webm',
        'm4a' => 'audio/mp4', 'mp4' => 'video/mp4', 'json' => 'application/json', 'txt' => 'text/plain',
        'zip' => 'application/zip', 'pdf' => 'application/pdf', 'html' => 'text/html', 'css' => 'text/css', 'js' => 'application/javascript',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}

function send_file(string $path, ?string $mimetype = null, bool $as_attachment = false, ?string $download_name = null, ?int $max_age = null): Response {
    if (!is_file($path)) {
        return new Response('<!doctype html>' . "\n" . '<html lang=en>' . "\n" . '<title>404 Not Found</title>' . "\n" . '<h1>Not Found</h1>' . "\n" . '<p>The requested URL was not found on the server. If you entered the URL manually please check your spelling and try again.</p>' . "\n", 404);
    }
    $r = new Response('', 200, $mimetype ?? mime_for_path($path));
    $r->file = $path;
    $mtime = filemtime($path);
    $r->headers['Accept-Ranges'] = 'bytes';
    $r->headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    $r->headers['ETag'] = '"' . $mtime . '-' . filesize($path) . '"';
    if ($max_age !== null) {   // Flask: public + max-age + Expires
        $r->headers['Cache-Control'] = 'public, max-age=' . $max_age;
        $r->headers['Expires'] = gmdate('D, d M Y H:i:s', time() + $max_age) . ' GMT';
    } else {
        $r->headers['Cache-Control'] = 'no-cache';
    }
    // Content-Disposition كما يضعها werkzeug: inline; filename=name (أو attachment)
    $name = $download_name ?? basename($path);
    $fn = preg_match('/^[!#$%&*+.^_`|~0-9A-Za-z-]+$/', $name) ? $name : '"' . addcslashes($name, '"\\') . '"';
    $r->headers['Content-Disposition'] = ($as_attachment ? 'attachment' : 'inline') . '; filename=' . $fn;
    return $r;
}

function http_error_page(int $code): Response {
    $titles = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 415 => 'Unsupported Media Type', 500 => 'Internal Server Error'];
    $msgs = [
        400 => 'The browser (or proxy) sent a request that this server could not understand.',
        404 => 'The requested URL was not found on the server. If you entered the URL manually please check your spelling and try again.',
        405 => 'The method is not allowed for the requested URL.',
        415 => 'Did not attempt to load JSON data because the request Content-Type was not &#39;application/json&#39;.',
        500 => 'The server encountered an internal error and was unable to complete your request. Either the server is overloaded or there is an error in the application.',
    ];
    $t = $titles[$code] ?? 'Error';
    return new Response('<!doctype html>' . "\n" . '<html lang=en>' . "\n" . '<title>' . $code . ' ' . $t . '</title>' . "\n" . '<h1>' . $t . '</h1>' . "\n" . '<p>' . ($msgs[$code] ?? '') . '</p>' . "\n", $code);
}

/**
 * إرسال الاستجابة للمتصفح: يطبّق after_request (gzip) + كوكي الجلسة.
 */
function emit_response(Response $r): void {
    $acceptsGzip = stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;
    $ct = $r->headers['Content-Type'] ?? '';

    // 304 للملفات المخزّنة
    if ($r->file !== null) {
        $etag = $r->headers['ETag'] ?? null;
        $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        $ims = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) : false;
        $lm = isset($r->headers['Last-Modified']) ? strtotime($r->headers['Last-Modified']) : false;
        if ($etag && $inm !== '' ? $inm === $etag : ($ims !== false && $lm !== false && $lm <= $ims)) {
            $r->status = 304; $r->file = null; $r->body = '';
        }
    }

    $body = $r->body;
    $isFile = $r->file !== null;
    // gzip للاستجابات الناجحة النصية ≥ 500 بايت فقط (مطابق لـ gzip_response بالأصل)
    if (!$isFile && $r->status >= 200 && $r->status < 300 && $acceptsGzip && strlen($body) >= 500
        && (strpos($ct, 'text/html') !== false || strpos($ct, 'application/json') !== false
            || strpos($ct, 'text/css') !== false || strpos($ct, 'application/javascript') !== false)) {
        $gz = gzencode($body, 6);
        if ($gz !== false) {
            $body = $gz;
            $r->headers['Content-Encoding'] = 'gzip';
            $r->headers['Vary'] = 'Accept-Encoding';
        }
    }
    // Flask يضيف Vary: Cookie بعد after_request (لأن الجلسة قُرئت بكل طلب)
    $r->headers['Vary'] = isset($r->headers['Vary']) ? $r->headers['Vary'] . ', Cookie' : 'Cookie';
    security_headers($r);

    $cookie = fc_session_cookie_header();
    if (function_exists('header_remove')) @header_remove('X-Powered-By');
    http_response_code($r->status);
    foreach ($r->headers as $k => $v) header($k . ': ' . $v, true);
    if ($cookie !== null) header('Set-Cookie: ' . $cookie, false);

    if ($isFile) {
        emit_file_body($r);
        return;
    }
    if ($r->status !== 304) header('Content-Length: ' . strlen($body));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
}

/** بعد إرسال الاستجابة: أغلق اتصال العميل (FPM) ليكمل PHP بالمهام المؤجلة (بديل threads/executor بالأصل) */
function finish_request(): void {
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    else { if (ob_get_level() > 0) @ob_end_flush(); @flush(); }
}

/** بثّ ملف مع دعم Range (مثل werkzeug.send_file: 206/416 + Accept-Ranges) — ضروري لتشغيل الصوت بالمتصفحات */
function emit_file_body(Response $r): void {
    $size = (int)filesize($r->file);
    $start = 0; $end = $size - 1;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($r->status === 200 && $range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); $end = $size - 1; }
        else { $start = (int)$m[1]; $end = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) {
            // مثل werkzeug: صفحة خطأ 416 HTML
            $body = "<!doctype html>\n<html lang=en>\n<title>416 Requested Range Not Satisfiable</title>\n<h1>Requested Range Not Satisfiable</h1>\n<p>The server cannot provide the requested range.</p>\n";
            http_response_code(416);
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Range: bytes */' . $size);
            header('Content-Length: ' . strlen($body));
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $body;
            return;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $len = $end - $start + 1;
    header('Content-Length: ' . ($size === 0 ? 0 : $len));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $size === 0) return;
    $fp = fopen($r->file, 'rb');
    if (!$fp) return;
    if ($start > 0) fseek($fp, $start);
    $left = $len;
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, min(65536, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $left -= strlen($chunk);
    }
    fclose($fp);
}
