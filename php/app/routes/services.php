<?php
/**
 * الكتالوج وإدارة الخدمات (الفلاتر/الاستيراد/الإخفاء/المزامنة) — منقولة من app.py:3022-3475 و 528-534.
 */

route('/api/svc-version', function () {
    return jsonify(['v' => get_setting('svc_version', '0')]);
});

route('/api/services', function () {
    [$platforms, $total] = _build_services_catalog();
    $resp = jsonify(['ok' => true, 'platforms' => jobj($platforms), 'total' => $total, 'v' => get_setting('svc_version', '0')]);
    $resp->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
    $resp->headers['Pragma'] = 'no-cache';
    $resp->headers['Expires'] = '0';
    return $resp;
});

/** جلب خدمات كل المزودين الفعّالين للوحة الإدارة (مشترك بين filters/platforms و svc-mgmt/data) */
function _admin_collect_services(bool $withBalance): array {
    $all_provs = get_all_providers();
    $provider_list = []; $all_svcs = [];
    foreach ($all_provs as $prov) {
        if (!py_truthy($prov['active']) || !py_truthy($prov['api_url']) || !py_truthy($prov['api_key'])) {
            $row = ['id' => $prov['id'], 'name' => $prov['name'], 'active' => false, 'count' => 0];
            if ($withBalance) $row['balance'] = $prov['balance'] ?? 0;
            $provider_list[] = $row;
            continue;
        }
        try {
            if (py_truthy($prov['is_default'])) {
                $svcs = get_smm_services();
            } else {
                $r = requests_post($prov['api_url'], ['data' => ['key' => $prov['api_key'], 'action' => 'services'], 'timeout' => 15]);
                $rj = $r->json();
                $svcs = (is_array($rj) && is_list_array($rj)) ? $rj : [];
            }
            $row = ['id' => $prov['id'], 'name' => $prov['name'], 'active' => true, 'is_default' => (bool)$prov['is_default'], 'count' => count($svcs)];
            if ($withBalance) $row['balance'] = $prov['balance'] ?? 0;
            $provider_list[] = $row;
            foreach ($svcs as $s) {
                $s['_prov_id'] = $prov['id'];
                $s['_prov_name'] = $prov['name'];
                $all_svcs[] = $s;
            }
        } catch (Throwable $e) {
            $row = ['id' => $prov['id'], 'name' => $prov['name'], 'active' => true, 'count' => 0, 'error' => true];
            if ($withBalance) $row['balance'] = $prov['balance'] ?? 0;
            // بايثون: ترتيب المفاتيح غير مهم (jsonify يرتّبها)
            $provider_list[] = $row;
        }
    }
    return [$provider_list, $all_svcs];
}

/* '/api/admin/filters/platforms'  (app.py:3112) */
route('/api/admin/filters/platforms', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    [$provider_list, $all_svcs] = _admin_collect_services(true);
    $platforms = [];
    foreach ($all_svcs as $s) {
        $cat = dget($s, 'category', '');
        $name = dget($s, 'name', '');
        $sid = py_str(dget($s, 'service', ''));
        $rate = dget($s, 'rate', '0');
        $prov_id = dget($s, '_prov_id', 0);
        $prov_name = dget($s, '_prov_name', '');
        $platform = _detect_platform($cat, $name);
        $ck = py_str($cat);
        if (!isset($platforms[$platform])) $platforms[$platform] = ['categories' => []];
        if (!isset($platforms[$platform]['categories'][$ck])) $platforms[$platform]['categories'][$ck] = [];
        $platforms[$platform]['categories'][$ck][] = ['id' => $sid, 'name' => $name, 'rate' => $rate, 'prov_id' => $prov_id, 'prov_name' => $prov_name];
    }
    $conn = get_db();
    $frows = $conn->execute('SELECT * FROM service_filters')->fetchall();
    $filters = [];
    foreach ($frows as $f) {
        $k = $f['filter_type'] . ':' . py_str($f['filter_key']) . ':' . $f['platform'];
        $filters[$k] = ['hidden' => (bool)$f['hidden'], 'profit_pct' => $f['profit_pct'], 'custom_name' => $f->has('custom_name') ? $f['custom_name'] : ''];
    }
    return jsonify(['ok' => true, 'platforms' => jobj($platforms), 'filters' => jobj($filters), 'total' => count($all_svcs), 'provider_list' => $provider_list]);
});

/* '/api/admin/filters/save'  (app.py:3159) */
route('/api/admin/filters/save', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $items = dget($data, 'items', []);
    $conn = get_db();
    foreach ((array)$items as $item) {
        $ft = dget($item, 'type', '');
        $fk = py_str(dget($item, 'key', ''));
        $fp = dget($item, 'platform', '');
        $hidden = py_truthy(dget($item, 'hidden')) ? 1 : 0;
        $profit = dget($item, 'profit_pct');
        $cname = dget($item, 'custom_name', '');
        if ($profit === '' || $profit === null) $profit = null;
        else $profit = py_float($profit);
        $conn->execute('INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,custom_name)
            VALUES(?,?,?,?,?,?) ON CONFLICT(filter_type,filter_key,platform)
            DO UPDATE SET hidden=excluded.hidden, profit_pct=excluded.profit_pct, custom_name=excluded.custom_name',
            [sql_str($ft), $fk, sql_str($fp), $hidden, $profit, sql_str(py_or($cname, ''))]);
    }
    $conn->commit();
    _bump_svc_version();
    return jsonify(['ok' => true, 'msg' => 'تم حفظ الإعدادات']);
}, ['POST']);

/* '/api/admin/svc-mgmt/data'  (app.py:3186) */
route('/api/admin/svc-mgmt/data', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    [$provider_list, $all_svcs] = _admin_collect_services(false);
    $cats = [];
    foreach ($all_svcs as $s) {
        $cat = dget($s, 'category', '');
        $name = dget($s, 'name', '');
        $sid = py_str(dget($s, 'service', ''));
        $rate = dget($s, 'rate', '0');
        $min_q = dget($s, 'min', '0');
        $max_q = dget($s, 'max', '0');
        $prov_id = dget($s, '_prov_id', 0);
        $prov_name = dget($s, '_prov_name', '');
        $platform = _detect_platform($cat, $name);
        $key = py_str($prov_id) . '|||' . py_str($cat);
        if (!isset($cats[$key])) {
            $cats[$key] = ['cat' => $cat, 'platform' => $platform, 'prov_id' => $prov_id, 'prov_name' => $prov_name, 'services' => []];
        }
        $cats[$key]['services'][] = [
            'id' => $sid, 'name' => $name, 'rate' => $rate, 'min' => $min_q, 'max' => $max_q,
            'type' => py_str(py_or(dget($s, 'type', ''), '')),
            'refill' => py_str(py_or(dget($s, 'refill', ''), '')),
        ];
    }
    $conn = get_db();
    $frows = $conn->execute('SELECT * FROM service_filters')->fetchall();
    $filters = [];
    foreach ($frows as $f) {
        $k = $f['filter_type'] . ':' . py_str($f['filter_key']) . ':' . $f['platform'];
        $filters[$k] = ['hidden' => (bool)$f['hidden'], 'profit_pct' => $f['profit_pct']];
    }
    return jsonify(['ok' => true, 'categories' => array_values($cats), 'filters' => jobj($filters), 'providers' => $provider_list, 'total' => count($all_svcs)]);
});

/* '/api/admin/svc-mgmt/import'  (app.py:3238) */
route('/api/admin/svc-mgmt/import', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $items = (array)dget($data, 'items', []);
    $profit = py_float(py_or(dget($data, 'profit_pct', 0), 0));
    $conn = get_db();
    $count = 0; $cats_added = []; $provs_touched = [];
    foreach ($items as $item) {
        $sid = py_strip(py_str(dget($item, 'id', '')));
        $platform = py_strip(py_or(dget($item, 'platform', ''), ''));
        $cat = py_strip(py_or(dget($item, 'category', ''), ''));
        $pid = py_int(py_or(dget($item, 'prov_id', 0), 0));
        if (!py_truthy($sid)) continue;
        if ($pid) $provs_touched[$pid] = true;
        $conn->execute("DELETE FROM service_filters WHERE filter_type='service' AND filter_key=? AND platform!=? AND prov_id=?", [$sid, $platform, $pid]);
        $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,custom_category,prov_id)
            VALUES('service',?,?,0,?,?,?) ON CONFLICT(filter_type,filter_key,platform)
            DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct, custom_category=excluded.custom_category, prov_id=excluded.prov_id",
            [$sid, $platform, $profit, $cat, $pid]);
        $count += 1;
        if (py_truthy($cat) && !isset($cats_added[$cat . '|||' . $platform])) {
            $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,prov_id)
                VALUES('category',?,?,0,?,?) ON CONFLICT(filter_type,filter_key,platform)
                DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct, prov_id=excluded.prov_id",
                [$cat, $platform, $profit, $pid]);
            $cats_added[$cat . '|||' . $platform] = true;
        }
    }
    foreach (array_keys($provs_touched) as $pid) {
        try { $conn->execute('UPDATE providers SET show_in_store=1 WHERE id=? AND show_in_store=0', [$pid]); } catch (Throwable $e) {}
    }
    $conn->commit();
    _bump_svc_version();
    return jsonify(['ok' => true, 'msg' => "تم استيراد $count خدمة بنجاح", 'count' => $count]);
}, ['POST']);

/* '/api/admin/svc-mgmt/existing-platforms'  (app.py:3282) */
route('/api/admin/svc-mgmt/existing-platforms', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $rows = $conn->execute("SELECT filter_type, filter_key, platform, custom_category FROM service_filters WHERE hidden=0 AND platform != ''")->fetchall();
    $platforms = [];
    foreach ($rows as $r) {
        $p = py_strip(py_or($r['platform'], ''));
        if (!py_truthy($p)) continue;
        if (!isset($platforms[$p])) $platforms[$p] = [];
        $ft = $r['filter_type'];
        if ($ft === 'category') {
            $c = py_strip(py_or($r['filter_key'], ''));
            if (py_truthy($c)) $platforms[$p][$c] = true;
        } elseif ($ft === 'service') {
            try { $ccat = py_strip(py_or($r['custom_category'], '')); } catch (Throwable $e) { $ccat = ''; }
            if (py_truthy($ccat)) $platforms[$p][$ccat] = true;
        }
    }
    $result = [];
    foreach ($platforms as $p => $cats) {
        $cl = array_map('strval', array_keys($cats));
        sort($cl, SORT_STRING);
        $result[] = ['name' => (string)$p, 'categories' => $cl];
    }
    usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));
    return jsonify(['ok' => true, 'platforms' => $result]);
});

/* '/api/admin/svc-mgmt/delete'  (app.py:3319) */
route('/api/admin/svc-mgmt/delete', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $dtype = dget($data, 'type', '');
    $items = (array)dget($data, 'items', []);
    $conn = get_db();
    $count = 0;
    foreach ($items as $item) {
        $key = py_str(dget($item, 'key', ''));
        $platform = dget($item, 'platform', '');
        if (!py_truthy($key)) continue;
        if ($dtype === 'service') {
            $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden)
                VALUES('service',?,?,1) ON CONFLICT(filter_type,filter_key,platform)
                DO UPDATE SET hidden=1", [$key, sql_str($platform)]);
            $count += 1;
        } elseif ($dtype === 'category') {
            $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden)
                VALUES('category',?,?,1) ON CONFLICT(filter_type,filter_key,platform)
                DO UPDATE SET hidden=1", [$key, sql_str($platform)]);
            $count += 1;
        }
    }
    $conn->commit();
    _bump_svc_version();
    return jsonify(['ok' => true, 'msg' => "تم حذف $count عنصر", 'count' => $count]);
}, ['POST']);

/* '/api/admin/svc-mgmt/delete-platform'  (app.py:3348) */
route('/api/admin/svc-mgmt/delete-platform', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $platform = py_strip(py_or(dget($data, 'platform', ''), ''));
    if (!py_truthy($platform)) {
        return jsonify(['ok' => false, 'msg' => 'اسم المنصة مطلوب']);
    }
    $conn = get_db();
    $cur = $conn->execute('UPDATE service_filters SET hidden=1 WHERE platform=? AND hidden=0', [$platform]);
    $count = $cur->rowcount;
    $conn->commit();
    _bump_svc_version();
    return jsonify(['ok' => true, 'msg' => "تم إخفاء منصة $platform ($count عنصر)", 'count' => $count]);
}, ['POST']);

/* '/api/admin/svc-mgmt/restore'  (app.py:3364) */
route('/api/admin/svc-mgmt/restore', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $rtype = dget($data, 'type', '');
    $items = (array)dget($data, 'items', []);
    $conn = get_db();
    $count = 0;
    if ($rtype === 'platform') {
        foreach ($items as $item) {
            $platform = py_strip(py_or(dget($item, 'platform', ''), ''));
            if (py_truthy($platform)) {
                $cur = $conn->execute('UPDATE service_filters SET hidden=0 WHERE platform=? AND hidden=1', [$platform]);
                $count += $cur->rowcount;
            }
        }
    } else {
        foreach ($items as $item) {
            $key = py_str(dget($item, 'key', ''));
            $platform = dget($item, 'platform', '');
            if (!py_truthy($key)) continue;
            if ($rtype === 'service') {
                $conn->execute("UPDATE service_filters SET hidden=0 WHERE filter_type='service' AND filter_key=? AND platform=?", [$key, sql_str($platform)]);
                $count += 1;
            } elseif ($rtype === 'category') {
                $conn->execute("UPDATE service_filters SET hidden=0 WHERE filter_type='category' AND filter_key=? AND platform=?", [$key, sql_str($platform)]);
                $count += 1;
            }
        }
    }
    $conn->commit();
    _bump_svc_version();
    return jsonify(['ok' => true, 'msg' => "تم استعادة $count عنصر", 'count' => $count]);
}, ['POST']);

/* '/api/admin/svc-mgmt/hidden'  (app.py:3398) */
route('/api/admin/svc-mgmt/hidden', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $frows = $conn->execute('SELECT * FROM service_filters WHERE hidden=1')->fetchall();
    $services = []; $categories = []; $platforms = [];
    foreach ($frows as $f) {
        $ft = $f['filter_type'];
        $fk = $f['filter_key'];
        $fp = py_or($f['platform'], '');
        $cname = '';
        try { $cname = py_or($f['custom_name'], ''); } catch (Throwable $e) {}
        if ($ft === 'service') $services[] = ['key' => $fk, 'platform' => $fp, 'custom_name' => $cname];
        elseif ($ft === 'category') $categories[] = ['key' => $fk, 'platform' => $fp, 'custom_name' => $cname];
        if (py_truthy($fp)) {
            if (!isset($platforms[$fp])) $platforms[$fp] = ['svc' => 0, 'cat' => 0];
            if ($ft === 'service') $platforms[$fp]['svc'] += 1;
            elseif ($ft === 'category') $platforms[$fp]['cat'] += 1;
        }
    }
    $plat_list = [];
    foreach ($platforms as $k => $v) $plat_list[] = ['name' => (string)$k, 'svc_count' => $v['svc'], 'cat_count' => $v['cat']];
    usort($plat_list, fn($a, $b) => strcmp($a['name'], $b['name']));
    return jsonify(['ok' => true, 'services' => $services, 'categories' => $categories, 'platforms' => $plat_list,
        'total' => count($services) + count($categories)]);
});

/* '/api/admin/svc-mgmt/sync'  (app.py:3432) */
route('/api/admin/svc-mgmt/sync', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $force = py_truthy(dget($data, 'force', false));
    $result = _do_sync_services($force);
    return jsonify(['ok' => true,
        'msg' => 'تمت المزامنة — حُذفت ' . py_str(dget($result, 'removed_svcs', 0)) . ' خدمة و ' . py_str(dget($result, 'removed_cats', 0)) . ' فئة',
        'removed_svcs' => dget($result, 'removed_svcs', 0),
        'removed_cats' => dget($result, 'removed_cats', 0),
        'errors' => dget($result, 'errors', []),
        'total_checked' => dget($result, 'total_checked', 0),
        'live_services' => dget($result, 'live_services', 0),
        'blocked' => dget($result, 'blocked', false),
        'skipped' => dget($result, 'skipped', false),
        'would_delete' => dget($result, 'would_delete', 0)]);
}, ['POST']);

/* '/api/admin/svc-mgmt/sync-status'  (app.py:3450) */
route('/api/admin/svc-mgmt/sync-status', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    return jsonify(array_merge(['ok' => true], _last_sync_result()));
});

/* '/api/admin/svc-mgmt/reset-all'  (app.py:3456) */
route('/api/admin/svc-mgmt/reset-all', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    if (!py_truthy(dget($data, 'confirm'))) {
        return jsonify(['ok' => false, 'msg' => 'يجب تأكيد العملية'], 400);
    }
    $include_hidden = py_truthy(dget($data, 'include_hidden', false));
    $conn = get_db();
    if ($include_hidden) {
        $cur = $conn->execute("DELETE FROM service_filters WHERE filter_type IN ('service','category','platform')");
    } else {
        $cur = $conn->execute("DELETE FROM service_filters WHERE filter_type IN ('service','category') AND hidden=0");
    }
    $removed = $cur->rowcount;
    $conn->commit();
    _bump_svc_version();
    return jsonify(['ok' => true, 'removed' => $removed, 'msg' => "تم حذف $removed استيراد"]);
}, ['POST']);

/* '/api/admin/auto-import/run-now'  (app.py:528) */
route('/api/admin/auto-import/run-now', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $result = _do_auto_import_new_services(true);
    return jsonify(['ok' => true, 'imported' => dget($result, 'imported', 0), 'errors' => dget($result, 'errors', []),
        'live_services' => dget($result, 'live_services')]);
}, ['POST']);
