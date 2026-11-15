<?php
/**
 * طرق شحن محفظة واصل: يدوي (بما فيها "Super Key" كقالب يدوي) + Binance Pay + آسياسيل.
 * منقولة من مرجع fastcrand (core/routes/payment_methods.php) بنفس الآلية، مقصورة على
 * طرق الدفع الثلاث التي طلبها المستخدم (بدون نجوم تليجرام التي لا علاقة لها بالطلب).
 */

function _extras_dict($raw): array {
    $d = json_loads(py_or($raw, '{}'));
    if (!py_truthy($d)) return [];
    if (!is_array($d) || is_list_array($d)) throw new PyTypeError("object has no attribute 'get'");
    return $d;
}

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

function _save_pm_image($img_data, $fallback) {
    if ($img_data === '__remove__') return '';
    if (!(py_truthy($img_data) && is_string($img_data) && strpos($img_data, ',') !== false)) return $fallback;
    try { return _write_pm_image($img_data); }
    catch (Throwable $e) { return $fallback; }
}

/** يضمن وجود صف Binance وصف آسياسيل دائماً (الإدارة تعدّلهما، لا تنشئهما) - يُستدعى من init_db() */
function wsl_ensure_payment_method_singletons(): void {
    $conn = get_db();
    $has = fn($t) => (int)$conn->execute('SELECT COUNT(*) AS n FROM payment_methods WHERE method_type=?', [$t])->fetchone()['n'] > 0;
    if (!$has('binance')) {
        $conn->execute(
            "INSERT INTO payment_methods(name,icon,method_type,currency,active,sort_order,method_extras,created_at) VALUES('Binance Pay','fa-brands fa-bitcoin','binance','USD',0,1,'{}',?)",
            [now_str()]
        );
        $conn->commit();
    }
    if (!$has('asiacell')) {
        $conn->execute(
            "INSERT INTO payment_methods(name,icon,method_type,currency,active,sort_order,method_extras,created_at) VALUES('آسياسيل','fa-solid fa-sim-card','asiacell','IQD',0,2,?,?)",
            [py_json_dumps(['tiers' => _default_asiacell_tiers(), 'max_transfer' => 10000]), now_str()]
        );
        $conn->commit();
    }
}

function sql_text($v) {
    if (is_float($v)) return py_sqlite_float_text($v);
    if (is_bool($v)) return $v ? '1' : '0';
    return $v;
}

/** بيانات طريقة دفع للعرض العام (مستخدم يريد شحن محفظته) */
function _pm_public_row($r): array {
    $mtype = $r->has('method_type') ? $r['method_type'] : 'manual';
    $tiers = null;
    $qrCode = '';
    if ($mtype === 'asiacell') {
        try { $tiers = py_or(dget(_extras_dict($r['method_extras']), 'tiers'), _default_asiacell_tiers()); }
        catch (Throwable $e) { $tiers = _default_asiacell_tiers(); }
    }
    if ($mtype === 'binance') {
        try { $qrCode = dget(_extras_dict($r['method_extras']), 'qr_code', ''); }
        catch (Throwable $e) { $qrCode = ''; }
    }
    return [
        'id' => $r['id'], 'name' => $r['name'], 'icon' => $r['icon'],
        'number' => $r['number'], 'exchange_rate' => $r['exchange_rate'],
        'currency' => $r['currency'], 'note' => $r['note'], 'image' => $r->has('image') ? $r['image'] : '',
        'method_type' => $mtype, 'tiers' => $tiers, 'qr_code' => $qrCode,
    ];
}

/* طرق الدفع المفعّلة - لصفحة "شحن المحفظة" لأي مستخدم مسجّل دخول */
route('/payment-methods', function () {
    if (($r = require_login()) !== null) return $r;
    $rows = get_db()->execute('SELECT * FROM payment_methods WHERE active=1 ORDER BY sort_order ASC, id ASC')->fetchall();
    $s = get_site_settings();
    return jsonify(['ok' => true, 'methods' => array_map('_pm_public_row', $rows), 'usd_to_iqd_rate' => (int)py_or($s['usd_to_iqd_rate'], 1310)]);
}, ['GET']);

/* إدارة طرق الدفع - قائمة كاملة (مفعّلة وغير مفعّلة) */
route('/admin/payment-methods', function () {
    if (($r = require_admin()) !== null) return $r;
    $rows = get_db()->execute('SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC')->fetchall();
    $methods = [];
    foreach ($rows as $r) {
        $mtype = $r->has('method_type') ? $r['method_type'] : 'manual';
        try { $extras = _extras_dict($r['method_extras']); } catch (Throwable $e) { $extras = []; }
        $methods[] = [
            'id' => $r['id'], 'name' => $r['name'], 'icon' => $r['icon'],
            'number' => $r['number'], 'exchange_rate' => $r['exchange_rate'],
            'currency' => $r['currency'], 'note' => $r['note'], 'active' => py_truthy($r['active']),
            'image' => $r->has('image') ? $r['image'] : '', 'method_type' => $mtype,
            'sort_order' => (int)py_or($r['sort_order'], 0),
            'has_binance_keys' => $mtype === 'binance' ? py_truthy(dget($extras, 'api_key')) : false,
            'max_transfer' => $mtype === 'asiacell' ? dget($extras, 'max_transfer') : null,
            'tiers' => $mtype === 'asiacell' ? py_or(dget($extras, 'tiers'), _default_asiacell_tiers()) : null,
        ];
    }
    return jsonify(['ok' => true, 'methods' => $methods]);
}, ['GET']);

route('/admin/payment-methods/reorder', function () {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data();
    $order = py_or(dget($data, 'order'), []);
    $conn = get_db();
    foreach (array_values((array)$order) as $idx => $pmId) {
        try { $conn->execute('UPDATE payment_methods SET sort_order=? WHERE id=?', [$idx, (int)$pmId]); }
        catch (Throwable $e) {}
    }
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

/* إضافة طريقة يدوية جديدة (مثل "Super Key" أو أي بطاقة/محفظة محلية) */
route('/admin/payment-methods', function () {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data_strict();
    $name = py_strip(py_or(dget($data, 'name'), ''));
    if (!py_truthy($name)) return jsonify(['ok' => false, 'msg' => 'اسم الطريقة مطلوب']);
    $pmImage = '';
    $imgData = dget($data, 'image_data', '');
    if (py_truthy($imgData) && is_string($imgData) && strpos($imgData, ',') !== false) {
        try { $pmImage = _write_pm_image($imgData); } catch (Throwable $e) {}
    }
    $conn = get_db();
    $conn->execute(
        "INSERT INTO payment_methods(name,icon,number,exchange_rate,currency,note,image,active,method_type,created_at) VALUES(?,?,?,?,?,?,?,?,'manual',?)",
        [$name, sql_text(dget($data, 'icon', 'fa-solid fa-credit-card')), sql_text(dget($data, 'number', '')),
         sql_text(dget($data, 'exchange_rate', '')), sql_text(dget($data, 'currency', 'IQD')),
         sql_text(dget($data, 'note', '')), $pmImage, py_truthy(dget($data, 'active', true)) ? 1 : 0, now_str()]
    );
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم إضافة طريقة الدفع']);
}, ['POST']);

route('/admin/payment-methods/<int:pmId>', function ($pmId) {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data_strict();
    $conn = get_db();
    $typeRow = $conn->execute('SELECT method_type FROM payment_methods WHERE id=?', [$pmId])->fetchone();
    if ($typeRow && py_truthy($typeRow['method_type']) && $typeRow['method_type'] !== 'manual') {
        return jsonify(['ok' => false, 'msg' => 'لا يمكن تعديل هذه الطريقة من هنا. استخدم إعدادات Binance/آسياسيل.']);
    }
    $imgData = dget($data, 'image_data', '');
    if (py_truthy($imgData) && is_string($imgData) && strpos($imgData, ',') !== false) {
        try {
            $fname = _write_pm_image($imgData);
            $old = $conn->execute('SELECT image FROM payment_methods WHERE id=?', [$pmId])->fetchone();
            if ($old && py_truthy($old['image'])) _remove_pm_image_file($old['image']);
            $conn->execute('UPDATE payment_methods SET image=? WHERE id=?', [$fname, $pmId]);
        } catch (Throwable $e) {}
    } elseif ($imgData === '__remove__') {
        $old = $conn->execute('SELECT image FROM payment_methods WHERE id=?', [$pmId])->fetchone();
        if ($old && py_truthy($old['image'])) _remove_pm_image_file($old['image']);
        $conn->execute('UPDATE payment_methods SET image=? WHERE id=?', ['', $pmId]);
    }
    $conn->execute('UPDATE payment_methods SET name=?,icon=?,number=?,exchange_rate=?,currency=?,note=?,active=? WHERE id=?',
        [sql_text(dget($data, 'name', '')), sql_text(dget($data, 'icon', 'fa-solid fa-credit-card')), sql_text(dget($data, 'number', '')),
         sql_text(dget($data, 'exchange_rate', '')), sql_text(dget($data, 'currency', 'IQD')),
         sql_text(dget($data, 'note', '')), py_truthy(dget($data, 'active', true)) ? 1 : 0, $pmId]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم تحديث طريقة الدفع']);
}, ['PUT']);

route('/admin/payment-methods/<int:pmId>', function ($pmId) {
    if (($r = require_admin()) !== null) return $r;
    $conn = get_db();
    $typeRow = $conn->execute('SELECT method_type FROM payment_methods WHERE id=?', [$pmId])->fetchone();
    if ($typeRow && py_truthy($typeRow['method_type']) && $typeRow['method_type'] !== 'manual') {
        return jsonify(['ok' => false, 'msg' => 'لا يمكن حذف Binance Pay أو آسياسيل، فقط تعطيلهما من إعداداتهما.']);
    }
    $old = $conn->execute('SELECT image FROM payment_methods WHERE id=?', [$pmId])->fetchone();
    if ($old && py_truthy($old['image'])) _remove_pm_image_file($old['image']);
    $conn->execute('DELETE FROM payment_methods WHERE id=?', [$pmId]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم حذف طريقة الدفع']);
}, ['DELETE']);

/* إعداد Binance Pay: مفاتيح API + رقم المعرّف + صورة QR */
route('/admin/payment-methods/binance', function () {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data();
    $conn = get_db();
    $row = $conn->execute("SELECT * FROM payment_methods WHERE method_type='binance' LIMIT 1")->fetchone();
    if (!$row) return jsonify(['ok' => false, 'msg' => 'تعذر العثور على طريقة Binance Pay.']);
    try { $extras = _extras_dict($row['method_extras']); } catch (Throwable $e) { $extras = []; }
    $apiKey = py_strip(py_or(dget($data, 'api_key'), ''));
    $apiSecret = py_strip(py_or(dget($data, 'api_secret'), ''));
    $binanceId = py_strip(py_or(dget($data, 'binance_id'), ''));
    if (py_truthy($apiKey)) $extras['api_key'] = $apiKey;
    if (py_truthy($apiSecret)) $extras['api_secret'] = $apiSecret;
    $pmImage = _save_pm_image(dget($data, 'image_data', ''), $row->has('image') ? $row['image'] : '');
    $qrCode = _save_pm_image(dget($data, 'qr_data', ''), dget($extras, 'qr_code', ''));
    $extras['qr_code'] = $qrCode;
    $conn->execute('UPDATE payment_methods SET number=?, image=?, active=?, method_extras=? WHERE id=?',
        [$binanceId, $pmImage, py_truthy(dget($data, 'active')) ? 1 : 0, py_json_dumps($extras), $row['id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم حفظ إعدادات Binance Pay']);
}, ['POST']);

/* إعداد آسياسيل: رقم الاستقبال + نسبة الخصم + حد التحويل الواحد + الشرائح */
route('/admin/payment-methods/asiacell', function () {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data();
    $conn = get_db();
    $row = $conn->execute("SELECT * FROM payment_methods WHERE method_type='asiacell' LIMIT 1")->fetchone();
    if (!$row) return jsonify(['ok' => false, 'msg' => 'تعذر العثور على طريقة آسياسيل.']);
    try { $extras = _extras_dict($row['method_extras']); } catch (Throwable $e) { $extras = []; }
    $receiver = py_strip(py_or(dget($data, 'receiver_msisdn'), ''));
    try { $exchangeRate = py_float(py_or(dget($data, 'exchange_rate'), 1)); }
    catch (PyValueError | PyTypeError $e) { $exchangeRate = 1; }
    if ($exchangeRate <= 0) $exchangeRate = 1;
    try { $maxTransfer = py_int(py_or(dget($data, 'max_transfer'), 10000)); }
    catch (PyValueError | PyTypeError $e) { $maxTransfer = 10000; }
    if ($maxTransfer < 1000) $maxTransfer = 10000;
    $extras['max_transfer'] = $maxTransfer;
    $tiersIn = dget($data, 'tiers');
    if (is_array($tiersIn) && is_list_array($tiersIn) && $tiersIn) {
        $clean = [];
        foreach ($tiersIn as $t) {
            try {
                if (!is_array($t) || (is_list_array($t) && $t !== [])) throw new PyTypeError('AttributeError');
                $iqd = py_int(dget($t, 'iqd'));
                $bonus = py_float(py_or(dget($t, 'bonus'), 0));
            } catch (PyValueError | PyTypeError $e) { continue; }
            if (in_array($iqd, ASIACELL_TIER_AMOUNTS, true)) $clean[] = ['iqd' => $iqd, 'bonus' => max(0, $bonus)];
        }
        if ($clean) $extras['tiers'] = $clean;
    }
    $pmImage = _save_pm_image(dget($data, 'image_data', ''), $row->has('image') ? $row['image'] : '');
    $conn->execute('UPDATE payment_methods SET number=?, exchange_rate=?, note=?, image=?, active=?, method_extras=? WHERE id=?',
        [$receiver, py_str($exchangeRate), sql_text(dget($data, 'note', py_or($row['note'], ''))),
         $pmImage, py_truthy(dget($data, 'active')) ? 1 : 0, py_json_dumps($extras), $row['id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم حفظ إعدادات آسياسيل']);
}, ['POST']);

route('/admin/asiacell-debug', function () {
    if (($r = require_admin()) !== null) return $r;
    return jsonify(['ok' => true, 'debug' => get_asiacell_debug()]);
}, ['GET']);

/* صور طرق الدفع (شعارات مرفوعة من الأدمن + QR) */
route('/pm-image/<fname>', function ($fname) {
    $fname = str_replace('/', '', str_replace('..', '', $fname));
    $fpath = PM_IMG_DIR . '/' . $fname;
    if (!is_file($fpath)) return make_response('', 404);
    $ext = strpos($fname, '.') !== false ? strtolower(substr($fname, strrpos($fname, '.') + 1)) : 'png';
    $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'image/png';
    return send_file($fpath, $mime, false, null, 86400);
});
