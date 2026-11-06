<?php
/**
 * كتالوج الخدمات: كاش الخدمات (ملفات بدل ذاكرة العملية) + كشف المنصات + بناء الكتالوج
 * منقول من app.py:690-830 و 2902-3110 و 3477-3491.
 *
 * بايثون كان يحتفظ بالكاش في ذاكرة العملية؛ PHP لا ذاكرة بين الطلبات، فنستعمل ملفات JSON:
 *   DATA_DIR/fc_services_cache.json       (نفس ملف بايثون: خدمات المزوّد الافتراضي)
 *   DATA_DIR/cache/all_services.json      (كل المزوّدين المعروضين بالمتجر)
 */
require_once __DIR__ . '/platform_data.php';

/* ------------------------------ ملفات الكاش ------------------------------ */

function svc_cache_file(): string { return DATA_DIR . '/fc_services_cache.json'; }
function all_svc_cache_file(): string { return DATA_DIR . '/cache/all_services.json'; }

/** @return array|null  ['data' => ..., 'time' => float] أو null إن لم يوجد الملف */
function cache_file_read(string $f): ?array {
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') return null;
    $d = json_decode($raw, true);
    if (!is_array($d) || !array_key_exists('data', $d)) return null;
    return ['data' => $d['data'], 'time' => (float)($d['time'] ?? 0)];
}

function cache_file_write(string $f, $data, float $time): void {
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode(['data' => $data, 'time' => $time], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR)) !== false) {
        @rename($tmp, $f);
    } else {
        @unlink($tmp);
    }
}

/** قفل غير حاجب بملف (بديل threading.Lock.acquire(blocking=False)) — يُرجع المقبض أو null */
function file_lock_try(string $name) {
    $dir = DATA_DIR . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $fp = @fopen($dir . '/' . $name . '.lock', 'c');
    if (!$fp) return null;
    if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return null; }
    return $fp;
}
function file_lock_release($fp): void { if ($fp) { flock($fp, LOCK_UN); fclose($fp); } }

/* ------------------------------ خدمات المزوّد الافتراضي ------------------------------ */

function _svc_ids(array $list): array {
    $ids = [];
    foreach ($list as $s) $ids[py_str(dget($s, 'service', ''))] = true;
    ksort($ids, SORT_STRING);
    return $ids;
}

/** get_smm_services  (app.py:722) */
function get_smm_services(): array {
    $now = microtime(true);
    [$api_url, $api_key, $_pid] = get_provider_api();
    $c = cache_file_read(svc_cache_file());
    $cdata = ($c && is_array($c['data']) && $c['data']) ? $c['data'] : null;
    $ctime = $c ? $c['time'] : 0;
    if ($cdata && $now - $ctime < 60) return $cdata;
    if ($cdata) {
        defer_after_response(function () use ($api_url, $api_key) { _svc_bg_refresh($api_url, $api_key); });
        return $cdata;
    }
    try {
        $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'services'], 'timeout' => 15]);
        $data = $r->json();
        if (is_array($data) && is_list_array($data) && count($data) > 0) {
            cache_file_write(svc_cache_file(), $data, microtime(true));
            return $data;
        }
    } catch (Throwable $e) {}
    return [];
}

function _svc_bg_refresh(string $api_url, string $api_key): void {
    $lock = file_lock_try('svc_refresh');
    if (!$lock) return;
    try {
        $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'services'], 'timeout' => 15]);
        $data = $r->json();
        if (is_array($data) && is_list_array($data) && count($data) > 0) {
            $old = cache_file_read(svc_cache_file());
            $old_ids = $old && is_array($old['data']) ? _svc_ids($old['data']) : [];
            $new_ids = _svc_ids($data);
            cache_file_write(svc_cache_file(), $data, microtime(true));
            if ($old_ids !== $new_ids) _bump_svc_version_only();
        }
    } catch (Throwable $e) {
    } finally {
        file_lock_release($lock);
    }
}

/* ------------------------------ كل المزوّدين ------------------------------ */

/** _all_services_do_refresh  (app.py:764) */
function _all_services_do_refresh(): void {
    $now = microtime(true);
    $all_svcs = [];
    try {
        foreach (get_all_providers() as $prov) {
            if (!py_truthy($prov['active'] ?? null)) continue;
            if (!py_truthy($prov['show_in_store'] ?? null)) continue;
            if (!py_truthy($prov['api_url'] ?? null) || !py_truthy($prov['api_key'] ?? null)) continue;
            $svcs = [];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    if (py_truthy($prov['is_default'] ?? null)) {
                        $svcs = get_smm_services();
                    } else {
                        $r = requests_post($prov['api_url'], ['data' => ['key' => $prov['api_key'], 'action' => 'services'], 'timeout' => 15]);
                        $rj = $r->json();
                        $svcs = (is_array($rj) && is_list_array($rj)) ? $rj : [];
                    }
                    if ($svcs) break;
                } catch (Throwable $e) {}
                if ($attempt === 0) sleep(1);
            }
            try {
                foreach ($svcs as $i => $s) { if (is_array($s)) $svcs[$i]['_prov_id'] = $prov['id']; }
                foreach ($svcs as $_s) $all_svcs[] = $_s;
            } catch (Throwable $e) {}
        }
        $old = cache_file_read(all_svc_cache_file());
        $old_data = $old ? $old['data'] : null;
        $old_count = is_array($old_data) ? count($old_data) : 0;
        $new_count = count($all_svcs);
        if ($old_count > 20 && $new_count < $old_count * 0.5) {
            // الجولة أعطت أقل من نصف الخدمات: نتجاهلها (فشل جزئي بمزوّد)، ونعيد المحاولة بعد 30 ثانية
            if ($old) cache_file_write(all_svc_cache_file(), $old_data, $now - 90);
            return;
        }
        $key = fn($s) => py_str(dget($s, 'service', '')) . ':' . py_str(dget($s, '_prov_id', ''));
        $old_ids = []; foreach ((array)$old_data as $s) $old_ids[$key($s)] = true;
        $new_ids = []; foreach ($all_svcs as $s) $new_ids[$key($s)] = true;
        ksort($old_ids, SORT_STRING); ksort($new_ids, SORT_STRING);
        cache_file_write(all_svc_cache_file(), $all_svcs, $now);
        if ($old_ids !== $new_ids) _bump_svc_version_only();
    } catch (Throwable $e) {}
}

/** get_all_smm_services  (app.py:812) */
function get_all_smm_services(): array {
    $mem = &$GLOBALS['__all_svc_mem'];   // نتيجة هذا الطلب فقط (لتفادي قراءة الملف مراراً)
    if (isset($mem)) return $mem;
    $now = microtime(true);
    $c = cache_file_read(all_svc_cache_file());
    if ($c !== null && is_array($c['data']) && $now - $c['time'] < 120) return $mem = $c['data'];
    if ($c !== null && is_array($c['data'])) {
        defer_after_response(function () {
            $lock = file_lock_try('all_svc_refresh');
            if (!$lock) return;
            try { _all_services_do_refresh(); } finally { file_lock_release($lock); }
        });
        return $mem = $c['data'];
    }
    $lock = file_lock_try('all_svc_refresh');
    if ($lock) {
        try {
            $c2 = cache_file_read(all_svc_cache_file());
            if ($c2 !== null && is_array($c2['data']) && microtime(true) - $c2['time'] < 120) return $mem = $c2['data'];
            _all_services_do_refresh();
        } finally { file_lock_release($lock); }
    } else {
        // مجلب آخر يعمل الآن: انتظر قليلاً ثم اقرأ (بايثون كان ينتظر القفل)
        for ($i = 0; $i < 60 && !($c = cache_file_read(all_svc_cache_file())); $i++) usleep(500000);
    }
    $c = cache_file_read(all_svc_cache_file());
    return $mem = ($c !== null && is_array($c['data']) ? $c['data'] : []);
}

/** تفريغ كاش الخدمات (بديل _services_cache['data']=None / _all_services_cache['data']=None بالأصل) */
function clear_services_cache(bool $default = true, bool $all = true): void {
    if ($default) @unlink(svc_cache_file());
    if ($all) { @unlink(all_svc_cache_file()); unset($GLOBALS['__all_svc_mem']); }
}

/** _bump_svc_version  (app.py:3010): يغيّر رقم نسخة الخدمات ويمسح الكاش */
function _bump_svc_version(): void {
    $v = py_int(py_or(get_setting('svc_version', '0'), 0)) + 1;
    set_setting('svc_version', (string)$v);
    @unlink(all_svc_cache_file());
    @unlink(svc_cache_file());
    unset($GLOBALS['__all_svc_mem']);
}

/** _bump_svc_version_only  (app.py:3018) */
function _bump_svc_version_only(): void {
    $v = py_int(py_or(get_setting('svc_version', '0'), 0)) + 1;
    set_setting('svc_version', (string)$v);
}

/* ------------------------------ كشف المنصة ------------------------------ */

/** @return array [platform => [[kw, kw_lower, regex], ...]] مرتبة كما بـ app.py:2960-2973 */
function _platform_regexes(): array {
    static $rx = null;
    if ($rx !== null) return $rx;
    $rx = [];
    foreach (PLATFORM_MAP as $pn => $kws) {
        $sorted = $kws;
        usort($sorted, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));   // len تنازلياً (ترتيب مستقر)
        $pats = [];
        foreach ($sorted as $kw) {
            $pats[] = [$kw, mb_strtolower($kw, 'UTF-8'),
                '/(*UCP)(?:^|[\s\-–—\/|,.()\[\]])' . preg_quote($kw, '/') . '(?:$|[\s\-–—\/|,.()\[\]])/iu'];
        }
        $rx[$pn] = $pats;
    }
    if (isset($rx['X (Twitter)'])) {
        $rx['Twitter / X'] = array_merge($rx['Twitter / X'] ?? [], $rx['X (Twitter)']);
        unset($rx['X (Twitter)']);
    }
    return $rx;
}

function _platform_exact(): array {
    static $ex = null;
    if ($ex === null) {
        $ex = [];
        foreach (PLATFORM_MAP as $pn => $_) $ex[mb_strtolower($pn, 'UTF-8')] = $pn;
    }
    return $ex;
}

/** _detect_platform  (app.py:2975) — مع تخزين مؤقت للنتائج لأن آلاف الخدمات تتشارك الفئات نفسها */
function _detect_platform($cat, $name): string {
    static $catMemo = [];      // الخطوات المعتمدة على الفئة وحدها
    static $pairMemo = [];
    $cat = (string)$cat; $name = (string)$name;
    $mk = $cat . "\x00" . $name;
    if (isset($pairMemo[$mk])) return $pairMemo[$mk];

    if (!array_key_exists($cat, $catMemo)) {
        $cat_s = py_strip($cat);
        $cat_low = mb_strtolower($cat_s, 'UTF-8');
        $res = null;
        $exact = _platform_exact();
        if (isset($exact[$cat_low])) $res = $exact[$cat_low];
        if ($res === null) {
            $cat_clean = preg_replace('/(*UCP)[\s\-–—]+$/u', '', $cat_low);
            if (isset($exact[$cat_clean])) $res = $exact[$cat_clean];
        }
        if ($res === null) {
            foreach (_platform_regexes() as $pname => $patterns) {
                foreach ($patterns as [$kw, $kwl, $_pat]) {
                    if (strncmp($cat_low, $kwl, strlen($kwl)) === 0) { $res = $pname; break 2; }
                }
            }
        }
        if ($res === null) {
            $cat_padded = ' ' . $cat_low . ' ';
            foreach (_platform_regexes() as $pname => $patterns) {
                foreach ($patterns as [$kw, $kwl, $pat]) {
                    if (preg_match($pat, $cat_padded)) { $res = $pname; break 2; }
                }
            }
        }
        $catMemo[$cat] = [$res, $cat_low];
    }
    [$res, $cat_low] = $catMemo[$cat];
    if ($res !== null) return $pairMemo[$mk] = $res;

    $name_low = py_strip(mb_strtolower($name, 'UTF-8'));
    $name_padded = ' ' . $name_low . ' ';
    foreach (_platform_regexes() as $pname => $patterns) {
        foreach ($patterns as [$kw, $kwl, $pat]) {
            if (preg_match($pat, $name_padded)) return $pairMemo[$mk] = $pname;
        }
    }
    if (strpos($cat_low, 'review') !== false && strpos($cat_low, 'google') === false) {
        return $pairMemo[$mk] = (strpos($cat_low, 'app') !== false ? 'Mobile App' : 'Google');
    }
    return $pairMemo[$mk] = 'Other';
}

/* ------------------------------ بناء الكتالوج ------------------------------ */

/** _build_services_catalog  (app.py:3026) → [platforms(array), total(int)] */
function _build_services_catalog(): array {
    $svcs = get_all_smm_services();
    $conn = get_db();
    $frows = $conn->execute('SELECT * FROM service_filters')->fetchall();
    $allowed_svcs = []; $hidden_svcs = []; $hidden_plats = []; $hidden_cats = [];
    $cat_profits = []; $svc_profits = []; $custom_names = []; $custom_categories = []; $svc_platforms = [];
    foreach ($frows as $f) {
        $ft = $f['filter_type']; $fk = py_str($f['filter_key']); $fp = $f['platform'];
        $cname = $f->has('custom_name') ? $f['custom_name'] : '';
        $ccat = $f->has('custom_category') ? $f['custom_category'] : '';
        try { $fpid = py_int(py_or($f['prov_id'], 0)); } catch (Throwable $e) { $fpid = 0; }
        if (py_truthy($f['hidden'])) {
            if ($ft === 'service') $hidden_svcs[$fk . '|' . $fpid] = true;
            elseif ($ft === 'platform') $hidden_plats[$fk] = true;
            elseif ($ft === 'category') $hidden_cats[py_str($fp) . '|||' . $fk] = true;
        } else {
            if ($ft === 'service') $allowed_svcs[$fk . '|' . $fpid] = true;
        }
        if ($f['profit_pct'] !== null) {
            if ($ft === 'category') $cat_profits[py_str($fp) . '|||' . $fk] = $f['profit_pct'];
            elseif ($ft === 'service') $svc_profits[$fk] = $f['profit_pct'];
        }
        if (py_truthy($cname)) $custom_names[$ft . ':' . $fk . ':' . py_str($fp)] = $cname;
        if ($ft === 'service' && py_truthy($ccat)) {
            $custom_categories[$fk] = $ccat;
            $svc_platforms[$fk] = $fp;
        }
    }

    $platforms = [];
    foreach ($svcs as $s) {
        $cat = dget($s, 'category', '');
        $name = dget($s, 'name', '');
        $sid = py_str(dget($s, 'service', ''));
        $rate = py_float(py_or(dget($s, 'rate', '0'), 0));
        $min_q = dget($s, 'min', '0');
        $max_q = dget($s, 'max', '0');
        try { $spid = py_int(py_or(dget($s, '_prov_id', 0), 0)); } catch (Throwable $e) { $spid = 0; }
        $platform = _detect_platform($cat, $name);
        if (array_key_exists($sid, $custom_categories)) {
            $cat = $custom_categories[$sid];
            $platform = array_key_exists($sid, $svc_platforms) ? $svc_platforms[$sid] : $platform;
        }
        if (isset($hidden_plats[$platform])) continue;
        if (isset($hidden_svcs[$sid . '|' . $spid]) || isset($hidden_svcs[$sid . '|0'])) continue;
        $pc = $platform . '|||' . py_str($cat);
        if (isset($hidden_cats[$pc])) continue;
        if (!(isset($allowed_svcs[$sid . '|' . $spid]) || isset($allowed_svcs[$sid . '|0']))) continue;
        if (array_key_exists($sid, $svc_profits) && $svc_profits[$sid] > 0) {
            $rate = py_round($rate * (1 + $svc_profits[$sid] / 100.0), 4);
        } elseif (array_key_exists($pc, $cat_profits) && $cat_profits[$pc] > 0) {
            $rate = py_round($rate * (1 + $cat_profits[$pc] / 100.0), 4);
        }
        $display_name = $custom_names['service:' . $sid . ':' . $platform] ?? $name;
        $display_cat = $custom_names['category:' . py_str($cat) . ':' . $platform] ?? $cat;
        $dc = py_str($display_cat);
        if (!isset($platforms[$platform])) $platforms[$platform] = ['categories' => []];
        if (!isset($platforms[$platform]['categories'][$dc])) $platforms[$platform]['categories'][$dc] = [];
        $platforms[$platform]['categories'][$dc][] = [
            'id' => $sid, 'name' => $display_name, 'rate' => py_str($rate), 'min' => $min_q, 'max' => $max_q,
            'refill' => py_str(py_or(dget($s, 'refill', ''), '')),
            'desc' => py_str(py_or(dget($s, 'description', ''), py_or(dget($s, 'desc', ''), ''))),
            'cancel' => dget($s, 'cancel', false), 'type' => py_str(py_or(dget($s, 'type', ''), '')),
        ];
    }
    return [$platforms, count($svcs)];
}

/** _get_user_level_discount  (app.py:3477) */
function _get_user_level_discount($uid): float {
    try {
        $conn = get_db();
        $row = $conn->execute("SELECT COALESCE(SUM(CAST(charge AS REAL)),0) as total FROM orders WHERE user_id=? AND status='Completed'", [$uid])->fetchone();
        $total_spent = $row ? py_float($row['total']) : 0;
        $lv_rows = $conn->execute('SELECT discount, min_spent FROM levels WHERE active=1 ORDER BY min_spent ASC, id ASC')->fetchall();
        $discount = 0.0;
        foreach ($lv_rows as $lv) {
            if ($total_spent >= py_float($lv['min_spent'])) $discount = py_float(py_or($lv['discount'], 0));
        }
        return $discount;
    } catch (Throwable $e) {
        return 0.0;
    }
}
