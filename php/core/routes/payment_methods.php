<?php
/**
 * طرق الدفع (عامة + إدارة)، Binance/آسياسيل/نجوم تليجرام، طلبات الأدمن (قائمة/إعادة إرسال/تغيير حالة)، الإحصائيات
 * منقولة من app.py:4463-5100.
 */

/** json.loads(raw or '{}') or {}  ثم .get(...) — غير القاموس يرمي PyTypeError (مثل AttributeError) */
function _extras_dict($raw): array {
    $d = json_loads(py_or($raw, '{}'));
    if (!py_truthy($d)) return [];
    if (!is_array($d) || is_list_array($d)) throw new PyTypeError("object has no attribute 'get'");
    return $d;
}

/** كتابة صورة data-URL إلى PM_IMG_DIR وإرجاع اسم الملف (ترمي استثناء عند الفشل) */
function _write_pm_image(string $img_data): string {
    [$header, $b64] = explode(',', $img_data, 2);
    $ext = 'png';
    if (strpos($header, 'jpeg') !== false || strpos($header, 'jpg') !== false) $ext = 'jpg';
    elseif (strpos($header, 'webp') !== false) $ext = 'webp';
    elseif (strpos($header, 'gif') !== false) $ext = 'gif';
    $fname = 'pm_' . substr(bin2hex(random_bytes(16)), 0, 12) . '.' . $ext;
    $bytes = py_b64decode($b64);
    if (file_put_contents(PM_IMG_DIR . '/' . $fname, $bytes) === false) throw new RuntimeException('write failed');
    return $fname;
}

function _remove_pm_image_file($name): void {
    if (!py_truthy($name)) return;
    $p = PM_IMG_DIR . '/' . $name;
    if (file_exists($p)) @unlink($p);
}

/** _save_pm_image  (app.py:4783) */
function _save_pm_image($img_data, $fallback) {
    if ($img_data === '__remove__') return '';
    if (!(py_truthy($img_data) && is_string($img_data) && strpos($img_data, ',') !== false)) return $fallback;
    try { return _write_pm_image($img_data); }
    catch (Throwable $e) { return $fallback; }
}

/* '/api/payment-methods'  (app.py:4464) */
route('/api/payment-methods', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM payment_methods WHERE active=1 ORDER BY sort_order ASC, created_at DESC, id ASC')->fetchall();
    $methods = [];
    foreach ($rows as $r) {
        $mtype = $r->has('method_type') ? $r['method_type'] : 'manual';
        $qr_code = '';
        $tiers = null;
        if ($mtype === 'binance') {
            try { $qr_code = dget(_extras_dict($r['method_extras']), 'qr_code', ''); }
            catch (Throwable $e) { $qr_code = ''; }
        }
        if ($mtype === 'asiacell') {
            try { $tiers = py_or(dget(_extras_dict($r['method_extras']), 'tiers'), _default_asiacell_tiers()); }
            catch (Throwable $e) { $tiers = _default_asiacell_tiers(); }
        }
        $tg_stars_bot_username = '';
        $tg_stars_rate = null;
        if ($mtype === 'telegram_stars') {
            try {
                $_tgex = _extras_dict($r['method_extras']);
                $tg_stars_bot_username = dget($_tgex, 'bot_username', '');
                $tg_stars_rate = dget($_tgex, 'rate_usd_per_100', 1.5);
            } catch (Throwable $e) {
                $tg_stars_rate = 1.5;
            }
        }
        $methods[] = [
            'id' => $r['id'], 'name' => $r['name'], 'icon' => $r['icon'],
            'number' => $r['number'], 'exchange_rate' => $r['exchange_rate'],
            'currency' => $r['currency'], 'note' => $r['note'],
            'bot_link' => $r->has('bot_link') ? $r['bot_link'] : '',
            'image' => $r->has('image') ? $r['image'] : '',
            'method_type' => $mtype,
            'qr_code' => $qr_code,
            'tiers' => $tiers,
            'tg_stars_bot_username' => $tg_stars_bot_username,
            'tg_stars_rate' => $tg_stars_rate,
        ];
    }
    return jsonify(['ok' => true, 'methods' => $methods]);
});

/* '/api/admin/orders'  (app.py:4509) */
route('/api/admin/orders', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT o.*, u.name as user_name, u.email as user_email
                          FROM orders o LEFT JOIN users u ON o.user_id=u.id
                          ORDER BY o.created_at DESC, o.id ASC LIMIT 3000')->fetchall();
    $orders = [];
    foreach ($rows as $r) {
        $orders[] = [
            'id' => $r['id'],
            'order' => py_or($r['order_id'], ''), 'service' => $r['service'],
            'display_id' => $r->has('display_id') ? $r['display_id'] : '',
            'service_name' => $r['service_name'], 'link' => $r['link'],
            'quantity' => $r['quantity'], 'charge' => $r['charge'],
            'site_charge' => $r->has('site_charge') ? $r['site_charge'] : $r['charge'],
            'status' => $r['status'], 'user_name' => $r['user_name'],
            'user_email' => $r['user_email'], 'user_id' => $r['user_id'],
            'refunded' => $r->has('refunded') ? $r['refunded'] : 0,
            'created_at' => $r['created_at'],
        ];
    }
    return jsonify(['ok' => true, 'orders' => $orders]);
});

/* '/api/admin/order/resend'  (app.py:4534) */
route('/api/admin/order/resend', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 403);
    $data = req_data();
    $order_id = py_strip(py_str(dget($data, 'order_id', '')));
    if (!py_truthy($order_id)) {
        return jsonify(['ok' => false, 'msg' => 'order_id مطلوب']);
    }
    $conn = get_db();
    $row = $conn->execute('SELECT * FROM orders WHERE order_id=?', [$order_id])->fetchone();
    if (!$row) {
        return jsonify(['ok' => false, 'msg' => "الطلب #$order_id غير موجود"]);
    }
    try {
        try { $_ord_pid = py_int(py_or($row['provider_id'], 0)); } catch (Throwable $e) { $_ord_pid = 0; }
        if ($_ord_pid) { [$_api_url, $_api_key, $_prov_id] = get_provider_api($_ord_pid); }
        else { [$_api_url, $_api_key, $_prov_id] = get_provider_api(); }
        $r = requests_post($_api_url, ['data' => [
            'key' => $_api_key, 'action' => 'add',
            'service' => py_str($row['service']), 'link' => py_str($row['link']), 'quantity' => py_int($row['quantity']),
        ], 'timeout' => 30]);
        $result0 = $r->json();
        $result = ($result0 instanceof stdClass) ? [] : $result0;
        if (is_array($result) && !is_list_array($result) && array_key_exists('order', $result)) {
            $new_oid = py_str($result['order']);
            $conn->execute('UPDATE orders SET order_id=?, status=? WHERE order_id=?', [$new_oid, 'Processing', $order_id]);
            $conn->commit();
            try {
                push_user_notif($row['user_id'], 'order', "تم إعادة إرسال طلبك #$new_oid", $row['service_name'] . ' — تمت إعادة الإرسال بنجاح', 'check', "order:$new_oid");
            } catch (Throwable $e) {}
            return jsonify(['ok' => true, 'msg' => "تم إعادة الإرسال — طلب جديد #$new_oid", 'new_order_id' => $new_oid]);
        } else {
            $result = py_dict($result0);
            $err_msg = py_or(py_or(dget($result, 'error', ''), dget($result, 'message', '')), py_repr($result0));
            return jsonify(['ok' => false, 'msg' => 'خطأ من المزود: ' . py_str($err_msg)]);
        }
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => 'خطأ: ' . $e->getMessage()]);
    }
}, ['POST']);

/* '/api/admin/order/set-status'  (app.py:4577) */
route('/api/admin/order/set-status', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 403);
    $data = req_data();
    try { $oid = py_int(dget($data, 'id', 0)); }
    catch (PyValueError | PyTypeError $e) { $oid = 0; }
    $new_status = py_strip(py_str(dget($data, 'status', '')));
    $valid_statuses = ['Completed', 'Processing', 'Pending', 'In progress', 'Canceled', 'Partial', 'Error', 'Queued'];
    if (!$oid || !in_array($new_status, $valid_statuses, true)) {
        return jsonify(['ok' => false, 'msg' => 'بيانات غير صحيحة']);
    }
    try { $refund_amount = py_round(py_float(py_or(dget($data, 'refund_amount', 0), 0)), 4); }
    catch (PyValueError | PyTypeError $e) { $refund_amount = 0; }
    $conn = get_db();
    $row = $conn->execute('SELECT * FROM orders WHERE id=?', [$oid])->fetchone();
    if (!$row) {
        return jsonify(['ok' => false, 'msg' => 'الطلب غير موجود']);
    }
    $old_status = $row['status'];
    $conn->execute('UPDATE orders SET status=? WHERE id=?', [$new_status, $oid]);
    $display_ref = py_or(py_or($row['display_id'], $row['order_id']), (string)$oid);
    if ($refund_amount > 0 && !py_truthy($row['refunded'])) {
        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$refund_amount, $row['user_id']]);
        $conn->execute('UPDATE orders SET refunded=1 WHERE id=?', [$oid]);
        push_user_notif($row['user_id'], 'system', 'استرجاع $' . py_fmt($refund_amount),
            "طلب #$display_ref — تم إرجاع المبلغ إلى رصيدك", 'wallet', 'wallet:');
    }
    if ($new_status === 'Completed' && $old_status !== 'Completed') {
        push_user_notif($row['user_id'], 'order', "طلب #$display_ref اكتمل", py_str($row['service_name']), 'done', "order:$display_ref");
    }
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم تحديث حالة الطلب']);
}, ['POST']);

/* '/api/admin/stats'  (app.py:4613) */
route('/api/admin/stats', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $total_users = $conn->execute('SELECT COUNT(*) FROM users')->fetchone()[0];
    $total_orders = $conn->execute('SELECT COUNT(*) FROM orders')->fetchone()[0];
    $completed = $conn->execute("SELECT COUNT(*) FROM orders WHERE status='Completed'")->fetchone()[0];
    $processing = $conn->execute("SELECT COUNT(*) FROM orders WHERE status IN ('Processing','Pending','In progress')")->fetchone()[0];
    $queued = $conn->execute("SELECT COUNT(*) FROM orders WHERE status='Queued'")->fetchone()[0];
    return jsonify(['ok' => true, 'total_users' => $total_users, 'total_orders' => $total_orders, 'completed' => $completed,
        'processing' => $processing, 'queued' => $queued]);
});

/* '/api/admin/payment-methods' (GET)  (app.py:4626) */
route('/api/admin/payment-methods', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM payment_methods ORDER BY sort_order ASC, created_at DESC, id ASC')->fetchall();
    $methods = [];
    foreach ($rows as $r) {
        $mtype = $r->has('method_type') ? $r['method_type'] : 'manual';
        try { $extras = ($r->has('method_extras') && py_truthy($r['method_extras'])) ? json_loads($r['method_extras']) : []; }
        catch (Throwable $e) { $extras = []; }
        if (!is_array($extras)) $extras = [];
        $methods[] = [
            'id' => $r['id'], 'name' => $r['name'], 'icon' => $r['icon'],
            'number' => $r['number'], 'exchange_rate' => $r['exchange_rate'],
            'currency' => $r['currency'], 'note' => $r['note'], 'active' => $r['active'],
            'bot_link' => $r->has('bot_link') ? $r['bot_link'] : '',
            'image' => $r->has('image') ? $r['image'] : '',
            'method_type' => $mtype,
            'sort_order' => $r->has('sort_order') ? $r['sort_order'] : 0,
            // لا نُرسل مفاتيح Binance الفعلية أبداً، فقط إشارة إلى وجودها
            'has_binance_keys' => $mtype === 'binance' ? py_truthy(dget($extras, 'api_key')) : false,
            'qr_code' => $mtype === 'binance' ? dget($extras, 'qr_code', '') : '',
            'max_transfer' => $mtype === 'asiacell' ? dget($extras, 'max_transfer') : null,
            'tiers' => $mtype === 'asiacell' ? py_or(dget($extras, 'tiers'), _default_asiacell_tiers()) : null,
            // التوكن الفعلي لا يُرسل أبداً، فقط إشارة لوجوده
            'has_tg_stars_token' => $mtype === 'telegram_stars' ? py_truthy(dget($extras, 'bot_token')) : false,
            'tg_stars_bot_username' => $mtype === 'telegram_stars' ? dget($extras, 'bot_username', '') : '',
            'tg_stars_admin_chat_id' => $mtype === 'telegram_stars' ? dget($extras, 'admin_chat_id', '') : '',
            'tg_stars_rate' => $mtype === 'telegram_stars' ? dget($extras, 'rate_usd_per_100', 1.5) : null,
        ];
    }
    return jsonify(['ok' => true, 'methods' => $methods]);
});

/* '/api/admin/payment-methods/reorder'  (app.py:4662) */
route('/api/admin/payment-methods/reorder', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $order = py_or(dget($data, 'order'), []);
    $conn = get_db();
    foreach (array_values((array)$order) as $idx => $pm_id) {
        try { $conn->execute('UPDATE payment_methods SET sort_order=? WHERE id=?', [$idx, py_int($pm_id)]); }
        catch (Throwable $e) {}
    }
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم تحديث الترتيب']);
}, ['POST']);

/** قيمة تُخزَّن بعمود TEXT: SQLite يحوّل الأعداد العشرية إلى نص بصيغة %!.15g */
function sql_text($v) {
    if (is_float($v)) return py_sqlite_float_text($v);
    if (is_bool($v)) return $v ? '1' : '0';
    return $v;
}

/* '/api/admin/payment-methods' (POST)  (app.py:4678) */
route('/api/admin/payment-methods', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_strict();
    $name = py_strip(py_or(dget($data, 'name'), ''));
    if (!py_truthy($name)) {
        return jsonify(['ok' => false, 'msg' => 'اسم الطريقة مطلوب']);
    }
    $pm_image = '';
    $img_data = dget($data, 'image_data', '');
    if (py_truthy($img_data) && is_string($img_data) && strpos($img_data, ',') !== false) {
        try { $pm_image = _write_pm_image($img_data); }
        catch (Throwable $e) {}
    }
    $conn = get_db();
    // هذا المسار لطرق الدفع اليدوية فقط
    $conn->execute("INSERT INTO payment_methods(name,icon,number,exchange_rate,currency,note,bot_link,image,active,method_type) VALUES(?,?,?,?,?,?,?,?,?,'manual')",
        [$name, sql_text(dget($data, 'icon', 'fa-solid fa-credit-card')), sql_text(dget($data, 'number', '')),
         sql_text(dget($data, 'exchange_rate', '')), sql_text(dget($data, 'currency', 'IQD')),
         sql_text(dget($data, 'note', '')), sql_text(dget($data, 'bot_link', '')), $pm_image,
         py_truthy(dget($data, 'active', true)) ? 1 : 0]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم إضافة طريقة الدفع']);
}, ['POST']);

/* '/api/admin/payment-methods/<int:pm_id>' (PUT)  (app.py:4714) */
route('/api/admin/payment-methods/<int:pm_id>', function ($pm_id) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_strict();
    $img_data = dget($data, 'image_data', '');
    $conn = get_db();
    $_type_row = $conn->execute('SELECT method_type FROM payment_methods WHERE id=?', [$pm_id])->fetchone();
    if ($_type_row && py_truthy($_type_row['method_type']) && $_type_row['method_type'] !== 'manual') {
        return jsonify(['ok' => false, 'msg' => 'لا يمكن تعديل هذه الطريقة من هنا. استخدم إعدادات Binance/آسياسيل.']);
    }
    if (py_truthy($img_data) && is_string($img_data) && strpos($img_data, ',') !== false) {
        try {
            $fname = _write_pm_image($img_data);
            $old = $conn->execute('SELECT image FROM payment_methods WHERE id=?', [$pm_id])->fetchone();
            if ($old && py_truthy($old['image'])) _remove_pm_image_file($old['image']);
            $conn->execute('UPDATE payment_methods SET image=? WHERE id=?', [$fname, $pm_id]);
        } catch (Throwable $e) {}
    } elseif ($img_data === '__remove__') {
        $old = $conn->execute('SELECT image FROM payment_methods WHERE id=?', [$pm_id])->fetchone();
        if ($old && py_truthy($old['image'])) _remove_pm_image_file($old['image']);
        $conn->execute('UPDATE payment_methods SET image=? WHERE id=?', ['', $pm_id]);
    }
    $conn->execute('UPDATE payment_methods SET name=?,icon=?,number=?,exchange_rate=?,currency=?,note=?,bot_link=?,active=? WHERE id=?',
        [sql_text(dget($data, 'name', '')), sql_text(dget($data, 'icon', 'fa-solid fa-credit-card')), sql_text(dget($data, 'number', '')),
         sql_text(dget($data, 'exchange_rate', '')), sql_text(dget($data, 'currency', 'IQD')),
         sql_text(dget($data, 'note', '')), sql_text(dget($data, 'bot_link', '')),
         py_truthy(dget($data, 'active', true)) ? 1 : 0, $pm_id]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم تحديث طريقة الدفع']);
}, ['PUT']);

/* '/api/admin/payment-methods/<int:pm_id>' (DELETE)  (app.py:4763) */
route('/api/admin/payment-methods/<int:pm_id>', function ($pm_id) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $_type_row = $conn->execute('SELECT method_type FROM payment_methods WHERE id=?', [$pm_id])->fetchone();
    if ($_type_row && py_truthy($_type_row['method_type']) && $_type_row['method_type'] !== 'manual') {
        return jsonify(['ok' => false, 'msg' => 'لا يمكن حذف Binance Pay أو آسياسيل، فقط تعطيلهما من إعداداتهما.']);
    }
    $old = $conn->execute('SELECT image FROM payment_methods WHERE id=?', [$pm_id])->fetchone();
    if ($old && py_truthy($old['image'])) _remove_pm_image_file($old['image']);
    $conn->execute('DELETE FROM payment_methods WHERE id=?', [$pm_id]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم حذف طريقة الدفع']);
}, ['DELETE']);

/* '/api/admin/payment-methods/binance'  (app.py:4803) */
route('/api/admin/payment-methods/binance', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $conn = get_db();
    $row = $conn->execute("SELECT * FROM payment_methods WHERE method_type='binance' LIMIT 1")->fetchone();
    if (!$row) {
        return jsonify(['ok' => false, 'msg' => 'تعذر العثور على طريقة Binance Pay.']);
    }
    try { $extras = json_loads(py_or($row['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $api_key = py_strip(py_or(dget($data, 'api_key'), ''));
    $api_secret = py_strip(py_or(dget($data, 'api_secret'), ''));
    $binance_id = py_strip(py_or(dget($data, 'binance_id'), ''));
    // حقل فارغ يُبقي على المفتاح الحالي
    if (py_truthy($api_key)) $extras['api_key'] = $api_key;
    if (py_truthy($api_secret)) $extras['api_secret'] = $api_secret;
    $pm_image = _save_pm_image(dget($data, 'image_data', ''), $row->has('image') ? $row['image'] : '');
    $qr_code = _save_pm_image(dget($data, 'qr_data', ''), dget($extras, 'qr_code', ''));
    $extras['qr_code'] = $qr_code;
    $conn->execute('UPDATE payment_methods SET number=?, image=?, active=?, method_extras=? WHERE id=?',
        [$binance_id, $pm_image, py_truthy(dget($data, 'active')) ? 1 : 0, py_json_dumps($extras), $row['id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم حفظ إعدادات Binance Pay']);
}, ['POST']);

/* '/api/admin/payment-methods/asiacell'  (app.py:4832) */
route('/api/admin/payment-methods/asiacell', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $conn = get_db();
    $row = $conn->execute("SELECT * FROM payment_methods WHERE method_type='asiacell' LIMIT 1")->fetchone();
    if (!$row) {
        return jsonify(['ok' => false, 'msg' => 'تعذر العثور على طريقة آسياسيل.']);
    }
    try { $extras = json_loads(py_or($row['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $receiver = py_strip(py_or(dget($data, 'receiver_msisdn'), ''));
    try { $exchange_rate = py_float(py_or(dget($data, 'exchange_rate'), 0)); }
    catch (PyValueError | PyTypeError $e) { $exchange_rate = 0; }
    try { $max_transfer = py_int(py_or(dget($data, 'max_transfer'), 10000)); }
    catch (PyValueError | PyTypeError $e) { $max_transfer = 10000; }
    if ($max_transfer < 1000) {
        $max_transfer = 10000;
    }
    $extras['max_transfer'] = $max_transfer;
    $tiers_in = dget($data, 'tiers');
    if (is_array($tiers_in) && is_list_array($tiers_in) && $tiers_in) {
        $clean_tiers = [];
        foreach ($tiers_in as $t) {
            try {
                if (!is_array($t) || (is_list_array($t) && $t !== [])) throw new PyTypeError('AttributeError');
                $iqd = py_int(dget($t, 'iqd'));
                $bonus = py_float(py_or(dget($t, 'bonus'), 0));
            } catch (PyValueError | PyTypeError $e) {
                continue;
            }
            if (in_array($iqd, ASIACELL_TIER_AMOUNTS, true)) {
                $clean_tiers[] = ['iqd' => $iqd, 'bonus' => max(0, $bonus)];
            }
        }
        if ($clean_tiers) {
            $extras['tiers'] = $clean_tiers;
        }
    }
    $pm_image = _save_pm_image(dget($data, 'image_data', ''), $row->has('image') ? $row['image'] : '');
    $conn->execute('UPDATE payment_methods SET number=?, exchange_rate=?, note=?, image=?, active=?, method_extras=? WHERE id=?',
        [$receiver, $exchange_rate ? py_str($exchange_rate) : '', sql_text(dget($data, 'note', py_or($row['note'], ''))),
         $pm_image, py_truthy(dget($data, 'active')) ? 1 : 0, py_json_dumps($extras), $row['id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم حفظ إعدادات آسياسيل']);
}, ['POST']);

/* '/api/admin/payment-methods/telegram-stars'  (app.py:4879) */
route('/api/admin/payment-methods/telegram-stars', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $conn = get_db();
    $row = $conn->execute("SELECT * FROM payment_methods WHERE method_type='telegram_stars' LIMIT 1")->fetchone();
    if (!$row) {
        return jsonify(['ok' => false, 'msg' => 'تعذر العثور على طريقة نجوم تليجرام.']);
    }
    try { $extras = json_loads(py_or($row['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $bot_token = py_strip(py_or(dget($data, 'bot_token'), ''));
    $bot_username = ltrim(py_strip(py_or(dget($data, 'bot_username'), '')), '@');
    $admin_chat_id = py_strip(py_or(dget($data, 'admin_chat_id'), ''));
    try { $rate = py_float(py_or(dget($data, 'rate_usd_per_100'), 1.5)); }
    catch (PyValueError | PyTypeError $e) { $rate = 1.5; }
    if (py_truthy($bot_token)) {
        $extras['bot_token'] = $bot_token;
    }
    $extras['bot_username'] = $bot_username;
    $extras['admin_chat_id'] = $admin_chat_id;
    $extras['rate_usd_per_100'] = $rate;
    $conn->execute('UPDATE payment_methods SET active=?, method_extras=? WHERE id=?',
        [py_truthy(dget($data, 'active')) ? 1 : 0, py_json_dumps($extras), $row['id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم حفظ إعدادات نجوم تليجرام']);
}, ['POST']);

/* '/api/admin/payment-methods/telegram-stars/set-webhook'  (app.py:4911) */
route('/api/admin/payment-methods/telegram-stars/set-webhook', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $row = $conn->execute("SELECT method_extras FROM payment_methods WHERE method_type='telegram_stars' LIMIT 1")->fetchone();
    if (!$row) {
        return jsonify(['ok' => false, 'msg' => 'تعذر العثور على طريقة نجوم تليجرام.']);
    }
    try { $extras = json_loads(py_or($row['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $token = dget($extras, 'bot_token', '');
    if (!py_truthy($token)) {
        return jsonify(['ok' => false, 'msg' => 'أدخل Bot Token واحفظ أولاً']);
    }
    // تليجرام يشترط https دائماً — نبني الرابط من Host مباشرة
    $webhook_url = 'https://' . req_host() . '/api/telegram/stars-webhook';
    try {
        $r = requests_post("https://api.telegram.org/bot$token/setWebhook",
            ['json' => ['url' => $webhook_url, 'allowed_updates' => ['message', 'pre_checkout_query']], 'timeout' => 15]);
        $rj = $r->json();
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => 'تعذر الاتصال بتليجرام: ' . py_slice($e->getMessage(), null, 80)]);
    }
    if (py_truthy(dget($rj, 'ok'))) {
        return jsonify(['ok' => true, 'msg' => "تم تسجيل الـ Webhook بنجاح على: $webhook_url"]);
    }
    return jsonify(['ok' => false, 'msg' => 'فشل تليجرام: ' . py_str(dget($rj, 'description', 'خطأ غير معروف'))]);
}, ['POST']);

/* '/api/recharge/telegram-stars/link'  (app.py:4940) */
route('/api/recharge/telegram-stars/link', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    try { $stars = py_int(req_args('stars', 0)); }
    catch (PyValueError | PyTypeError $e) { $stars = 0; }
    if ($stars < 1) {
        return jsonify(['ok' => false, 'msg' => 'أدخل عدد نجوم صحيح']);
    }
    $conn = get_db();
    $row = $conn->execute("SELECT method_extras FROM payment_methods WHERE method_type='telegram_stars' AND active=1 LIMIT 1")->fetchone();
    if (!$row) {
        return jsonify(['ok' => false, 'msg' => 'طريقة الدفع غير مفعّلة حالياً']);
    }
    try { $extras = json_loads(py_or($row['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $bot_username = dget($extras, 'bot_username', '');
    if (!py_truthy($bot_username)) {
        return jsonify(['ok' => false, 'msg' => 'لم يتم إعداد بوت تليجرام بعد. تواصل مع الإدارة']);
    }
    $payload = 's' . $_SESSION['user_id'] . '_' . $stars;
    return jsonify(['ok' => true, 'url' => "https://t.me/$bot_username?start=$payload", 'rate' => dget($extras, 'rate_usd_per_100', 1.5)]);
});

/* '/api/telegram/stars-webhook'  (app.py:4965) — عام بدون تسجيل دخول */
route('/api/telegram/stars-webhook', function () {
    $conn = get_db();
    $row = $conn->execute("SELECT method_extras FROM payment_methods WHERE method_type='telegram_stars' LIMIT 1")->fetchone();
    if (!$row) return jsonify(['ok' => true]);
    try { $extras = json_loads(py_or($row['method_extras'], '{}')); if (!is_array($extras)) $extras = []; }
    catch (Throwable $e) { $extras = []; }
    $token = dget($extras, 'bot_token', '');
    if (!py_truthy($token)) return jsonify(['ok' => true]);
    try { $rate = py_float(py_or(dget($extras, 'rate_usd_per_100', 1.5), 1.5)); }
    catch (PyValueError | PyTypeError $e) { $rate = 1.5; }
    $admin_chat_id = dget($extras, 'admin_chat_id', '');

    $update = py_or(req_json(false, true), []);
    if (!is_array($update) || (is_list_array($update) && $update !== [])) throw new PyTypeError("object has no attribute 'get'");

    $tg_call = function ($method, $payload) use ($token) {
        try { requests_post("https://api.telegram.org/bot$token/$method", ['json' => $payload, 'timeout' => 10]); }
        catch (Throwable $e) {}
    };

    $pcq = dget($update, 'pre_checkout_query');
    if (py_truthy($pcq)) {
        $tg_call('answerPreCheckoutQuery', ['pre_checkout_query_id' => $pcq['id'], 'ok' => true]);
        return jsonify(['ok' => true]);
    }

    $msg = dget($update, 'message');
    if (!py_truthy($msg)) return jsonify(['ok' => true]);

    $chat_id = dget(py_or(dget($msg, 'chat'), []), 'id');

    $sp = dget($msg, 'successful_payment');
    if (py_truthy($sp)) {
        $charge_id = dget($sp, 'telegram_payment_charge_id', '');
        $payload = dget($sp, 'invoice_payload', '');
        try { $stars = py_int(dget($sp, 'total_amount', 0)); }
        catch (PyValueError | PyTypeError $e) { $stars = 0; }
        $mm = [];
        $okm = preg_match('/^s(\d+)_(\d+)$/D', (string)py_or($payload, ''), $mm);
        if (!$okm || !py_truthy($charge_id)) return jsonify(['ok' => true]);
        $uid = (int)$mm[1];
        $credited = py_round(($stars / 100.0) * $rate, 4);
        $ext_ref = 'tgstars_' . $charge_id;
        $conn = get_db();
        $urow = $conn->execute('SELECT id, name FROM users WHERE id=?', [$uid])->fetchone();
        if (!$urow) return jsonify(['ok' => true]);
        try {
            $conn->execute('INSERT INTO recharge_requests(user_id, amount, method, status, ext_ref, auto) VALUES(?,?,?,?,?,1)',
                [$uid, $credited, 'Telegram Stars', 'approved', $ext_ref]);
            $conn->execute('UPDATE users SET balance=balance+?, telegram_chat_id=? WHERE id=?', [$credited, py_str($chat_id), $uid]);
            $conn->commit();
        } catch (IntegrityError $e) {
            $conn->rollback();
            return jsonify(['ok' => true]);
        }
        push_user_notif($uid, 'recharge', 'تم شحن $' . py_fmt($credited),
            "تم الدفع بـ $stars نجمة تليجرام وإضافة الرصيد تلقائياً", 'check', 'recharge:');
        if (py_truthy($admin_chat_id)) {
            $tg_call('sendMessage', ['chat_id' => $admin_chat_id,
                'text' => "💰 دفع جديد بنجوم تليجرام\nالمستخدم: " . $urow['name'] . " (#$uid)\nالنجوم: $stars\nالمبلغ: $" . py_fmt($credited)]);
        }
        $tg_call('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ تم شحن رصيدك بمقدار $' . py_fmt($credited) . ' بنجاح! يمكنك الآن العودة للموقع.']);
        return jsonify(['ok' => true]);
    }

    $text = py_strip(py_or(dget($msg, 'text'), ''));
    if (strncmp($text, '/start', 6) === 0) {
        $parts = explode(' ', $text, 2);
        $payload = count($parts) > 1 ? py_strip($parts[1]) : '';
        $mm = [];
        if (!preg_match('/^s(\d+)_(\d+)$/D', $payload, $mm)) {
            $tg_call('sendMessage', ['chat_id' => $chat_id, 'text' => 'أهلاً! افتح هذا الرابط من داخل الموقع لشحن رصيدك بالنجوم.']);
            return jsonify(['ok' => true]);
        }
        $uid = (int)$mm[1]; $stars = (int)$mm[2];
        $conn = get_db();
        $urow = $conn->execute('SELECT id FROM users WHERE id=?', [$uid])->fetchone();
        if ($urow) {
            $conn->execute('UPDATE users SET telegram_chat_id=? WHERE id=?', [py_str($chat_id), $uid]);
            $conn->commit();
        }
        if (!$urow) {
            $tg_call('sendMessage', ['chat_id' => $chat_id, 'text' => 'تعذر التعرف على حسابك، حاول الدخول من الموقع مرة أخرى.']);
            return jsonify(['ok' => true]);
        }
        $credited = py_round(($stars / 100.0) * $rate, 4);
        $tg_call('sendInvoice', [
            'chat_id' => $chat_id,
            'title' => "شحن رصيد — $stars نجمة",
            'description' => 'شحن رصيدك في الموقع بمقدار $' . py_fmt($credited) . " مقابل $stars نجمة تليجرام",
            'payload' => $payload,
            'currency' => 'XTR',
            'prices' => [['label' => "$stars نجمة", 'amount' => $stars]],
        ]);
        return jsonify(['ok' => true]);
    }

    return jsonify(['ok' => true]);
}, ['POST']);

/* '/api/admin/asiacell-debug'  (app.py:5073) */
route('/api/admin/asiacell-debug', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $raw = get_setting('asiacell_last_debug', '');
    try { $debug = py_truthy($raw) ? json_loads($raw) : null; }
    catch (Throwable $e) { $debug = null; }
    return jsonify(['ok' => true, 'debug' => $debug]);
});

/* '/api/pm-image/<fname>'  (app.py:5084) */
route('/api/pm-image/<fname>', function ($fname) {
    $fname = str_replace('/', '', str_replace('..', '', $fname));
    $fpath = PM_IMG_DIR . '/' . $fname;
    if (!is_file($fpath)) {
        try {
            $conn = get_db();
            $conn->execute('UPDATE payment_methods SET image=? WHERE image=?', ['', $fname]);
            $conn->commit();
        } catch (Throwable $e) {}
        return make_response('', 404);
    }
    $ext = strpos($fname, '.') !== false ? strtolower(substr($fname, strrpos($fname, '.') + 1)) : 'png';
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'image/png';
    return send_file($fpath, $mime, false, null, 86400);
});
