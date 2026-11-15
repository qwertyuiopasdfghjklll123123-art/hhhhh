<?php
/**
 * محفظة المستخدم: شحن يدوي (ينتظر موافقة الأدمن) أو تلقائي (Binance Pay/آسياسيل بعد
 * تحقق فعلي)، وإدارة طلبات الشحن من لوحة الأدمن. منقولة من مرجع fastcrand (core/routes/
 * wallet.php) مع تحويل اتجاه العملة لدينار عراقي مباشرة - راجع core/lib/payments.php.
 */

route('/wallet/balance', function () {
    if (($r = require_login()) !== null) return $r;
    $s = get_site_settings();
    return jsonify([
        'ok' => true, 'balance_iqd' => wallet_balance_iqd($_SESSION['user_id']),
        'price_per_message_iqd' => (int)py_or($s['price_per_message_iqd'], 0),
        'metering_enabled' => (int)py_or($s['price_per_message_iqd'], 0) > 0,
    ]);
}, ['GET']);

route('/wallet/recharge/submit', function () {
    if (($r = require_login()) !== null) return $r;
    $data = req_data();
    try { $amount = py_int(dget($data, 'amount_iqd', 0)); } catch (PyValueError | PyTypeError $e) { $amount = 0; }
    $method = py_str(py_or(dget($data, 'method'), ''));
    $receipt = py_or(dget($data, 'receipt'), '');
    if ($amount <= 0) return jsonify(['ok' => false, 'msg' => 'أدخل مبلغاً صحيحاً']);
    if (!py_truthy($method)) return jsonify(['ok' => false, 'msg' => 'اختر طريقة الدفع']);
    if (!py_truthy($receipt)) return jsonify(['ok' => false, 'msg' => 'أرفق صورة إثبات الدفع أو رقم العملية']);
    $conn = get_db();
    $conn->execute('INSERT INTO recharge_requests(user_id, amount_iqd, method, status, receipt, created_at) VALUES(?,?,?,?,?,?)',
        [$_SESSION['user_id'], $amount, $method, 'pending', py_str($receipt), now_str()]);
    $conn->commit();
    add_event((int)$_SESSION['user_id'], null, 'طلب شحن ' . number_format($amount) . ' د.ع', 'طلب شحن عبر ' . $method . ' بانتظار موافقة الإدارة', 'info');
    $admins = $conn->execute('SELECT id FROM users WHERE is_admin=1')->fetchall();
    $uname = $_SESSION['name'] ?? ($_SESSION['email'] ?? 'مستخدم');
    foreach ($admins as $a) add_event((int)$a['id'], null, 'طلب شحن جديد', number_format($amount) . ' د.ع من ' . $uname . ' عبر ' . $method, 'info');
    return jsonify(['ok' => true, 'msg' => 'تم إرسال طلب الشحن، سيتم مراجعته قريباً']);
}, ['POST']);

route('/wallet/recharge/binance/verify', function () {
    if (($r = require_login()) !== null) return $r;
    $data = req_data();
    try { $amountUsd = py_float(dget($data, 'amount_usd', 0)); } catch (PyValueError | PyTypeError $e) { $amountUsd = 0; }
    $orderId = py_strip(py_str(dget($data, 'order_id', '')));
    if ($amountUsd <= 0) return jsonify(['ok' => false, 'msg' => 'أدخل مبلغاً صحيحاً بالدولار']);
    if (!py_truthy($orderId)) return jsonify(['ok' => false, 'msg' => 'الرجاء إدخال رقم عملية Binance (Order ID)']);

    $conn = get_db();
    $pm = $conn->execute("SELECT * FROM payment_methods WHERE method_type='binance' AND active=1 LIMIT 1")->fetchone();
    if (!$pm) return jsonify(['ok' => false, 'msg' => 'طريقة الدفع غير متاحة حالياً']);
    $dup = $conn->execute('SELECT COUNT(*) AS n FROM recharge_requests WHERE ext_ref=?', [$orderId])->fetchone()['n'];
    if ($dup) return jsonify(['ok' => false, 'msg' => 'رقم عملية Binance هذا مستخدم مسبقاً لشحن رصيد آخر']);

    [$verified, $result] = verify_binance_order($pm, $orderId, $amountUsd);
    if (!$verified) return jsonify(['ok' => false, 'msg' => $result]);

    $s = get_site_settings();
    $rate = max(1, (int)py_or($s['usd_to_iqd_rate'], 1310));
    $creditedIqd = (int)round($amountUsd * $rate);
    try {
        $conn->execute('INSERT INTO recharge_requests(user_id, amount_iqd, method, status, receipt, ext_ref, auto, created_at) VALUES(?,?,?,?,?,?,1,?)',
            [$_SESSION['user_id'], $creditedIqd, py_or($pm['name'], 'Binance Pay'), 'approved', '', $orderId, now_str()]);
        $conn->execute('UPDATE users SET balance_iqd=balance_iqd+? WHERE id=?', [$creditedIqd, $_SESSION['user_id']]);
        $conn->commit();
    } catch (IntegrityError $e) {
        $conn->rollback();
        return jsonify(['ok' => false, 'msg' => 'رقم عملية Binance هذا مستخدم مسبقاً لشحن رصيد آخر']);
    }
    $newBalance = wallet_balance_iqd($_SESSION['user_id']);
    add_event((int)$_SESSION['user_id'], null, 'تم شحن ' . number_format($creditedIqd) . ' د.ع', 'تم التحقق من عملية Binance Pay تلقائياً وإضافة الرصيد', 'success');
    return jsonify(['ok' => true, 'msg' => 'تم التحقق من الدفع وإضافة الرصيد بنجاح', 'balance_iqd' => $newBalance]);
}, ['POST']);

route('/wallet/recharge/asiacell/start', function () {
    if (($r = require_login()) !== null) return $r;
    $uid = $_SESSION['user_id'];
    $data = req_data();
    $phone = py_strip(py_str(dget($data, 'phone', '')));
    try { $amountIqd = py_int(dget($data, 'amount_iqd', 0)); } catch (PyValueError | PyTypeError $e) { $amountIqd = 0; }

    $existing = _asiacell_flow_get($uid);
    if ($existing && py_float(py_or(dget($existing, 'amount_iqd_paid', 0), 0)) > 0) {
        return jsonify(['ok' => false, 'msg' => 'لديك عملية دفع آسياسيل غير مكتملة، أكملها أو ألغِها أولاً', 'has_pending' => true]);
    }
    if (!preg_match('/^(077|078|079)\p{Nd}{8}$/Du', $phone)) {
        return jsonify(['ok' => false, 'msg' => 'رقم هاتف آسياسيل غير صحيح، يجب أن يكون بصيغة 07xxxxxxxxx']);
    }

    $conn = get_db();
    $pm = $conn->execute("SELECT * FROM payment_methods WHERE method_type='asiacell' AND active=1 LIMIT 1")->fetchone();
    if (!$pm) return jsonify(['ok' => false, 'msg' => 'طريقة الدفع غير متاحة حالياً']);
    try { $extras = _extras_dict($pm['method_extras']); } catch (Throwable $e) { $extras = []; }
    $receiver = py_strip(py_or($pm['number'], ''));
    try { $exchangeRate = py_float(py_or($pm['exchange_rate'], 0)); } catch (PyValueError | PyTypeError $e) { $exchangeRate = 0; }
    $maxTransfer = py_int(py_or(dget($extras, 'max_transfer'), 10000));
    if ($maxTransfer < 1000) $maxTransfer = 10000;
    if (!py_truthy($receiver) || $exchangeRate <= 0) return jsonify(['ok' => false, 'msg' => 'لم يتم إعداد آسياسيل من الإدارة بعد']);

    $tiers = py_or(dget($extras, 'tiers'), _default_asiacell_tiers());
    $tier = null;
    foreach ($tiers as $t) if (py_int(dget($t, 'iqd', 0)) === $amountIqd) { $tier = $t; break; }
    if (!$tier) return jsonify(['ok' => false, 'msg' => 'الرجاء اختيار أحد المبالغ المتاحة']);
    $bonusIqd = py_float(py_or(dget($tier, 'bonus'), 0));
    $creditedIqd = (int)round(($amountIqd + $bonusIqd) / $exchangeRate);

    $api = new AsiaCellAPI();
    [$success, $result] = $api->login($phone);
    if (!$success) { log_asiacell_debug('login', $api); return jsonify(['ok' => false, 'msg' => $result]); }

    _asiacell_flow_set($uid, [
        'api' => $api->get_state(), 'phone' => $phone,
        'bonus_iqd' => $bonusIqd, 'credited_iqd' => $creditedIqd,
        'exchange_rate' => $exchangeRate, 'amount_iqd_total' => $amountIqd,
        'amount_iqd_paid' => 0, 'current_chunk' => 0, 'max_transfer' => $maxTransfer,
        'receiver_msisdn' => $receiver, 'step' => 'sms',
    ]);
    return jsonify(['ok' => true, 'amount_iqd_total' => $amountIqd, 'bonus_iqd' => $bonusIqd, 'credited_iqd' => $creditedIqd]);
}, ['POST']);

route('/wallet/recharge/asiacell/verify-sms', function () {
    if (($r = require_login()) !== null) return $r;
    $uid = $_SESSION['user_id'];
    $flow = _asiacell_flow_get($uid);
    if (!$flow || dget($flow, 'step') !== 'sms') return jsonify(['ok' => false, 'msg' => 'انتهت صلاحية العملية، ابدأ من جديد']);
    $code = py_strip(py_str(dget(req_data(), 'code', '')));
    if (!preg_match('/^\p{Nd}{4,6}$/Du', $code)) return jsonify(['ok' => false, 'msg' => 'رمز التحقق يجب أن يكون من 4 إلى 6 أرقام']);

    $api = new AsiaCellAPI($flow['api']);
    [$success, $result] = $api->verify_sms($code);
    if (!$success) { log_asiacell_debug('verifySms', $api); return jsonify(['ok' => false, 'msg' => $result]); }

    $chunk = min($flow['max_transfer'], $flow['amount_iqd_total'] - $flow['amount_iqd_paid']);
    [$success2, $result2] = $api->start_transfer($chunk, $flow['receiver_msisdn']);
    if (!$success2) { log_asiacell_debug('startTransfer', $api); return jsonify(['ok' => false, 'msg' => $result2]); }

    $flow['api'] = $api->get_state();
    $flow['current_chunk'] = $chunk;
    $flow['step'] = 'confirm';
    _asiacell_flow_set($uid, $flow);
    return jsonify(['ok' => true, 'chunk_amount' => $chunk, 'total' => $flow['amount_iqd_total'],
        'remaining_after' => $flow['amount_iqd_total'] - $flow['amount_iqd_paid'] - $chunk]);
}, ['POST']);

route('/wallet/recharge/asiacell/confirm', function () {
    if (($r = require_login()) !== null) return $r;
    $uid = $_SESSION['user_id'];
    $flow = _asiacell_flow_get($uid);
    if (!$flow || dget($flow, 'step') !== 'confirm') return jsonify(['ok' => false, 'msg' => 'انتهت صلاحية العملية، ابدأ من جديد']);
    $code = py_strip(py_str(dget(req_data(), 'code', '')));
    if (!preg_match('/^\p{Nd}{4,6}$/Du', $code)) return jsonify(['ok' => false, 'msg' => 'رمز التأكيد يجب أن يكون من 4 إلى 6 أرقام']);

    $api = new AsiaCellAPI($flow['api']);
    [$success, $result] = $api->confirm_transfer($code);
    if (!$success) { log_asiacell_debug('confirmTransfer', $api); return jsonify(['ok' => false, 'msg' => $result]); }

    $flow['amount_iqd_paid'] = py_int($flow['amount_iqd_paid']) + py_int($flow['current_chunk']);
    $fullyPaid = $flow['amount_iqd_paid'] >= $flow['amount_iqd_total'];

    if (!$fullyPaid) {
        $nextChunk = min($flow['max_transfer'], $flow['amount_iqd_total'] - $flow['amount_iqd_paid']);
        [$success2, $result2] = $api->start_transfer($nextChunk, $flow['receiver_msisdn']);
        if (!$success2) {
            log_asiacell_debug('startTransfer_chunk', $api);
            $creditedIqd = $flow['exchange_rate'] > 0 ? (int)round($flow['amount_iqd_paid'] / $flow['exchange_rate']) : 0;
            if ($creditedIqd > 0) {
                $conn = get_db();
                $conn->execute('UPDATE users SET balance_iqd=balance_iqd+? WHERE id=?', [$creditedIqd, $uid]);
                $conn->execute("INSERT INTO recharge_requests(user_id, amount_iqd, method, status, receipt, auto, created_at) VALUES(?,?,?,?,?,1,?)",
                    [$uid, $creditedIqd, 'آسياسيل', 'approved', '', now_str()]);
                $conn->commit();
                add_event($uid, null, '⚠️ توقف تحويل آسياسيل جزئياً',
                    'تم تحويل ' . number_format(py_int($flow['amount_iqd_paid'])) . ' د.ع بنجاح، لكن تعذر إكمال المبلغ المتبقي. أضفنا ' . number_format($creditedIqd) . ' د.ع كرصيد إلى حسابك.', 'warning');
            }
            _asiacell_flow_clear($uid);
            return jsonify(['ok' => false, 'msg' => 'تم تحويل جزء من المبلغ لكن تعذر إكمال الباقي: ' . py_str($result2), 'partial_stopped' => true, 'credited_iqd' => $creditedIqd]);
        }
        $flow['api'] = $api->get_state();
        $flow['current_chunk'] = $nextChunk;
        _asiacell_flow_set($uid, $flow);
        return jsonify(['ok' => true, 'done' => false, 'paid' => $flow['amount_iqd_paid'], 'total' => $flow['amount_iqd_total'], 'next_chunk' => $nextChunk]);
    }

    $creditedIqd = py_int(py_round(py_float($flow['credited_iqd'])));
    $bonusIqd = py_float(py_or(dget($flow, 'bonus_iqd', 0), 0));
    $conn = get_db();
    $conn->execute('UPDATE users SET balance_iqd=balance_iqd+? WHERE id=?', [$creditedIqd, $uid]);
    $conn->execute("INSERT INTO recharge_requests(user_id, amount_iqd, method, status, receipt, auto, created_at) VALUES(?,?,?,?,?,1,?)",
        [$uid, $creditedIqd, 'آسياسيل', 'approved', '', now_str()]);
    $conn->commit();
    $newBalance = wallet_balance_iqd($uid);
    _asiacell_flow_clear($uid);
    $bonusTxt = $bonusIqd > 0 ? ' (تشمل هدية ' . number_format((int)$bonusIqd) . ' د.ع)' : '';
    add_event($uid, null, 'تم شحن رصيدك', 'تم إضافة ' . number_format($creditedIqd) . ' د.ع إلى رصيد حسابك تلقائياً عبر آسياسيل' . $bonusTxt . '.', 'success');
    return jsonify(['ok' => true, 'done' => true, 'balance_iqd' => $newBalance, 'bonus_iqd' => $bonusIqd]);
}, ['POST']);

route('/wallet/recharge/asiacell/cancel', function () {
    if (($r = require_login()) !== null) return $r;
    $uid = $_SESSION['user_id'];
    $flow = _asiacell_flow_get($uid);
    $creditedIqd = 0;
    if ($flow && py_float(py_or(dget($flow, 'amount_iqd_paid', 0), 0)) > 0 && py_float(py_or(dget($flow, 'exchange_rate', 0), 0)) > 0) {
        $creditedIqd = (int)round($flow['amount_iqd_paid'] / $flow['exchange_rate']);
        $conn = get_db();
        $conn->execute('UPDATE users SET balance_iqd=balance_iqd+? WHERE id=?', [$creditedIqd, $uid]);
        $conn->execute("INSERT INTO recharge_requests(user_id, amount_iqd, method, status, receipt, auto, created_at) VALUES(?,?,?,?,?,1,?)",
            [$uid, $creditedIqd, 'آسياسيل', 'approved', '', now_str()]);
        $conn->commit();
        add_event($uid, null, 'تم استرداد رصيدك',
            'تم إلغاء عملية آسياسيل بعد تحويل ' . number_format(py_int($flow['amount_iqd_paid'])) . ' د.ع، وأضفنا ' . number_format($creditedIqd) . ' د.ع كرصيد إلى حسابك.', 'info');
    }
    _asiacell_flow_clear($uid);
    return jsonify(['ok' => true, 'credited_iqd' => $creditedIqd]);
}, ['POST']);

/* ---------------------------------------------------------------- لوحة الأدمن: طلبات الشحن */

route('/admin/recharge-requests', function () {
    if (($r = require_admin()) !== null) return $r;
    $rows = get_db()->execute(
        'SELECT r.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone FROM recharge_requests r '
        . 'LEFT JOIN users u ON r.user_id=u.id ORDER BY r.id DESC LIMIT 200'
    )->fetchall();
    $reqs = array_map(fn($r) => [
        'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'user_name' => py_or($r['user_name'], ''),
        'user_email' => py_or($r['user_email'], ''), 'user_phone' => py_or($r['user_phone'], ''),
        'amount_iqd' => (int)$r['amount_iqd'], 'method' => $r['method'], 'status' => $r['status'],
        'has_receipt' => py_truthy($r['receipt']), 'auto' => py_truthy($r['auto']), 'created_at' => $r['created_at'],
    ], $rows);
    $cnt = fn($s) => count(array_filter($reqs, fn($r) => $r['status'] === $s));
    return jsonify(['ok' => true, 'requests' => $reqs, 'pending' => $cnt('pending'), 'approved' => $cnt('approved'), 'rejected' => $cnt('rejected')]);
}, ['GET']);

route('/admin/recharge-requests/<int:rid>/receipt', function ($rid) {
    if (($r = require_admin()) !== null) return $r;
    $row = get_db()->execute('SELECT receipt FROM recharge_requests WHERE id=?', [$rid])->fetchone();
    return jsonify(['ok' => (bool)$row, 'receipt' => $row ? py_or($row['receipt'], '') : '']);
}, ['GET']);

route('/admin/recharge-requests/<int:rid>/action', function ($rid) {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data_strict();
    $action = dget($data, 'action');
    if (!in_array($action, ['approve', 'reject'], true)) return jsonify(['ok' => false, 'msg' => 'إجراء غير صالح']);
    $conn = get_db();
    $req = $conn->execute('SELECT * FROM recharge_requests WHERE id=?', [$rid])->fetchone();
    if (!$req) return jsonify(['ok' => false, 'msg' => 'الطلب غير موجود']);
    if ($req['status'] !== 'pending') return jsonify(['ok' => false, 'msg' => 'الطلب تمت معالجته مسبقاً']);
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    // تحديث مشروط بـ status='pending' يمنع الشحن المزدوج لو ضغط مشرفان بنفس اللحظة
    $cur = $conn->execute("UPDATE recharge_requests SET status=? WHERE id=? AND status='pending'", [$newStatus, $rid]);
    if ($cur->rowcount < 1) { $conn->rollback(); return jsonify(['ok' => false, 'msg' => 'الطلب تمت معالجته مسبقاً']); }
    if ($action === 'approve') {
        $conn->execute('UPDATE users SET balance_iqd=balance_iqd+? WHERE id=?', [(int)$req['amount_iqd'], $req['user_id']]);
        $conn->commit();
        add_event((int)$req['user_id'], null, 'تم شحن ' . number_format((int)$req['amount_iqd']) . ' د.ع', 'تمت الموافقة على طلب الشحن وإضافة الرصيد', 'success');
    } else {
        $conn->commit();
        add_event((int)$req['user_id'], null, 'تم رفض طلب الشحن', 'طلب شحن ' . number_format((int)$req['amount_iqd']) . ' د.ع عبر ' . $req['method'] . ' تم رفضه', 'warning');
    }
    return jsonify(['ok' => true, 'msg' => $action === 'approve' ? 'تم قبول الطلب' : 'تم رفض الطلب']);
}, ['POST']);
