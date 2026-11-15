<?php
/**
 * واصل — محرك الإرسال التلقائي (عبر خدمة واتساب API).
 *
 * يشتغل من cron.php (مهمة مجدولة كل دقيقة من cPanel)، أو من المتصفح ما دام
 * الموقع مفتوح بأي جهاز (احتياط إذا الـ Cron مو مفعّل). بكل تشغيل يمر على
 * حسابات الواتساب المربوطة بخدمة API واللي عندها رسائل حان موعدها، ويرسل
 * رسالة وحدة لكل حساب كل "مهلة" ثواني (مع فرق عشوائي بسيط حتى ما يبين آلي).
 *
 * قفل بقاعدة البيانات (GET_LOCK) يضمن ما يشتغل تشغيلين بنفس الوقت، فما
 * تنرسل نفس الرسالة مرتين حتى لو الـ Cron والمتصفح اشتغلوا سوا.
 */

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/gateway.php';

function auto_lock_name()
{
    // أسماء الأقفال مشتركة بكل قواعد بيانات السيرفر — نميّزها باسم قاعدة بياناتنا
    return 'wasel_auto_' . substr(sha1((string) cfg('db_name', '') . '|' . WASEL_ROOT), 0, 16);
}

function auto_try_lock()
{
    try {
        return (int) q_val('SELECT GET_LOCK(?, 0)', [auto_lock_name()]) === 1;
    } catch (PDOException $e) {
        error_log('[wasel auto] GET_LOCK failed: ' . $e->getMessage());
        return false;
    }
}

function auto_unlock()
{
    try {
        q('SELECT RELEASE_LOCK(?)', [auto_lock_name()]);
    } catch (PDOException $e) {
        // القفل ينفك تلقائياً لما ينسكر الاتصال
    }
}

function auto_min_delay()
{
    return max(0, (int) cfg('auto_min_delay', AUTO_MIN_DELAY));
}

/** الفترة قبل الرسالة الجاية من نفس الرقم: مهلة الحملة (وأقلها AUTO_MIN_DELAY) + فرق عشوائي لحد 30٪. */
function auto_next_gap($delay)
{
    $delay = max(auto_min_delay(), (int) $delay);
    $jitter = cfg('auto_jitter', true) && $delay > 0 ? mt_rand(0, max(1, (int) round($delay * 0.3))) : 0;
    return $delay + $jitter;
}

/** آخر مرة اشتغل فيها cron.php خلال دقيقتين ونص = الإرسال شغّال بالخلفية. */
function cron_recent()
{
    $last = site_settings(true)['cron_last_run'] ?? null;
    return $last !== null && strtotime($last) >= time() - 150;
}

/** مسار PHP لسطر الأوامر بالاستضافة (cPanel/DirectAdmin غالباً /usr/local/bin/php، وهوستنجر /usr/bin/php). */
function cron_php_binary()
{
    foreach (['/usr/local/bin/php', '/usr/bin/php'] as $bin) {
        if (@is_file($bin) && @is_executable($bin)) {
            return $bin;
        }
    }
    return 'php';
}

/** الأمر اللي ينضاف بـ Cron Jobs بلوحة الاستضافة (كل دقيقة). */
function cron_command()
{
    return cron_php_binary() . ' -q ' . WASEL_ROOT . '/cron.php >/dev/null 2>&1';
}

/** مسار نسبةً لمجلد المستخدم بالاستضافة (مثل public_html/cron.php أو domains/موقعك/public_html/cron.php). */
function home_relative($path)
{
    return preg_match('#^/home\d*/[^/]+/(.+)$#', $path, $m) ? $m[1] : $path;
}

/** نوع لوحة الاستضافة على الأغلب (من شكل المسار) — حتى نعرض الخطوات المناسبة أول مرة. */
function hosting_panel_hint()
{
    return preg_match('#/domains/[^/]+/public_html#', WASEL_ROOT) ? 'hostinger' : 'cpanel';
}

/** حسابات مربوطة بخدمة API وعندها رسائل حان موعدها (بحملات شغّالة ومو موقوفة). */
function auto_due_accounts()
{
    return q_all(
        "SELECT a.* FROM wa_accounts a
         WHERE a.provider <> 'manual' AND EXISTS (
            SELECT 1 FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
            WHERE m.account_id = a.id AND m.status = 'pending' AND m.scheduled_at <= ?
              AND c.status = 'active' AND c.paused = 0)
         ORDER BY a.id",
        [now_dt()]
    );
}

/** هل لازم نسأل الخدمة عن حالة الحساب من جديد؟ */
function auto_state_stale(array $acc)
{
    if (empty($acc['api_checked_at'])) {
        return true;
    }
    $age = time() - strtotime($acc['api_checked_at']);
    switch ((string) $acc['api_state']) {
        case 'connected':
            return $age >= 300;
        case 'starting':
        case 'error':
        case '':
            return $age >= 60;
    }
    // حالات تحتاج تدخّل (QR، اشتراك، حظر) — نفحص كل 10 دقايق، يمكن انحلّت من لوحة الخدمة
    return $age >= 600;
}

function auto_next_message($accountId)
{
    return q_row(
        "SELECT m.id, m.campaign_id, m.phone, m.day_index, m.attempts, m.status, c.message, c.delay_seconds
         FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
         WHERE m.account_id = ? AND m.status = 'pending' AND m.scheduled_at <= ?
           AND c.status = 'active' AND c.paused = 0
           AND (m.locked_by IS NULL OR m.locked_at IS NULL OR m.locked_at < ?)
         ORDER BY m.skips, m.scheduled_at, m.id LIMIT 1",
        [(int) $accountId, now_dt(), now_dt(-SEND_LOCK_SECONDS)]
    );
}

/** يرسل رسالة وحدة ويسجّل نتيجتها. يرجّع sent أو failed أو pending (تتأجل) أو skipped. */
function auto_send_one(array $acc, array $m)
{
    $lock = q(
        "UPDATE campaign_messages SET locked_at = ?, locked_by = 'auto'
         WHERE id = ? AND status = 'pending' AND (locked_by IS NULL OR locked_at IS NULL OR locked_at < ?)",
        [now_dt(), (int) $m['id'], now_dt(-SEND_LOCK_SECONDS)]
    );
    if ($lock->rowCount() !== 1) {
        return 'skipped';
    }
    $res = gw_send($acc, $m['phone'], $m['message']);
    $gap = auto_next_gap($m['delay_seconds']);
    $out = 'pending';
    switch ($res['result']) {
        case 'ok':
        case 'maybe':
            q(
                "UPDATE campaign_messages SET status = 'sent', sent_at = ?, error = ?, provider_msg_id = ?, locked_at = NULL, locked_by = NULL WHERE id = ?",
                [now_dt(), $res['result'] === 'maybe' ? 'غير مؤكد: انقطع الاتصال بالخدمة أثناء الإرسال' : null, isset($res['id']) ? $res['id'] : null, (int) $m['id']]
            );
            $out = 'sent';
            break;
        case 'invalid':
            q(
                "UPDATE campaign_messages SET status = 'failed', sent_at = ?, error = ?, locked_at = NULL, locked_by = NULL WHERE id = ?",
                [now_dt(), str_cut($res['note'], 255), (int) $m['id']]
            );
            $out = 'failed';
            break;
        case 'retry':
            $attempts = (int) $m['attempts'] + 1;
            if ($attempts >= AUTO_MAX_ATTEMPTS) {
                q(
                    "UPDATE campaign_messages SET status = 'failed', sent_at = ?, attempts = ?, error = ?, locked_at = NULL, locked_by = NULL WHERE id = ?",
                    [now_dt(), $attempts, str_cut('فشل بعد ' . $attempts . ' محاولات: ' . $res['note'], 255), (int) $m['id']]
                );
                $out = 'failed';
            } else {
                // تتأجل لآخر الدور حتى ما يوقف رقم واحد باقي الأرقام
                q(
                    'UPDATE campaign_messages SET attempts = ?, error = ?, skips = LEAST(skips + 1, 250), locked_at = NULL, locked_by = NULL WHERE id = ?',
                    [$attempts, str_cut($res['note'], 255), (int) $m['id']]
                );
            }
            $gap = max($gap, 60);   // نهدّي دقيقة قبل المحاولة الجاية من هذا الرقم
            q('UPDATE wa_accounts SET api_checked_at = NULL WHERE id = ?', [(int) $acc['id']]);   // نتأكد من حالة الحساب بالتشغيل الجاي
            break;
        default:   // account: مشكلة بالحساب نفسه — الرسالة ترجع للدور بدون ما تنحسب محاولة
            q('UPDATE campaign_messages SET locked_at = NULL, locked_by = NULL WHERE id = ?', [(int) $m['id']]);
            account_store_state($acc, ['state' => isset($res['state']) ? $res['state'] : 'error', 'note' => isset($res['note']) ? $res['note'] : '']);
            break;
    }
    q('UPDATE wa_accounts SET next_send_at = ? WHERE id = ?', [now_dt($gap), (int) $acc['id']]);
    if ($out === 'sent' || $out === 'failed') {
        $campaign = q_row('SELECT * FROM campaigns WHERE id = ?', [(int) $m['campaign_id']]);
        if ($campaign) {
            campaign_after_final($campaign, (int) $m['day_index']);
        }
    }
    return $out;
}

/**
 * الخدمة تبلّغ لاحقاً إذا رسالة ما وصلت (الرقم ما عنده واتساب) — نحدّث سجل
 * آخر ساعة ونص لكل حساب، مرة كل 10 دقايق، ولحد 3 حسابات بكل تشغيل.
 */
function auto_sync_statuses($maxAccounts = 3)
{
    $rows = q_all(
        "SELECT a.* FROM wa_accounts a
         WHERE a.provider <> 'manual' AND a.api_state = 'connected' AND (a.sync_at IS NULL OR a.sync_at < ?)
           AND EXISTS (SELECT 1 FROM campaign_messages m WHERE m.account_id = a.id AND m.status = 'sent'
                       AND m.provider_msg_id IS NOT NULL AND m.sent_at >= ?)
         ORDER BY a.sync_at LIMIT " . (int) $maxAccounts,
        [now_dt(-600), now_dt(-5400)]
    );
    foreach ($rows as $acc) {
        q('UPDATE wa_accounts SET sync_at = ? WHERE id = ?', [now_dt(), (int) $acc['id']]);
        $map = gw_failed_statuses($acc);
        if (!$map) {
            continue;
        }
        foreach (array_chunk(array_keys($map), 200) as $chunk) {
            $chunk = array_map('strval', $chunk);
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $msgs = q_all(
                "SELECT id, provider_msg_id FROM campaign_messages WHERE account_id = ? AND status = 'sent' AND provider_msg_id IN ($in)",
                array_merge([(int) $acc['id']], $chunk)
            );
            foreach ($msgs as $m) {
                q("UPDATE campaign_messages SET status = 'failed', error = ? WHERE id = ? AND status = 'sent'", [$map[$m['provider_msg_id']], (int) $m['id']]);
            }
        }
    }
}

/** بعد كم ثانية يصير فيه رسالة تلقائية جاهزة (0 = هسه)، أو null إذا ما فيه شي. */
function auto_next_in()
{
    $v = q_val(
        "SELECT MIN(COALESCE(a.next_send_at, '2000-01-01 00:00:00')) FROM wa_accounts a
         WHERE a.provider <> 'manual' AND a.api_state = 'connected' AND EXISTS (
            SELECT 1 FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
            WHERE m.account_id = a.id AND m.status = 'pending' AND m.scheduled_at <= ?
              AND c.status = 'active' AND c.paused = 0)",
        [now_dt()]
    );
    return $v === null ? null : max(0, strtotime($v) - time());
}

/**
 * تشغيل واحد للإرسال التلقائي لحد $budget ثانية. يرجّع: busy (تشغيل ثاني
 * شغّال هسه)، sent، failed، next_in (ثواني لحد الرسالة الجاية أو null).
 */
function auto_send_run($budget)
{
    $started = microtime(true);
    $result = ['ok' => true, 'busy' => false, 'sent' => 0, 'failed' => 0, 'next_in' => null];
    if (!auto_try_lock()) {
        $result['busy'] = true;
        return $result;
    }
    try {
        // رسائل حجزها تشغيل سابق وانقطع بنصه (القفل صار عندنا، فأكيد ما أحد يرسلها هسه)
        q("UPDATE campaign_messages SET locked_at = NULL, locked_by = NULL WHERE locked_by = 'auto' AND status = 'pending'");
        auto_sync_statuses();
        $checked = [];
        while (microtime(true) - $started < $budget - 1) {
            $accounts = auto_due_accounts();
            $ready = null;
            $wait = null;
            foreach ($accounts as $acc) {
                if (!isset($checked[$acc['id']]) && auto_state_stale($acc)) {
                    $checked[$acc['id']] = true;
                    $acc = account_store_state($acc, gw_check_state($acc));
                }
                if ($acc['api_state'] !== 'connected') {
                    continue;
                }
                $in = $acc['next_send_at'] ? strtotime($acc['next_send_at']) - time() : 0;
                if ($in <= 0) {
                    $ready = $acc;
                    break;
                }
                if ($wait === null || $in < $wait) {
                    $wait = $in;
                }
            }
            if ($ready) {
                $m = auto_next_message($ready['id']);
                if (!$m) {
                    // كل المستحق محجوز لجهاز يرسل يدوياً — نرجع له بعد شوي
                    q('UPDATE wa_accounts SET next_send_at = ? WHERE id = ?', [now_dt(15), (int) $ready['id']]);
                    continue;
                }
                $r = auto_send_one($ready, $m);
                if ($r === 'sent') {
                    $result['sent']++;
                } elseif ($r === 'failed') {
                    $result['failed']++;
                }
                continue;
            }
            if ($wait === null) {
                break;   // ما فيه حساب متصل عنده رسائل مستحقة
            }
            $left = $budget - (microtime(true) - $started);
            if ($wait >= $left - 1) {
                break;   // الرسالة الجاية بعد نهاية هذا التشغيل — التشغيل الجاي يكمّل
            }
            // ننتظر بدفعات قصيرة: بعض الاستضافات تقطع اتصال MySQL الخامل بسرعة
            sleep(max(1, min(15, $wait)));
        }
        $result['next_in'] = auto_next_in();
    } finally {
        auto_unlock();
    }
    return $result;
}

/**
 * ملخص للواجهة (كل المنصة): رسائل تلقائية مستحقة هسه — حتى المتصفح المفتوح
 * يكمّل الإرسال إذا الـ Cron مو مفعّل — وهل الـ Cron شغّال. الفرع ياخذ
 * 0 أو 1 بس (حتى ما يعرف حجم شغل الفروع الثانية).
 */
function auto_overview($isAdmin = true)
{
    $due = (int) q_val(
        "SELECT COUNT(*) FROM campaign_messages m
         JOIN campaigns c ON c.id = m.campaign_id
         JOIN wa_accounts a ON a.id = m.account_id
         WHERE m.status = 'pending' AND m.scheduled_at <= ? AND c.status = 'active' AND c.paused = 0
           AND a.provider <> 'manual' AND (a.api_state IS NULL OR a.api_state IN ('connected', 'starting', 'error'))",
        [now_dt()]
    );
    return ['due' => $isAdmin ? $due : min(1, $due), 'cron_ok' => cron_recent()];
}
