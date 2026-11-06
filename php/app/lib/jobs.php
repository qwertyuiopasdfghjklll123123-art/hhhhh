<?php
/**
 * مهام الخلفية المنقولة من app.py (كانت threads، تُشغَّل الآن من cron.php أو من الأدمن):
 *   مزامنة الخدمات (app.py:272-417) والاستيراد التلقائي (419-526) والتعويض التلقائي (3786-3866)
 * الحالة العامة (مثل _last_sync_result) تُحفظ بملفات JSON ليراها كل الطلبات.
 */

function state_file(string $name): string { return DATA_DIR . '/cache/state_' . $name . '.json'; }

function state_get(string $name, array $default = []): array {
    $f = state_file($name);
    if (!is_file($f)) return $default;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : $default;
}

/** دمج مفاتيح بالحالة (مثل dict.update) وإرجاع الحالة الجديدة */
function state_update(string $name, array $updates, array $default = []): array {
    $f = state_file($name);
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0775, true);
    $fp = fopen($f, 'c+');
    if (!$fp) return array_merge($default, $updates);
    flock($fp, LOCK_EX);
    $cur = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($cur)) $cur = $default;
    $cur = array_merge($cur, $updates);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode($cur, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    fflush($fp); flock($fp, LOCK_UN); fclose($fp);
    return $cur;
}

const SYNC_DEFAULT = ['time' => 0, 'removed_svcs' => 0, 'removed_cats' => 0, 'errors' => []];
const MIN_PROVIDER_SERVICES = 20;     // أقل عدد خدمات مقبول من مزود (أقل = فشل API)
const MAX_DELETE_RATIO = 0.25;        // أقصى نسبة حذف مسموحة بمزامنة واحدة (25%)

function _last_sync_result(): array { return state_get('last_sync', SYNC_DEFAULT); }

/** _do_sync_services  (app.py:279) */
function _do_sync_services(bool $force = false): array {
    $lock = file_lock_try('sync_services');
    if (!$lock) return _last_sync_result();
    try {
        $provs = get_all_providers();
        $active_provs = array_values(array_filter($provs, fn($p) => py_truthy($p['active'] ?? null) && py_truthy($p['api_url'] ?? null) && py_truthy($p['api_key'] ?? null)));
        if (!$active_provs) {
            return state_update('last_sync', ['time' => microtime(true), 'removed_svcs' => 0, 'removed_cats' => 0, 'errors' => ['لا يوجد مزودين فعالين']], SYNC_DEFAULT);
        }

        // 1) جلب خدمات كل مزود
        $live_svc_ids = []; $live_cat_names = [];
        $successful_provs = 0; $failed_provs = 0; $errors = []; $total_live = 0;
        foreach ($active_provs as $prov) {
            try {
                $r = requests_post($prov['api_url'], ['data' => ['key' => $prov['api_key'], 'action' => 'services'], 'timeout' => 25]);
                $rj = $r->json();
                $svcs = (is_array($rj) && is_list_array($rj)) ? $rj : [];
                $pcount = count($svcs);
                // حماية: إذا المزود رجّع أقل من الحد الأدنى — نعتبره فشل API
                if ($pcount < MIN_PROVIDER_SERVICES) {
                    $errors[] = '⚠️ ' . py_str($prov['name'] ?? '') . ": رجّع $pcount خدمة فقط — تجاوز (قد يكون API معطل)";
                    $failed_provs++;
                    continue;
                }
                foreach ($svcs as $s) {
                    $live_svc_ids[py_str(dget($s, 'service', ''))] = true;
                    $live_cat_names[py_str(dget($s, 'category', ''))] = true;
                }
                $total_live += $pcount;
                $successful_provs++;
            } catch (Throwable $e) {
                $errors[] = '❌ فشل ' . py_str($prov['name'] ?? '') . ': ' . py_slice($e->getMessage(), null, 60);
                $failed_provs++;
                continue;
            }
        }

        // حماية: فشل كل المزودين — لا نحذف شيئاً
        if ($successful_provs == 0) {
            return state_update('last_sync', ['time' => microtime(true), 'removed_svcs' => 0, 'removed_cats' => 0,
                'errors' => $errors ?: ['فشل الاتصال بجميع المزودين — لم يتم حذف شيء']], SYNC_DEFAULT);
        }

        // حماية: إذا فشل مزود — لا نحذف خدمات لأنها قد تكون مال هذا المزود
        if ($failed_provs > 0 && !$force) {
            return state_update('last_sync', ['time' => microtime(true), 'removed_svcs' => 0, 'removed_cats' => 0,
                'errors' => array_merge($errors, ["تم إيقاف الحذف التلقائي — $failed_provs مزود لم يستجب"]),
                'live_services' => $total_live, 'skipped' => true], SYNC_DEFAULT);
        }

        // 2) جلب الفلاتر المخزنة
        $conn = get_db();
        $frows = $conn->execute('SELECT id, filter_type, filter_key, platform FROM service_filters WHERE hidden=0')->fetchall();
        $total_stored = count($frows);
        if ($total_stored == 0) {
            return state_update('last_sync', ['time' => microtime(true), 'removed_svcs' => 0, 'removed_cats' => 0, 'errors' => $errors, 'live_services' => $total_live], SYNC_DEFAULT);
        }

        // 3) تحديد المحذوفات
        $to_delete_svcs = []; $to_delete_cats = [];
        foreach ($frows as $f) {
            $fid = $f['id']; $ft = $f['filter_type']; $fk = py_str($f['filter_key']);
            if ($ft === 'service' && !isset($live_svc_ids[$fk])) $to_delete_svcs[] = $fid;
            elseif ($ft === 'category' && !isset($live_cat_names[$fk])) $to_delete_cats[] = $fid;
        }
        $total_to_delete = count($to_delete_svcs) + count($to_delete_cats);

        // حماية: لا نحذف أكثر من 25% بمزامنة واحدة (إلا بالمزامنة اليدوية force)
        if (!$force && $total_stored > 0) {
            $ratio = $total_to_delete / $total_stored;
            if ($ratio > MAX_DELETE_RATIO) {
                return state_update('last_sync', ['time' => microtime(true), 'removed_svcs' => 0, 'removed_cats' => 0,
                    'errors' => ["⚠️ المزامنة تريد حذف $total_to_delete من $total_stored (" . py_fmt($ratio * 100, 0) . '%) — تجاوز الحد الآمن (' . py_fmt(MAX_DELETE_RATIO * 100, 0) . '%). استخدم المزامنة اليدوية.'],
                    'live_services' => $total_live, 'blocked' => true, 'would_delete' => $total_to_delete], SYNC_DEFAULT);
            }
        }

        // 4) إخفاء الخدمات المحذوفة من المزودين (بدلاً من الحذف النهائي)
        $removed_svcs = count($to_delete_svcs);
        $removed_cats = count($to_delete_cats);
        if ($total_to_delete > 0) {
            foreach (array_merge($to_delete_svcs, $to_delete_cats) as $fid) {
                $conn->execute('UPDATE service_filters SET hidden=1 WHERE id=?', [$fid]);
            }
            $conn->commit();
            _bump_svc_version();
        }

        $result = ['time' => microtime(true), 'removed_svcs' => $removed_svcs, 'removed_cats' => $removed_cats,
                   'errors' => $errors, 'total_checked' => $total_stored, 'live_services' => $total_live];
        state_update('last_sync', $result, SYNC_DEFAULT);

        if ($removed_svcs > 0 || $removed_cats > 0) {
            try {
                $conn2 = get_db();
                foreach ($conn2->execute('SELECT id FROM users WHERE is_admin=1')->fetchall() as $adm) {
                    push_user_notif($adm['id'], 'admin', '🔄 مزامنة الخدمات',
                        "تم إخفاء $removed_svcs خدمة و $removed_cats فئة محذوفة من المزودين", 'sync');
                }
            } catch (Throwable $e) {}
        }
        return $result;
    } catch (Throwable $e) {
        return state_update('last_sync', ['time' => microtime(true), 'errors' => [$e->getMessage()]], SYNC_DEFAULT);
    } finally {
        file_lock_release($lock);
    }
}

const AUTO_IMPORT_DEFAULT = ['time' => 0, 'imported' => 0, 'errors' => []];

/** _do_auto_import_new_services  (app.py:423) */
function _do_auto_import_new_services(bool $force = false): array {
    if (!$force && get_setting('auto_import_enabled', '0') !== '1') return state_get('last_auto_import', AUTO_IMPORT_DEFAULT);
    try { $profit = py_float(py_or(get_setting('auto_import_profit_pct', '30'), 30)); }
    catch (PyValueError $e) { $profit = 30; }
    $provs = get_all_providers();
    $active_provs = array_values(array_filter($provs, fn($p) => py_truthy($p['active'] ?? null) && py_truthy($p['api_url'] ?? null) && py_truthy($p['api_key'] ?? null)));
    if (!$active_provs) {
        return state_update('last_auto_import', ['time' => microtime(true), 'imported' => 0, 'errors' => ['لا يوجد مزودين فعالين']], AUTO_IMPORT_DEFAULT);
    }

    // المرحلة 1: كل استدعاءات الشبكة
    $errors = []; $new_services = [];
    foreach ($active_provs as $prov) {
        try {
            $r = requests_post($prov['api_url'], ['data' => ['key' => $prov['api_key'], 'action' => 'services'], 'timeout' => 25]);
            $rj = $r->json();
            $svcs = (is_array($rj) && is_list_array($rj)) ? $rj : [];
        } catch (Throwable $e) {
            $errors[] = '❌ فشل ' . py_str($prov['name'] ?? '') . ': ' . py_slice($e->getMessage(), null, 60);
            continue;
        }
        if (count($svcs) < MIN_PROVIDER_SERVICES) {
            $errors[] = '⚠️ ' . py_str($prov['name'] ?? '') . ': رجّع ' . count($svcs) . ' خدمة فقط — تجاوز';
            continue;
        }
        foreach ($svcs as $s) {
            $sid = py_str(dget($s, 'service', ''));
            if (!py_truthy($sid)) continue;
            $cat = dget($s, 'category', '');
            $name = dget($s, 'name', '');
            $new_services[] = [$sid, _detect_platform($cat, $name), $cat, $prov['id']];
        }
    }

    // المرحلة 2: كتابات قاعدة البيانات دفعة واحدة
    $conn = get_db();
    $existing = []; $existing_cats = [];
    foreach ($conn->execute("SELECT filter_key, platform FROM service_filters WHERE filter_type='service'")->fetchall() as $r) {
        $existing[py_str($r['filter_key']) . "\x00" . py_or($r['platform'], '')] = true;
    }
    foreach ($conn->execute("SELECT filter_key, platform FROM service_filters WHERE filter_type='category'")->fetchall() as $r) {
        $existing_cats[py_str($r['filter_key']) . "\x00" . py_or($r['platform'], '')] = true;
    }
    $imported = 0; $touched_provs = [];
    foreach ($new_services as [$sid, $platform, $cat, $prov_id]) {
        if (isset($existing[$sid . "\x00" . $platform])) continue;
        try {
            $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,custom_category,prov_id)
                VALUES('service',?,?,0,?,?,?) ON CONFLICT(filter_type,filter_key,platform)
                DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct, custom_category=excluded.custom_category, prov_id=excluded.prov_id",
                [$sid, $platform, $profit, sql_str($cat), $prov_id]);
            $existing[$sid . "\x00" . $platform] = true;
            $imported++;
            $touched_provs[$prov_id] = true;
            $catkey = py_str($cat) . "\x00" . $platform;
            if (py_truthy($cat) && !isset($existing_cats[$catkey])) {
                $conn->execute("INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,prov_id)
                    VALUES('category',?,?,0,?,?) ON CONFLICT(filter_type,filter_key,platform)
                    DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct, prov_id=excluded.prov_id",
                    [sql_str($cat), $platform, $profit, $prov_id]);
                $existing_cats[$catkey] = true;
            }
        } catch (Throwable $e) {
            $errors[] = "خطأ باستيراد خدمة $sid: " . py_slice($e->getMessage(), null, 60);
        }
    }
    foreach (array_keys($touched_provs) as $prov_id) {
        try { $conn->execute('UPDATE providers SET show_in_store=1 WHERE id=? AND show_in_store=0', [$prov_id]); } catch (Throwable $e) {}
    }
    $conn->commit();
    if ($imported > 0) {
        _bump_svc_version();
        try {
            $nconn = get_db();
            foreach ($nconn->execute('SELECT id FROM users WHERE is_admin=1')->fetchall() as $adm) {
                push_user_notif($adm['id'], 'admin', '📦 استيراد تلقائي للخدمات',
                    "تم إضافة $imported خدمة جديدة تلقائياً من المزودين بنسبة ربح " . py_fmt_g($profit) . '%', 'sync');
            }
        } catch (Throwable $e) {}
    }
    return state_update('last_auto_import', ['time' => microtime(true), 'imported' => $imported, 'errors' => $errors], AUTO_IMPORT_DEFAULT);
}
