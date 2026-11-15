<?php
/**
 * مفاتيح API الخارجية - مقابل routes /api_keys و /api/v1/send بـ webapp.py. المفتاح الخام
 * يُولَّد ويُعرض مرة واحدة فقط؛ نخزّن SHA-256 منه بس (نفس بايثون حرفياً).
 */

function wsl_create_api_key(int $owner, string $accId, string $label): string {
    $raw = 'wsl_' . bin2hex(random_bytes(24));
    $hash = hash('sha256', $raw);
    $conn = get_db();
    $conn->execute(
        'INSERT INTO api_keys (owner, account_id, label, key_hash, key_prefix, created_at) VALUES (?,?,?,?,?,?)',
        [$owner, $accId, $label, $hash, substr($raw, 0, 12), now_str()]
    );
    $conn->commit();
    return $raw;
}

route('/api_keys', function () {
    if (($r = require_login()) !== null) return $r;
    $user = current_user();
    $rows = get_db()->execute('SELECT * FROM api_keys WHERE owner=? ORDER BY id DESC', [$_SESSION['user_id']])->fetchall();
    $keys = [];
    foreach ($rows as $row) {
        $connected = (gateway_status($row['account_id'])['status'] ?? '') === 'connected';
        $keys[] = [
            'id' => (int)$row['id'], 'label' => $row['label'], 'account_id' => $row['account_id'],
            'key_prefix' => $row['key_prefix'], 'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'], 'revoked' => py_truthy($row['revoked']),
            'account_connected' => $connected,
        ];
    }
    return jsonify(['plan_active' => $user ? effective_plan_active($user) : false, 'keys' => $keys]);
}, ['GET']);

route('/api_keys', function () {
    if (($r = require_login()) !== null) return $r;
    $user = current_user();
    if (!$user || !effective_plan_active($user)) {
        return jsonify(['ok' => false, 'error' => 'مفاتيح API تحتاج اشتراك مفعّل (مو فترة تجربة)', 'needs_subscription' => true], 402);
    }
    $data = req_data();
    $accId = (string)($data['account_id'] ?? '');
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'اختر حساب واتساب صحيح تملكه'], 400);
    if ((gateway_status($accId)['status'] ?? '') !== 'connected') {
        return jsonify(['ok' => false, 'error' => 'اختر حساب واتساب متصل حالياً - ما يمكن توليد مفتاح لحساب غير متصل'], 400);
    }
    $label = mb_substr(trim((string)($data['label'] ?? '')), 0, 60, 'UTF-8');
    return jsonify(['ok' => true, 'key' => wsl_create_api_key($_SESSION['user_id'], $accId, $label)]);
}, ['POST']);

route('/api_keys/<int:keyId>', function ($keyId) {
    if (($r = require_login()) !== null) return $r;
    get_db()->execute('UPDATE api_keys SET revoked=1 WHERE id=? AND owner=?', [$keyId, $_SESSION['user_id']]);
    get_db()->commit();
    return jsonify(['ok' => true]);
}, ['DELETE']);

route('/api/v1/send', function () {
    $authHeader = req_header('Authorization');
    $raw = req_header('X-API-Key') ?: (str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : '');
    if ($raw === '') return jsonify(['ok' => false, 'error' => 'مفتاح API مفقود - أرسله بترويسة X-API-Key أو Authorization: Bearer <key>'], 401);

    $hash = hash('sha256', $raw);
    $keyRow = get_db()->execute('SELECT * FROM api_keys WHERE key_hash=? AND revoked=0', [$hash])->fetchone();
    if (!$keyRow) return jsonify(['ok' => false, 'error' => 'مفتاح API غير صالح أو ملغى'], 401);

    [$allowed] = rate_limit_hit('apikey|' . $hash, 20, 60);
    if (!$allowed) return jsonify(['ok' => false, 'error' => 'تجاوزت الحد المسموح من الطلبات (20 بالدقيقة)، حاول بعد قليل'], 429);

    $user = get_db()->execute('SELECT * FROM users WHERE id=?', [$keyRow['owner']])->fetchone();
    if (!$user || !effective_plan_active($user)) return jsonify(['ok' => false, 'error' => 'اشتراك صاحب المفتاح غير فعّال حالياً'], 402);

    $acc = get_db()->execute('SELECT * FROM wa_accounts WHERE id=? AND owner=?', [$keyRow['account_id'], $keyRow['owner']])->fetchone();
    if (!$acc || (gateway_status($keyRow['account_id'])['status'] ?? '') !== 'connected') {
        return jsonify(['ok' => false, 'error' => 'حساب الواتساب المرتبط بهذا المفتاح غير متصل حالياً'], 400);
    }

    $data = req_data_force();
    $phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
    if ($phone === '') return jsonify(['ok' => false, 'error' => 'أرسل رقم هاتف صالح بحقل phone'], 400);
    $code = trim((string)($data['code'] ?? ''));
    $message = trim((string)($data['message'] ?? ''));
    if ($message === '') {
        if ($code === '') return jsonify(['ok' => false, 'error' => 'أرسل code أو message بجسم الطلب'], 400);
        $message = "رمز التحقق الخاص بك: $code\nصالح لمدة 10 دقائق.";
    }

    get_db()->execute('UPDATE api_keys SET last_used_at=? WHERE id=?', [now_str(), $keyRow['id']]);
    get_db()->commit();
    try {
        wsl_wallet_charge_and_send((int)$keyRow['owner'], $keyRow['account_id'], $phone, $message);
    } catch (InsufficientBalanceException $e) {
        return jsonify(['ok' => false, 'error' => $e->getMessage()], 402);
    } catch (GatewayException $e) {
        return jsonify(['ok' => false, 'error' => 'فشل الإرسال: ' . $e->getMessage()], 502);
    }
    return jsonify(['ok' => true]);
}, ['POST']);
