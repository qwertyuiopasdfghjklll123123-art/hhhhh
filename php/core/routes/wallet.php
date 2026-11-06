<?php
/**
 * المحفظة: طلبات الشحن اليدوي + Binance Pay + آسياسيل + إدارة طلبات الشحن
 * منقولة من app.py:2558-2900.
 */

function _is_admin_session(): bool {
    return array_key_exists('user_id', $_SESSION) && py_truthy($_SESSION['is_admin'] ?? false);
}

/* '/api/recharge/submit'  (app.py:2558) */
route('/api/recharge/submit', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $data = req_data();
    $amount = py_float(dget($data, 'amount', 0));
    $method = dget($data, 'method', '');
    $receipt = dget($data, 'receipt', '');
    if ($amount <= 0) {
        return jsonify(['ok' => false, 'msg' => 'أدخل مبلغ صحيح']);
    }
    if (!py_truthy($method)) {
        return jsonify(['ok' => false, 'msg' => 'اختر طريقة الدفع']);
    }
    if (!py_truthy($receipt)) {
        return jsonify(['ok' => false, 'msg' => 'يرجى إرفاق صورة إثبات الدفع']);
    }
    $conn = get_db();
    $conn->execute('INSERT INTO recharge_requests(user_id, amount, method, status, receipt) VALUES(?,?,?,?,?)',
        [$_SESSION['user_id'], $amount, py_str($method), 'pending', py_str($receipt)]);
    $conn->commit();
    push_user_notif($_SESSION['user_id'], 'recharge', 'طلب شحن $' . py_fmt($amount), 'طلب شحن عبر ' . py_str($method) . ' بانتظار الموافقة', 'bolt');
    $admins = get_db()->execute('SELECT id FROM users WHERE is_admin=1')->fetchall();
    $user_name = $_SESSION['user_name'] ?? 'مستخدم';
    foreach ($admins as $a) {
        push_user_notif($a['id'], 'admin', 'طلب شحن جديد $' . py_fmt($amount), 'من ' . py_str($user_name) . ' عبر ' . py_str($method), 'wallet');
    }
    return jsonify(['ok' => true, 'msg' => 'تم إرسال طلب الشحن بنجاح! سيتم مراجعته قريباً']);
}, ['POST']);

/* '/api/recharge/binance/verify'  (app.py:2587) */
route('/api/recharge/binance/verify', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $data = req_data();
    try { $amount = py_float(dget($data, 'amount', 0)); }
    catch (PyValueError | PyTypeError $e) { $amount = 0; }
    $order_id = py_strip(py_str(dget($data, 'order_id', '')));
    if ($amount <= 0) {
        return jsonify(['ok' => false, 'msg' => 'أدخل مبلغ صحيح']);
    }
    if (!py_truthy($order_id)) {
        return jsonify(['ok' => false, 'msg' => 'الرجاء إدخال رقم عملية Binance (Order ID).']);
    }

    $conn = get_db();
    $pm = $conn->execute("SELECT * FROM payment_methods WHERE method_type='binance' AND active=1 LIMIT 1")->fetchone();
    if (!$pm) {
        return jsonify(['ok' => false, 'msg' => 'طريقة الدفع غير متاحة.']);
    }

    $dup = $conn->execute('SELECT COUNT(*) FROM recharge_requests WHERE ext_ref=?', [$order_id])->fetchone()[0];
    if ($dup) {
        return jsonify(['ok' => false, 'msg' => 'رقم عملية Binance هذا مستخدم مسبقاً لشحن رصيد آخر.']);
    }

    [$verified, $result] = verify_binance_order($pm, $order_id, $amount);
    if (!$verified) {
        return jsonify(['ok' => false, 'msg' => $result]);
    }

    try {
        $conn->execute('INSERT INTO recharge_requests(user_id, amount, method, status, receipt, ext_ref, auto) VALUES(?,?,?,?,?,?,1)',
            [$_SESSION['user_id'], $amount, py_or($pm['name'], 'Binance Pay'), 'approved', '', $order_id]);
        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$amount, $_SESSION['user_id']]);
        $conn->commit();
    } catch (IntegrityError $e) {
        $conn->rollback();
        return jsonify(['ok' => false, 'msg' => 'رقم عملية Binance هذا مستخدم مسبقاً لشحن رصيد آخر.']);
    }
    $new_balance = $conn->execute('SELECT balance FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone()['balance'];

    push_user_notif($_SESSION['user_id'], 'recharge', 'تم شحن $' . py_fmt($amount), 'تم التحقق من عملية Binance Pay تلقائياً وإضافة الرصيد', 'check', 'recharge:');
    $admins = get_db()->execute('SELECT id FROM users WHERE is_admin=1')->fetchall();
    $user_name = $_SESSION['user_name'] ?? 'مستخدم';
    foreach ($admins as $a) {
        push_user_notif($a['id'], 'admin', 'شحن رصيد تلقائي $' . py_fmt($amount), 'من ' . py_str($user_name) . ' عبر Binance Pay (تم التحقق تلقائياً)', 'wallet');
    }
    return jsonify(['ok' => true, 'msg' => 'تم التحقق من الدفع وإضافة الرصيد بنجاح!', 'balance' => $new_balance]);
}, ['POST']);

/* '/api/recharge/asiacell/start'  (app.py:2639) */
route('/api/recharge/asiacell/start', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data();
    $phone = py_strip(py_str(dget($data, 'phone', '')));
    try { $amount_iqd = py_int(dget($data, 'amount_iqd', 0)); }
    catch (PyValueError | PyTypeError $e) { $amount_iqd = 0; }

    $existing = _asiacell_flow_get($uid);
    if ($existing && py_float(py_or(dget($existing, 'amount_iqd_paid', 0), 0)) > 0) {
        return jsonify(['ok' => false, 'msg' => 'لديك عملية دفع آسياسيل غير مكتملة، أكملها أو ألغِها أولاً.', 'has_pending' => true]);
    }

    if (!preg_match('/^(077|078|079)\p{Nd}{8}$/Du', $phone)) {
        return jsonify(['ok' => false, 'msg' => 'رقم هاتف آسياسيل غير صحيح، يجب أن يكون بصيغة 07xxxxxxxxx.']);
    }

    $conn = get_db();
    $pm = $conn->execute("SELECT * FROM payment_methods WHERE method_type='asiacell' AND active=1 LIMIT 1")->fetchone();
    if (!$pm) {
        return jsonify(['ok' => false, 'msg' => 'طريقة الدفع غير متاحة.']);
    }
    try { $extras = json_loads(py_or($pm['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $receiver = py_strip(py_or($pm['number'], ''));
    try { $exchange_rate = py_float(py_or($pm['exchange_rate'], 0)); }
    catch (PyValueError | PyTypeError $e) { $exchange_rate = 0; }
    $max_transfer = py_int(py_or(dget($extras, 'max_transfer'), 10000));
    if ($max_transfer < 1000) {
        $max_transfer = 10000;
    }
    if (!py_truthy($receiver) || $exchange_rate <= 0) {
        return jsonify(['ok' => false, 'msg' => 'لم يتم إعداد آسياسيل من الإدارة بعد.']);
    }

    $tiers = py_or(dget($extras, 'tiers'), _default_asiacell_tiers());
    $tier = null;
    foreach ($tiers as $t) {
        if (py_int(dget($t, 'iqd', 0)) === $amount_iqd) { $tier = $t; break; }
    }
    if (!$tier) {
        return jsonify(['ok' => false, 'msg' => 'الرجاء اختيار أحد المبالغ المتاحة.']);
    }
    $bonus_iqd = py_float(py_or(dget($tier, 'bonus'), 0));
    $credited_usd = py_round(($amount_iqd + $bonus_iqd) / $exchange_rate, 2);

    $api = new AsiaCellAPI();
    [$success, $result] = $api->login($phone);
    if (!$success) {
        log_asiacell_debug('login', $api);
        return jsonify(['ok' => false, 'msg' => $result]);
    }

    _asiacell_flow_set($uid, [
        'api' => $api->get_state(), 'phone' => $phone,
        'bonus_iqd' => $bonus_iqd, 'credited_usd' => $credited_usd,
        'exchange_rate' => $exchange_rate, 'amount_iqd_total' => $amount_iqd,
        'amount_iqd_paid' => 0, 'current_chunk' => 0, 'max_transfer' => $max_transfer,
        'receiver_msisdn' => $receiver, 'step' => 'sms',
    ]);
    return jsonify(['ok' => true, 'amount_iqd_total' => $amount_iqd, 'bonus_iqd' => $bonus_iqd, 'credited_usd' => $credited_usd]);
}, ['POST']);

/* '/api/recharge/asiacell/verify-sms'  (app.py:2700) */
route('/api/recharge/asiacell/verify-sms', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $flow = _asiacell_flow_get($uid);
    if (!$flow || dget($flow, 'step') !== 'sms') {
        return jsonify(['ok' => false, 'msg' => 'انتهت صلاحية العملية، ابدأ من جديد.']);
    }
    $code = py_strip(py_str(dget(req_data(), 'code', '')));
    if (!preg_match('/^\p{Nd}{4,6}$/Du', $code)) {
        return jsonify(['ok' => false, 'msg' => 'رمز التحقق يجب أن يكون من 4 إلى 6 أرقام.']);
    }

    $api = new AsiaCellAPI($flow['api']);
    [$success, $result] = $api->verify_sms($code);
    if (!$success) {
        log_asiacell_debug('verifySms', $api);
        return jsonify(['ok' => false, 'msg' => $result]);
    }

    $chunk = min($flow['max_transfer'], $flow['amount_iqd_total'] - $flow['amount_iqd_paid']);
    [$success2, $result2] = $api->start_transfer($chunk, $flow['receiver_msisdn']);
    if (!$success2) {
        log_asiacell_debug('startTransfer', $api);
        return jsonify(['ok' => false, 'msg' => $result2]);
    }

    $flow['api'] = $api->get_state();
    $flow['current_chunk'] = $chunk;
    $flow['step'] = 'confirm';
    _asiacell_flow_set($uid, $flow);
    return jsonify(['ok' => true, 'chunk_amount' => $chunk, 'total' => $flow['amount_iqd_total'],
        'remaining_after' => $flow['amount_iqd_total'] - $flow['amount_iqd_paid'] - $chunk]);
}, ['POST']);

/* '/api/recharge/asiacell/confirm'  (app.py:2731) */
route('/api/recharge/asiacell/confirm', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $flow = _asiacell_flow_get($uid);
    if (!$flow || dget($flow, 'step') !== 'confirm') {
        return jsonify(['ok' => false, 'msg' => 'انتهت صلاحية العملية، ابدأ من جديد.']);
    }
    $code = py_strip(py_str(dget(req_data(), 'code', '')));
    if (!preg_match('/^\p{Nd}{4,6}$/Du', $code)) {
        return jsonify(['ok' => false, 'msg' => 'رمز التأكيد يجب أن يكون من 4 إلى 6 أرقام.']);
    }

    $api = new AsiaCellAPI($flow['api']);
    [$success, $result] = $api->confirm_transfer($code);
    if (!$success) {
        log_asiacell_debug('confirmTransfer', $api);
        return jsonify(['ok' => false, 'msg' => $result]);
    }

    $flow['amount_iqd_paid'] = py_int($flow['amount_iqd_paid']) + py_int($flow['current_chunk']);
    $fully_paid = $flow['amount_iqd_paid'] >= $flow['amount_iqd_total'];

    if (!$fully_paid) {
        $next_chunk = min($flow['max_transfer'], $flow['amount_iqd_total'] - $flow['amount_iqd_paid']);
        [$success2, $result2] = $api->start_transfer($next_chunk, $flow['receiver_msisdn']);
        if (!$success2) {
            log_asiacell_debug('startTransfer_chunk', $api);
            $credited_usd = $flow['exchange_rate'] > 0 ? py_round($flow['amount_iqd_paid'] / $flow['exchange_rate'], 2) : 0;
            if ($credited_usd > 0) {
                $conn = get_db();
                $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$credited_usd, $uid]);
                $conn->execute("INSERT INTO recharge_requests(user_id, amount, method, status, receipt, auto) VALUES(?,?,?,?,?,1)",
                    [$uid, $credited_usd, 'آسياسيل', 'approved', '']);
                $conn->commit();
                push_user_notif($uid, 'system', '⚠️ توقف تحويل آسياسيل جزئياً',
                    'تم تحويل ' . number_format(py_int($flow['amount_iqd_paid'])) . ' د.ع بنجاح، لكن تعذر إكمال المبلغ المتبقي. أضفنا $' . py_fmt($credited_usd) . ' كرصيد إلى حسابك.', 'wallet');
            }
            _asiacell_flow_clear($uid);
            return jsonify(['ok' => false, 'msg' => 'تم تحويل جزء من المبلغ لكن تعذر إكمال الباقي: ' . py_str($result2),
                'partial_stopped' => true, 'credited_usd' => $credited_usd]);
        }

        $flow['api'] = $api->get_state();
        $flow['current_chunk'] = $next_chunk;
        _asiacell_flow_set($uid, $flow);
        return jsonify(['ok' => true, 'done' => false, 'paid' => $flow['amount_iqd_paid'], 'total' => $flow['amount_iqd_total'], 'next_chunk' => $next_chunk]);
    }

    $credited_usd = py_round(py_float($flow['credited_usd']), 2);
    $bonus_iqd = py_float(py_or(dget($flow, 'bonus_iqd', 0), 0));

    $conn = get_db();
    $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$credited_usd, $uid]);
    $conn->execute("INSERT INTO recharge_requests(user_id, amount, method, status, receipt, auto) VALUES(?,?,?,?,?,1)",
        [$uid, $credited_usd, 'آسياسيل', 'approved', '']);
    $conn->commit();
    $new_balance = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone()['balance'];

    _asiacell_flow_clear($uid);
    $bonus_txt = $bonus_iqd > 0 ? ' (تشمل هدية ' . number_format((int)$bonus_iqd) . ' د.ع)' : '';
    push_user_notif($uid, 'recharge', 'تم شحن رصيدك', 'تم إضافة $' . py_fmt($credited_usd) . ' إلى رصيد حسابك تلقائياً عبر آسياسيل' . $bonus_txt . '.', 'check', 'recharge:');
    return jsonify(['ok' => true, 'done' => true, 'balance' => $new_balance, 'bonus_iqd' => $bonus_iqd]);
}, ['POST']);

/* '/api/recharge/asiacell/cancel'  (app.py:2792) */
route('/api/recharge/asiacell/cancel', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $flow = _asiacell_flow_get($uid);
    $credited_usd = 0;
    if ($flow && py_float(py_or(dget($flow, 'amount_iqd_paid', 0), 0)) > 0 && py_float(py_or(dget($flow, 'exchange_rate', 0), 0)) > 0) {
        $credited_usd = py_round($flow['amount_iqd_paid'] / $flow['exchange_rate'], 2);
        $conn = get_db();
        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$credited_usd, $uid]);
        $conn->execute("INSERT INTO recharge_requests(user_id, amount, method, status, receipt, auto) VALUES(?,?,?,?,?,1)",
            [$uid, $credited_usd, 'آسياسيل', 'approved', '']);
        $conn->commit();
        push_user_notif($uid, 'recharge', 'تم استرداد رصيدك',
            'تم إلغاء عملية آسياسيل بعد تحويل ' . number_format(py_int($flow['amount_iqd_paid'])) . ' د.ع، وأضفنا $' . py_fmt($credited_usd) . ' كرصيد إلى حسابك.', 'wallet');
    }
    _asiacell_flow_clear($uid);
    return jsonify(['ok' => true, 'credited_usd' => $credited_usd]);
}, ['POST']);

/* '/api/admin/recharge-requests'  (app.py:2812) */
route('/api/admin/recharge-requests', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT r.*, u.name as user_name, u.email as user_email
                          FROM recharge_requests r LEFT JOIN users u ON r.user_id=u.id
                          ORDER BY r.created_at DESC, r.id ASC LIMIT 100')->fetchall();
    $reqs = [];
    foreach ($rows as $r) {
        $receipt_val = py_or($r['receipt'], '');
        $reqs[] = [
            'id' => $r['id'], 'user_id' => $r['user_id'], 'user_name' => py_or($r['user_name'], ''),
            'user_email' => py_or($r['user_email'], ''), 'amount' => $r['amount'],
            'method' => $r['method'], 'status' => $r['status'],
            'has_receipt' => py_truthy($receipt_val),
            'receipt' => py_len($receipt_val) < 500 ? $receipt_val : '',
            'receipt_id' => $r['id'],
            'date' => fmt_time('%Y-%m-%d %H:%M', $r['created_at']),
        ];
    }
    $cnt = fn($s) => count(array_filter($reqs, fn($r) => $r['status'] === $s));
    return jsonify(['ok' => true, 'requests' => $reqs, 'pending' => $cnt('pending'), 'approved' => $cnt('approved'),
        'rejected' => $cnt('rejected'), 'total' => count($reqs)]);
});

/* '/api/admin/recharge-receipt/<int:rid>'  (app.py:2838) */
route('/api/admin/recharge-receipt/<int:rid>', function ($rid) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $r = $conn->execute('SELECT receipt FROM recharge_requests WHERE id=?', [$rid])->fetchone();
    if ($r) return jsonify(['ok' => true, 'receipt' => py_or($r['receipt'], '')]);
    return jsonify(['ok' => false]);
});

/* '/api/admin/recharge-action'  (app.py:2849) */
route('/api/admin/recharge-action', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 403);
    $data = req_data_strict();
    $rid = dget($data, 'id');
    $action = dget($data, 'action');
    if (!py_truthy($rid) || !in_array($action, ['approve', 'reject'], true)) {
        return jsonify(['ok' => false, 'msg' => 'بيانات غير صالحة']);
    }
    $conn = get_db();
    $req = $conn->execute('SELECT * FROM recharge_requests WHERE id=?', [$rid])->fetchone();
    if (!$req) {
        return jsonify(['ok' => false, 'msg' => 'الطلب غير موجود']);
    }
    if ($req['status'] !== 'pending') {
        return jsonify(['ok' => false, 'msg' => 'الطلب تمت معالجته مسبقاً']);
    }
    // تحديث الحالة بشرط أنها ما زالت pending (يمنع الشحن المزدوج لو ضغط مشرفان معاً)
    $new_status = $action === 'approve' ? 'approved' : 'rejected';
    $cur = $conn->execute("UPDATE recharge_requests SET status=? WHERE id=? AND status='pending'", [$new_status, $rid]);
    if ($cur->rowcount < 1) {
        $conn->rollback();
        return jsonify(['ok' => false, 'msg' => 'الطلب تمت معالجته مسبقاً']);
    }
    if ($action === 'approve') {
        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$req['amount'], $req['user_id']]);
        $conn->commit();
        push_user_notif($req['user_id'], 'recharge', 'تم شحن $' . py_fmt($req['amount']), 'تمت الموافقة على طلب الشحن وإضافة الرصيد', 'check', 'recharge:');
    } else {
        $conn->commit();
        push_user_notif($req['user_id'], 'recharge', 'تم رفض طلب الشحن', 'طلب شحن $' . py_fmt($req['amount']) . ' عبر ' . $req['method'] . ' تم رفضه', 'xmark', 'recharge:');
    }
    return jsonify(['ok' => true, 'msg' => $action === 'approve' ? 'تم' : 'تم الرفض']);
}, ['POST']);

/* '/api/admin/rch-chat'  (app.py:2878) */
route('/api/admin/rch-chat', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $rch_id = dget($data, 'rch_id');
    $user_id = dget($data, 'user_id');
    if (!py_truthy($rch_id) || !py_truthy($user_id)) {
        return jsonify(['ok' => false, 'msg' => 'بيانات ناقصة']);
    }
    try {
        $conn = get_db();
        $subj = 'بخصوص طلب الشحن #' . py_str($rch_id);
        $cur = $conn->execute('INSERT INTO tickets(user_id,subject,category,status) VALUES(?,?,?,?)', [$user_id, $subj, 'شحن', 'open']);
        $tid = $cur->lastrowid;
        $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)',
            [$tid, 'admin', 'مرحباً، هذه المحادثة بخصوص طلب الشحن رقم #' . py_str($rch_id) . '. كيف يمكننا مساعدتك؟']);
        $conn->commit();
        push_user_notif($user_id, 'system', 'رسالة من الإدارة بخصوص طلب الشحن #' . py_str($rch_id), 'تم فتح محادثة جديدة — اضغط للرد', 'message', "ticket:$tid");
        return jsonify(['ok' => true, 'ticket_id' => $tid]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => $e->getMessage()]);
    }
}, ['POST']);
