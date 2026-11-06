<?php
/**
 * طبقة الحماية المركزية (لوحة الأدمن وتسجيل الدخول):
 *   • client_ip(): عنوان الزائر الحقيقي (يثق بترويسات البروكسي فقط من TRUSTED_PROXIES)
 *   • rate_limit_*: تحديد معدّل بملفات (بلا قاعدة بيانات) + login_throttle_*: إيقاف محاولات تخمين كلمة المرور
 *   • origin_check(): حماية CSRF — يرفض الطلبات المغيِّرة القادمة من موقع آخر للمتصفحات المسجّلة الدخول
 *   • csrf_token()/csrf_verify(): رمز إضافي لنقاط الاستيراد/التصدير الحساسة
 *   • audit_log(): سجل تدقيق بعمليات الأدمن (data/logs/admin_audit.log)
 *   • session epoch: "تسجيل خروج كل الجلسات" (الجلسات موقّعة بالكوكي بلا حالة بالخادم، فنقارن رقماً يُرفع عند الحاجة)
 *   • admin_ip_allowed(): قائمة بيضاء اختيارية لعناوين الأدمن (ADMIN_IP_ALLOWLIST بـ config.php)
 *   • maintenance: وضع صيانة مؤقت أثناء استيراد قاعدة البيانات
 *   • security_headers(): ترويسات أمان عامة
 * كل الإعدادات اختيارية بـ config.php ولها قيم افتراضية آمنة.
 */

/* ------------------------------ عناوين IP ------------------------------ */

/** هل $ip ضمن القائمة (عناوين مفردة أو CIDR مثل 203.0.113.0/24 أو 2001:db8::/32)؟ */
function ip_in_list(string $ip, array $list): bool {
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    foreach ($list as $item) {
        $item = trim((string)$item);
        if ($item === '') continue;
        $bits = null;
        if (strpos($item, '/') !== false) { [$item, $b] = explode('/', $item, 2); $bits = (int)$b; }
        $nb = @inet_pton(trim($item));
        if ($nb === false || strlen($nb) !== strlen($bin)) continue;
        if ($bits === null) { if ($nb === $bin) return true; continue; }
        $bits = max(0, min($bits, strlen($bin) * 8));
        $full = intdiv($bits, 8); $rem = $bits % 8;
        if ($full > 0 && substr($nb, 0, $full) !== substr($bin, 0, $full)) continue;
        if ($rem === 0) return true;
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        if ((ord($nb[$full]) & $mask) === (ord($bin[$full]) & $mask)) return true;
    }
    return false;
}

/** عنوان الزائر الحقيقي: REMOTE_ADDR، أو ترويسة البروكسي إن كان REMOTE_ADDR ضمن TRUSTED_PROXIES */
function client_ip(): string {
    static $ip = null;
    if ($ip !== null) return $ip;
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ip = $remote;
    $trusted = defined('TRUSTED_PROXIES') ? (array)TRUSTED_PROXIES : [];
    if ($trusted && $remote !== '' && ip_in_list($remote, $trusted)) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            $v = trim((string)($_SERVER[$h] ?? ''));
            if ($v === '') continue;
            $parts = array_map('trim', explode(',', $v));
            for ($i = count($parts) - 1; $i >= 0; $i--) {      // أبعد عنوان من اليمين ليس بروكسي موثوقاً
                if (filter_var($parts[$i], FILTER_VALIDATE_IP) && !ip_in_list($parts[$i], $trusted)) { $ip = $parts[$i]; break 2; }
            }
        }
    }
    return $ip;
}

/* ------------------------------ تحديد المعدّل (ملفات) ------------------------------ */

function rl_dir(): string { $d = DATA_DIR . '/cache/rl'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }
function rl_file(string $bucket): string { return rl_dir() . '/' . hash('sha1', $bucket) . '.json'; }

/** يقرأ/يعدّل قائمة أوقات المحاولات داخل قفل. $fn(array $times): array يرجع القائمة الجديدة */
function rl_update(string $bucket, int $window, callable $fn): array {
    $f = rl_file($bucket);
    $fp = @fopen($f, 'c+');
    if (!$fp) return [];
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $times = json_decode((string)$raw, true);
    $now = time();
    $times = is_array($times) ? array_values(array_filter($times, fn($t) => is_int($t) && $t > $now - $window)) : [];
    $times = $fn($times);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(array_values($times)));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    if (random_int(1, 200) === 1) rl_gc();
    return $times;
}
function rl_gc(): void {
    foreach (glob(rl_dir() . '/*.json') ?: [] as $f) { if (@filemtime($f) < time() - 86400) @unlink($f); }
}
function rl_count(string $bucket, int $window): int { return count(rl_update($bucket, $window, fn($t) => $t)); }
function rl_add(string $bucket, int $window): int { return count(rl_update($bucket, $window, function ($t) { $t[] = time(); return $t; })); }
function rl_clear(string $bucket): void { @unlink(rl_file($bucket)); }
/** كم ثانية حتى يخفّ الحظر (أقدم محاولة + النافذة) */
function rl_retry_after(string $bucket, int $window): int {
    $t = rl_update($bucket, $window, fn($x) => $x);
    return $t ? max(1, min($t) + $window - time()) : 0;
}

/** حدّ عام: يرجع [مسموح؟, ثواني الانتظار] ويسجّل المحاولة */
function rate_limit_hit(string $bucket, int $max, int $window): array {
    $n = rl_add($bucket, $window);
    return $n > $max ? [false, rl_retry_after($bucket, $window)] : [true, 0];
}

/* ------------------------------ إيقاف تخمين كلمات المرور ------------------------------ */

function login_limits(): array {
    return [
        'per_account' => defined('LOGIN_MAX_FAILS') ? (int)LOGIN_MAX_FAILS : 8,        // محاولات فاشلة لنفس (IP + حساب)
        'per_ip' => defined('LOGIN_MAX_FAILS_IP') ? (int)LOGIN_MAX_FAILS_IP : 40,       // محاولات فاشلة من نفس الـ IP لكل الحسابات
        'window' => defined('LOGIN_WINDOW') ? (int)LOGIN_WINDOW : 900,                  // 15 دقيقة
    ];
}
function _login_bucket_account(string $ident): string { return 'login|' . client_ip() . '|' . mb_strtolower($ident, 'UTF-8'); }
function _login_bucket_ip(): string { return 'loginip|' . client_ip(); }

/** قبل فحص كلمة المرور: يرجع Response 429 إن كان الحظر مفعّلاً وإلا null */
function login_throttle_check(string $ident): ?Response {
    $lim = login_limits();
    foreach ([[_login_bucket_account($ident), $lim['per_account']], [_login_bucket_ip(), $lim['per_ip']]] as [$b, $max]) {
        if ($max > 0 && rl_count($b, $lim['window']) >= $max) {
            $wait = max(60, rl_retry_after($b, $lim['window']));
            $r = jsonify(['ok' => false, 'msg' => 'محاولات دخول كثيرة. حاول مجدداً بعد ' . (int)ceil($wait / 60) . ' دقيقة'], 429);
            $r->headers['Retry-After'] = (string)$wait;
            return $r;
        }
    }
    return null;
}
function login_throttle_fail(string $ident): void {
    $lim = login_limits();
    rl_add(_login_bucket_account($ident), $lim['window']);
    rl_add(_login_bucket_ip(), $lim['window']);
}
function login_throttle_ok(string $ident): void { rl_clear(_login_bucket_account($ident)); }

/* ------------------------------ تخمين الأكواد (رمز التفعيل/استعادة كلمة المرور من 6 أرقام) ------------------------------ */

function guess_limit(): int { return defined('CODE_GUESS_MAX') ? (int)CODE_GUESS_MAX : 6; }   // 0 = بلا حد
const GUESS_WINDOW = 900;

/** قبل فحص الرمز: Response 429 إن كان الحساب/العنوان تجاوز المحاولات الفاشلة */
function guess_check(string $key): ?Response {
    $max = guess_limit();
    if ($max <= 0) return null;
    foreach ([['guess|' . $key, $max], ['guessip|' . client_ip(), $max * 6]] as [$b, $m]) {
        if (rl_count($b, GUESS_WINDOW) >= $m) {
            $wait = max(60, rl_retry_after($b, GUESS_WINDOW));
            $r = jsonify(['ok' => false, 'msg' => 'محاولات كثيرة. حاول مجدداً بعد ' . (int)ceil($wait / 60) . ' دقيقة'], 429);
            $r->headers['Retry-After'] = (string)$wait;
            return $r;
        }
    }
    return null;
}
/** بعد محاولة فاشلة: يسجّلها؛ يرجع true إن بلغ الحد (يُبطَل الرمز عندها) */
function guess_fail(string $key): bool {
    $max = guess_limit();
    if ($max <= 0) return false;
    $n = rl_add('guess|' . $key, GUESS_WINDOW);
    rl_add('guessip|' . client_ip(), GUESS_WINDOW);
    return $n >= $max;
}
function guess_ok(string $key): void { rl_clear('guess|' . $key); }

/** حدّ لطلبات إرسال الأكواد/التسجيل (يمنع إغراق بريد الضحية والتسجيل الآلي): Response 429 أو null */
function request_throttle(string $name, string $key, int $maxKey, int $maxIp, int $window = 900): ?Response {
    if ($maxKey <= 0) return null;
    foreach ([[$name . '|' . $key, $maxKey], [$name . 'ip|' . client_ip(), $maxIp]] as [$b, $m]) {
        [$ok, $wait] = rate_limit_hit($b, $m, $window);
        if (!$ok) {
            $r = jsonify(['ok' => false, 'msg' => 'طلبات كثيرة. حاول مجدداً بعد ' . (int)ceil(max(60, $wait) / 60) . ' دقيقة'], 429);
            $r->headers['Retry-After'] = (string)max(60, $wait);
            return $r;
        }
    }
    return null;
}

/* ------------------------------ CSRF ------------------------------ */

/** مسارات تقبل طلبات من خارج الموقع عمداً (API الريسيلر وواجهات الخادم-للخادم) */
function origin_exempt_path(string $path): bool {
    return strncmp($path, '/api/v2', 7) === 0 || strncmp($path, '/api/master', 11) === 0;
}

/**
 * يرفض الطلب المغيِّر (POST/PUT/PATCH/DELETE) إن جاء من صفحة موقع آخر بينما المتصفح يحمل كوكي الجلسة.
 * العملاء بلا كوكي (واجهة API بالمفتاح، المفتاح الرئيسي، curl...) لا يتأثرون.
 */
function origin_check(): ?Response {
    $m = req_method();
    if ($m === 'GET' || $m === 'HEAD' || $m === 'OPTIONS') return null;
    if (empty($_COOKIE[FC_SESSION_COOKIE]) || origin_exempt_path(req_path())) return null;
    $host = strtolower(req_host());
    $origin = req_header('Origin');
    $src = null;
    if ($origin !== '') {
        if (strtolower($origin) === 'null') $src = false;
        else { $p = parse_url($origin); $src = isset($p['host']) ? strtolower($p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')) : false; }
    } else {
        $ref = req_header('Referer');
        if ($ref !== '') { $p = parse_url($ref); $src = isset($p['host']) ? strtolower($p['host'] . (isset($p['port']) ? ':' . $p['port'] : '')) : false; }
    }
    if ($src === null) return null;                    // لا Origin ولا Referer (عميل غير متصفح) — يمرّ
    if ($src === $host) return null;
    // خلف بروكسي ينهي TLS قد يختلف المنفذ الظاهر: نقارن اسم المضيف بلا منفذ عند غياب منفذ صريح بأحد الطرفين
    $strip = fn($h) => preg_replace('/:\d+$/', '', (string)$h);
    if ($src !== false && $strip($src) === $strip($host) && (strpos($src, ':') === false || strpos($host, ':') === false)) return null;
    audit_log('csrf_blocked', ['path' => req_path(), 'origin' => $origin !== '' ? $origin : req_header('Referer')]);
    return jsonify(['ok' => false, 'msg' => 'طلب مرفوض (مصدر غير موثوق)'], 403);
}

/** رمز CSRF مربوط بالجلسة (يُخزَّن بكوكي الجلسة الموقّع؛ لا يقرؤه موقع آخر) */
function csrf_token(): string {
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['_csrf'];
}
function csrf_verify(): bool {
    $sent = req_header('X-CSRF-Token');
    $mine = $_SESSION['_csrf'] ?? '';
    return $sent !== '' && is_string($mine) && $mine !== '' && hash_equals($mine, $sent);
}

/* ------------------------------ سجل التدقيق ------------------------------ */

function audit_log_file(): string { return DATA_DIR . '/logs/admin_audit.log'; }

/** يضيف سطر JSON: الوقت، IP، المستخدم، الحدث + سياق (بلا أي أسرار) */
function audit_log(string $event, array $ctx = []): void {
    $entry = ['t' => date('c'), 'ip' => client_ip(), 'uid' => $_SESSION['user_id'] ?? null, 'event' => $event] + $ctx;
    $f = audit_log_file();
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($f, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    if (@filesize($f) > 5 * 1024 * 1024) {
        $lines = @file($f);
        if ($lines) @file_put_contents($f, implode('', array_slice($lines, (int)(count($lines) / 2))), LOCK_EX);
    }
}
function audit_tail(int $n = 100): array {
    $f = audit_log_file();
    if (!is_file($f)) return [];
    $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach (array_slice($lines, -$n) as $l) { $d = json_decode($l, true); if (is_array($d)) $out[] = $d; }
    return array_reverse($out);
}

/* ------------------------------ إلغاء كل الجلسات ------------------------------ */

function session_epoch_file(): string { return DATA_DIR . '/cache/session_epoch'; }
function session_epoch(): int {
    static $e = null;
    if ($e === null) { $e = is_file(session_epoch_file()) ? (int)trim((string)@file_get_contents(session_epoch_file())) : 0; }
    return $e;
}
/** يرفع الرقم فتُلغى كل الجلسات الحالية (يحتاج الجميع لتسجيل دخول جديد) */
function bump_session_epoch(): int {
    $n = max(session_epoch() + 1, time());
    @file_put_contents(session_epoch_file(), (string)$n, LOCK_EX);
    return $n;
}

/* ------------------------------ قائمة IP للأدمن ------------------------------ */

function admin_ip_restricted(): bool { return defined('ADMIN_IP_ALLOWLIST') && is_array(ADMIN_IP_ALLOWLIST) && count(array_filter(ADMIN_IP_ALLOWLIST)) > 0; }
function admin_ip_allowed(): bool {
    if (!admin_ip_restricted()) return true;
    return ip_in_list(client_ip(), ADMIN_IP_ALLOWLIST);
}
/** مسارات لوحة الإدارة (يُستثنى /api/admin/logo: يخدم شعار الموقع للجميع بالأصل رغم اسمه) */
function admin_path(string $path): bool { return strncmp($path, '/api/admin', 10) === 0 && $path !== '/api/admin/logo'; }

/* ------------------------------ وضع الصيانة (أثناء الاستيراد) ------------------------------ */

function maintenance_file(): string { return DATA_DIR . '/cache/maintenance.json'; }
function maintenance_start(int $seconds = 600): void { @file_put_contents(maintenance_file(), json_encode(['until' => time() + $seconds]), LOCK_EX); }
function maintenance_end(): void { @unlink(maintenance_file()); }
function maintenance_active(): bool {
    $f = maintenance_file();
    if (!is_file($f)) return false;
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d) || (int)($d['until'] ?? 0) < time()) { @unlink($f); return false; }
    return true;
}

/* ------------------------------ ترويسات الأمان ------------------------------ */

function security_headers(Response $r): void {
    $h = &$r->headers;
    $h['X-Content-Type-Options'] = 'nosniff';
    $h['X-Frame-Options'] = 'SAMEORIGIN';
    $h['Referrer-Policy'] = 'strict-origin-when-cross-origin';
    if (req_scheme() === 'https' && !(defined('HSTS_ENABLE') && HSTS_ENABLE === false)) {
        $h['Strict-Transport-Security'] = 'max-age=15552000';
    }
}
