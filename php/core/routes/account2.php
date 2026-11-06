<?php
/**
 * أمان الحساب: المصادقة الثنائية (2FA) + الأجهزة والجلسات.
 * الواجهة: حسابي ← الأمان (dash.html).
 */

function _acc_user(): ?Row {
    if (!array_key_exists('user_id', $_SESSION)) return null;
    return get_db()->execute('SELECT * FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
}
function _acc_deny(): Response { return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401); }

/** إعادة التحقق بكلمة المرور (+ الرمز إن كانت 2FA مفعّلة) للعمليات الحساسة */
function _acc_reauth(Row $user, array $data, bool $needCode): ?Response {
    [$okRate] = rate_limit_hit('acc_sens|' . $user['id'], 10, 600);
    if (!$okRate) return jsonify(['ok' => false, 'msg' => 'محاولات كثيرة، انتظر قليلاً'], 429);
    $hasPw = py_truthy($user['password']);
    if ($hasPw && !verify_pw((string)py_or(dget($data, 'password'), ''), (string)$user['password'])) return jsonify(['ok' => false, 'msg' => 'كلمة المرور غير صحيحة'], 403);
    if ($needCode && !twofa_check_code($user, (string)py_or(dget($data, 'code'), ''))) return jsonify(['ok' => false, 'msg' => 'رمز المصادقة غير صحيح'], 403);
    return null;
}

route('/api/login/2fa', function () {
    $data = req_data_strict();
    return twofa_complete((string)py_or(dget($data, 'code'), ''));
}, ['POST']);

route('/api/account/security', function () {
    $u = _acc_user(); if (!$u) return _acc_deny();
    $left = 0;
    if (user_has_2fa($u)) { $l = json_decode((string)($u['totp_recovery'] ?? '[]'), true); $left = is_array($l) ? count($l) : 0; }
    return jsonify(['ok' => true, 'twofa' => ['enabled' => user_has_2fa($u), 'recovery_left' => $left], 'email' => (string)$u['email'],
        'has_password' => py_truthy($u['password']), 'sessions' => usess_list((int)$u['id'], (string)($_SESSION['_sid'] ?? ''))]);
});

route('/api/account/2fa/setup', function () {
    $u = _acc_user(); if (!$u) return _acc_deny();
    if (user_has_2fa($u)) return jsonify(['ok' => false, 'msg' => 'المصادقة الثنائية مفعّلة بالفعل']);
    $secret = totp_new_secret();
    $conn = get_db();
    $conn->execute('UPDATE users SET totp_secret=?, totp_enabled=0 WHERE id=?', [$secret, $u['id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'secret' => $secret, 'uri' => totp_uri($secret, (string)$u['email'])]);
}, ['POST']);

route('/api/account/2fa/enable', function () {
    $u = _acc_user(); if (!$u) return _acc_deny();
    if (user_has_2fa($u)) return jsonify(['ok' => false, 'msg' => 'المصادقة الثنائية مفعّلة بالفعل']);
    [$okRate] = rate_limit_hit('acc_enable|' . $u['id'], 10, 600);
    if (!$okRate) return jsonify(['ok' => false, 'msg' => 'محاولات كثيرة، انتظر قليلاً'], 429);
    $secret = (string)($u['totp_secret'] ?? '');
    if ($secret === '') return jsonify(['ok' => false, 'msg' => 'ابدأ الإعداد أولاً']);
    $code = (string)py_or(dget(req_data(), 'code'), '');
    if (!totp_verify($secret, $code, (int)$u['id'])) return jsonify(['ok' => false, 'msg' => 'الرمز غير صحيح — تأكد من ساعة هاتفك وأعد المحاولة']);
    [$plain, $json] = recovery_generate();
    $conn = get_db();
    $conn->execute('UPDATE users SET totp_enabled=1, totp_recovery=? WHERE id=?', [$json, $u['id']]);
    $conn->commit();
    push_user_notif($u['id'], 'system', 'تم تفعيل المصادقة الثنائية 🔐', 'حسابك الآن محمي برمز من تطبيق المصادقة عند كل تسجيل دخول.', 'system');
    audit_log('2fa_enabled', ['uid' => (int)$u['id']]);
    return jsonify(['ok' => true, 'recovery' => $plain, 'msg' => 'تم تفعيل المصادقة الثنائية']);
}, ['POST']);

route('/api/account/2fa/disable', function () {
    $u = _acc_user(); if (!$u) return _acc_deny();
    if (!user_has_2fa($u)) return jsonify(['ok' => false, 'msg' => 'المصادقة الثنائية غير مفعّلة']);
    if ($r = _acc_reauth($u, req_data(), true)) return $r;
    $conn = get_db();
    $conn->execute('UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_recovery=NULL WHERE id=?', [$u['id']]);
    $conn->commit();
    audit_log('2fa_disabled', ['uid' => (int)$u['id']]);
    return jsonify(['ok' => true, 'msg' => 'تم إيقاف المصادقة الثنائية']);
}, ['POST']);

route('/api/account/2fa/recovery', function () {
    $u = _acc_user(); if (!$u) return _acc_deny();
    if (!user_has_2fa($u)) return jsonify(['ok' => false, 'msg' => 'المصادقة الثنائية غير مفعّلة']);
    if ($r = _acc_reauth($u, req_data(), true)) return $r;
    [$plain, $json] = recovery_generate();
    $conn = get_db();
    $conn->execute('UPDATE users SET totp_recovery=? WHERE id=?', [$json, $u['id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'recovery' => $plain]);
}, ['POST']);

route('/api/account/sessions/revoke', function () {
    $u = _acc_user(); if (!$u) return _acc_deny();
    $id = py_int(py_or(dget(req_data(), 'id'), 0));
    $conn = get_db();
    $row = $conn->execute('SELECT sid FROM user_sessions WHERE id=? AND user_id=?', [$id, $u['id']])->fetchone();
    if (!$row) return jsonify(['ok' => false, 'msg' => 'الجلسة غير موجودة']);
    $conn->execute('UPDATE user_sessions SET revoked=1 WHERE id=?', [$id]);
    $conn->commit();
    $self = hash_equals((string)($_SESSION['_sid'] ?? ''), (string)$row['sid']);
    return jsonify(['ok' => true, 'self' => $self, 'msg' => 'تم إنهاء الجلسة']);
}, ['POST']);

route('/api/account/sessions/revoke-others', function () {
    $u = _acc_user(); if (!$u) return _acc_deny();
    $conn = get_db();
    $cur = (string)($_SESSION['_sid'] ?? '');
    $cur_stmt = $conn->execute('UPDATE user_sessions SET revoked=1 WHERE user_id=? AND revoked=0 AND sid<>?', [$u['id'], $cur]);
    $conn->commit();
    return jsonify(['ok' => true, 'count' => $cur_stmt->rowcount, 'msg' => 'تم إنهاء كل الجلسات الأخرى']);
}, ['POST']);

/* مكتبة رسم QR (MIT، qrcode-generator) تُحمَّل عند صفحة تفعيل 2FA فقط */
route('/assets/qrcode.js', function () {
    $f = APP_DIR . '/templates/qrcode.min.js';
    $r = new Response(is_file($f) ? (string)file_get_contents($f) : '', 200);
    $r->headers['Content-Type'] = 'application/javascript; charset=utf-8';
    $r->headers['Cache-Control'] = 'public, max-age=86400';
    return $r;
});
