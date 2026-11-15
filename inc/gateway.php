<?php
/**
 * واصل — الربط مع خدمات واتساب API (الإرسال التلقائي بدون أي تثبيت).
 *
 * الخدمة (Green-API أو UltraMsg) تشغّل "واتساب ويب" على سيرفراتها: تسجّل
 * فيها بالبريد، تنسخ رقم الـ Instance والرمز (Token) لواصل، وتمسح رمز QR
 * مرة وحدة من واتساب جوالك (الأجهزة المرتبطة ← ربط جهاز). بعدها موقعك يرسل
 * الرسائل بنفسه من السيرفر — بدون ما ينفتح واتساب بأي جهاز.
 *
 * كل دالة ترجع حالة موحّدة للحساب:
 *   connected  متصل وجاهز للإرسال
 *   qr         يحتاج مسح رمز QR من الجوال
 *   starting   الخدمة تشغّل الحساب (مؤقت)
 *   blocked    الرقم محظور من واتساب
 *   suspended  واتساب أوقف الإرسال من الرقم مؤقتاً
 *   quota      الباقة/الاشتراك بالخدمة ما يسمح (انتهى أو وصل الحد)
 *   auth_error رقم الـ Instance أو الرمز غلط
 *   error      ما قدرنا نتصل بالخدمة (مؤقت — نعيد المحاولة)
 */

require_once __DIR__ . '/core.php';

/** الخدمات المدعومة — ومعلومات تظهر بالواجهة. */
function gw_providers()
{
    return [
        'greenapi' => ['name' => 'Green-API', 'site' => 'https://green-api.com/', 'console' => 'https://console.green-api.com/'],
        'ultramsg' => ['name' => 'UltraMsg', 'site' => 'https://ultramsg.com/', 'console' => 'https://user.ultramsg.com/'],
    ];
}

function gw_provider_name($provider)
{
    $p = gw_providers();
    return isset($p[$provider]) ? $p[$provider]['name'] : 'يدوي';
}

/** الحالات اللي تحتاج تدخّل من صاحب الحساب (نرسل له إشعار لما يصير فيها). */
function gw_state_needs_action($state)
{
    return in_array($state, ['qr', 'blocked', 'suspended', 'quota', 'auth_error'], true);
}

// --------------------------------------------------------------- HTTP

/**
 * طلب HTTP للخدمة. يرجّع: status (0 إذا ما وصل رد)، body، type، json، error،
 * و maybe_sent = الطلب انرسل بس الرد ما وصل بالوقت (يمكن الرسالة انرسلت).
 */
function gw_http($method, $url, array $opts = [])
{
    $timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 25;
    $headers = ['Accept: application/json, image/*;q=0.8, */*;q=0.5', 'User-Agent: Wasel/' . WASEL_VERSION];
    $body = null;
    if (array_key_exists('json', $opts)) {
        $body = json_encode($opts['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    } elseif (array_key_exists('form', $opts)) {
        $body = http_build_query($opts['form'], '', '&');
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $out = ['status' => 0, 'body' => '', 'type' => '', 'json' => null, 'error' => null, 'maybe_sent' => false];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $errno = curl_errno($ch);
            $out['error'] = curl_error($ch) ?: ('cURL ' . $errno);
            // انتهت المهلة بعد ما انرسل الطلب فعلاً — الخدمة يمكن استلمت الرسالة
            $out['maybe_sent'] = $errno === 28 && (float) curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0;
        } else {
            $out['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $out['type'] = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            $out['body'] = (string) $resp;
        }
        curl_close($ch);
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body === null ? '' : $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $hdrs = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?: []) : (isset($http_response_header) ? $http_response_header : []);
        if ($resp === false && !$hdrs) {
            $out['error'] = 'تعذّر الاتصال';
        } else {
            $out['body'] = (string) $resp;
            foreach ($hdrs as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $out['status'] = (int) $m[1];
                } elseif (stripos($h, 'content-type:') === 0) {
                    $out['type'] = strtolower(trim(substr($h, 13)));
                }
            }
        }
    } else {
        $out['error'] = 'الاستضافة ما تسمح بالاتصال الخارجي (فعّل إضافة cURL من cPanel ← Select PHP Version)';
    }

    $trim = ltrim($out['body']);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $j = json_decode($trim, true);
        if (is_array($j)) {
            $out['json'] = $j;
        }
    }
    return $out;
}

/** مشكلة عامة بالرد (اتصال، صلاحيات، حد الباقة، ضغط) — أو null إذا الرد طبيعي. */
function gw_http_problem(array $r)
{
    if ($r['error'] !== null) {
        return ['error', 'تعذّر الاتصال بخدمة الواتساب: ' . str_cut($r['error'], 150)];
    }
    $s = (int) $r['status'];
    if ($s === 401 || $s === 403) {
        return ['auth_error', 'رقم الـ Instance أو الرمز (Token) غير صحيح'];
    }
    if ($s === 466 || $s === 402) {
        return ['quota', 'باقتك بالخدمة ما تسمح بهذا الإرسال (الباقة المجانية محدودة أو الاشتراك منتهي) — رقّي الباقة من لوحة الخدمة'];
    }
    if ($s === 429) {
        return ['error', 'طلبات كثيرة على الخدمة — بنعيد المحاولة بعد شوي'];
    }
    if ($s >= 500 || $s === 0) {
        return ['error', 'خدمة الواتساب ما ترد حالياً (' . $s . ') — بنعيد المحاولة تلقائياً'];
    }
    return null;
}

function gw_state($state, $note = '')
{
    return ['state' => $state, 'note' => (string) $note];
}

/** نص قصير من رد الخدمة (للعرض كسبب الخطأ). */
function gw_body_note(array $r)
{
    $j = $r['json'];
    if (is_array($j)) {
        foreach (['message', 'description', 'error'] as $k) {
            if (isset($j[$k]) && is_scalar($j[$k]) && trim((string) $j[$k]) !== '') {
                return str_cut(trim((string) $j[$k]), 200);
            }
        }
        if (isset($j['error']) && is_array($j['error'])) {
            return str_cut(gw_flatten($j['error']), 200);
        }
    }
    return str_cut(trim(strip_tags($r['body'])), 200);
}

function gw_flatten($v)
{
    if (!is_array($v)) {
        return (string) $v;
    }
    $parts = [];
    array_walk_recursive($v, function ($x, $k) use (&$parts) {
        $parts[] = (is_string($k) ? $k . ': ' : '') . $x;
    });
    return implode('، ', $parts);
}

// --------------------------------------------------------------- Green-API

/** وضع الاختبار (config.php): يسمح بخدمة وهمية على http://127.0.0.1 بدل الخدمة الحقيقية. */
function gw_test_mode()
{
    return (bool) cfg('gateway_test_mode', false);
}

/** رابط apiUrl صالح لـ Green-API (دومينات Green-API فقط — حماية من توجيه السيرفر لعناوين ثانية). */
function green_api_url_normalize($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^[a-z]+://#i', $url)) {
        $url = 'https://' . $url;
    }
    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['scheme'])) {
        return null;
    }
    $scheme = strtolower($p['scheme']);
    $host = strtolower($p['host']);
    $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
    $path = isset($p['path']) ? rtrim($p['path'], '/') : '';
    if (gw_test_mode()) {
        return in_array($scheme, ['http', 'https'], true) ? $scheme . '://' . $host . $port . $path : null;
    }
    if ($scheme !== 'https' || $path !== '' || !preg_match('/(^|\.)(green-api\.com|greenapi\.com)$/', $host)) {
        return null;
    }
    return 'https://' . $host . $port;
}

/** روابط Green-API المحتملة إذا المستخدم ما كتب apiUrl: رابط الـ Instance الخاص (أول 4 أرقام) ثم الرابط العام. */
function green_api_url_candidates($instance)
{
    if (gw_test_mode() && cfg('greenapi_default_url')) {
        return [rtrim((string) cfg('greenapi_default_url'), '/')];
    }
    $list = [];
    if (strlen($instance) >= 4) {
        $list[] = 'https://' . substr($instance, 0, 4) . '.api.greenapi.com';
    }
    $list[] = 'https://api.green-api.com';
    return $list;
}

function green_call(array $acc, $method, $httpMethod = 'GET', $json = null, $query = '', $timeout = 25)
{
    $url = rtrim((string) $acc['api_url'], '/') . '/waInstance' . rawurlencode((string) $acc['api_instance'])
        . '/' . $method . '/' . rawurlencode((string) $acc['api_token']) . ($query !== '' ? '?' . $query : '');
    $opts = ['timeout' => $timeout];
    if ($json !== null) {
        $opts['json'] = $json;
    }
    return gw_http($httpMethod, $url, $opts);
}

function green_state(array $acc)
{
    $r = green_call($acc, 'getStateInstance', 'GET', null, '', 15);
    if ($p = gw_http_problem($r)) {
        return gw_state($p[0], $p[1]);
    }
    $st = is_array($r['json']) && isset($r['json']['stateInstance']) ? (string) $r['json']['stateInstance'] : '';
    switch ($st) {
        case 'authorized':
            return gw_state('connected');
        case 'notAuthorized':
            return gw_state('qr', 'الرقم غير مربوط — امسح رمز QR من واتساب جوالك');
        case 'starting':
            return gw_state('starting', 'الخدمة تشغّل الحساب — انتظر دقيقة');
        case 'sleepMode':
            return gw_state('starting', 'جوالك مطفي أو غير متصل بالإنترنت');
        case 'blocked':
            return gw_state('blocked', 'رقم الواتساب محظور من واتساب');
        case 'yellowCard':
        case 'suspended':
            return gw_state('suspended', 'واتساب أوقف الإرسال من هذا الرقم مؤقتاً (رسائل كثيرة) — خفّف الإرسال وكبّر المهلة');
    }
    if ($r['status'] === 400 || $r['status'] === 404) {
        return gw_state('auth_error', 'تأكد من رقم الـ Instance والرابط apiUrl');
    }
    return gw_state('error', 'رد غير متوقع من Green-API (' . $r['status'] . ')');
}

function green_qr(array $acc)
{
    $r = green_call($acc, 'qr', 'GET', null, '', 20);
    if ($p = gw_http_problem($r)) {
        return gw_state($p[0], $p[1]) + ['qr' => null];
    }
    $j = is_array($r['json']) ? $r['json'] : [];
    $type = isset($j['type']) ? (string) $j['type'] : '';
    $msg = isset($j['message']) && is_string($j['message']) ? $j['message'] : '';
    if ($type === 'qrCode' && $msg !== '') {
        $b64 = preg_replace('#^data:image/[a-z+]+;base64,#i', '', $msg);
        $b64 = preg_replace('#[^A-Za-z0-9+/=]#', '', $b64);
        return gw_state('qr') + ['qr' => 'data:image/png;base64,' . $b64];
    }
    if ($type === 'alreadyLogged') {
        return gw_state('connected') + ['qr' => null];
    }
    if ($type === 'passkeyRequired') {
        return gw_state('qr', 'واتساب يطلب تأكيد مفتاح المرور (Passkey) — كمّل الربط من لوحة Green-API') + ['qr' => null];
    }
    return gw_state('qr', $msg !== '' ? str_cut($msg, 150) : 'الرمز يتجهّز — ثواني ويظهر') + ['qr' => null];
}

function green_send(array $acc, $phone, $text)
{
    $r = green_call($acc, 'sendMessage', 'POST', ['chatId' => $phone . '@c.us', 'message' => $text], '', 25);
    if ($r['error'] !== null) {
        return ['result' => $r['maybe_sent'] ? 'maybe' : 'retry', 'note' => 'تعذّر الاتصال بالخدمة: ' . str_cut($r['error'], 120)];
    }
    if ($r['status'] === 200 && is_array($r['json']) && !empty($r['json']['idMessage'])) {
        return ['result' => 'ok', 'id' => str_cut((string) $r['json']['idMessage'], 100)];
    }
    if ($p = gw_http_problem($r)) {
        return $p[0] === 'error' ? ['result' => 'retry', 'note' => $p[1]] : ['result' => 'account', 'state' => $p[0], 'note' => $p[1]];
    }
    if ($r['status'] === 400) {
        return ['result' => 'invalid', 'note' => 'رفضت الخدمة الرقم أو الرسالة: ' . gw_body_note($r)];
    }
    return ['result' => 'retry', 'note' => 'رد غير متوقع من Green-API (' . $r['status'] . ')'];
}

/** رمز ربط من 8 خانات (بدل مسح QR): واتساب ← الأجهزة المرتبطة ← ربط جهاز ← الربط برقم الهاتف. */
function green_pair_code(array $acc, $phone)
{
    $r = green_call($acc, 'getAuthorizationCode', 'POST', ['phoneNumber' => PHP_INT_SIZE >= 8 ? (int) $phone : $phone], '', 25);
    if ($p = gw_http_problem($r)) {
        return ['ok' => false, 'state' => $p[0], 'note' => $p[1]];
    }
    $j = is_array($r['json']) ? $r['json'] : [];
    if (!empty($j['status']) && !empty($j['code']) && is_scalar($j['code'])) {
        return ['ok' => true, 'code' => str_cut(preg_replace('/[^A-Za-z0-9-]/', '', (string) $j['code']), 20)];
    }
    $note = gw_body_note($r);
    return ['ok' => false, 'state' => 'qr', 'note' => 'تعذّر طلب رمز الربط' . ($note !== '' ? ' (' . $note . ')' : '') . ' — تأكد إن الرقم مكتوب صح وإنه ما مربوط من قبل'];
}

function green_logout(array $acc)
{
    $r = green_call($acc, 'logout', 'GET', null, '', 20);
    return $r['error'] === null && $r['status'] === 200;
}

function green_phone(array $acc)
{
    $r = green_call($acc, 'getWaSettings', 'GET', null, '', 15);
    if ($r['status'] === 200 && is_array($r['json']) && !empty($r['json']['phone'])) {
        $d = preg_replace('/\D+/', '', (string) $r['json']['phone']);
        return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
    }
    return null;
}

/** حالات الرسائل اللي ما وصلت (آخر ساعة ونص): الرقم ما عنده واتساب أو فشل التوصيل. */
function green_failed_statuses(array $acc)
{
    $r = green_call($acc, 'lastOutgoingMessages', 'GET', null, 'minutes=90', 30);
    if ($r['error'] !== null || $r['status'] !== 200 || !is_array($r['json'])) {
        return null;
    }
    $out = [];
    foreach ($r['json'] as $m) {
        if (!is_array($m) || empty($m['idMessage']) || !isset($m['statusMessage'])) {
            continue;
        }
        if ($m['statusMessage'] === 'noAccount') {
            $out[(string) $m['idMessage']] = 'الرقم ما عنده واتساب';
        } elseif ($m['statusMessage'] === 'failed') {
            $out[(string) $m['idMessage']] = 'فشل توصيل الرسالة';
        }
    }
    return $out;
}

// --------------------------------------------------------------- UltraMsg

function ultra_base()
{
    $base = gw_test_mode() && cfg('ultramsg_base') ? (string) cfg('ultramsg_base') : 'https://api.ultramsg.com';
    return rtrim($base, '/');
}

function ultra_call(array $acc, $path, $httpMethod = 'GET', array $params = [], $timeout = 25)
{
    $params = ['token' => (string) $acc['api_token']] + $params;
    $url = ultra_base() . '/' . rawurlencode((string) $acc['api_instance']) . '/' . $path;
    if ($httpMethod === 'GET') {
        return gw_http('GET', $url . '?' . http_build_query($params, '', '&'), ['timeout' => $timeout]);
    }
    return gw_http('POST', $url, ['form' => $params, 'timeout' => $timeout]);
}

/** UltraMsg يرجّع غالباً 200 مع {"error": "..."} — نطلّع نص الخطأ إذا موجود. */
function ultra_error(array $r)
{
    if (!is_array($r['json']) || !isset($r['json']['error'])) {
        return null;
    }
    $e = $r['json']['error'];
    $txt = trim(gw_flatten($e));
    return $txt === '' ? 'خطأ غير معروف' : str_cut($txt, 200);
}

/** يحدد نوع خطأ UltraMsg: اشتراك منتهي، بيانات غلط، أو غيره. */
function ultra_error_state($err)
{
    $e = strtolower($err);
    foreach (['expire', 'subscription', 'payment', 'paid', 'renew', 'stopped', 'suspend'] as $w) {
        if (str_contains($e, $w)) {
            return gw_state('quota', 'اشتراك UltraMsg منتهي أو موقوف — جدّده من لوحة الخدمة (' . $err . ')');
        }
    }
    foreach (['token', 'instance', 'unauthorized', 'not found', 'invalid id'] as $w) {
        if (str_contains($e, $w)) {
            return gw_state('auth_error', 'رقم الـ Instance أو الرمز (Token) غير صحيح (' . $err . ')');
        }
    }
    return gw_state('error', 'UltraMsg: ' . $err);
}

function ultra_state(array $acc)
{
    $r = ultra_call($acc, 'instance/status', 'GET', [], 15);
    if (($err = ultra_error($r)) !== null) {
        return ultra_error_state($err);
    }
    if ($p = gw_http_problem($r)) {
        return gw_state($p[0], $p[1]);
    }
    $j = is_array($r['json']) ? $r['json'] : [];
    $st = '';
    if (isset($j['status']['accountStatus']['status'])) {
        $st = $j['status']['accountStatus']['status'];
    } elseif (isset($j['accountStatus']['status'])) {
        $st = $j['accountStatus']['status'];
    } elseif (isset($j['status']) && is_string($j['status'])) {
        $st = $j['status'];
    }
    switch (strtolower((string) $st)) {
        case 'authenticated':
            return gw_state('connected');
        case 'qr':
        case 'initialize':
        case 'disconnected':
            return gw_state('qr', 'الرقم غير مربوط — امسح رمز QR من واتساب جوالك');
        case 'loading':
        case 'retrying':
            return gw_state('starting', 'الخدمة تشغّل الحساب — انتظر دقيقة');
        case 'standby':
            return gw_state('starting', 'جوالك غير متصل بالإنترنت — افتح واتساب بالجوال');
    }
    if ($r['status'] === 400 || $r['status'] === 404) {
        return gw_state('auth_error', 'تأكد من رقم الـ Instance');
    }
    return gw_state('error', 'رد غير متوقع من UltraMsg (' . $r['status'] . ')');
}

function ultra_qr(array $acc)
{
    $st = ultra_state($acc);
    if ($st['state'] !== 'qr') {
        return $st + ['qr' => null];
    }
    $r = ultra_call($acc, 'instance/qr', 'GET', [], 20);
    $body = $r['body'];
    if ($r['error'] === null && $r['status'] === 200 && strlen($body) > 20) {
        $mime = null;
        if (strncmp($body, "\x89PNG", 4) === 0) {
            $mime = 'image/png';
        } elseif (strncmp($body, "\xFF\xD8", 2) === 0) {
            $mime = 'image/jpeg';
        } elseif (str_contains($r['type'], 'svg') || stripos(ltrim($body), '<svg') === 0 || stripos(ltrim($body), '<?xml') === 0) {
            $mime = 'image/svg+xml';
        }
        if ($mime) {
            return gw_state('qr') + ['qr' => 'data:' . $mime . ';base64,' . base64_encode($body)];
        }
    }
    return gw_state('qr', 'الرمز يتجهّز — ثواني ويظهر') + ['qr' => null];
}

function ultra_send(array $acc, $phone, $text)
{
    $r = ultra_call($acc, 'messages/chat', 'POST', ['to' => '+' . $phone, 'body' => $text], 25);
    if ($r['error'] !== null) {
        return ['result' => $r['maybe_sent'] ? 'maybe' : 'retry', 'note' => 'تعذّر الاتصال بالخدمة: ' . str_cut($r['error'], 120)];
    }
    $j = is_array($r['json']) ? $r['json'] : [];
    if (isset($j['sent']) && ($j['sent'] === true || $j['sent'] === 'true')) {
        return ['result' => 'ok', 'id' => isset($j['id']) && is_scalar($j['id']) ? str_cut((string) $j['id'], 100) : null];
    }
    if (($err = ultra_error($r)) !== null) {
        $st = ultra_error_state($err);
        if ($st['state'] !== 'error') {
            return ['result' => 'account', 'state' => $st['state'], 'note' => $st['note']];
        }
        $e = strtolower($err);
        foreach (['phone', 'number', 'to:', 'chatid', 'not valid', 'invalid'] as $w) {
            if (str_contains($e, $w)) {
                return ['result' => 'invalid', 'note' => 'رفضت الخدمة الرقم: ' . $err];
            }
        }
        return ['result' => 'retry', 'note' => 'UltraMsg: ' . $err];
    }
    if ($p = gw_http_problem($r)) {
        return $p[0] === 'error' ? ['result' => 'retry', 'note' => $p[1]] : ['result' => 'account', 'state' => $p[0], 'note' => $p[1]];
    }
    if (isset($j['sent']) && ($j['sent'] === false || $j['sent'] === 'false')) {
        return ['result' => 'invalid', 'note' => 'ما انرسلت: ' . gw_body_note($r)];
    }
    return ['result' => 'retry', 'note' => 'رد غير متوقع من UltraMsg (' . $r['status'] . ')'];
}

function ultra_logout(array $acc)
{
    $r = ultra_call($acc, 'instance/logout', 'POST', [], 20);
    return $r['error'] === null && $r['status'] === 200 && ultra_error($r) === null;
}

function ultra_phone(array $acc)
{
    $r = ultra_call($acc, 'instance/me', 'GET', [], 15);
    if ($r['status'] === 200 && is_array($r['json']) && !empty($r['json']['id']) && is_string($r['json']['id'])) {
        $d = preg_replace('/\D+/', '', explode('@', $r['json']['id'])[0]);
        return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
    }
    return null;
}

function ultra_failed_statuses(array $acc)
{
    $r = ultra_call($acc, 'messages', 'GET', ['page' => 1, 'limit' => 100, 'status' => 'invalid', 'sort' => 'desc'], 30);
    if ($r['error'] !== null || $r['status'] !== 200 || !is_array($r['json']) || !isset($r['json']['messages']) || !is_array($r['json']['messages'])) {
        return null;
    }
    $out = [];
    foreach ($r['json']['messages'] as $m) {
        if (is_array($m) && isset($m['id']) && is_scalar($m['id']) && ($m['status'] ?? '') === 'invalid') {
            $out[(string) $m['id']] = 'الرقم ما عنده واتساب';
        }
    }
    return $out;
}

// --------------------------------------------------------------- واجهة موحّدة

function gw_check_state(array $acc)
{
    switch ($acc['provider']) {
        case 'greenapi':
            return green_state($acc);
        case 'ultramsg':
            return ultra_state($acc);
    }
    return gw_state('error', 'خدمة غير معروفة');
}

function gw_qr(array $acc)
{
    switch ($acc['provider']) {
        case 'greenapi':
            return green_qr($acc);
        case 'ultramsg':
            return ultra_qr($acc);
    }
    return gw_state('error', 'خدمة غير معروفة') + ['qr' => null];
}

/**
 * يرسل رسالة نصية. result:
 *   ok      انقبلت (id = رقمها عند الخدمة)
 *   maybe   انقطع الاتصال بعد الإرسال — نعتبرها انرسلت حتى ما تتكرر
 *   invalid الخدمة رفضت الرقم/الرسالة (فشل نهائي لهذا الرقم)
 *   retry   مشكلة مؤقتة — نعيد المحاولة لاحقاً
 *   account مشكلة بالحساب نفسه (state) — نوقف إرسال هذا الحساب لحد ما تنحل
 */
function gw_send(array $acc, $phone, $text)
{
    switch ($acc['provider']) {
        case 'greenapi':
            return green_send($acc, $phone, $text);
        case 'ultramsg':
            return ultra_send($acc, $phone, $text);
    }
    return ['result' => 'account', 'state' => 'auth_error', 'note' => 'خدمة غير معروفة'];
}

/** رمز ربط برقم الهاتف — Green-API فقط (UltraMsg تدعم مسح QR بس). */
function gw_pair_code(array $acc, $phone)
{
    if ($acc['provider'] === 'greenapi') {
        return green_pair_code($acc, $phone);
    }
    return ['ok' => false, 'state' => 'qr', 'note' => gw_provider_name($acc['provider']) . ' تدعم الربط بمسح رمز QR فقط — افتح هذه الصفحة من جهاز ثاني (كمبيوتر أو جوال ثاني) وامسح الرمز بجوالك'];
}

function gw_logout(array $acc)
{
    switch ($acc['provider']) {
        case 'greenapi':
            return green_logout($acc);
        case 'ultramsg':
            return ultra_logout($acc);
    }
    return false;
}

function gw_phone(array $acc)
{
    switch ($acc['provider']) {
        case 'greenapi':
            return green_phone($acc);
        case 'ultramsg':
            return ultra_phone($acc);
    }
    return null;
}

/** [رقم الرسالة عند الخدمة => سبب الفشل] أو null إذا الخدمة ما وفّرت المعلومة. */
function gw_failed_statuses(array $acc)
{
    switch ($acc['provider']) {
        case 'greenapi':
            return green_failed_statuses($acc);
        case 'ultramsg':
            return ultra_failed_statuses($acc);
    }
    return null;
}

// --------------------------------------------------------------- حفظ حالة الحساب

/**
 * يحفظ حالة الحساب، ويرسل إشعار لصاحبه إذا حساب كان شغّال انقطع ويحتاج
 * تدخّل منه (مو أثناء الربط لأول مرة — هناك هو قدّام الشاشة أصلاً).
 */
function account_store_state(array $acc, array $st, $notify = true)
{
    $state = $st['state'];
    $note = str_cut((string) ($st['note'] ?? ''), 255);
    q('UPDATE wa_accounts SET api_state = ?, api_note = ?, api_checked_at = ? WHERE id = ?', [$state, $note, now_dt(), (int) $acc['id']]);
    if ($state === 'connected' && empty($acc['api_phone'])) {
        $phone = gw_phone($acc);
        if ($phone) {
            q('UPDATE wa_accounts SET api_phone = ?, phone = COALESCE(phone, ?) WHERE id = ?', [$phone, $phone, (int) $acc['id']]);
            $acc['api_phone'] = $phone;
        }
    }
    $old = (string) $acc['api_state'];
    $wasWorking = $old === 'connected' || !empty($acc['api_phone']);
    if ($notify && $wasWorking && $old !== $state && gw_state_needs_action($state) && !gw_state_needs_action($old)) {
        $titles = [
            'qr' => 'انفصل رقم الواتساب عن الإرسال التلقائي',
            'blocked' => 'رقم الواتساب محظور',
            'suspended' => 'واتساب أوقف الإرسال مؤقتاً',
            'quota' => 'الإرسال التلقائي متوقف — الباقة/الاشتراك',
            'auth_error' => 'بيانات API غير صحيحة',
        ];
        notify(
            $acc['owner_id'],
            $titles[$state],
            ($note !== '' ? $note . ' — ' : '') . 'الإرسال متوقف لهذا الرقم. افتح "حسابي" ← "ربط الإرسال التلقائي".',
            'warning',
            $acc['name']
        );
    }
    $acc['api_state'] = $state;
    $acc['api_note'] = $note;
    $acc['api_checked_at'] = now_dt();
    return $acc;
}
