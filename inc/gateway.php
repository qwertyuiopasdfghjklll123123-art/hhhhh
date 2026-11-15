<?php
/**
 * واصل — الربط مع البوابة الخاصة (الإرسال التلقائي بدون أي اشتراك أو تثبيت على أجهزة الفروع).
 *
 * البوابة (مجلد wasel-gateway) تطبيق Node.js صغير يشتغل على استضافتك نفسها
 * (Setup Node.js App بـ cPanel و DirectAdmin، أو Node.js بهوستنجر)، ويشغّل
 * "واتساب ويب" لأرقام الفروع. كل رقم يرتبط مرة وحدة بمسح رمز QR من واتساب
 * الجوال (الأجهزة المرتبطة ← ربط جهاز) أو برمز ربط من 8 خانات، وبعدها الموقع
 * يرسل الحملات بنفسه — بدون ما ينفتح واتساب بأي جهاز.
 *
 * حالات الرقم الموحّدة:
 *   connected  متصل وجاهز للإرسال
 *   qr         يحتاج ربط (مسح QR أو رمز الربط)
 *   starting   البوابة تتصل بواتساب (مؤقت)
 *   auth_error البوابة مو مفعّلة أو مفتاحها غلط
 *   error      البوابة ما ترد أو واتساب رفض الاتصال (نعيد المحاولة تلقائياً)
 */

require_once __DIR__ . '/core.php';

/** اسم طريقة الإرسال كما يظهر بالواجهة. */
function gw_provider_name($provider = 'self')
{
    return 'سيرفرك الخاص';
}

/** الحالات اللي تحتاج تدخّل من صاحب الحساب (نرسل له إشعار لما يصير فيها). */
function gw_state_needs_action($state)
{
    return in_array($state, ['qr', 'auth_error'], true);
}

/**
 * البوابة صارت شغّالة (الأدمن صلّحها): نفحص من جديد الأرقام اللي توقفت بسببها، حتى يرجع
 * إرسالها فوراً بدل ما تنتظر الفحص الدوري (كل 10 دقايق). محدود بالوقت — الباقي ينفحص بأول تشغيل.
 */
function gateway_recheck_accounts($seconds)
{
    $bad = q_all("SELECT * FROM wa_accounts WHERE provider = 'self' AND api_state IN ('auth_error', 'error') ORDER BY id LIMIT 200");
    if (!$bad) {
        return;
    }
    q("UPDATE wa_accounts SET api_checked_at = NULL WHERE provider = 'self' AND api_state IN ('auth_error', 'error')");
    $deadline = microtime(true) + $seconds;
    foreach ($bad as $acc) {
        if (microtime(true) > $deadline) {
            break;
        }
        account_store_state($acc, gw_check_state($acc));
    }
}

/**
 * ملاحظة البوابة كما يشوفها المستخدم: الأدمن يشوف التفاصيل التقنية (حتى يصلحها)، والفرع
 * يشوف شنو يسوي — مشاكل البوابة نفسها بيد الأدمن مو بيده.
 */
function gw_note_for($state, $note, $isAdmin = null)
{
    if ($isAdmin === null) {
        $u = current_user();
        $isAdmin = !$u || !empty($u['is_admin']);
    }
    if ($isAdmin || ($state !== 'error' && $state !== 'auth_error')) {
        return (string) $note;
    }
    return $state === 'error'
        ? 'البوابة الخاصة ما ترد حالياً — بلّغ أدمن المنصة يتأكد إنها شغّالة. الرسائل تنتظر وتكمّل لحالها أول ما ترجع.'
        : 'البوابة الخاصة متوقفة — بلّغ أدمن المنصة يصلحها من لوحة التحكم. الرسائل تنتظر وتكمّل لحالها أول ما ترجع.';
}

// --------------------------------------------------------------- HTTP

function gw_http($method, $url, array $opts = [])
{
    $timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 25;
    $headers = ['Accept: application/json, image/*;q=0.8, */*;q=0.5', 'User-Agent: Wasel/' . WASEL_VERSION];
    if (!empty($opts['headers'])) {
        $headers = array_merge($headers, $opts['headers']);
    }
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

function gw_state($state, $note = '')
{
    return ['state' => $state, 'note' => (string) $note];
}

// --------------------------------------------------------------- البوابة الخاصة (Node.js على نفس الاستضافة)

/** رابط البوابة ومفتاحها (من لوحة تحكم الأدمن). */
function self_conf()
{
    $s = site_settings();
    return [rtrim((string) ($s['gateway_url'] ?? ''), '/'), (string) ($s['gateway_key'] ?? '')];
}

function self_ready()
{
    return self_conf()[0] !== '';
}

/** رابط صالح للبوابة (http/https فقط، بدون استعلام). */
function self_url_normalize($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^[a-z]+://#i', $url)) {
        $url = 'https://' . $url;
    }
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['query'])) {
        return null;
    }
    $path = isset($p['path']) ? rtrim($p['path'], '/') : '';
    if (preg_match('#/api(/.*)?$#', $path)) {
        $path = preg_replace('#/api(/.*)?$#', '', $path);   // لصق رابط فيه /api/... بالغلط
    }
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $path;
}

/** مسار مجلد البوابة داخل الموقع (إذا موجود) — حتى نكتب المفتاح فيه تلقائياً. */
function self_folder()
{
    $dir = WASEL_ROOT . '/wasel-gateway';
    return is_dir($dir) && is_file($dir . '/app.js') ? $dir : null;
}

/**
 * يكتب المفتاح السري بملف wasel-key.php داخل مجلد البوابة — البوابة تقراه
 * تلقائياً. ملف PHP يطلع فاضي إذا أحد فتحه من المتصفح.
 */
function self_write_key_file()
{
    $dir = self_folder();
    if (!$dir) {
        return false;
    }
    list(, $key) = self_conf();
    $php = "<?php http_response_code(404); exit; ?>\n" . $key . "\n";
    $file = $dir . '/wasel-key.php';
    if (is_file($file) && (string) @file_get_contents($file) === $php) {
        return true;
    }
    if (@file_put_contents($file, $php, LOCK_EX) === false) {
        return false;
    }
    @chmod($file, 0640);
    return true;
}

function self_call($method, $path, array $query = [], $body = null, $timeout = 25)
{
    list($base, $key) = self_conf();
    if ($base === '') {
        return ['status' => 0, 'body' => '', 'type' => '', 'json' => null, 'error' => 'not_configured', 'maybe_sent' => false];
    }
    $url = $base . $path . ($query ? '?' . http_build_query($query, '', '&') : '');
    $opts = ['timeout' => $timeout, 'headers' => ['X-Wasel-Key: ' . $key]];
    if ($body !== null) {
        $opts['json'] = $body;
    }
    return gw_http($method, $url, $opts);
}

/** مشكلة بالاتصال بالبوابة نفسها — أو null إذا ردّت طبيعي. */
function self_problem(array $r)
{
    if ($r['error'] === 'not_configured') {
        return ['auth_error', 'البوابة الخاصة مو مفعّلة بعد — الأدمن يفعّلها من لوحة التحكم ← البوابة الخاصة'];
    }
    if ($r['error'] !== null) {
        return ['error', 'البوابة الخاصة ما ترد — تأكد إن تطبيق Node.js شغّال بلوحة الاستضافة وإن الرابط صحيح (' . str_cut($r['error'], 100) . ')'];
    }
    $j = is_array($r['json']) ? $r['json'] : [];
    $err = isset($j['error']) && is_string($j['error']) ? $j['error'] : '';
    if ($r['status'] === 401) {
        return ['auth_error', 'مفتاح البوابة غير متطابق — من لوحة التحكم اضغط "حفظ واختبار" للبوابة، أو حدّث متغيّر WASEL_KEY بتطبيق Node.js'];
    }
    if ($r['status'] === 503 && $err === 'no_key') {
        return ['auth_error', 'البوابة ما عندها مفتاح — من لوحة التحكم اضغط "حفظ واختبار" للبوابة'];
    }
    if ($r['status'] === 503) {
        return ['starting', 'البوابة تشتغل — ثواني'];
    }
    if ($r['status'] !== 200 || !$j) {
        return ['error', 'رد غير متوقع من البوابة (' . $r['status'] . ') — تأكد من رابط تطبيق Node.js'];
    }
    return null;
}

function self_map_state(array $j)
{
    $note = isset($j['note']) && is_string($j['note']) ? $j['note'] : '';
    switch ($j['state'] ?? '') {
        case 'connected':
            return gw_state('connected');
        case 'qr':
            return gw_state('qr', 'الرقم غير مربوط — امسح رمز QR من واتساب جوالك');
        case 'starting':
            return gw_state('starting', 'البوابة تتصل بواتساب — ثواني');
    }
    return gw_state('error', $note !== '' ? $note : 'خطأ بالبوابة الخاصة');
}

function self_state(array $acc)
{
    $r = self_call('GET', '/api/state', ['session' => $acc['api_instance']], null, 20);
    if ($p = self_problem($r)) {
        return gw_state($p[0], $p[1]);
    }
    return self_map_state($r['json']);
}

function self_qr(array $acc)
{
    $r = self_call('GET', '/api/qr', ['session' => $acc['api_instance']], null, 30);
    if ($p = self_problem($r)) {
        return gw_state($p[0], $p[1]) + ['qr' => null];
    }
    $j = $r['json'];
    $st = self_map_state($j);
    $qr = isset($j['qr']) && is_string($j['qr']) && preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $j['qr']) ? $j['qr'] : null;
    if ($st['state'] === 'qr' && !$qr) {
        $st['note'] = 'الرمز يتجهّز — ثواني ويظهر';
    }
    return $st + ['qr' => $qr];
}

function self_send(array $acc, $phone, $text)
{
    $r = self_call('POST', '/api/send', [], ['session' => $acc['api_instance'], 'phone' => $phone, 'text' => $text], 60);
    if ($r['error'] !== null && $r['error'] !== 'not_configured') {
        return ['result' => $r['maybe_sent'] ? 'maybe' : 'retry', 'note' => 'تعذّر الاتصال بالبوابة: ' . str_cut($r['error'], 120)];
    }
    if ($p = self_problem($r)) {
        return $p[0] === 'error' ? ['result' => 'retry', 'note' => $p[1]] : ['result' => 'account', 'state' => $p[0], 'note' => $p[1]];
    }
    $j = $r['json'];
    if (!empty($j['ok'])) {
        return ['result' => 'ok', 'id' => isset($j['id']) && is_string($j['id']) ? str_cut($j['id'], 100) : null];
    }
    switch ($j['error'] ?? '') {
        case 'not_on_whatsapp':
            return ['result' => 'invalid', 'note' => 'الرقم ما عنده واتساب'];
        case 'not_connected':
            $st = self_map_state(['state' => $j['state'] ?? 'starting']);
            return ['result' => 'account', 'state' => $st['state'], 'note' => $st['note']];
    }
    return ['result' => 'retry', 'note' => 'البوابة: ' . str_cut((string) ($j['message'] ?? $j['error'] ?? 'خطأ'), 150)];
}

function self_pair_code(array $acc, $phone)
{
    $r = self_call('POST', '/api/pair', [], ['session' => $acc['api_instance'], 'phone' => $phone], 45);
    if ($p = self_problem($r)) {
        return ['ok' => false, 'state' => $p[0], 'note' => $p[1]];
    }
    $j = $r['json'];
    if (!empty($j['ok']) && !empty($j['code'])) {
        return ['ok' => true, 'code' => str_cut(preg_replace('/[^A-Za-z0-9-]/', '', (string) $j['code']), 20)];
    }
    if (($j['error'] ?? '') === 'already_connected') {
        return ['ok' => false, 'state' => 'connected', 'note' => 'الرقم مربوط أصلاً'];
    }
    return ['ok' => false, 'state' => 'qr', 'note' => 'البوابة ما جهّزت رمز الربط بعد — حاول بعد ثواني'];
}

function self_logout(array $acc)
{
    $r = self_call('POST', '/api/logout', [], ['session' => $acc['api_instance']], 30);
    return self_problem($r) === null && !empty($r['json']['ok']);
}

function self_phone(array $acc)
{
    $r = self_call('GET', '/api/state', ['session' => $acc['api_instance']], null, 20);
    if (self_problem($r) === null && !empty($r['json']['phone']) && is_string($r['json']['phone'])) {
        $d = preg_replace('/\D+/', '', $r['json']['phone']);
        return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
    }
    return null;
}

/** فحص البوابة من لوحة التحكم (ويبقيها شغّالة: الـ Cron يناديها كل دقيقة). */
function self_info()
{
    $r = self_call('GET', '/api/info', [], null, 15);
    if ($p = self_problem($r)) {
        return ['ok' => false, 'note' => $p[1]];
    }
    $j = $r['json'];
    return [
        'ok' => true,
        'version' => isset($j['version']) ? (string) $j['version'] : '',
        'sessions' => (int) ($j['sessions'] ?? 0),
        'connected' => (int) ($j['connected'] ?? 0),
    ];
}

// --------------------------------------------------------------- واجهة موحّدة (يستخدمها المحرك والـ API)

function gw_check_state(array $acc)
{
    return self_state($acc);
}

function gw_qr(array $acc)
{
    return self_qr($acc);
}

/**
 * يرسل رسالة نصية. result:
 *   ok      انرسلت (id = رقمها عند واتساب)
 *   maybe   انقطع الاتصال بعد الإرسال — نعتبرها انرسلت حتى ما تتكرر
 *   invalid الرقم ما عنده واتساب أو انرفض (فشل نهائي لهذا الرقم)
 *   retry   مشكلة مؤقتة — نعيد المحاولة لاحقاً
 *   account مشكلة بالربط نفسه (state) — نوقف إرسال هذا الرقم لحد ما تنحل
 */
function gw_send(array $acc, $phone, $text)
{
    return self_send($acc, $phone, $text);
}

/** رمز ربط من 8 خانات بدل مسح QR (للي فاتح الموقع من نفس الجوال). */
function gw_pair_code(array $acc, $phone)
{
    return self_pair_code($acc, $phone);
}

function gw_logout(array $acc)
{
    return self_logout($acc);
}

function gw_phone(array $acc)
{
    return self_phone($acc);
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
    $old = (string) $acc['api_state'];
    $wasWorking = $old === 'connected' || !empty($acc['api_phone']);
    q('UPDATE wa_accounts SET api_state = ?, api_note = ?, api_checked_at = ? WHERE id = ?', [$state, $note, now_dt(), (int) $acc['id']]);
    if ($state === 'connected' && (empty($acc['api_phone']) || $old !== 'connected')) {
        // اتصل (أو رجع اتصل): نقرأ الرقم المربوط — يمكن انربط رقم غير الأول
        $phone = gw_phone($acc);
        if ($phone) {
            q('UPDATE wa_accounts SET api_phone = ?, phone = COALESCE(phone, ?) WHERE id = ?', [$phone, $phone, (int) $acc['id']]);
            $acc['api_phone'] = $phone;
        }
    } elseif ($state === 'qr' && !empty($acc['api_phone'])) {
        q('UPDATE wa_accounts SET api_phone = NULL WHERE id = ?', [(int) $acc['id']]);   // انفصل الرقم
        $acc['api_phone'] = null;
    }
    if ($notify && $wasWorking && $old !== $state && gw_state_needs_action($state) && !gw_state_needs_action($old)) {
        if ($state === 'qr') {
            $title = 'انفصل رقم الواتساب عن الإرسال التلقائي';
            $body = ($note !== '' ? $note . ' — ' : '') . 'الإرسال متوقف لهذا الرقم لحد ما تربطه من جديد: "حسابي" ← "ربط الرقم".';
        } else {
            $ownerIsAdmin = (int) q_val('SELECT is_admin FROM users WHERE id = ?', [(int) $acc['owner_id']]) === 1;
            $title = 'الإرسال التلقائي متوقف — البوابة الخاصة';
            $body = $ownerIsAdmin
                ? ($note !== '' ? $note . ' — ' : '') . 'الإرسال متوقف لحد ما تصلح البوابة من لوحة التحكم ← البوابة الخاصة.'
                : gw_note_for($state, $note, false);
        }
        notify($acc['owner_id'], $title, $body, 'warning', $acc['name']);
    }
    $acc['api_state'] = $state;
    $acc['api_note'] = $note;
    $acc['api_checked_at'] = now_dt();
    return $acc;
}
