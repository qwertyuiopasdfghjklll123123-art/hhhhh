<?php
/**
 * إدارة المزوّدين (providers): قائمة/إضافة/تعديل/حذف/اختبار/جلب الخدمات — منقولة من app.py:7110-7230.
 */

route('/api/admin/providers', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $provs = get_all_providers();
    foreach ($provs as $i => $p) {
        if (py_truthy($p['active']) && py_truthy($p['api_url']) && py_truthy($p['api_key'])) {
            try {
                $r = requests_post($p['api_url'], ['data' => ['key' => $p['api_key'], 'action' => 'balance'], 'timeout' => 8]);
                $bd = py_dict($r->json());
                $provs[$i]['balance'] = py_float(dget($bd, 'balance', 0));
                $conn = get_db();
                $conn->execute('UPDATE providers SET balance=?, balance_updated=? WHERE id=?', [$provs[$i]['balance'], microtime(true), $p['id']]);
                $conn->commit();
            } catch (Throwable $e) {}
        }
    }
    return jsonify(['ok' => true, 'providers' => $provs]);
});

route('/api/admin/providers/add', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_force();
    $name = py_strip_s(py_or(dget($data, 'name'), ''));
    $api_url = py_strip_s(py_or(dget($data, 'api_url'), ''));
    $api_key = py_strip_s(py_or(dget($data, 'api_key'), ''));
    if (!py_truthy($name)) return jsonify(['ok' => false, 'msg' => 'أدخل اسم المزود']);
    $conn = get_db();
    $conn->execute('INSERT INTO providers(name,api_url,api_key,active,is_default) VALUES(?,?,?,1,0)', [$name, $api_url, $api_key]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم إضافة المزود']);
}, ['POST']);

route('/api/admin/providers/update', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_force();
    $pid = py_int(dget($data, 'id', 0));
    if (!$pid) return jsonify(['ok' => false, 'msg' => 'مزود غير صالح']);
    $conn = get_db();
    $sets = [];
    $vals = [];
    foreach (['name', 'api_url', 'api_key'] as $k) {
        if (array_key_exists($k, $data)) {
            $sets[] = "$k=?";
            $vals[] = sql_text($data[$k]);
        }
    }
    if (array_key_exists('active', $data)) {
        $sets[] = 'active=?';
        $vals[] = py_truthy($data['active']) ? 1 : 0;
    }
    if (array_key_exists('show_in_store', $data)) {
        $sets[] = 'show_in_store=?';
        $vals[] = py_truthy($data['show_in_store']) ? 1 : 0;
    }
    if (array_key_exists('is_default', $data) && py_truthy($data['is_default'])) {
        $conn->execute('UPDATE providers SET is_default=0');
        $sets[] = 'is_default=1';
        // المزود الرئيسي يعرض خدماته تلقائياً
        if (!array_key_exists('show_in_store', $data)) $sets[] = 'show_in_store=1';
    }
    if ($sets) {
        $vals[] = $pid;
        $conn->execute('UPDATE providers SET ' . implode(',', $sets) . ' WHERE id=?', $vals);
    }
    $conn->commit();
    if (array_key_exists('show_in_store', $data) || array_key_exists('active', $data)
        || (array_key_exists('is_default', $data) && py_truthy($data['is_default']))) {
        clear_services_cache(true, true);
        _bump_svc_version_only();
    }
    return jsonify(['ok' => true, 'msg' => 'تم التحديث']);
}, ['POST']);

route('/api/admin/providers/delete', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_force();
    $pid = py_int(dget($data, 'id', 0));
    $conn = get_db();
    $cnt = $conn->execute('SELECT COUNT(*) as c FROM providers')->fetchone()['c'];
    if ($cnt <= 1) return jsonify(['ok' => false, 'msg' => 'لا يمكن حذف المزود الوحيد']);
    $conn->execute('DELETE FROM providers WHERE id=?', [$pid]);
    try { $conn->execute('DELETE FROM service_filters WHERE prov_id=?', [$pid]); } catch (Throwable $e) {}
    $has_default = $conn->execute('SELECT COUNT(*) as c FROM providers WHERE is_default=1')->fetchone()['c'];
    if (!$has_default) {
        $first = $conn->execute('SELECT id FROM providers ORDER BY id ASC LIMIT 1')->fetchone();
        if ($first) $conn->execute('UPDATE providers SET is_default=1 WHERE id=?', [$first['id']]);
    }
    $conn->commit();
    clear_services_cache(true, true);
    _bump_svc_version_only();
    return jsonify(['ok' => true, 'msg' => 'تم الحذف']);
}, ['POST']);

route('/api/admin/providers/test', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_force();
    $api_url = py_strip_s(py_or(dget($data, 'api_url'), ''));
    $api_key = py_strip_s(py_or(dget($data, 'api_key'), ''));
    if (!py_truthy($api_url) || !py_truthy($api_key)) return jsonify(['ok' => false, 'msg' => 'أدخل الرابط والمفتاح']);
    try {
        $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'balance'], 'timeout' => 10]);
        $bd = py_dict($r->json());
        $bal = py_float(dget($bd, 'balance', 0));
        return jsonify(['ok' => true, 'balance' => $bal, 'currency' => dget($bd, 'currency', 'USD')]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => 'فشل الاتصال: ' . py_slice($e->getMessage(), null, 80)]);
    }
}, ['POST']);

route('/api/admin/providers/services', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_force();
    $pid = py_int(dget($data, 'id', 0));
    $p = get_provider($pid);
    try {
        $r = requests_post($p['api_url'], ['data' => ['key' => $p['api_key'], 'action' => 'services'], 'timeout' => 15]);
        $svcs = $r->json();
        if (is_array($svcs) && is_list_array($svcs)) return jsonify(['ok' => true, 'count' => count($svcs)]);
    } catch (Throwable $e) {}
    return jsonify(['ok' => false, 'msg' => 'فشل جلب الخدمات']);
}, ['POST']);
