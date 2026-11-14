<?php
/**
 * واصل — منطق الحملات وطابور الإرسال.
 *
 * كل حملة تنحفظ كرسائل منفصلة (رقم لكل صف) بجدول campaign_messages ولكل
 * رسالة موعدها (scheduled_at). الإضافة على جهاز الفرع تسأل agent.php كل
 * شوي: "شنو الرسالة الجاية؟" فنعطيها أقدم رسالة مستحقة لحسابها، ونحترم
 * الفاصل الزمني بين الرسائل (next_send_at)، وهي ترجع تبلّغ بالنتيجة.
 * فما نحتاج أي برنامج شغّال بالخلفية على الاستضافة ولا Cron.
 */

require_once __DIR__ . '/core.php';

const ERROR_TEXTS = [
    'invalid_number' => 'الرقم غير مسجّل على واتساب',
    'timeout' => 'ما انفتحت المحادثة خلال المهلة',
    'not_sent' => 'تعذّر تأكيد إرسال الرسالة',
    'no_send_button' => 'تعذّر الضغط على زر الإرسال',
    'not_logged_in' => 'واتساب ويب غير مسجّل الدخول',
];

/**
 * ينشئ حملة ويوزّع أرقامها على المواعيد:
 *  - mode=now: كلها مستحقة فوراً.
 *  - mode=once: كلها بموعد واحد ($opts['send_at']).
 *  - mode=split: تقسيم على أيام (عدد ثابت باليوم أو عدد أيام) بوقت يومي ثابت.
 */
function campaign_create(array $user, array $acc, array $numbers, $bodyText, $link, $delay, array $opts)
{
    $message = $link !== '' ? $bodyText . "\n" . $link : $bodyText;
    $mode = $opts['mode'];
    $schedule = [];   // [day_index => [scheduled_at, numbers[]]]
    $perDay = count($numbers);
    $sendTime = null;

    if ($mode === 'split') {
        $value = (int) $opts['split_value'];
        $perDay = $opts['split_mode'] === 'count' ? $value : (int) ceil(count($numbers) / $value);
        $perDay = max(1, $perDay);
        $sendTime = $opts['send_time'];
        foreach (array_chunk($numbers, $perDay) as $i => $chunk) {
            $date = date('Y-m-d', strtotime($opts['start_date'] . ' +' . $i . ' day'));
            $schedule[$i + 1] = [$date . ' ' . $sendTime . ':00', $chunk];
        }
    } else {
        $at = $mode === 'once' ? $opts['send_at'] : now_dt();
        $schedule[1] = [$at, $numbers];
    }

    $days = count($schedule);
    $startAt = $schedule[1][0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q(
            'INSERT INTO campaigns (owner_id, account_id, body_text, link, message, delay_seconds, mode, total, days, per_day, start_at, send_time, country_code, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)',
            [$user['id'], $acc['id'], $bodyText, $link, $message, $delay, $mode, count($numbers), $days, $perDay, $startAt, $sendTime, $opts['country_code'], now_dt()]
        );
        $campaignId = (int) $pdo->lastInsertId();
        $rows = [];
        foreach ($schedule as $day => $entry) {
            foreach ($entry[1] as $phone) {
                $rows[] = [$campaignId, (int) $acc['id'], $phone, $day, $entry[0]];
            }
        }
        foreach (array_chunk($rows, 400) as $batch) {
            $sql = 'INSERT INTO campaign_messages (campaign_id, account_id, phone, day_index, scheduled_at, status) VALUES '
                . implode(',', array_fill(0, count($batch), "(?, ?, ?, ?, ?, 'pending')"));
            q($sql, array_merge(...$batch));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if ($mode === 'split') {
        notify(
            $user['id'],
            'تمت جدولة حملة مقسّمة على أيام',
            count($numbers) . ' رقم على ' . $days . ' يوم (~' . $perDay . ' يومياً)، ابتداءً من ' . substr($startAt, 0, 10) . ' الساعة ' . $sendTime,
            'info',
            $acc['name']
        );
    } elseif ($mode === 'once') {
        notify($user['id'], 'تمت جدولة حملة', count($numbers) . ' رسالة بموعد ' . substr($startAt, 0, 16), 'info', $acc['name']);
    }

    return [
        'id' => $campaignId,
        'total' => count($numbers),
        'mode' => $mode,
        'days' => $days,
        'per_day' => $perDay,
        'start_at' => substr($startAt, 0, 16),
    ];
}

/** أعداد رسائل حملة (أو مجموعة حملات) حسب الحالة. */
function campaign_counts(array $campaignIds)
{
    $out = [];
    if (!$campaignIds) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($campaignIds), '?'));
    $rows = q_all(
        "SELECT campaign_id,
                SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed,
                SUM(status IN ('pending','sending')) AS pending, SUM(status = 'cancelled') AS cancelled,
                MIN(CASE WHEN status = 'pending' THEN scheduled_at END) AS next_at
         FROM campaign_messages WHERE campaign_id IN ($in) GROUP BY campaign_id",
        $campaignIds
    );
    foreach ($rows as $r) {
        $out[(int) $r['campaign_id']] = [
            'sent' => (int) $r['sent'],
            'failed' => (int) $r['failed'],
            'pending' => (int) $r['pending'],
            'cancelled' => (int) $r['cancelled'],
            'next_at' => $r['next_at'] ? substr($r['next_at'], 0, 16) : null,
        ];
    }
    return $out;
}

/** صيغة موحّدة لعرض الحملة بالواجهة. */
function campaign_public(array $c, array $counts)
{
    $k = isset($counts[(int) $c['id']]) ? $counts[(int) $c['id']] : ['sent' => 0, 'failed' => 0, 'pending' => 0, 'cancelled' => 0, 'next_at' => null];
    $status = $c['status'];
    if ($status === 'active' && $k['sent'] + $k['failed'] === 0 && $k['next_at'] && strtotime($k['next_at']) > time()) {
        $status = 'scheduled';
    }
    return [
        'id' => (int) $c['id'],
        'account_id' => (int) $c['account_id'],
        'account_name' => isset($c['account_name']) ? $c['account_name'] : '',
        'owner_name' => isset($c['owner_name']) ? $c['owner_name'] : '',
        'text' => $c['body_text'],
        'link' => $c['link'],
        'message' => $c['message'],
        'mode' => $c['mode'],
        'total' => (int) $c['total'],
        'days' => (int) $c['days'],
        'per_day' => (int) $c['per_day'],
        'send_time' => $c['send_time'],
        'delay' => (int) $c['delay_seconds'],
        'start_at' => substr($c['start_at'], 0, 16),
        'created_at' => substr($c['created_at'], 0, 16),
        'finished_at' => $c['finished_at'] ? substr($c['finished_at'], 0, 16) : null,
        'status' => $status,
        'sent' => $k['sent'],
        'failed' => $k['failed'],
        'pending' => $k['pending'],
        'cancelled' => $k['cancelled'],
        'next_at' => $k['next_at'],
    ];
}

/** يقفل الحملة كـ"مكتملة" إذا ما بقى فيها أي رسالة بانتظار الإرسال، ويرسل إشعار. */
function campaign_check_done($campaignId)
{
    $c = q_row('SELECT c.*, a.name AS account_name FROM campaigns c LEFT JOIN wa_accounts a ON a.id = c.account_id WHERE c.id = ?', [(int) $campaignId]);
    if (!$c || $c['status'] !== 'active') {
        return;
    }
    $left = (int) q_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND status IN ('pending','sending')", [(int) $campaignId]);
    if ($left > 0) {
        return;
    }
    $done = q("UPDATE campaigns SET status = 'done', finished_at = ? WHERE id = ? AND status = 'active'", [now_dt(), (int) $campaignId]);
    if ($done->rowCount() === 1) {
        $k = campaign_counts([(int) $campaignId]);
        $k = isset($k[(int) $campaignId]) ? $k[(int) $campaignId] : ['sent' => 0, 'failed' => 0];
        $total = $k['sent'] + $k['failed'];
        notify(
            $c['owner_id'],
            'اكتملت الحملة',
            'نجح ' . $k['sent'] . ' من ' . $total . '، فشل ' . $k['failed'],
            $k['failed'] === 0 ? 'success' : 'warning',
            $c['account_name']
        );
    }
}

function campaign_cancel($campaignId)
{
    $upd = q("UPDATE campaigns SET status = 'cancelled', finished_at = ? WHERE id = ? AND status = 'active'", [now_dt(), (int) $campaignId]);
    q("UPDATE campaign_messages SET status = 'cancelled', locked_at = NULL WHERE campaign_id = ? AND status = 'pending'", [(int) $campaignId]);
    return $upd->rowCount() === 1;
}

function campaign_cancel_day($campaignId, $day)
{
    $n = q(
        "UPDATE campaign_messages SET status = 'cancelled' WHERE campaign_id = ? AND day_index = ? AND status = 'pending'",
        [(int) $campaignId, (int) $day]
    )->rowCount();
    campaign_check_done($campaignId);
    return $n;
}

/** تفاصيل أيام حملة مقسّمة: لكل يوم موعده وأعداده. */
function campaign_day_rows($campaignId)
{
    $rows = q_all(
        "SELECT day_index, MIN(scheduled_at) AS scheduled_at, COUNT(*) AS total,
                SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed,
                SUM(status IN ('pending','sending')) AS pending, SUM(status = 'cancelled') AS cancelled,
                SUM(status = 'sending') AS sending
         FROM campaign_messages WHERE campaign_id = ? GROUP BY day_index ORDER BY day_index",
        [(int) $campaignId]
    );
    $out = [];
    foreach ($rows as $r) {
        $pending = (int) $r['pending'];
        $sentOrFailed = (int) $r['sent'] + (int) $r['failed'];
        if ($pending === 0) {
            $state = ((int) $r['cancelled'] > 0 && $sentOrFailed === 0) ? 'cancelled' : 'done';
        } elseif ((int) $r['sending'] > 0 || $sentOrFailed > 0 || strtotime($r['scheduled_at']) <= time()) {
            $state = 'running';
        } else {
            $state = 'pending';
        }
        $out[] = [
            'day' => (int) $r['day_index'],
            'scheduled_at' => substr($r['scheduled_at'], 0, 16),
            'total' => (int) $r['total'],
            'sent' => (int) $r['sent'],
            'failed' => (int) $r['failed'],
            'pending' => $pending,
            'cancelled' => (int) $r['cancelled'],
            'state' => $state,
        ];
    }
    return $out;
}

// --------------------------------------------------------------- طابور الإضافة (agent.php)

function agent_account_by_key($key)
{
    if (!is_string($key) || !preg_match('/^[a-f0-9]{40}$/', $key)) {
        return null;
    }
    return q_row('SELECT * FROM wa_accounts WHERE agent_key_hash = ?', [agent_key_hash($key)]);
}

/** يسجّل تواصل الإضافة وحالة واتساب ويب عندها، ويرسل إشعار عند الربط أول مرة أو عند طلب QR. */
function agent_touch(array $acc, $state, $version)
{
    $allowed = ['ready', 'qr', 'loading', 'conflict', 'paused', 'no_tab'];
    $state = in_array($state, $allowed, true) ? $state : 'loading';
    q(
        'UPDATE wa_accounts SET last_seen_at = ?, wa_state = ?, agent_version = ? WHERE id = ?',
        [now_dt(), $state, str_cut(preg_replace('/[^0-9A-Za-z.\-]/', '', (string) $version), 20), $acc['id']]
    );
    if (empty($acc['last_seen_at'])) {
        notify($acc['owner_id'], 'تم ربط جهاز الإرسال', 'إضافة واصل صارت متصلة بهذا الحساب', 'success', $acc['name']);
    } elseif ($state === 'qr' && $acc['wa_state'] !== 'qr') {
        notify($acc['owner_id'], 'واتساب ويب يحتاج مسح رمز QR', 'افتح تبويب واتساب ويب على جهاز الإرسال وامسح الرمز من جوالك حتى تكمل الحملات', 'warning', $acc['name']);
    }
    $acc['wa_state'] = $state;
    $acc['last_seen_at'] = now_dt();
    return $acc;
}

/** يرجّع رسائل علقت "قيد الإرسال" (الإضافة انقطعت بالنص) للطابور، أو يفشّلها بعد عدة محاولات. */
function agent_recover_stale(array $acc)
{
    $stale = q_all(
        "SELECT id, campaign_id, attempts FROM campaign_messages WHERE account_id = ? AND status = 'sending' AND locked_at < ?",
        [$acc['id'], now_dt(-AGENT_LOCK_SECONDS)]
    );
    foreach ($stale as $m) {
        if ((int) $m['attempts'] >= MAX_ATTEMPTS) {
            q("UPDATE campaign_messages SET status = 'failed', error = ?, locked_at = NULL WHERE id = ? AND status = 'sending'", [ERROR_TEXTS['timeout'], $m['id']]);
            campaign_check_done($m['campaign_id']);
        } else {
            q("UPDATE campaign_messages SET status = 'pending', locked_at = NULL WHERE id = ? AND status = 'sending'", [$m['id']]);
        }
    }
}

/**
 * الرسالة الجاية لهذا الحساب، أو مدة انتظار بالثواني. إذا فيه رسالة لسا
 * "قيد الإرسال" نرجّعها نفسها (الإضافة يمكن انعادت تحميلها وضيّعتها).
 */
function agent_next(array $acc)
{
    agent_recover_stale($acc);

    $current = q_row(
        "SELECT m.id, m.phone, c.message, c.status AS campaign_status FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
         WHERE m.account_id = ? AND m.status = 'sending' ORDER BY m.locked_at DESC LIMIT 1",
        [$acc['id']]
    );
    if ($current && $current['campaign_status'] === 'active') {
        return ['job' => ['id' => (int) $current['id'], 'phone' => $current['phone'], 'text' => $current['message']]];
    }
    if ($current) {
        // الحملة انلغت والإضافة ضيّعت الرسالة قبل ما ترسلها — ما نرسلها
        q("UPDATE campaign_messages SET status = 'cancelled', locked_at = NULL WHERE id = ? AND status = 'sending'", [$current['id']]);
    }

    if (!empty($acc['next_send_at'])) {
        $wait = strtotime($acc['next_send_at']) - time();
        if ($wait > 0) {
            return ['wait' => min(max($wait, 2), 120), 'reason' => 'delay'];
        }
    }

    $pdo = db();
    $now = now_dt();
    $pdo->beginTransaction();
    try {
        $m = q_row(
            "SELECT m.id, m.phone, m.campaign_id, m.day_index, m.attempts, c.message, c.days, c.owner_id
             FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
             WHERE m.account_id = ? AND m.status = 'pending' AND m.scheduled_at <= ? AND c.status = 'active'
             ORDER BY m.scheduled_at, m.id LIMIT 1 FOR UPDATE",
            [$acc['id'], $now]
        );
        if ($m) {
            q("UPDATE campaign_messages SET status = 'sending', locked_at = ?, attempts = attempts + 1 WHERE id = ?", [$now, $m['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (!$m) {
        $next = q_val(
            "SELECT MIN(m.scheduled_at) FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
             WHERE m.account_id = ? AND m.status = 'pending' AND c.status = 'active'",
            [$acc['id']]
        );
        $wait = $next ? strtotime($next) - time() : 60;
        return ['wait' => min(max($wait, 5), 60), 'reason' => $next ? 'scheduled' : 'idle'];
    }

    // أول محاولة لأول رسالة بيوم/حملة = إشعار "بدأ الإرسال"
    $started = (int) q_val(
        "SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND day_index = ? AND id <> ? AND (attempts > 0 OR status IN ('sent','failed'))",
        [$m['campaign_id'], $m['day_index'], $m['id']]
    );
    if ($started === 0 && (int) $m['attempts'] === 0) {
        $dayTotal = (int) q_val('SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND day_index = ?', [$m['campaign_id'], $m['day_index']]);
        if ((int) $m['days'] > 1) {
            notify($m['owner_id'], 'بدأ يوم من حملة مجدولة', 'اليوم ' . $m['day_index'] . '/' . $m['days'] . ' — جارِ إرسال ' . $dayTotal . ' رسالة', 'info', $acc['name']);
        } else {
            notify($m['owner_id'], 'بدأت حملة جديدة', 'جارِ إرسال ' . $dayTotal . ' رسالة', 'info', $acc['name']);
        }
    }

    return ['job' => ['id' => (int) $m['id'], 'phone' => $m['phone'], 'text' => $m['message']]];
}

/** نتيجة إرسال رسالة من الإضافة. */
function agent_report(array $acc, $jobId, $ok, $code, $error)
{
    $m = q_row(
        'SELECT m.*, c.delay_seconds, c.owner_id, c.days FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
         WHERE m.id = ? AND m.account_id = ?',
        [(int) $jobId, $acc['id']]
    );
    if (!$m) {
        return ['ignored' => true];
    }
    $now = now_dt();
    $becameFinal = false;   // هل هذا التبليغ هو اللي حسم مصير الرسالة (حتى ما نكرر الإشعارات)
    if ($ok) {
        $becameFinal = $m['status'] !== 'sent';
        q("UPDATE campaign_messages SET status = 'sent', sent_at = ?, error = NULL, locked_at = NULL WHERE id = ?", [$now, $m['id']]);
    } elseif ($m['status'] === 'sending' || $m['status'] === 'pending') {
        $text = array_key_exists((string) $code, ERROR_TEXTS) ? ERROR_TEXTS[(string) $code] : (trim((string) $error) !== '' ? str_cut($error, 200) : 'فشل الإرسال');
        if ($code === 'not_logged_in') {
            // مو ذنب الرقم — نرجّعها للطابور بدون ما نحسبها محاولة
            q("UPDATE campaign_messages SET status = 'pending', attempts = GREATEST(attempts, 1) - 1, locked_at = NULL WHERE id = ?", [$m['id']]);
        } elseif ($code === 'invalid_number' || (int) $m['attempts'] >= MAX_ATTEMPTS) {
            $becameFinal = true;
            q("UPDATE campaign_messages SET status = 'failed', error = ?, locked_at = NULL WHERE id = ?", [$text, $m['id']]);
        } else {
            q("UPDATE campaign_messages SET status = 'pending', error = ?, locked_at = NULL, scheduled_at = ? WHERE id = ?", [$text, now_dt(60), $m['id']]);
        }
    }

    // الفاصل الزمني قبل الرسالة الجاية (مع تفاوت عشوائي بسيط حتى ما يبين الإرسال آلي)
    $delay = max(MIN_DELAY, (int) $m['delay_seconds']);
    $jitter = random_int(0, (int) ceil($delay * 0.3));
    if (!$ok && $code === 'invalid_number') {
        $delay = MIN_DELAY;
        $jitter = 0;
    }
    q('UPDATE wa_accounts SET next_send_at = ? WHERE id = ?', [now_dt($delay + $jitter), $acc['id']]);

    // إشعار انتهاء يوم من حملة مقسّمة (غير اليوم الأخير — له إشعار "اكتملت الحملة")
    if ((int) $m['days'] > 1 && (int) $m['day_index'] < (int) $m['days']) {
        $left = (int) q_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND day_index = ? AND status IN ('pending','sending')", [$m['campaign_id'], $m['day_index']]);
        if ($left === 0 && $becameFinal) {
            $k = q_row("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM campaign_messages WHERE campaign_id = ? AND day_index = ?", [$m['campaign_id'], $m['day_index']]);
            $sent = (int) $k['sent'];
            $failed = (int) $k['failed'];
            notify(
                $m['owner_id'],
                'اكتمل يوم من حملة مجدولة',
                'اليوم ' . $m['day_index'] . '/' . $m['days'] . ' — نجح ' . $sent . ' من ' . ($sent + $failed) . '، فشل ' . $failed,
                $failed === 0 ? 'success' : 'warning',
                $acc['name']
            );
        }
    }
    campaign_check_done($m['campaign_id']);
    return ['recorded' => true];
}

// --------------------------------------------------------------- إحصائيات

/** إحصائيات لوحة المعلومات: لمستخدم واحد، أو للمنصة كلها (null). */
function stats_for($ownerId)
{
    $where = $ownerId === null ? '1=1' : 'owner_id = ?';
    $params = $ownerId === null ? [] : [(int) $ownerId];

    $accounts = q_all("SELECT * FROM wa_accounts WHERE $where", $params);
    $online = 0;
    foreach ($accounts as $a) {
        if (account_state($a) === 'online') {
            $online++;
        }
    }
    $msg = q_row(
        "SELECT SUM(m.status = 'sent') AS sent, SUM(m.status = 'failed') AS failed, SUM(m.status IN ('pending','sending') AND c.status = 'active') AS pending
         FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id WHERE " . ($ownerId === null ? '1=1' : 'c.owner_id = ?'),
        $params
    );
    $sent = (int) $msg['sent'];
    $failed = (int) $msg['failed'];

    $camp = q_all("SELECT id, status FROM campaigns WHERE $where", $params);
    $ids = array_map(function ($c) {
        return (int) $c['id'];
    }, $camp);
    $counts = campaign_counts($ids);
    $success = 0;
    $withFailures = 0;
    $active = 0;
    foreach ($camp as $c) {
        $k = isset($counts[(int) $c['id']]) ? $counts[(int) $c['id']] : ['failed' => 0];
        if ($c['status'] === 'active') {
            $active++;
        } elseif ($c['status'] === 'done') {
            if ($k['failed'] > 0) {
                $withFailures++;
            } else {
                $success++;
            }
        }
    }
    $today = date('Y-m-d') . ' 00:00:00';
    $sentToday = (int) q_val(
        "SELECT COUNT(*) FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id WHERE m.status = 'sent' AND m.sent_at >= ? AND " . ($ownerId === null ? '1=1' : 'c.owner_id = ?'),
        array_merge([$today], $params)
    );
    return [
        'accounts_total' => count($accounts),
        'accounts_connected' => $online,
        'messages_sent' => $sent,
        'messages_failed' => $failed,
        'messages_pending' => (int) $msg['pending'],
        'sent_today' => $sentToday,
        'success_rate' => ($sent + $failed) > 0 ? (int) round($sent * 100 / ($sent + $failed)) : 0,
        'campaigns_total' => count($camp),
        'campaigns_active' => $active,
        'campaigns_success' => $success,
        'campaigns_with_failures' => $withFailures,
    ];
}

/** ترتيب الأدمن والفروع حسب نسبة نجاح الإرسال. */
function leaderboard()
{
    $rows = q_all(
        "SELECT u.id, u.name, u.email, u.is_admin,
                SUM(m.status = 'sent') AS sent, SUM(m.status = 'failed') AS failed
         FROM users u
         JOIN campaigns c ON c.owner_id = u.id
         JOIN campaign_messages m ON m.campaign_id = c.id
         GROUP BY u.id, u.name, u.email, u.is_admin"
    );
    $out = [];
    foreach ($rows as $r) {
        $sent = (int) $r['sent'];
        $failed = (int) $r['failed'];
        if ($sent + $failed === 0) {
            continue;
        }
        $out[] = [
            'user_id' => (int) $r['id'],
            'name' => $r['name'] ?: $r['email'],
            'email' => $r['email'],
            'is_branch' => !(int) $r['is_admin'],
            'sent' => $sent,
            'failed' => $failed,
            'total' => $sent + $failed,
            'success_rate' => (int) round($sent * 100 / ($sent + $failed)),
        ];
    }
    usort($out, function ($a, $b) {
        if ($a['success_rate'] !== $b['success_rate']) {
            return $b['success_rate'] - $a['success_rate'];
        }
        return $b['total'] - $a['total'];
    });
    return array_slice($out, 0, 20);
}
