<?php
/**
 * حسابات واتساب - مقابل routes الحسابات بـ webapp.py، بس الاتصال الفعلي بواتساب صار عبر
 * wasel-gateway (core/lib/gateway.php) بدل Selenium مباشر.
 */

function wsl_owned_account(string $accId, int $ownerId): ?Row {
    return get_db()->execute('SELECT * FROM wa_accounts WHERE id=? AND owner=?', [$accId, $ownerId])->fetchone();
}

route('/accounts', function () {
    if (($r = require_login()) !== null) return $r;
    $rows = get_db()->execute('SELECT * FROM wa_accounts WHERE owner=? ORDER BY created_at ASC', [$_SESSION['user_id']])->fetchall();
    $out = [];
    foreach ($rows as $a) {
        $st = gateway_status($a['id']);
        $out[] = [
            'id' => $a['id'], 'name' => $a['name'],
            'logged_in' => ($st['status'] ?? '') === 'connected',
            'status' => $st['status'] ?? 'disconnected',
            'phone' => $st['phone'] ?? null,
            'otp_sender' => py_truthy($a['otp_sender']),
        ];
    }
    return jsonify($out);
}, ['GET']);

route('/accounts', function () {
    if (($r = require_login()) !== null) return $r;
    $name = trim((string)(req_data()['name'] ?? ''));
    $conn = get_db();
    $n = (int)$conn->execute('SELECT COUNT(*) AS n FROM wa_accounts WHERE owner=?', [$_SESSION['user_id']])->fetchone()['n'];
    $accId = bin2hex(random_bytes(4));
    $name = $name !== '' ? $name : ('حساب ' . ($n + 1));
    $conn->execute(
        'INSERT INTO wa_accounts (id, owner, name, auto_reply_rules, created_at) VALUES (?,?,?,?,?)',
        [$accId, $_SESSION['user_id'], $name, '[]', now_str()]
    );
    $conn->commit();
    try {
        gateway_start_session($accId);
    } catch (GatewayException $e) {
        log_app_error('WARNING', 'gateway_start_session failed: ' . $e->getMessage());
    }
    return jsonify(['id' => $accId, 'name' => $name]);
}, ['POST']);

/** يحذف حساب واتساب نهائياً: يفصله من البوابة ويمسح كل حملاته (campaigns.account_id) معه */
function wsl_delete_wa_account(string $accId): void {
    gateway_delete_session($accId);
    $conn = get_db();
    $ids = $conn->execute('SELECT id FROM campaigns WHERE account_id=?', [$accId])->fetchall();
    foreach ($ids as $c) $conn->execute('DELETE FROM campaign_numbers WHERE campaign_id=?', [$c['id']]);
    $conn->execute('DELETE FROM campaigns WHERE account_id=?', [$accId]);
    $conn->execute('DELETE FROM api_keys WHERE account_id=?', [$accId]);
    $conn->execute('DELETE FROM wa_accounts WHERE id=?', [$accId]);
    $conn->commit();
}

route('/accounts/<accId>/logout', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 404);
    wsl_delete_wa_account($accId);
    return jsonify(['ok' => true]);
}, ['POST']);

route('/settings/delete_my_data', function () {
    if (($r = require_login()) !== null) return $r;
    $rows = get_db()->execute('SELECT id FROM wa_accounts WHERE owner=?', [$_SESSION['user_id']])->fetchall();
    foreach ($rows as $a) wsl_delete_wa_account($a['id']);
    return jsonify(['ok' => true, 'deleted' => count($rows)]);
}, ['POST']);

route('/accounts/<accId>/otp_sender', function ($accId) {
    if (($r = require_admin()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 404);
    $enabled = py_truthy(req_data()['enabled'] ?? false);
    $conn = get_db();
    if ($enabled) $conn->execute('UPDATE wa_accounts SET otp_sender=0');
    $conn->execute('UPDATE wa_accounts SET otp_sender=? WHERE id=?', [$enabled ? 1 : 0, $accId]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/accounts/<accId>/qr', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return make_response('', 204);
    $png = gateway_qr_png($accId);
    if ($png === null) return make_response('', 204);
    return new Response($png, 200, 'image/png');
}, ['GET']);

route('/accounts/<accId>/status', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['logged_in' => false], 404);
    $st = gateway_status($accId);
    return jsonify(['logged_in' => ($st['status'] ?? '') === 'connected', 'status' => $st['status'] ?? 'disconnected']);
}, ['GET']);

route('/accounts/<accId>/auto_reply', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 404);
    return jsonify(['enabled' => py_truthy($acc['auto_reply_enabled']), 'rules' => json_decode($acc['auto_reply_rules'], true) ?: []]);
}, ['GET']);

route('/accounts/<accId>/auto_reply', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 404);
    $data = req_data();
    $enabled = py_truthy($data['enabled'] ?? false);
    $rules = [];
    foreach ((array)($data['rules'] ?? []) as $r0) {
        $kw = trim((string)($r0['keyword'] ?? ''));
        $rp = trim((string)($r0['reply'] ?? ''));
        if ($kw !== '' && $rp !== '') $rules[] = ['keyword' => $kw, 'reply' => $rp];
    }
    get_db()->execute('UPDATE wa_accounts SET auto_reply_enabled=?, auto_reply_rules=? WHERE id=?',
        [$enabled ? 1 : 0, json_encode($rules, JSON_UNESCAPED_UNICODE), $accId]);
    get_db()->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

/* ---------------------------------------------------------------- استلام الرسائل الواردة */

route('/webhook/wa_incoming', function () {
    $sent = req_header('Authorization');
    $token = str_starts_with($sent, 'Bearer ') ? substr($sent, 7) : '';
    if (GATEWAY_TOKEN === '' || !hash_equals(GATEWAY_TOKEN, $token)) {
        return jsonify(['ok' => false], 401);
    }
    $data = req_data_force();
    $accId = (string)($data['session_id'] ?? '');
    $phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
    $text = (string)($data['text'] ?? '');
    if ($accId === '' || $phone === '' || $text === '') return jsonify(['ok' => true]); // تجاهل بأمان

    $acc = get_db()->execute('SELECT * FROM wa_accounts WHERE id=?', [$accId])->fetchone();
    if (!$acc || !py_truthy($acc['auto_reply_enabled'])) return jsonify(['ok' => true]);

    $rules = json_decode($acc['auto_reply_rules'], true) ?: [];
    $reply = null;
    foreach ($rules as $rule) {
        if (mb_stripos($text, (string)$rule['keyword'], 0, 'UTF-8') !== false) { $reply = (string)$rule['reply']; break; }
    }
    if ($reply !== null) {
        try { gateway_send($accId, $phone, $reply); } catch (GatewayException $e) {
            log_app_error('WARNING', 'auto-reply send failed: ' . $e->getMessage());
        }
    }
    return jsonify(['ok' => true]);
}, ['POST']);
