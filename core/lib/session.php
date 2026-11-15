<?php
/**
 * جلسة موقّعة داخل الكوكي (stateless) بنفس صيغة Flask/itsdangerous بالضبط:
 *   [.]base64url(json) . base64url(timestamp) . base64url(HMAC-SHA1(derived_key, payload.timestamp))
 *   derived_key = HMAC-SHA1(key=SECRET_KEY, msg='cookie-session')   (key_derivation='hmac')
 * الاسم wsl_session، المدة 30 يوم (بطلب صاحب المنصة)، SameSite=Lax، HttpOnly.
 *
 * النتيجة العملية: مادام SECRET_KEY نفسه المستعمل بـ webapp.py (Flask)، يبقى كل المستخدمين
 * مسجّلي الدخول بعد النقل من بايثون إلى PHP بلا أي تسجيل دخول جديد - الكوكيز متوافقة تماماً.
 * القيم تُقرأ/تُكتب عبر $_SESSION كما بنسخة بايثون (session['user_id'] ...).
 */

const WSL_SESSION_COOKIE = 'wsl_session';
const WSL_SESSION_LIFETIME = 30 * 86400;
const WSL_SESSION_SALT = 'cookie-session';

function _b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function _b64u_dec(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = (4 - strlen($s) % 4) % 4;
    $r = base64_decode($s . str_repeat('=', $pad), true);
    return $r === false ? '' : $r;
}

function wsl_session_key(): string {
    static $k = null;
    if ($k === null) $k = hash_hmac('sha1', WSL_SESSION_SALT, SECRET_KEY, true);
    return $k;
}

function wsl_session_signature(string $value): string {
    return _b64u_enc(hash_hmac('sha1', $value, wsl_session_key(), true));
}

/** int → أقصر bytes big-endian (مثل itsdangerous.int_to_bytes) */
function _int_to_bytes(int $n): string {
    $out = '';
    while ($n > 0) { $out = chr($n & 0xFF) . $out; $n >>= 8; }
    return $out === '' ? "\0" : $out;
}
function _bytes_to_int(string $b): int {
    $n = 0;
    for ($i = 0, $l = strlen($b); $i < $l; $i++) $n = ($n << 8) | ord($b[$i]);
    return $n;
}

/** تحميل الجلسة من الكوكي إلى $_SESSION (جلسة فارغة عند أي تلاعب/انتهاء) */
function wsl_session_load(): void {
    $GLOBALS['__session_had_cookie'] = false;
    $GLOBALS['__session_orig'] = '[]';
    $_SESSION = [];
    $raw = $_COOKIE[WSL_SESSION_COOKIE] ?? '';
    if (!is_string($raw) || $raw === '') return;
    // الصيغة: [.]payload.timestamp.signature — التوقيع يشمل النقطة الأولى (حالة الضغط)
    $p2 = strrpos($raw, '.');
    if ($p2 === false) return;
    $sig = substr($raw, $p2 + 1);
    $rest = substr($raw, 0, $p2);
    $p1 = strrpos($rest, '.');
    if ($p1 === false) return;
    $ts = substr($rest, $p1 + 1);
    $payload = substr($rest, 0, $p1);
    if ($payload === '' || $payload === '.') return;
    if (!hash_equals(wsl_session_signature($rest), $sig)) return;
    $age = time() - _bytes_to_int(_b64u_dec($ts));
    if ($age > WSL_SESSION_LIFETIME) return;
    $compressed = false;
    if ($payload[0] === '.') { $compressed = true; $payload = substr($payload, 1); }
    $json = _b64u_dec($payload);
    if ($compressed) {
        $json = @zlib_decode($json);
        if ($json === false) return;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) return;
    $GLOBALS['__session_had_cookie'] = true;
    $GLOBALS['__session_orig'] = json_encode($data);
    // "تسجيل خروج كل الجلسات": جلسة مسجَّلة الدخول بلا رقم الحقبة الحالي تُهمَل (انظر security.php)
    if (array_key_exists('user_id', $data) && (int)($data['_ep'] ?? 0) !== session_epoch()) { $_SESSION = []; return; }
    $_SESSION = $data;
}

/** ترويسة Set-Cookie المناسبة للاستجابة (أو null) — مثل save_session بفلاسك */
function wsl_session_cookie_header(): ?string {
    if (array_key_exists('user_id', $_SESSION) && !array_key_exists('_ep', $_SESSION)) $_SESSION['_ep'] = session_epoch();
    $now = json_encode($_SESSION, JSON_PRESERVE_ZERO_FRACTION);
    $secure = req_scheme() === 'https' ? '; Secure' : '';
    $changed = $now !== ($GLOBALS['__session_orig'] ?? '[]');
    if (empty($_SESSION)) {
        if ($changed || ($GLOBALS['__session_had_cookie'] ?? false)) {
            // الجلسة فُرِّغت: احذف الكوكي (مثل Flask: delete_cookie عند session.clear())
            return WSL_SESSION_COOKIE . '=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/; HttpOnly; SameSite=Lax' . $secure;
        }
        return null;
    }
    $payload = _b64u_enc($now === false ? '{}' : $now);
    $ts = _b64u_enc(_int_to_bytes(time()));
    $val = $payload . '.' . $ts . '.' . wsl_session_signature($payload . '.' . $ts);
    return WSL_SESSION_COOKIE . '=' . $val . '; Expires=' . gmdate('D, d M Y H:i:s', time() + WSL_SESSION_LIFETIME)
        . ' GMT; Max-Age=' . WSL_SESSION_LIFETIME . '; Path=/; HttpOnly; SameSite=Lax' . $secure;
}
