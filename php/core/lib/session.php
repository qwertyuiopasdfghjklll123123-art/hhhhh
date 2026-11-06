<?php
/**
 * جلسة موقّعة داخل الكوكي (stateless) بنفس صيغة Flask/itsdangerous بالضبط:
 *   [.]base64url(json) . base64url(timestamp) . base64url(HMAC-SHA1(derived_key, payload.timestamp))
 *   derived_key = HMAC-SHA1(key=SECRET_KEY, msg='cookie-session')   (key_derivation='hmac')
 * الاسم fc_session، المدة 365 يوم، SameSite=Lax، HttpOnly.
 *
 * النتيجة العملية: مادام SECRET_KEY كما هو، يبقى كل المستخدمين مسجّلي الدخول بعد التحويل من
 * بايثون إلى PHP (وبالعكس) — الكوكيز متوافقة في الاتجاهين.
 * القيم تُقرأ/تُكتب عبر $_SESSION كما بنسخة بايثون (session['user_id'] ...).
 */

const FC_SESSION_COOKIE = 'fc_session';
const FC_SESSION_LIFETIME = 365 * 86400;
const FC_SESSION_SALT = 'cookie-session';

function _b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function _b64u_dec(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = (4 - strlen($s) % 4) % 4;
    $r = base64_decode($s . str_repeat('=', $pad), true);
    return $r === false ? '' : $r;
}

function fc_session_key(): string {
    static $k = null;
    if ($k === null) $k = hash_hmac('sha1', FC_SESSION_SALT, SECRET_KEY, true);
    return $k;
}

function fc_session_signature(string $value): string {
    return _b64u_enc(hash_hmac('sha1', $value, fc_session_key(), true));
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
function fc_session_load(): void {
    $GLOBALS['__session_had_cookie'] = false;
    $GLOBALS['__session_orig'] = '[]';
    $_SESSION = [];
    $raw = $_COOKIE[FC_SESSION_COOKIE] ?? '';
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
    if (!hash_equals(fc_session_signature($rest), $sig)) return;
    $age = time() - _bytes_to_int(_b64u_dec($ts));
    if ($age > FC_SESSION_LIFETIME) return;
    $compressed = false;
    if ($payload[0] === '.') { $compressed = true; $payload = substr($payload, 1); }
    $json = _b64u_dec($payload);
    if ($compressed) {
        $json = @zlib_decode($json);
        if ($json === false) return;
    }
    $data = json_decode($json, true);
    if (!is_array($data)) return;
    $_SESSION = $data;
    $GLOBALS['__session_had_cookie'] = true;
    $GLOBALS['__session_orig'] = json_encode($data);
}

/** ترويسة Set-Cookie المناسبة للاستجابة (أو null) — مثل save_session بفلاسك */
function fc_session_cookie_header(): ?string {
    $now = json_encode($_SESSION, JSON_PRESERVE_ZERO_FRACTION);
    $changed = $now !== ($GLOBALS['__session_orig'] ?? '[]');
    if (empty($_SESSION)) {
        if ($changed || ($GLOBALS['__session_had_cookie'] ?? false)) {
            // الجلسة فُرِّغت: احذف الكوكي (مثل Flask: delete_cookie عند session.clear())
            return FC_SESSION_COOKIE . '=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; Path=/; HttpOnly; SameSite=Lax';
        }
        return null;
    }
    // Flask يرتّب المفاتيح (sort_keys=True) ويستعمل separators مضغوطة؛ أي JSON صالح يُقرأ
    $payload = _b64u_enc($now === false ? '{}' : $now);
    $ts = _b64u_enc(_int_to_bytes(time()));
    $val = $payload . '.' . $ts . '.' . fc_session_signature($payload . '.' . $ts);
    // جلسة دائمة (permanent): نجدّد الصلاحية مع كل استجابة كما يفعل Flask
    return FC_SESSION_COOKIE . '=' . $val . '; Expires=' . gmdate('D, d M Y H:i:s', time() + FC_SESSION_LIFETIME)
        . ' GMT; Max-Age=' . FC_SESSION_LIFETIME . '; Path=/; HttpOnly; SameSite=Lax';
}
