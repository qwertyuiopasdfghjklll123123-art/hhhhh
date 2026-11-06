<?php
/**
 * بوابات الدفع: التحقق من Binance Pay + عميل آسياسيل (app.py:3941-4181).
 */

function _norm_currency_code($x): string {
    $x = mb_strtoupper(py_strip(py_or($x, '')), 'UTF-8');
    if ($x === '$' || $x === 'US$') return 'USD';
    $x = preg_replace('/[^A-Z]/', '', $x);
    if ($x === 'TETHER' || $x === 'TETHERUS') return 'USDT';
    return py_or($x, 'USDT');
}

function _currency_equivalent($a, $b): bool {
    $A = _norm_currency_code($a); $B = _norm_currency_code($b);
    if ($A === $B) return true;
    $usd = ['USD', 'USDT'];
    return in_array($A, $usd, true) && in_array($B, $usd, true);
}

/** @return array [bool verified, string|array result] */
function verify_binance_order($pm_row, $binance_order_id, $expected_amount_usd): array {
    try { $extras = json_loads(py_or($pm_row['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $api_key = py_strip(py_or(dget($extras, 'api_key'), ''));
    $api_secret = py_strip(py_or(dget($extras, 'api_secret'), ''));
    if (!py_truthy($api_key) || !py_truthy($api_secret)) {
        return [false, 'لم يتم إعداد مفاتيح Binance من الإدارة بعد.'];
    }
    $binance_order_id = py_strip(py_or($binance_order_id, ''));
    if (!py_truthy($binance_order_id)) {
        return [false, 'الرجاء إدخال رقم عملية Binance (Order ID).'];
    }

    $now_ms = (int)(microtime(true) * 1000);
    $params = [
        'timestamp' => $now_ms,
        'status' => '1',
        'startTime' => $now_ms - 7 * 24 * 60 * 60 * 1000,
        'endTime' => $now_ms,
    ];
    $qs = [];
    foreach ($params as $k => $v) $qs[] = "$k=$v";
    $query_string = implode('&', $qs);
    $signature = hash_hmac('sha256', $query_string, $api_secret);
    $url = 'https://api3.binance.com/sapi/v1/pay/transactions?' . $query_string . '&signature=' . $signature;
    try {
        $resp = requests_get($url, ['headers' => ['X-MBX-APIKEY' => $api_key], 'timeout' => 30]);
        $data = $resp->json();
    } catch (Throwable $e) {
        return [false, 'تعذر الاتصال بـ Binance: ' . $e->getMessage()];
    }

    $rows = (is_array($data) && !is_list_array($data)) ? dget($data, 'data') : null;
    if (!is_array($rows) || !(is_list_array($rows))) {
        return [false, 'استجابة غير متوقعة من Binance. تأكد من صحة مفاتيح API.'];
    }

    $found = null;
    foreach ($rows as $row) {
        if (py_str(dget($row, 'orderId', '')) === $binance_order_id) { $found = $row; break; }
    }
    if (!$found) {
        return [false, 'لم يتم العثور على عملية دفع بهذا الرقم خلال آخر 7 أيام.'];
    }

    $binance_ccy = py_str(dget($found, 'currency', ''));
    if (!_currency_equivalent('USD', $binance_ccy)) {
        return [false, 'عملة العملية غير مطابقة (' . mb_strtoupper($binance_ccy, 'UTF-8') . ').'];
    }

    try { $binance_amount = py_float(dget($found, 'amount', 0)); }
    catch (PyValueError | PyTypeError $e) { $binance_amount = 0.0; }
    if (abs($binance_amount - $expected_amount_usd) >= 0.0001) {
        return [false, 'المبلغ غير مطابق. المتوقع $' . py_fmt($expected_amount_usd) . ' والمستلم $' . py_fmt($binance_amount) . '.'];
    }
    return [true, $found];
}

/** عميل غير رسمي لواجهة تطبيق آسياسيل (الحالة تُخزَّن كـ dict داخل جدول asiacell_flows) */
final class AsiaCellAPI {
    public $device_id; public $api_key; public $access_token; public $pid; public $transfer_pid;
    public $last_url = null; public $last_raw = null;

    public function __construct($state = null) {
        $state = $state ?: [];
        $this->device_id = py_or(dget($state, 'deviceId'), uuid4());
        $this->api_key = py_or(dget($state, 'apiKey'), token_hex(16));
        $this->access_token = dget($state, 'accessToken');
        $this->pid = dget($state, 'pid');
        $this->transfer_pid = dget($state, 'transferPid');
    }

    private function _headers(): array {
        $h = [
            'User-Agent' => 'okhttp/5.0.0-alpha.2',
            'Connection' => 'Keep-Alive',
            'Accept-Encoding' => 'gzip',
            'Cache-Control' => 'no-cache',
            'X-OS-Version' => '11',
            'X-Device-Type' => '[Android][realme][RMX3834 11][TIRAMISU][HMS][4.3.7:90000325]',
            'X-ODP-APP-VERSION' => '4.3.7',
            'X-FROM-APP' => 'odp',
            'X-ODP-CHANNEL' => 'mobile',
            'X-SCREEN-TYPE' => 'false',
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-ODP-API-KEY' => $this->api_key,
            'DeviceID' => $this->device_id,
        ];
        if (py_truthy($this->access_token)) $h['Authorization'] = 'Bearer ' . $this->access_token;
        return $h;
    }

    public function get_state(): array {
        return ['deviceId' => $this->device_id, 'apiKey' => $this->api_key, 'accessToken' => $this->access_token,
                'pid' => $this->pid, 'transferPid' => $this->transfer_pid];
    }

    public function get_last_call(): array { return ['url' => $this->last_url, 'raw' => $this->last_raw]; }

    private static function _pick($data, array $keys) {
        foreach ($keys as $k) if (py_truthy(dget($data, $k))) return $data[$k];
        return null;
    }

    private static function _extract_message($data, string $fallback) {
        $msg = self::_pick($data, ['message', 'error', 'errorMessage', 'desc']);
        if (py_truthy($msg)) return $msg;
        $next_action = dget($data, 'nextAction');
        if (py_truthy($next_action)) {
            try {
                $q = (string)parse_url((string)$next_action, PHP_URL_QUERY);
                parse_str($q, $params);
                if (py_truthy(dget($params, 'msg'))) return $params['msg'];
            } catch (Throwable $e) {}
        }
        return $fallback;
    }

    private function _post_json(string $url, array $payload): ?array {
        $this->last_url = $url;
        try {
            $resp = requests_post($url, ['data' => py_json_dumps($payload), 'headers' => $this->_headers(), 'timeout' => 30, 'verify' => false]);
            $this->last_raw = $resp->text;
        } catch (Throwable $e) {
            $this->last_raw = 'connection error: ' . $e->getMessage();
            return null;
        }
        try {
            $data = $resp->json();
            return (is_array($data) && ($data === [] || !is_list_array($data))) ? $data : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** الخطوة 1: تسجيل الدخول برقم الهاتف وإرسال رمز SMS */
    public function login($phone_number): array {
        $data = $this->_post_json('https://odpapp.asiacell.com/api/v1/login?lang=en', ['captchaCode' => '', 'username' => $phone_number]);
        if (!py_truthy($data)) return [false, 'لا يوجد رد من خادم آسياسيل، حاول لاحقاً.'];
        if (dget($data, 'success') === false) return [false, self::_extract_message($data, 'فشل إرسال رمز التحقق إلى هذا الرقم.')];
        $pid = self::_pick($data, ['PID', 'pid']);
        if (!py_truthy($pid) && py_truthy(dget($data, 'nextUrl'))) {
            if (preg_match('/PID=([a-zA-Z0-9\-]+)/', (string)$data['nextUrl'], $m)) $pid = $m[1];
        }
        if (!py_truthy($pid)) return [false, self::_extract_message($data, 'تعذر بدء عملية تسجيل الدخول.')];
        $this->pid = $pid;
        return [true, $this->pid];
    }

    /** الخطوة 2: التحقق من رمز SMS الأول */
    public function verify_sms($passcode): array {
        $data = $this->_post_json('https://odpapp.asiacell.com/api/v1/smsvalidation?lang=en', ['PID' => $this->pid, 'passcode' => $passcode, 'token' => '']);
        if (!py_truthy($data)) return [false, 'لا يوجد رد من خادم آسياسيل، حاول لاحقاً.'];
        $token = self::_pick($data, ['access_token', 'accessToken', 'token']);
        if (dget($data, 'success') === false || !py_truthy($token)) return [false, self::_extract_message($data, 'رمز التحقق غير صحيح.')];
        $this->access_token = $token;
        return [true, $this->access_token];
    }

    /** الخطوة 3: بدء تحويل الرصيد */
    public function start_transfer($amount_iqd, $receiver_msisdn): array {
        $data = $this->_post_json('https://odpapp.asiacell.com/api/v1/credit-transfer/start?lang=ar', ['amount' => py_int($amount_iqd), 'receiverMsisdn' => $receiver_msisdn]);
        if (!py_truthy($data)) return [false, 'لا يوجد رد من خادم آسياسيل، حاول لاحقاً.'];
        $pid = self::_pick($data, ['PID', 'pid']);
        if (!py_truthy($pid) && py_truthy(dget($data, 'nextUrl'))) {
            if (preg_match('/PID=([a-zA-Z0-9\-]+)/', (string)$data['nextUrl'], $m)) $pid = $m[1];
        }
        if (!py_truthy($pid)) return [false, self::_extract_message($data, 'تعذر بدء عملية التحويل.')];
        $this->transfer_pid = $pid;
        return [true, $pid];
    }

    /** الخطوة 4: تأكيد التحويل برمز SMS الثاني */
    public function confirm_transfer($passcode): array {
        $data = $this->_post_json('https://odpapp.asiacell.com/api/v1/credit-transfer/do-transfer?lang=ar', ['PID' => $this->transfer_pid, 'passcode' => $passcode]);
        if (!py_truthy($data)) return [false, 'لا يوجد رد من خادم آسياسيل، حاول لاحقاً.'];
        if (py_truthy(dget($data, 'success'))) return [true, $data];
        return [false, self::_extract_message($data, 'فشل تأكيد التحويل.')];
    }
}

function log_asiacell_debug(string $step, AsiaCellAPI $api): void {
    $call = $api->get_last_call();
    $raw = py_str(py_or($call['raw'], ''));
    set_setting('asiacell_last_debug', py_json_dumps([
        'step' => $step, 'url' => $call['url'], 'raw' => py_slice($raw, null, 1500),
        'time' => date('Y-m-d H:i:s'),
    ], false));
}

function _asiacell_flow_get($user_id): ?array {
    $conn = get_db();
    $row = $conn->execute('SELECT data FROM asiacell_flows WHERE user_id=?', [$user_id])->fetchone();
    if (!$row) return null;
    try { $d = json_loads($row['data']); return is_array($d) ? $d : null; }
    catch (Throwable $e) { return null; }
}

function _asiacell_flow_set($user_id, array $flow): void {
    $conn = get_db();
    $conn->execute('INSERT OR REPLACE INTO asiacell_flows(user_id, data, created_at) VALUES(?,?,?)',
        [$user_id, py_json_dumps($flow), microtime(true)]);
    $conn->commit();
}

function _asiacell_flow_clear($user_id): void {
    $conn = get_db();
    $conn->execute('DELETE FROM asiacell_flows WHERE user_id=?', [$user_id]);
    $conn->commit();
}

const ASIACELL_TIER_AMOUNTS = [1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000];

function _default_asiacell_tiers(): array {
    return array_map(fn($v) => ['iqd' => $v, 'bonus' => 0], ASIACELL_TIER_AMOUNTS);
}
