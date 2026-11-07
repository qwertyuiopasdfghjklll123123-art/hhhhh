<?php
/**
 * «تحكم بالخدمات» (لوحة الأدمن): إضافة مجموعة خدمات من مزوّد، استيراد خدمة واحدة، وتعديل اسم/وصف/سعر أي خدمة.
 *
 *  - الخدمات المعروضة بالمتجر = صفوف service_filters(filter_type='service', hidden=0) (نفس نموذج «إدارة الخدمات» القديم).
 *  - تعديلات الأدمن تُحفظ بنفس الصف: custom_name (الاسم)، custom_desc (الوصف)، custom_rate (سعر ثابت لكل 1000)،
 *    profit_pct (نسبة الربح) ويقرؤها الكتالوج (lib/catalog.php) فتظهر فوراً للمستخدمين وللـ API.
 *  - يُكتشف دعم التعويض/الإلغاء تلقائياً من حقلي refill/cancel اللذين يرسلهما المزوّد لكل خدمة.
 */

const SVCCTL_MAX_IMPORT = 6000;

/** خدمات مزوّد واحد (للاستيراد): الافتراضي من كاشه، وغيره بكاش ملف قصير العمر */
function svcctl_provider_services(int $pid, bool $fresh = false): array {
    $conn = get_db();
    $prov = $conn->execute('SELECT * FROM providers WHERE id=?', [$pid])->fetchone();
    if (!$prov) throw new RuntimeException('المزوّد غير موجود');
    if (!py_truthy($prov['active']) || !py_truthy($prov['api_url']) || !py_truthy($prov['api_key'])) throw new RuntimeException('المزوّد غير مفعّل أو بلا رابط/مفتاح API');
    if (py_truthy($prov['is_default'])) return get_smm_services();
    $f = DATA_DIR . '/cache/prov_svc_' . $pid . '.json';
    $c = cache_file_read($f);
    if (!$fresh && $c && is_array($c['data']) && $c['data'] && microtime(true) - $c['time'] < 180) return $c['data'];
    $r = requests_post($prov['api_url'], ['data' => ['key' => $prov['api_key'], 'action' => 'services'], 'timeout' => 20]);
    $rj = $r->json();
    if (!(is_array($rj) && is_list_array($rj) && $rj)) {
        if ($c && is_array($c['data']) && $c['data']) return $c['data'];
        throw new RuntimeException('لم يرجع المزوّد قائمة خدمات صالحة');
    }
    cache_file_write($f, $rj, microtime(true));
    return $rj;
}

function svcctl_clip(string $s, int $max): string { return mb_substr(trim($s), 0, $max, 'UTF-8'); }

/* ------------------------------ نظرة عامة ------------------------------ */

route('/api/admin/svc-ctl/overview', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $provs = [];
    foreach (get_all_providers() as $p) {
        $n = (int)$conn->execute("SELECT COUNT(*) FROM service_filters WHERE filter_type='service' AND hidden=0 AND prov_id=?", [$p['id']])->fetchone()[0];
        $provs[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'active' => py_truthy($p['active']) && py_truthy($p['api_url']) && py_truthy($p['api_key']),
                    'is_default' => py_truthy($p['is_default']), 'imported' => $n];
    }
    $shown = (int)$conn->execute("SELECT COUNT(*) FROM service_filters WHERE filter_type='service' AND hidden=0")->fetchone()[0];
    $hidden = (int)$conn->execute("SELECT COUNT(*) FROM service_filters WHERE filter_type='service' AND hidden=1")->fetchone()[0];
    return jsonify(['ok' => true, 'providers' => $provs, 'shown' => $shown, 'hidden' => $hidden]);
});

/* ------------------------------ خدمات مزوّد (للاستيراد) ------------------------------ */

route('/api/admin/svc-ctl/provider', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $pid = (int)($_GET['prov_id'] ?? 0);
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8');
    $catF = (string)($_GET['category'] ?? '');
    $only = (string)($_GET['only'] ?? 'all');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(60, max(5, (int)($_GET['per'] ?? 30)));
    try { $svcs = svcctl_provider_services($pid, !empty($_GET['fresh'])); }
    catch (RuntimeException $e) { return jsonify(['ok' => false, 'msg' => $e->getMessage()]); }
    catch (Throwable $e) { return jsonify(['ok' => false, 'msg' => 'تعذّر جلب خدمات المزوّد']); }
    $conn = get_db();
    $imp = [];
    foreach ($conn->execute("SELECT filter_key FROM service_filters WHERE filter_type='service' AND hidden=0 AND prov_id=?", [$pid])->fetchall() as $r) $imp[py_str($r['filter_key'])] = true;
    $cats = []; $match = [];
    foreach ($svcs as $s) {
        if (!is_array($s)) continue;
        $sid = py_str(dget($s, 'service', '')); $cat = py_str(dget($s, 'category', '')); $name = py_str(dget($s, 'name', ''));
        $isImp = isset($imp[$sid]);
        if (!isset($cats[$cat])) $cats[$cat] = ['name' => $cat, 'count' => 0, 'imported' => 0, 'platform' => _detect_platform($cat, $name)];
        $cats[$cat]['count']++;
        if ($isImp) $cats[$cat]['imported']++;
        if ($catF !== '' && $cat !== $catF) continue;
        if ($only === 'new' && $isImp) continue;
        if ($only === 'imported' && !$isImp) continue;
        if ($q !== '' && mb_stripos($name . ' ' . $cat . ' ' . $sid, $q, 0, 'UTF-8') === false) continue;
        $match[] = $s;
    }
    $total = count($match);
    $items = [];
    foreach (array_slice($match, ($page - 1) * $per, $per) as $s) {
        $sid = py_str(dget($s, 'service', '')); $cat = py_str(dget($s, 'category', '')); $name = py_str(dget($s, 'name', ''));
        $items[] = ['id' => $sid, 'name' => $name, 'category' => $cat, 'platform' => _detect_platform($cat, $name),
                    'rate' => py_str(dget($s, 'rate', '0')), 'min' => dget($s, 'min', 0), 'max' => dget($s, 'max', 0), 'type' => py_str(dget($s, 'type', '')),
                    'desc' => svcctl_clip(py_str(py_or(dget($s, 'description', ''), py_or(dget($s, 'desc', ''), ''))), 600),
                    'refill' => svc_flag(dget($s, 'refill', false)), 'cancel' => svc_flag(dget($s, 'cancel', false)), 'imported' => isset($imp[$sid])];
    }
    $catList = array_values($cats);
    usort($catList, fn($a, $b) => strcmp($a['platform'] . $a['name'], $b['platform'] . $b['name']));
    return jsonify(['ok' => true, 'categories' => $catList, 'items' => $items, 'total' => $total, 'page' => $page, 'per' => $per, 'all_count' => count($svcs)]);
});

/* ------------------------------ الاستيراد (خدمة واحدة أو مجموعة) ------------------------------ */

route('/api/admin/svc-ctl/import', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $d = req_data();
    $pid = (int)dget($d, 'prov_id', 0);
    $ids = array_values(array_unique(array_map('strval', (array)dget($d, 'ids', []))));
    $cats = array_values(array_unique(array_map('strval', (array)dget($d, 'categories', []))));
    if (!$ids && !$cats) return jsonify(['ok' => false, 'msg' => 'اختر خدمة أو فئة للاستيراد']);
    $pRaw = dget($d, 'profit_pct', 0);
    if (!is_numeric($pRaw) || (float)$pRaw < 0 || (float)$pRaw > 10000) return jsonify(['ok' => false, 'msg' => 'نسبة الربح غير صالحة']);
    $profit = (float)$pRaw;
    $platOverride = svcctl_clip(py_str(dget($d, 'platform', '')), 64);
    $catOverride = svcctl_clip(py_str(dget($d, 'category', '')), 500);
    try { $svcs = svcctl_provider_services($pid); }
    catch (RuntimeException $e) { return jsonify(['ok' => false, 'msg' => $e->getMessage()]); }
    catch (Throwable $e) { return jsonify(['ok' => false, 'msg' => 'تعذّر جلب خدمات المزوّد']); }
    $idSet = array_flip($ids); $catSet = array_flip($cats);
    $pick = [];
    foreach ($svcs as $s) {
        if (!is_array($s)) continue;
        $sid = py_str(dget($s, 'service', ''));
        if (isset($idSet[$sid]) || isset($catSet[py_str(dget($s, 'category', ''))])) $pick[] = $s;
    }
    if (!$pick) return jsonify(['ok' => false, 'msg' => 'لم أجد هذه الخدمات عند المزوّد']);
    if (count($pick) > SVCCTL_MAX_IMPORT) return jsonify(['ok' => false, 'msg' => 'العدد كبير (' . count($pick) . ')؛ الحد الأقصى ' . SVCCTL_MAX_IMPORT . ' خدمة بالعملية الواحدة، قسّمها على فئات']);
    $single = count($pick) === 1 && $ids;
    $name = $single ? svcctl_clip(py_str(dget($d, 'name', '')), 300) : '';
    $desc = $single ? mb_substr(trim(py_str(dget($d, 'desc', ''))), 0, 4000, 'UTF-8') : '';
    $rateRaw = $single ? dget($d, 'rate', '') : '';
    $rate = null;
    if ($rateRaw !== '' && $rateRaw !== null) {
        if (!is_numeric($rateRaw) || (float)$rateRaw < 0 || (float)$rateRaw > 1000000) return jsonify(['ok' => false, 'msg' => 'السعر غير صالح']);
        $rate = (float)$rateRaw;
    }
    $conn = get_db();
    $count = 0; $conflicts = 0; $catsDone = [];
    try {
        foreach ($pick as $s) {
            $sid = svcctl_clip(py_str(dget($s, 'service', '')), 190);
            $origCat = svcctl_clip(py_str(dget($s, 'category', '')), 500);
            $platform = $platOverride !== '' ? $platOverride : _detect_platform($origCat, py_str(dget($s, 'name', '')));
            $cat = $catOverride !== '' ? $catOverride : $origCat;
            // الصف فريد بـ (النوع، المفتاح، المنصة): خدمة بنفس الرقم من مزوّد آخر على نفس المنصة لا تُستبدل بصمت
            $ex = $conn->execute("SELECT prov_id, hidden FROM service_filters WHERE filter_type='service' AND filter_key=? AND platform=?", [$sid, $platform])->fetchone();
            if ($ex && (int)$ex['prov_id'] !== $pid && !py_truthy($ex['hidden'])) { $conflicts++; continue; }
            $conn->execute("DELETE FROM service_filters WHERE filter_type='service' AND filter_key=? AND platform!=? AND prov_id=?", [$sid, $platform, $pid]);
            $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,custom_category,prov_id)
                VALUES('service',?,?,0,?,?,?) ON CONFLICT(filter_type,filter_key,platform)
                DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct, custom_category=excluded.custom_category, prov_id=excluded.prov_id",
                [$sid, $platform, $profit, $cat, $pid]);
            if ($single) {
                $conn->execute("UPDATE service_filters SET custom_name=?, custom_desc=?, custom_rate=? WHERE filter_type='service' AND filter_key=? AND platform=?",
                    [$name, $desc === '' ? null : $desc, $rate, $sid, $platform]);
            }
            $count++;
            if ($cat !== '' && !isset($catsDone[$cat . '|||' . $platform])) {
                $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,prov_id)
                    VALUES('category',?,?,0,?,?) ON CONFLICT(filter_type,filter_key,platform)
                    DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct, prov_id=excluded.prov_id", [$cat, $platform, $profit, $pid]);
                $catsDone[$cat . '|||' . $platform] = true;
            }
        }
        $conn->execute('UPDATE providers SET show_in_store=1 WHERE id=? AND show_in_store=0', [$pid]);
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
        log_app_error('EXCEPTION', 'svc-ctl import: ' . $e->getMessage(), $e->getFile(), $e->getLine());
        return jsonify(['ok' => false, 'msg' => 'فشل الاستيراد، لم يُحفظ شيء']);
    }
    _bump_svc_version();
    audit_log('svc_ctl_import', ['prov' => $pid, 'count' => $count, 'cats' => count($cats), 'ids' => count($ids), 'profit' => $profit]);
    $msg = $single ? 'تم استيراد الخدمة' : "تم استيراد $count خدمة" . (count($catsDone) ? ' من ' . count($catsDone) . ' فئة' : '');
    if ($conflicts) $msg .= " — تخطّيت $conflicts خدمة بنفس الرقم والمنصة من مزوّد آخر";
    return jsonify(['ok' => $count > 0 || !$conflicts, 'msg' => $msg, 'count' => $count, 'conflicts' => $conflicts]);
}, ['POST']);

/* ------------------------------ خدماتي (المعروضة بالمتجر) ------------------------------ */

route('/api/admin/svc-ctl/mine', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $q = mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8');
    $fProv = (int)($_GET['prov_id'] ?? 0);
    $fPlat = (string)($_GET['platform'] ?? '');
    $fCat = (string)($_GET['category'] ?? '');
    $flag = (string)($_GET['flag'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(60, max(5, (int)($_GET['per'] ?? 25)));
    $conn = get_db();
    $rows = $conn->execute("SELECT * FROM service_filters WHERE filter_type='service' AND hidden=0")->fetchall();
    $base = [];                                         // خدمات المزوّدين الحالية (من الكاش): sid|pid
    foreach (get_all_smm_services() as $s) { if (is_array($s)) $base[py_str(dget($s, 'service', '')) . '|' . py_str(dget($s, '_prov_id', 0))] = $s; }
    [$plats] = _build_services_catalog();               // ما يراه المستخدم فعلاً (الاسم/الوصف/السعر النهائي)
    $eff = [];
    foreach ($plats as $pn => $pl) foreach ($pl['categories'] as $cn => $items) foreach ($items as $it) $eff[$it['id'] . '|' . $pn] = $it + ['_cat' => $cn];
    $provNames = [];
    foreach (get_all_providers() as $p) $provNames[(int)$p['id']] = $p['name'];
    $out = []; $stats = ['total' => 0, 'refill' => 0, 'cancel' => 0, 'missing' => 0, 'edited' => 0];
    $platSet = []; $catSet = [];
    foreach ($rows as $r) {
        $sid = py_str($r['filter_key']); $pid = (int)$r['prov_id']; $plat = py_str($r['platform']);
        $b = $base[$sid . '|' . $pid] ?? null;
        if ($b === null && $pid === 0) foreach ($base as $k => $v) { if (strpos($k, $sid . '|') === 0) { $b = $v; break; } }
        $e = $eff[$sid . '|' . $plat] ?? null;
        $missing = $b === null;
        $name = $e['name'] ?? ($missing ? ('#' . $sid) : py_str(dget($b, 'name', '')));
        $cat = $e['_cat'] ?? py_str(py_or($r['custom_category'], $b ? dget($b, 'category', '') : ''));
        $cname = py_str($r['custom_name'] ?? ''); $cdesc = $r->has('custom_desc') ? py_str($r['custom_desc'] ?? '') : '';
        $crate = ($r->has('custom_rate') && $r['custom_rate'] !== null && $r['custom_rate'] !== '') ? (float)$r['custom_rate'] : null;
        $refill = $b ? svc_flag(dget($b, 'refill', false)) : false; $cancel = $b ? svc_flag(dget($b, 'cancel', false)) : false;
        $edited = $cname !== '' || $cdesc !== '' || $crate !== null;
        $platSet[$plat] = true; $catSet[$plat . '|||' . $cat] = ['platform' => $plat, 'name' => $cat];
        $stats['total']++; if ($refill) $stats['refill']++; if ($cancel) $stats['cancel']++; if ($missing) $stats['missing']++; if ($edited) $stats['edited']++;
        if ($fProv && $pid !== $fProv) continue;
        if ($fPlat !== '' && $plat !== $fPlat) continue;
        if ($fCat !== '' && $cat !== $fCat) continue;
        if ($flag === 'refill' && !$refill) continue;
        if ($flag === 'cancel' && !$cancel) continue;
        if ($flag === 'none' && ($refill || $cancel)) continue;
        if ($flag === 'missing' && !$missing) continue;
        if ($flag === 'edited' && !$edited) continue;
        if ($q !== '' && mb_stripos($name . ' ' . $cat . ' ' . $sid . ' ' . $cname, $q, 0, 'UTF-8') === false) continue;
        $provRate = $b ? py_float(py_or(dget($b, 'rate', 0), 0)) : null;
        $out[] = ['id' => $sid, 'prov_id' => $pid, 'prov_name' => $provNames[$pid] ?? '', 'platform' => $plat, 'category' => $cat,
                  'name' => $name, 'orig_name' => $b ? py_str(dget($b, 'name', '')) : '', 'custom_name' => $cname,
                  'desc' => $e['desc'] ?? '', 'orig_desc' => $b ? svcctl_clip(py_str(py_or(dget($b, 'description', ''), py_or(dget($b, 'desc', ''), ''))), 600) : '', 'custom_desc' => $cdesc,
                  'prov_rate' => $provRate, 'profit_pct' => $r['profit_pct'] === null ? null : (float)$r['profit_pct'], 'custom_rate' => $crate,
                  'price' => isset($e['rate']) ? py_float($e['rate']) : null,
                  'min' => $b ? dget($b, 'min', 0) : null, 'max' => $b ? dget($b, 'max', 0) : null,
                  'refill' => $refill, 'cancel' => $cancel, 'missing' => $missing, 'edited' => $edited];
    }
    usort($out, fn($a, $b) => strcmp($a['platform'] . "\0" . $a['category'] . "\0" . $a['name'], $b['platform'] . "\0" . $b['category'] . "\0" . $b['name']));
    $total = count($out);
    $plist = array_keys($platSet); sort($plist, SORT_STRING);
    $clist = array_values($catSet); usort($clist, fn($a, $b) => strcmp($a['platform'] . $a['name'], $b['platform'] . $b['name']));
    return jsonify(['ok' => true, 'items' => array_slice($out, ($page - 1) * $per, $per), 'total' => $total, 'page' => $page, 'per' => $per,
                    'stats' => $stats, 'platforms' => $plist, 'categories' => $clist]);
});

/* ------------------------------ تعديل خدمة (اسم/وصف/سعر/ربح/فئة) ------------------------------ */

function svcctl_find_row(string $sid, int $pid, string $platform = ''): ?object {
    $conn = get_db();
    if ($platform !== '') return $conn->execute("SELECT * FROM service_filters WHERE filter_type='service' AND filter_key=? AND prov_id=? AND platform=? AND hidden=0", [$sid, $pid, $platform])->fetchone() ?: null;
    return $conn->execute("SELECT * FROM service_filters WHERE filter_type='service' AND filter_key=? AND prov_id=? AND hidden=0 ORDER BY id LIMIT 1", [$sid, $pid])->fetchone() ?: null;
}

route('/api/admin/svc-ctl/update', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $d = req_data();
    $sid = py_str(dget($d, 'id', '')); $pid = (int)dget($d, 'prov_id', 0); $plat = py_str(dget($d, 'platform', ''));
    if ($sid === '') return jsonify(['ok' => false, 'msg' => 'رقم الخدمة مطلوب']);
    $row = svcctl_find_row($sid, $pid, $plat);
    if (!$row) return jsonify(['ok' => false, 'msg' => 'الخدمة غير موجودة بالمتجر (استوردها أولاً)'], 404);
    $set = []; $args = [];
    if (array_key_exists('name', $d)) { $set[] = 'custom_name=?'; $args[] = svcctl_clip(py_str($d['name']), 300); }
    if (array_key_exists('desc', $d)) { $v = mb_substr(trim(py_str($d['desc'])), 0, 4000, 'UTF-8'); $set[] = 'custom_desc=?'; $args[] = $v === '' ? null : $v; }
    if (array_key_exists('category', $d)) { $set[] = 'custom_category=?'; $args[] = svcctl_clip(py_str($d['category']), 500); }
    if (array_key_exists('price', $d)) {
        $v = $d['price'];
        if ($v === '' || $v === null) { $set[] = 'custom_rate=?'; $args[] = null; }
        else {
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000) return jsonify(['ok' => false, 'msg' => 'السعر غير صالح (رقم من 0 إلى 1,000,000)']);
            $set[] = 'custom_rate=?'; $args[] = round((float)$v, 6);
        }
    }
    if (array_key_exists('profit_pct', $d)) {
        $v = $d['profit_pct'];
        if ($v === '' || $v === null) { $set[] = 'profit_pct=?'; $args[] = null; }
        else {
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 10000) return jsonify(['ok' => false, 'msg' => 'نسبة الربح غير صالحة']);
            $set[] = 'profit_pct=?'; $args[] = (float)$v;
        }
    }
    if (!$set) return jsonify(['ok' => false, 'msg' => 'لا توجد تغييرات']);
    $args[] = $row['id'];
    $conn = get_db();
    $conn->execute('UPDATE service_filters SET ' . implode(',', $set) . ' WHERE id=?', $args);
    $conn->commit();
    _bump_svc_version();
    audit_log('svc_ctl_update', ['svc' => $sid, 'prov' => $pid, 'fields' => array_keys(array_intersect_key($d, array_flip(['name', 'desc', 'category', 'price', 'profit_pct'])))]);
    return jsonify(['ok' => true, 'msg' => 'تم حفظ التعديلات']);
}, ['POST']);

/* ------------------------------ إجراءات جماعية ------------------------------ */

route('/api/admin/svc-ctl/bulk', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $d = req_data();
    $action = (string)dget($d, 'action', '');
    $items = (array)dget($d, 'items', []);
    if (!in_array($action, ['profit', 'hide', 'reset_edits', 'reset_price'], true)) return jsonify(['ok' => false, 'msg' => 'إجراء غير معروف'], 400);
    if (!$items || count($items) > 2000) return jsonify(['ok' => false, 'msg' => 'اختر من 1 إلى 2000 خدمة']);
    $profit = null;
    if ($action === 'profit') {
        $v = dget($d, 'profit_pct', null);
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 10000) return jsonify(['ok' => false, 'msg' => 'نسبة الربح غير صالحة']);
        $profit = (float)$v;
    }
    $conn = get_db(); $n = 0;
    foreach ($items as $it) {
        $row = svcctl_find_row(py_str(dget($it, 'id', '')), (int)dget($it, 'prov_id', 0), py_str(dget($it, 'platform', '')));
        if (!$row) continue;
        if ($action === 'profit') $conn->execute('UPDATE service_filters SET profit_pct=?, custom_rate=NULL WHERE id=?', [$profit, $row['id']]);
        elseif ($action === 'hide') $conn->execute('UPDATE service_filters SET hidden=1 WHERE id=?', [$row['id']]);
        elseif ($action === 'reset_price') $conn->execute('UPDATE service_filters SET custom_rate=NULL WHERE id=?', [$row['id']]);
        else $conn->execute("UPDATE service_filters SET custom_name='', custom_desc=NULL, custom_rate=NULL WHERE id=?", [$row['id']]);
        $n++;
    }
    $conn->commit();
    _bump_svc_version();
    audit_log('svc_ctl_bulk', ['action' => $action, 'count' => $n]);
    return jsonify(['ok' => true, 'count' => $n, 'msg' => "تم تطبيق الإجراء على $n خدمة"]);
}, ['POST']);
