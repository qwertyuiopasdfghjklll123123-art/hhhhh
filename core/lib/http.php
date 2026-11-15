<?php
/**
 * عميل HTTP يحاكي مكتبة requests بايثون (post/get + الاستثناءات):
 *   requests.post(url, data=..., json=..., headers=..., files=..., timeout=..., verify=...)
 * يرسل نفس الترويسات الافتراضية (User-Agent: python-requests/2.31.0) حتى تتعامل معه
 * واجهات مزوّدي SMM بنفس الطريقة التي تعاملت بها مع نسخة بايثون.
 */

interface RequestsTimeoutT extends Throwable {}
interface RequestsConnectionErrorT extends Throwable {}
class RequestsException extends Exception {}
class ReadTimeout extends RequestsException implements RequestsTimeoutT {}
class ConnectTimeout extends RequestsException implements RequestsTimeoutT, RequestsConnectionErrorT {}
class RequestsConnectionError extends RequestsException implements RequestsConnectionErrorT {}
class RequestsSSLError extends RequestsConnectionError {}
class RequestsMissingSchema extends RequestsException {}
class RequestsInvalidSchema extends RequestsException {}
class RequestsInvalidURL extends RequestsException {}

/** requests.prepare_url: يرمي MissingSchema/InvalidSchema/InvalidURL بنفس رسائل المكتبة */
function _requests_check_url(string &$url): void {
    $u = ltrim($url);
    if (strpos($u, ':') !== false && stripos($u, 'http') !== 0) throw new RequestsInvalidSchema('No connection adapters were found for ' . py_repr_str($u));
    if (!preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $u)) throw new RequestsMissingSchema('Invalid URL ' . py_repr_str($u) . ': No scheme supplied. Perhaps you meant https://' . $u . '?');
    $host = parse_url($u, PHP_URL_HOST);
    if (!$host) throw new RequestsInvalidURL('Invalid URL ' . py_repr_str($u) . ': No host supplied');
    $url = $u;
}

/** HTTPSConnectionPool(host='h', port=443) كما تطبعها urllib3 */
function _requests_pool(string $url, bool $conn = false): string {
    $p = parse_url($url) ?: [];
    $https = strtolower($p['scheme'] ?? 'http') === 'https';
    $port = $p['port'] ?? ($https ? 443 : 80);
    $name = ($https ? 'HTTPS' : 'HTTP') . ($conn ? 'Connection' : 'ConnectionPool');
    return $name . "(host='" . strtolower($p['host'] ?? '') . "', port=" . $port . ')';
}

function _requests_path(string $url): string {
    $p = parse_url($url) ?: [];
    $path = ($p['path'] ?? '') === '' ? '/' : $p['path'];
    return $path . (isset($p['query']) ? '?' . $p['query'] : '');
}

final class HttpResponse {
    public int $status_code;
    public string $text;
    public string $content;
    public array $headers;
    public string $url;
    public function __construct(int $code, string $body, array $headers, string $url) {
        $this->status_code = $code;
        $this->text = $body;
        $this->content = $body;
        $this->headers = $headers;
        $this->url = $url;
    }
    /** response.json() — يرمي PyValueError عند فشل التحليل */
    public function json() { return json_loads($this->text); }
    public function ok(): bool { return $this->status_code < 400; }
}

/**
 * @param array $o  data (array|string) | json | headers | files | timeout | verify | params | allow_redirects
 */
function http_request(string $method, string $url, array $o = []): HttpResponse {
    $method = strtoupper($method);
    _requests_check_url($url);
    if (!empty($o['params'])) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($o['params']);

    $headers = [
        'User-Agent' => 'python-requests/2.31.0',
        'Accept-Encoding' => 'gzip, deflate',
        'Accept' => '*/*',
        'Connection' => 'keep-alive',
    ];
    foreach (($o['headers'] ?? []) as $k => $v) $headers[$k] = $v;

    $body = null;
    $tmpFiles = [];
    if (isset($o['files'])) {
        $fields = [];
        foreach (($o['data'] ?? []) as $k => $v) $fields[$k] = $v;
        foreach ($o['files'] as $name => $spec) {
            // spec = [filename, path_or_content, mime?]
            [$fname, $src] = [$spec[0], $spec[1]];
            if (is_string($src) && is_file($src)) $path = $src;
            else { $path = tempnam(sys_get_temp_dir(), 'upl'); file_put_contents($path, $src); $tmpFiles[] = $path; }
            $fields[$name] = new CURLFile($path, $spec[2] ?? mime_for_path($fname), $fname);
        }
        $body = $fields;
    } elseif (array_key_exists('json', $o)) {
        $body = json_encode($o['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!isset($headers['Content-Type'])) $headers['Content-Type'] = 'application/json';
    } elseif (array_key_exists('data', $o)) {
        if (is_array($o['data'])) {
            $body = http_build_query($o['data']);
            if (!isset($headers['Content-Type'])) $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } else {
            $body = (string)$o['data'];
        }
    }

    $timeout = $o['timeout'] ?? null;
    $connectT = 30; $readT = 30;
    if (is_array($timeout)) { $connectT = $timeout[0]; $readT = $timeout[1]; }
    elseif ($timeout !== null) { $connectT = $readT = $timeout; }

    $hdrLines = [];
    foreach ($headers as $k => $v) $hdrLines[] = $k . ': ' . $v;

    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $hdrLines,
        CURLOPT_ENCODING => '',
        CURLOPT_FOLLOWLOCATION => $o['allow_redirects'] ?? true,
        CURLOPT_MAXREDIRS => 30,
        CURLOPT_CONNECTTIMEOUT => (int)ceil($connectT),
        // مهلة القراءة كما بـ requests: لا بيانات واردة لمدة N ثانية (لا مهلة كلية صارمة)
        CURLOPT_LOW_SPEED_LIMIT => 1,
        CURLOPT_LOW_SPEED_TIME => (int)ceil($readT),
        CURLOPT_TIMEOUT => 600,
        CURLOPT_SSL_VERIFYPEER => ($o['verify'] ?? true) ? true : false,
        CURLOPT_SSL_VERIFYHOST => ($o['verify'] ?? true) ? 2 : 0,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if ($body !== null && $method !== 'GET' && $method !== 'HEAD') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $text = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $connTime = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME);
    $osErrno = curl_getinfo($ch, CURLINFO_OS_ERRNO);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    foreach ($tmpFiles as $f) @unlink($f);

    if ($text === false) {
        $pool = _requests_pool($url); $conn = _requests_pool($url, true);
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        $max = "$pool: Max retries exceeded with url: " . _requests_path($url);
        $tdisp = fn($t) => is_float($t) ? py_float_repr($t) : (string)$t;
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            if ($connTime <= 0) throw new ConnectTimeout("$max (Caused by ConnectTimeoutError(<$conn at 0x7f2b4c1d3a90>, 'Connection to $host timed out. (connect timeout=" . $tdisp($connectT) . ")'))");
            throw new ReadTimeout("$pool: Read timed out. (read timeout=" . $tdisp($readT) . ')');
        }
        if ($errno === 6) throw new RequestsConnectionError("$max (Caused by NameResolutionError(\"$conn: Failed to resolve '$host' ([Errno -2] Name or service not known)\"))");
        if ($errno === 7) {
            $en = (int)$osErrno ?: 111;
            $why = [111 => 'Connection refused', 113 => 'No route to host', 101 => 'Network is unreachable', 110 => 'Connection timed out'][$en] ?? 'Connection refused';
            throw new RequestsConnectionError("$max (Caused by NewConnectionError(\"$conn: Failed to establish a new connection: [Errno $en] $why\"))");
        }
        if ($errno === 60 || $errno === 58 || $errno === 77 || $errno === 83) {
            throw new RequestsSSLError("$max (Caused by SSLError(SSLCertVerificationError(1, '[SSL: CERTIFICATE_VERIFY_FAILED] certificate verify failed: unable to get local issuer certificate (_ssl.c:1018)')))");
        }
        if (in_array($errno, [35, 51, 53, 54, 59, 64, 66, 80, 82, 90, 91], true)) {
            $why = stripos($err, 'wrong version') !== false || stripos($err, 'packet length') !== false || $errno === 35
                ? '[SSL: WRONG_VERSION_NUMBER] wrong version number (_ssl.c:1018)' : '[SSL] ' . $err;
            throw new RequestsSSLError("$max (Caused by SSLError(SSLError(1, '$why')))");
        }
        if ($errno === 52) throw new RequestsConnectionError("('Connection aborted.', RemoteDisconnected('Remote end closed connection without response'))");
        if ($errno === 56 || $errno === 55) throw new RequestsConnectionError("('Connection aborted.', ConnectionResetError(104, 'Connection reset by peer'))");
        if ($errno === 47) throw new RequestsException("Exceeded 30 redirects.");
        throw new RequestsException("Request failed ($errno): $err");
    }
    return new HttpResponse($code, (string)$text, $respHeaders, $finalUrl);
}

function requests_post(string $url, array $o = []): HttpResponse { return http_request('POST', $url, $o); }
function requests_get(string $url, array $o = []): HttpResponse { return http_request('GET', $url, $o); }
