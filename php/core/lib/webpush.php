<?php
/**
 * Web Push أصلي بـ PHP (RFC 8291 aes128gcm + RFC 8292 VAPID) — بديل pywebpush.
 * يستعمل نفس ملفات بايثون: fc_vapid.json و fc_push_subs.json (مفاتيح وملف اشتراكات متوافقة، فلا يفقد المشتركون اشتراكهم).
 */

function vapid_file(): string { return DATA_DIR . '/fc_vapid.json'; }
function push_subs_file(): string { return DATA_DIR . '/fc_push_subs.json'; }

function _b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function _b64u_d(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** _get_vapid_keys  (app.py:537) → ['private' => PEM, 'public' => base64url(uncompressed point)] */
function get_vapid_keys(): array {
    static $keys = null;
    if ($keys !== null) return $keys;
    $f = vapid_file();
    if (is_file($f)) {
        $data = json_decode((string)file_get_contents($f), true);
        if (is_array($data) && !empty($data['private']) && !empty($data['public'])) return $keys = $data;
        @unlink($f);
    }
    $keys = ['private' => '', 'public' => ''];
    try {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($k) {
            openssl_pkey_export($k, $pem);
            $d = openssl_pkey_get_details($k);
            $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $keys = ['private' => $pem, 'public' => _b64u($pub)];
        }
    } catch (Throwable $e) {}
    if ($keys['private'] && $keys['public']) @file_put_contents($f, json_encode($keys));
    return $keys;
}

function load_push_subs(): array {
    $f = push_subs_file();
    if (is_file($f)) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    return [];
}

function save_push_subs(array $subs): void {
    @file_put_contents(push_subs_file(), json_encode(empty($subs) ? new stdClass() : $subs), LOCK_EX);
}

/** تعديل ذرّي لملف الاشتراكات (قراءة-تعديل-كتابة تحت قفل) */
function update_push_subs(callable $fn): void {
    $f = push_subs_file();
    $fp = fopen($f, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $subs = json_decode((string)$raw, true);
    if (!is_array($subs)) $subs = [];
    $subs = $fn($subs);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(empty($subs) ? new stdClass() : $subs));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
}

/** send_push  (app.py:593): يضع الإرسال في طابور ما بعد الاستجابة (بدل ThreadPoolExecutor) */
function send_push($user_id, $title, $body, $icon = '/pwa-icon-192.png', $url = '/app'): void {
    $subs = load_push_subs();
    $sub_data = $subs[(string)$user_id] ?? null;
    if (!py_truthy($sub_data)) return;
    $keys = get_vapid_keys();
    if (empty($keys['private'])) return;
    $sub_list = (is_array($sub_data) && is_list_array($sub_data)) ? $sub_data : [$sub_data];
    $sub_list = array_values(array_filter($sub_list, fn($s) => is_array($s) && !empty($s['endpoint'])));
    if (!$sub_list) return;
    foreach ($sub_list as $sub) {
        defer_after_response(function () use ($sub, $title, $body, $url, $user_id) {
            _send_push_single($sub, (string)$title, (string)$body, $url, (string)$user_id);
        });
    }
}

/** DER ECDSA → raw R||S (64 بايت) لتوقيع ES256 */
function _der_to_raw_es256(string $der): string {
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl);
    $o2 = $o + 2 + $rl;
    $sl = ord($der[$o2 + 1]); $s = substr($der, $o2 + 2, $sl);
    $r = ltrim($r, "\0"); $s = ltrim($s, "\0");
    return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
}

function vapid_authorization(string $endpoint): string {
    $keys = get_vapid_keys();
    $p = parse_url($endpoint);
    $aud = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    $hdr = _b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $clm = _b64u(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => 'mailto:noreply@' . DOMAIN], JSON_UNESCAPED_SLASHES));
    $input = $hdr . '.' . $clm;
    $pk = openssl_pkey_get_private($keys['private']);
    if (!$pk) throw new RuntimeException('VAPID private key invalid');
    openssl_sign($input, $der, $pk, OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $input . '.' . _b64u(_der_to_raw_es256($der)) . ',k=' . $keys['public'];
}

/** تشفير aes128gcm حسب RFC 8291 → [جسم الطلب كاملاً] */
function webpush_encrypt(string $payload, string $p256dh_b64u, string $auth_b64u): string {
    $ua_public = _b64u_d($p256dh_b64u);
    $auth = _b64u_d($auth_b64u);
    if (strlen($ua_public) !== 65 || $ua_public[0] !== "\x04") throw new RuntimeException('invalid p256dh');
    // مفتاح الخادم المؤقت
    $eph = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($eph);
    $as_public = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    // المفتاح العام للمتصفح كـ PEM (SubjectPublicKeyInfo)
    $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $ua_public;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $ua_key = openssl_pkey_get_public($pem);
    if (!$ua_key) throw new RuntimeException('cannot load subscriber key');
    $secret = openssl_pkey_derive($ua_key, $eph, 32);
    if ($secret === false) throw new RuntimeException('ECDH failed');
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $ua_public . $as_public, $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new RuntimeException('encrypt failed');
    return $salt . pack('N', 4096) . chr(65) . $as_public . $ct . $tag;
}

/** _send_push_single  (app.py:611) */
function _send_push_single(array $subscription, string $title, string $body, string $url = '/app', string $uid = ''): bool {
    $keys = get_vapid_keys();
    if (!$subscription || empty($keys['private'])) return false;
    $payload = py_json_dumps(['title' => $title, 'body' => $body, 'icon' => '/pwa-icon-192.png', 'url' => $url,
                              'tag' => 'fc-' . substr(bin2hex(random_bytes(16)), 0, 12)]);
    $status = null; $err = '';
    try {
        $p256dh = $subscription['keys']['p256dh'] ?? '';
        $auth = $subscription['keys']['auth'] ?? '';
        $data = webpush_encrypt($payload, $p256dh, $auth);
        $r = http_request('POST', $subscription['endpoint'], [
            'data' => $data,
            'headers' => [
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => '0',
                'Authorization' => vapid_authorization($subscription['endpoint']),
            ],
            'timeout' => 10,
        ]);
        $status = $r->status_code;
        if ($status >= 200 && $status < 300) return true;
        $err = 'HTTP ' . $status . ' ' . substr($r->text, 0, 150);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
    _mail_log("[push] FAILED uid=$uid status=" . var_export($status, true) . " err=" . substr($err, 0, 200));
    // حذف الاشتراكات الميتة (410=Gone, 404=NotFound, 403=Forbidden)
    if (in_array($status, [404, 410, 403], true)) {
        update_push_subs(function (array $subs) use ($uid, $subscription) {
            if (isset($subs[$uid])) {
                if (is_array($subs[$uid]) && is_list_array($subs[$uid])) {
                    $subs[$uid] = array_values(array_filter($subs[$uid], fn($s) => $s != $subscription));
                    if (!$subs[$uid]) unset($subs[$uid]);
                } else {
                    unset($subs[$uid]);
                }
            }
            return $subs;
        });
    }
    return false;
}
