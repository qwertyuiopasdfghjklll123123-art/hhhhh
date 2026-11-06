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

/* ====================================================================================
 * المهام الدورية (كانت threads بتطبيق بايثون: app.py:17619-17863 و 260-270 ...) — تُشغَّل من cron.php
 * ==================================================================================== */

function job_log(string $line): void {
    @file_put_contents(DATA_DIR . '/logs/cron.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** مزود الطلب (provider_id أو الافتراضي) → [url, key, id] */
function _job_order_provider($order): array {
    try { $pid = py_int(py_or($order['provider_id'], 0)); } catch (Throwable $e) { $pid = 0; }
    return $pid ? get_provider_api($pid) : get_provider_api();
}

/** معالج الطلبات المعلّقة — دورة واحدة (app.py:17621-17758) */
function job_process_queued(): void {
    $conn = get_db();
    $queued = $conn->execute("SELECT * FROM orders WHERE status='Queued' AND (order_id IS NULL OR order_id='') ORDER BY id ASC")->fetchall();
    if (!$queued) return;
    job_log('[QUEUE] Found ' . count($queued) . ' queued order(s) to retry');
    $prov_bal_cache = [];
    $bal_for = function ($pid) use (&$prov_bal_cache) {
        if (array_key_exists($pid, $prov_bal_cache)) return $prov_bal_cache[$pid];
        try {
            [$u, $k] = $pid ? get_provider_api($pid) : get_provider_api();
            $br = requests_post($u, ['data' => ['key' => $k, 'action' => 'balance'], 'timeout' => 10]);
            $bd = py_dict($br->json());
            $raw = dget($bd, 'balance', 0);
            if (is_string($raw)) $raw = py_strip(str_replace(',', '', $raw));
            $v = py_float($raw);
            job_log("[QUEUE] Provider $pid balance: $" . py_fmt($v, 4));
        } catch (Throwable $e) {
            job_log("[QUEUE] Failed balance check for provider $pid: " . $e->getMessage());
            $v = -1.0;   // -1 = فشل الفحص، نتجاوز هذه الدورة (لا نخسر الطلب)
        }
        return $prov_bal_cache[$pid] = $v;
    };
    foreach ($queued as $order) {
        try {
            try { $ord_pid = py_int(py_or($order['provider_id'], 0)); } catch (Throwable $e) { $ord_pid = 0; }
            $cur_bal = $bal_for($ord_pid);
            if ($cur_bal < 0) continue;
            if ($cur_bal <= 0) continue;
            [$api_url, $api_key, $_prov_id] = $ord_pid ? get_provider_api($ord_pid) : get_provider_api();
            $payload = ['key' => $api_key, 'action' => 'add', 'service' => $order['service'], 'link' => $order['link'], 'quantity' => py_int($order['quantity'])];
            try {
                $cmts = $order->has('comments') ? $order['comments'] : '';
                if (py_truthy($cmts)) $payload['comments'] = $cmts;
            } catch (Throwable $e) {}
            job_log("[QUEUE] Sending order #{$order['display_id']} to provider $ord_pid...");
            $r = requests_post($api_url, ['data' => $payload, 'timeout' => 15]);
            $result = py_dict($r->json());
            if (array_key_exists('order', $result) && py_truthy($result['order'])) {
                $provider_charge = py_float(dget($result, 'charge', 0));
                $prov_bal_cache[$ord_pid] = max(0, $cur_bal - $provider_charge);
                $conn->execute('UPDATE orders SET order_id=?, status=?, charge=? WHERE id=?',
                    [py_str($result['order']), 'Processing', py_str($provider_charge), $order['id']]);
                $conn->commit();
                push_user_notif($order['user_id'], 'order', 'تم إرسال طلبك #' . py_str($order['display_id']), py_str($order['service_name']) . ' — تم الإرسال تلقائياً', 'check', 'order:' . py_str($order['display_id']));
                try { _add_referral_commission($order['user_id'], py_str($result['order']), $provider_charge); } catch (Throwable $e) {}
                job_log("[QUEUE] OK Order #{$order['display_id']} sent successfully (provider order: " . py_str($result['order']) . ')');
            } else {
                $err = mb_strtolower(py_str(dget($result, 'error', '')), 'UTF-8');
                job_log("[QUEUE] FAIL Order #{$order['display_id']} failed: $err");
                if (strpos($err, 'balance') !== false || strpos($err, 'fund') !== false || strpos($err, 'insufficient') !== false) {
                    $prov_bal_cache[$ord_pid] = 0;     // رصيد غير كافي → نوقف بقية طلبات المزود بهذه الدورة
                } elseif (_job_is_dead_service_error($err)) {
                    $site_charge = py_float(py_or($order['site_charge'], 0));
                    if ($site_charge > 0) {
                        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$site_charge, $order['user_id']]);
                    }
                    $conn->execute("UPDATE orders SET status='Canceled', refunded=1 WHERE id=?", [$order['id']]);
                    $conn->commit();
                    push_user_notif($order['user_id'], 'system', 'تم إلغاء طلبك #' . py_str($order['display_id']),
                        'الخدمة لم تعد متوفرة — تم إرجاع $' . py_str($order['site_charge']) . ' لرصيدك', 'wallet', 'wallet:');
                    job_log("[QUEUE] CANCEL Order #{$order['display_id']} cancelled (service dead) — refunded");
                } else {
                    try {
                        try { $cur_attempts = py_int(py_or($order['refill_count'], 0)); } catch (Throwable $e) { $cur_attempts = 0; }
                        $new_attempts = $cur_attempts + 1;
                        if ($new_attempts >= 10) {
                            $conn->execute("UPDATE orders SET status='Error' WHERE id=?", [$order['id']]);
                            $conn->commit();
                            push_user_notif($order['user_id'], 'system', 'فشل طلب #' . py_str($order['display_id']), 'تواصل مع الدعم — ' . py_slice($err, null, 60), 'warning');
                            job_log("[QUEUE] WARN Order #{$order['display_id']} marked as Error after 10 retries");
                        } else {
                            $conn->execute('UPDATE orders SET refill_count=? WHERE id=?', [$new_attempts, $order['id']]);
                            $conn->commit();
                        }
                    } catch (Throwable $e) { $conn->rollback(); }
                }
            }
        } catch (Throwable $e) {
            $conn->rollback();
            job_log('[QUEUE] Exception while processing order: ' . $e->getMessage());
        }
        sleep(1);
    }
}

/** كلمات تدل على أن الخدمة حُذفت عند المزود (app.py:17707) */
function _job_is_dead_service_error(string $err): bool {
    foreach (['invalid service', 'service not found', 'not found', 'does not exist', 'service is not', 'incorrect service',
              'service unavailable', 'service was removed', 'service deleted', 'invalid service id', 'bad service'] as $kw) {
        if (strpos($err, $kw) !== false) return true;
    }
    return false;
}

/** فحص حالات الطلبات النشطة عند المزود (app.py:17762-17823) — كل 5 دقائق */
function job_check_orders(): void {
    $conn = get_db();
    try {
        // الطلبات النشطة + المكتملة خلال آخر 6 ساعات (بعض المزودين يعكسون الاكتمال لاحقاً إلى Canceled/Partial)
        // الترتيب يقابل ما يعيده SQLite عبر فهرس status: الحالات النشطة مرتبة بالاسم ثم المكتملة حديثاً (مع id تصاعدياً)
        $active = $conn->execute("SELECT * FROM orders WHERE (status IN ('Processing','Pending','In progress') OR (status='Completed' AND created_at > ?)) AND refunded=0 ORDER BY (status='Completed') ASC, status ASC, id ASC",
            [microtime(true) - 6 * 3600])->fetchall();
    } catch (Throwable $e) { job_log('[ORDERS-CHECK] Cycle failed: ' . $e->getMessage()); return; }
    foreach ($active as $order) {
        $notifs = [];
        try {
            [$api_url, $api_key] = _job_order_provider($order);
            $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'status', 'order' => $order['order_id']], 'timeout' => 15]);
            $result = py_dict($r->json());
            $new_status = dget($result, 'status', '');
            if (!py_truthy($new_status) || $new_status === $order['status']) continue;
            $conn->execute('UPDATE orders SET status=?, charge=? WHERE id=?', [sql_text($new_status), sql_text(dget($result, 'charge', '0')), $order['id']]);
            $uid = $order['user_id'];
            $site_charge = py_float(py_or($order['site_charge'], 0));
            $qty = py_int(py_or($order['quantity'], 0));
            $remains = py_int(dget($result, 'remains', 0));
            $oid = py_or(py_or($order['display_id'], $order['order_id']), $order['id']);
            if ($new_status === 'Canceled' && $site_charge > 0 && !py_truthy($order['refunded'])) {
                $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$site_charge, $uid]);
                $conn->execute('UPDATE orders SET refunded=1 WHERE id=?', [$order['id']]);
                $notifs[] = [$uid, 'system', 'استرجاع $' . py_fmt($site_charge, 2), 'طلب #' . py_str($oid) . ' تم إلغاؤه تلقائياً — تم إرجاع المبلغ', 'wallet', 'wallet:'];
            } elseif ($new_status === 'Partial' && $site_charge > 0 && $qty > 0 && $remains > 0 && !py_truthy($order['refunded'])) {
                $refund = py_round($site_charge * ($remains / $qty), 2);
                if ($refund > 0) {
                    $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$refund, $uid]);
                    $conn->execute('UPDATE orders SET refunded=1 WHERE id=?', [$order['id']]);
                    $notifs[] = [$uid, 'system', 'استرجاع جزئي $' . py_fmt($refund, 2), 'طلب #' . py_str($oid) . ' جزئي — تم إرجاع المتبقي', 'wallet', ''];
                }
            } elseif ($new_status === 'Completed' && $order['status'] !== 'Completed') {
                $notifs[] = [$uid, 'order', 'طلب #' . py_str($oid) . ' اكتمل ', py_str($order['service_name']), 'done', 'order:' . py_str($oid)];
            }
            $conn->commit();
            // الإشعارات بعد التثبيت (بالأصل كانت تُفقد بسبب قفل SQLite بين اتصالين: انظر ملاحظة README)
            foreach ($notifs as $n) push_user_notif(...$n);
        } catch (Throwable $e) {
            $conn->rollback();
            job_log('[ORDERS-CHECK] Failed to recheck order #' . py_or($order['display_id'], $order['id']) . ': ' . $e->getMessage());
        }
    }
}

/** مزامنة الأسعار: تحديث كاش خدمات المزود الافتراضي (app.py:17825-17840) — كل 3 دقائق */
function job_sync_prices(): void {
    try {
        [$api_url, $api_key] = get_provider_api();
        if (py_truthy($api_url) && py_truthy($api_key)) {
            $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'services'], 'timeout' => 15]);
            $data = $r->json();
            if (is_array($data) && is_list_array($data) && count($data) > 0) {
                cache_file_write(svc_cache_file(), $data, microtime(true));
            }
        }
    } catch (Throwable $e) {
        job_log('[PRICE-SYNC] Failed to sync prices: ' . $e->getMessage());
    }
}

/** حذف صور/تسجيلات التذاكر الأقدم من 48 ساعة (app.py:17842-17863) — كل ساعة */
function job_cleanup_images(): void {
    $cutoff = time() - 48 * 3600;
    foreach ([TK_IMG_DIR, VOICE_DIR] as $folder) {
        if (!is_dir($folder)) continue;
        foreach (scandir($folder) ?: [] as $fname) {
            $fpath = $folder . '/' . $fname;
            if (is_file($fpath) && @filemtime($fpath) < $cutoff) @unlink($fpath);
        }
    }
}

function job_auto_backup(): void { create_backup('auto'); }
function job_telegram_backup(): void { _create_telegram_backup(); }
function job_sync_services(): void { _do_sync_services(false); }
function job_auto_import(): void { _do_auto_import_new_services(); }
function job_ai_nudge(): void { _do_ai_inactivity_check(); }
function job_auto_refill(): void { _run_auto_refill(); }

/**
 * جدول المهام: every = الفاصل بالثواني بعد انتهاء التشغيل السابق (كما time.sleep بنهاية الحلقة)،
 * delay = التأخير قبل أول تشغيل (كما time.sleep أول الحلقة).
 */
function cron_jobs(): array {
    return [
        'queued'         => ['every' => 30,     'delay' => 15,    'fn' => 'job_process_queued',  'desc' => 'معالج الطلبات المعلّقة'],
        'check_orders'   => ['every' => 300,    'delay' => 300,   'fn' => 'job_check_orders',    'desc' => 'فحص حالات الطلبات عند المزود'],
        'price_sync'     => ['every' => 180,    'delay' => 30,    'fn' => 'job_sync_prices',     'desc' => 'مزامنة أسعار الخدمات'],
        'cleanup_images' => ['every' => 3600,   'delay' => 120,   'fn' => 'job_cleanup_images',  'desc' => 'تنظيف صور وتسجيلات التذاكر (>48 ساعة)'],
        'auto_backup'    => ['every' => 3600,   'delay' => 3600,  'fn' => 'job_auto_backup',     'desc' => 'نسخة احتياطية محلية'],
        'tg_backup'      => ['every' => 21600,  'delay' => 120,   'fn' => 'job_telegram_backup', 'desc' => 'نسخة احتياطية إلى تلغرام'],
        'sync_services'  => ['every' => 600,    'delay' => 300,   'fn' => 'job_sync_services',   'desc' => 'مزامنة الخدمات المحذوفة'],
        'auto_import'    => ['every' => 21600,  'delay' => 900,   'fn' => 'job_auto_import',     'desc' => 'استيراد الخدمات الجديدة تلقائياً'],
        'auto_refill'    => ['every' => 86400,  'delay' => 120,   'fn' => 'job_auto_refill',     'desc' => 'التعويض التلقائي'],
        'ai_nudge'       => ['every' => 21600,  'delay' => 600,   'fn' => 'job_ai_nudge',        'desc' => 'رسائل المساعد لغير النشطين'],
    ];
}
