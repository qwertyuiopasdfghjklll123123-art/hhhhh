<?php
/**
 * مسارات متنوعة: العملات، السياسات، سجل الشحن، الإحالة، الأرباح، الإعلانات، الإشعارات
 * منقولة من app.py:5597-5932.
 */

/* ------------------------------ العملات والسياسات ------------------------------ */

const CURRENCY_DEFAULT_RATES = [
    'IQD' => 1500, 'SAR' => 3.75, 'AED' => 3.67, 'KWD' => 0.307, 'QAR' => 3.64, 'BHD' => 0.376,
    'OMR' => 0.385, 'JOD' => 0.709, 'EGP' => 49, 'LBP' => 89500, 'SYP' => 13000, 'YER' => 250,
    'LYD' => 4.85, 'MAD' => 9.9, 'TND' => 3.1, 'DZD' => 134, 'EUR' => 0.92, 'GBP' => 0.79,
    'TRY' => 34, 'INR' => 83.5, 'PKR' => 278, 'CNY' => 7.24, 'CAD' => 1.36, 'AUD' => 1.52,
    'CHF' => 0.88, 'ZAR' => 18.3, 'NGN' => 1550, 'MYR' => 4.7, 'IDR' => 15750,
];

route('/api/currency-rates', function () {
    $rates = ['USD' => 1];
    foreach (CURRENCY_DEFAULT_RATES as $code => $default) {
        try {
            $rates[$code] = py_float(py_or(get_setting("rate_$code", py_str($default)), $default));
        } catch (PyValueError | PyTypeError $e) {
            $rates[$code] = $default;
        }
    }
    return jsonify(['ok' => true, 'rates' => $rates]);
});

route('/api/site-policies', function () {
    return jsonify(['ok' => true,
        'whatsapp' => get_setting('support_whatsapp', ''),
        'terms' => get_setting('site_terms', ''),
        'privacy' => get_setting('site_privacy', '')]);
});

/* -------------------------------- سجل الشحن -------------------------------- */

route('/api/recharge/history', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM recharge_requests WHERE user_id=? ORDER BY created_at DESC, id ASC LIMIT 50', [$uid])->fetchall();
    $result = [];
    foreach ($rows as $r) {
        $receipt_val = py_or($r['receipt'], '');
        $has_receipt = py_truthy($receipt_val);
        $receipt_short = ($receipt_val !== '' && strpos($receipt_val, 'data:image') !== 0) ? $receipt_val : '';
        $result[] = [
            'id' => $r['id'], 'amount' => $r['amount'], 'method' => $r['method'],
            'status' => $r['status'], 'date' => fmt_time('%d/%m %H:%M', $r['created_at']),
            'full_date' => fmt_time('%d/%m/%Y', $r['created_at']),
            'full_time' => fmt_time('%H:%M', $r['created_at']),
            'receipt' => $receipt_short, 'has_receipt' => $has_receipt,
        ];
    }
    $total = 0;
    foreach ($result as $x) if ($x['status'] === 'approved') $total += $x['amount'];
    return jsonify(['ok' => true, 'history' => $result, 'total' => $total]);
});

route('/api/recharge/receipt/<int:rid>', function ($rid) {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $conn = get_db();
    $row = $conn->execute('SELECT receipt FROM recharge_requests WHERE id=? AND user_id=?', [$rid, $_SESSION['user_id']])->fetchone();
    if ($row) return jsonify(['ok' => true, 'receipt' => py_or($row['receipt'], '')]);
    return jsonify(['ok' => false], 404);
});

/* ---------------------------------- الإحالة ---------------------------------- */

const REFERRAL_COMMISSION = 5;
const REFERRAL_MIN_WITHDRAW = 1.0;

function _get_or_create_referral($user_id) {
    $conn = get_db();
    $ref = $conn->execute('SELECT * FROM referrals WHERE user_id=?', [$user_id])->fetchone();
    if (!$ref) {
        $code = 'FC' . strtoupper(substr(bin2hex(random_bytes(16)), 0, 6));
        $conn->execute('INSERT INTO referrals(user_id,code) VALUES(?,?)', [$user_id, $code]);
        $conn->commit();
        $ref = $conn->execute('SELECT * FROM referrals WHERE user_id=?', [$user_id])->fetchone();
    }
    return $ref;
}

route('/api/referral', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $ref = _get_or_create_referral($uid);
    $conn = get_db();
    $history = $conn->execute('SELECT * FROM referral_history WHERE referrer_id=? ORDER BY created_at DESC, id ASC LIMIT 10', [$uid])->fetchall();
    $hist = [];
    foreach ($history as $h) {
        $hist[] = ['amount' => py_round($h['amount'], 4), 'from_order' => $h['from_order'],
                   'time' => fmt_time('%d/%m %H:%M', $h['created_at'])];
    }
    return jsonify(['ok' => true, 'referral' => [
        'code' => $ref['code'], 'count' => $ref['ref_count'],
        'earnings' => py_round($ref['earnings'], 2), 'pending' => py_round($ref['pending'], 2),
        'commission' => REFERRAL_COMMISSION, 'min_withdraw' => REFERRAL_MIN_WITHDRAW,
        'link' => 'https://' . DOMAIN . '/app?ref=' . $ref['code'],
        'history' => $hist,
    ]]);
}, ['POST']);

route('/api/referral/apply', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data();
    $code = mb_strtoupper(py_strip(py_str(dget($data, 'code', ''))), 'UTF-8');
    if (!py_truthy($code)) {
        return jsonify(['ok' => false, 'error' => 'أدخل كود الإحالة']);
    }
    $my_ref = _get_or_create_referral($uid);
    if (py_truthy($my_ref['referred_by'])) {
        return jsonify(['ok' => false, 'error' => 'لديك إحالة مسبقاً']);
    }
    if ($my_ref['code'] === $code) {
        return jsonify(['ok' => false, 'error' => 'لا يمكنك إحالة نفسك']);
    }
    $conn = get_db();
    $referrer = $conn->execute('SELECT * FROM referrals WHERE code=?', [$code])->fetchone();
    if (!$referrer) {
        return jsonify(['ok' => false, 'error' => 'كود إحالة غير صالح']);
    }
    $conn->execute('UPDATE referrals SET referred_by=? WHERE user_id=?', [$referrer['user_id'], $uid]);
    $conn->execute('UPDATE referrals SET ref_count=ref_count+1 WHERE user_id=?', [$referrer['user_id']]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/referral/withdraw', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $ref = _get_or_create_referral($uid);
    $pending = $ref['pending'];
    if ($pending < REFERRAL_MIN_WITHDRAW) {
        return jsonify(['ok' => false, 'error' => 'الحد الأدنى للسحب $' . py_str(REFERRAL_MIN_WITHDRAW)]);
    }
    $conn = get_db();
    $conn->execute('UPDATE referrals SET earnings=earnings+?, pending=0 WHERE user_id=?', [$pending, $uid]);
    $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$pending, $uid]);
    $conn->commit();
    $new_bal = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone()['balance'];
    return jsonify(['ok' => true, 'amount' => py_round($pending, 2), 'balance' => py_round($new_bal, 2)]);
}, ['POST']);

/* ---------------------------------- الأرباح ---------------------------------- */

const MARKUP_RATE = 1.5;

route('/api/admin/profits', function () {
    if (!array_key_exists('user_id', $_SESSION) || !py_truthy($_SESSION['is_admin'] ?? false)) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $row = $conn->execute('SELECT COUNT(*) AS cnt, SUM(CAST(charge AS REAL)) AS rev FROM orders')->fetchone();
    $total_orders = $row['cnt'];
    $total_revenue = py_or_null($row['rev'], 0);   // COALESCE(..., 0) بنوع SQLite: int 0 لجدول فارغ
    $total_provider = MARKUP_RATE > 0 ? py_round($total_revenue / MARKUP_RATE, 4) : $total_revenue;
    $profit = py_round($total_revenue - $total_provider, 2);
    $margin = $total_revenue > 0 ? py_round(($profit / $total_revenue * 100), 1) : 0;
    $avg_profit = $total_orders > 0 ? py_round($profit / $total_orders, 2) : 0;
    return jsonify(['ok' => true, 'revenue' => py_round($total_revenue, 2), 'provider_cost' => py_round($total_provider, 2),
        'profit' => $profit, 'margin' => $margin, 'total_orders' => $total_orders,
        'avg_profit' => $avg_profit, 'markup' => MARKUP_RATE]);
}, ['POST']);

/* --------------------------------- الإعلانات --------------------------------- */

route('/api/announcements', function () {
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM announcements ORDER BY created_at DESC, __fc_seq ASC')->fetchall();
    $anns = [];
    foreach ($rows as $r) {
        $anns[] = ['id' => $r['id'], 'text' => $r['text'], 'type' => $r['type'],
                   'date' => fmt_time('%Y-%m-%d %H:%M', $r['created_at']), 'created_at' => $r['created_at']];
    }
    return jsonify(['ok' => true, 'announcements' => $anns]);
});

route('/api/admin/announcements/add', function () {
    if (!array_key_exists('user_id', $_SESSION) || !py_truthy($_SESSION['is_admin'] ?? false)) return jsonify(['ok' => false], 403);
    $data = req_data_strict();
    $text = py_strip(py_or(dget($data, 'text'), ''));
    if (!py_truthy($text)) {
        return jsonify(['ok' => false, 'error' => 'النص مطلوب']);
    }
    $ann_id = 'ann_' . time() . '_' . bin2hex(random_bytes(2));
    $atype = dget($data, 'type', 'info');
    $conn = get_db();
    $conn->execute('INSERT INTO announcements(id,text,type) VALUES(?,?,?)', [$ann_id, $text, $atype]);
    $conn->commit();
    $users = $conn->execute('SELECT id FROM users')->fetchall();
    $type_labels = ['info' => 'ℹ', 'offer' => '', 'warning' => '', 'update' => '🆕'];
    $emoji = (is_string($atype) && array_key_exists($atype, $type_labels)) ? $type_labels[$atype] : '📢';
    // الأصل يرسلها بـ ThreadPoolExecutor؛ هنا بعد إرسال الاستجابة
    defer_after_response(function () use ($users, $emoji, $text) {
        foreach ($users as $u) {
            try { push_user_notif($u['id'], 'system', $emoji . ' إعلان جديد', py_slice($text, null, 100), 'bullhorn'); }
            catch (Throwable $e) {}
        }
    });
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/admin/announcements/delete', function () {
    if (!array_key_exists('user_id', $_SESSION) || !py_truthy($_SESSION['is_admin'] ?? false)) return jsonify(['ok' => false], 403);
    $data = req_data_strict();
    $ann_id = dget($data, 'id', '');
    $conn = get_db();
    $conn->execute('DELETE FROM announcements WHERE id=?', [py_str($ann_id)]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

/* --------------------------------- الإشعارات --------------------------------- */

route('/api/notifications', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC, __fc_seq ASC LIMIT 30', [$uid])->fetchall();
    $unread = $conn->execute('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$uid])->fetchone()[0];
    $notifs = [];
    foreach ($rows as $r) {
        $notifs[] = [
            'id' => $r['id'], 'type' => $r['type'], 'title' => $r['title'], 'text' => $r['text'],
            'icon' => $r['icon'], 'read' => (bool)$r['is_read'],
            'goto' => $r->has('goto') ? $r['goto'] : '',
            'time' => $r['created_at'], 'time_str' => fmt_time('%d/%m %H:%M', $r['created_at']),
        ];
    }
    return jsonify(['ok' => true, 'notifications' => $notifs, 'unread' => $unread]);
}, ['POST']);

route('/api/notifications/read', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $data = req_data();
    $nid = dget($data, 'id', '');
    $conn = get_db();
    if ($nid === 'all') {
        $conn->execute('UPDATE notifications SET is_read=1 WHERE user_id=?', [$_SESSION['user_id']]);
    } else {
        $conn->execute('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?', [py_str($nid), $_SESSION['user_id']]);
    }
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/notifications/delete', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $data = req_data();
    $nid = dget($data, 'id', '');
    $conn = get_db();
    if ($nid === 'all') {
        $conn->execute('DELETE FROM notifications WHERE user_id=?', [$_SESSION['user_id']]);
    } else {
        $conn->execute('DELETE FROM notifications WHERE id=? AND user_id=?', [py_str($nid), $_SESSION['user_id']]);
    }
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);
