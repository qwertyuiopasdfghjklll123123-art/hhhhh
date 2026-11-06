<?php
/**
 * مسارات Web Push (app.py:1971-2091): مفتاح VAPID، الاشتراك/إلغاؤه، اختبار الإشعارات، التشخيص.
 */

route('/api/push/vapid-key', function () {
    $k = get_vapid_keys();
    return jsonify(['ok' => true, 'key' => $k['public'] ?? '']);
});

route('/api/push/subscribe', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $data = req_data();
    $sub = dget($data, 'subscription');
    $isDict = is_array($sub) && !is_list_array($sub);
    if (!py_truthy($sub) || !$isDict || !py_truthy(dget($sub, 'endpoint'))) {
        return jsonify(['ok' => false, 'msg' => 'No subscription']);
    }
    $uid = (string)$_SESSION['user_id'];
    $isNew = false;
    update_push_subs(function (array $subs) use ($uid, $sub, &$isNew) {
        $cur = $subs[$uid] ?? null;
        if ($cur === null) {
            $subs[$uid] = [];
        } elseif (is_array($cur) && !is_list_array($cur)) {        // صيغة قديمة: قاموس واحد → قائمة
            $subs[$uid] = py_truthy(dget($cur, 'endpoint')) ? [$cur] : [];
        } elseif (!is_array($cur)) {
            $subs[$uid] = [];
        }
        $existing = [];
        foreach ($subs[$uid] as $s) {
            if (is_array($s) && !is_list_array($s)) $existing[] = dget($s, 'endpoint');
        }
        if (!in_array($sub['endpoint'], $existing, false)) {
            $subs[$uid][] = $sub;
            $isNew = true;
        }
        return $subs;
    });
    _mail_log($isNew
        ? "[push] new subscription saved: uid=$uid endpoint=" . py_slice((string)$sub['endpoint'], null, 60) . '...'
        : "[push] subscription already exists: uid=$uid");
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/push/unsubscribe', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = (string)$_SESSION['user_id'];
    update_push_subs(function (array $subs) use ($uid) {
        unset($subs[$uid]);
        return $subs;
    });
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/push/test', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'not logged in'], 401);
    $uid = $_SESSION['user_id'];
    $subs = load_push_subs();
    $sub_data = $subs[(string)$uid] ?? null;
    if (!py_truthy($sub_data)) {
        return jsonify(['ok' => false, 'msg' => 'ما عندك اشتراك إشعارات على السيرفر. افتح التطبيق وفعّل الإشعارات أولاً.']);
    }
    $keys = get_vapid_keys();
    if (!py_truthy($keys['private'] ?? '')) return jsonify(['ok' => false, 'msg' => 'VAPID keys مو مولّدة بالسيرفر']);
    $sub_list = (is_array($sub_data) && is_list_array($sub_data)) ? $sub_data : [$sub_data];
    send_push($uid, '🔔 اختبار الإشعار', 'إذا وصلتك هاي الرسالة يعني الإشعارات شغّالة 100%!');
    return jsonify(['ok' => true, 'msg' => 'تم إرسال إشعار اختبار إلى ' . count($sub_list) . ' جهاز/جلسة. شيكلوگ السيرفر إذا ما وصل.']);
}, ['POST']);

route('/api/push/test-admins', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'admin only'], 403);
    $keys = get_vapid_keys();
    if (!py_truthy($keys['private'] ?? '')) return jsonify(['ok' => false, 'msg' => 'VAPID keys مو مولّدة']);
    $conn = get_db();
    $admins = $conn->execute('SELECT id, name FROM users WHERE is_admin=1 ORDER BY id ASC')->fetchall();
    $subs = load_push_subs();
    $report = [];
    foreach ($admins as $a) {
        $aid = (string)$a['id'];
        $sd = $subs[$aid] ?? null;
        // bool(sd) and ((list and any(dict with endpoint)) or (dict and sd.get('endpoint'))) — بقيمة بايثون (قد تكون نصاً)
        $isList = is_array($sd) && is_list_array($sd);
        $isDict = is_array($sd) && !is_list_array($sd);
        $A = false;
        if ($isList) {
            foreach ($sd as $s) {
                if (is_array($s) && !is_list_array($s) && py_truthy(dget($s, 'endpoint'))) { $A = true; break; }
            }
        }
        if (!py_truthy($sd)) $has_sub = false;
        elseif ($A) $has_sub = true;
        else $has_sub = $isDict ? dget($sd, 'endpoint') : false;
        $report[] = ['id' => $a['id'], 'name' => $a['name'], 'has_subscription' => $has_sub];
        if (py_truthy($has_sub)) {
            push_user_notif($a['id'], 'admin', '🧪 اختبار إشعار أدمن', 'إذا وصلتك هاي الرسالة يعني إشعارات العمليات (طلبات/شحن/تذاكر) راح توصلك بنجاح', 'order');
        }
    }
    $sent_count = 0;
    foreach ($report as $r) { if (py_truthy($r['has_subscription'])) $sent_count++; }
    $missing_count = count($report) - $sent_count;
    return jsonify(['ok' => true,
        'msg' => "انبعث push اختباري لـ {$sent_count} أدمن. {$missing_count} أدمن ما عندهم اشتراك.",
        'admins' => $report]);
}, ['POST']);

route('/api/push/diagnostics', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = (string)$_SESSION['user_id'];
    $subs = load_push_subs();
    $sub_data = $subs[$uid] ?? null;
    $keys = get_vapid_keys();
    // بايثون يفحص تثبيت pywebpush/PyJWT؛ هنا التشفير والتوقيع مدمجان (OpenSSL) فيُعدّان "مثبّتين" عند توفر OpenSSL
    $have = function_exists('openssl_pkey_derive') && function_exists('openssl_encrypt');
    $sub_count = 0;
    $endpoints = [];
    if (is_array($sub_data) && is_list_array($sub_data)) {
        $sub_count = count($sub_data);
        foreach ($sub_data as $s) {
            if (is_array($s) && !is_list_array($s)) $endpoints[] = py_slice((string)py_or(dget($s, 'endpoint', ''), ''), null, 80) . '...';
        }
    } elseif (is_array($sub_data)) {
        $sub_count = 1;
        $endpoints = [py_slice((string)dget($sub_data, 'endpoint', ''), null, 80) . '...'];
    }
    return jsonify(['ok' => true,
        'vapid_public_key_set' => py_truthy($keys['public'] ?? ''),
        'vapid_private_key_set' => py_truthy($keys['private'] ?? ''),
        'vapid_public_key_length' => py_len($keys['public'] ?? ''),
        'pywebpush_installed' => $have,
        'pyjwt_installed' => $have,
        'subscription_count' => $sub_count,
        'endpoints' => $endpoints,
        'total_users_subscribed' => count($subs)]);
});
