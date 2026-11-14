<?php
/**
 * واصل — منطق الحملات والإرسال بالضغط.
 *
 * كل حملة تنحفظ كرسائل منفصلة (رقم لكل صف) بجدول campaign_messages ولكل
 * رسالة موعدها (scheduled_at). الفرع يفتح الحملة من جواله أو كمبيوتره ويضغط
 * "إرسال": واتساب ينفتح والرقم والرسالة جاهزين، يضغط إرسال بواتساب، والموقع
 * يعرض الرقم الجاي تلقائياً. ما يحتاج أي تثبيت ولا خدمة خارجية ولا Cron.
 *
 * الرقم المعروض ينحجز للجهاز اللي عرضه (SEND_LOCK_SECONDS) حتى لو اثنين من
 * الفرع يرسلون نفس الحملة سوا ما ينرسل نفس الرقم مرتين.
 */

require_once __DIR__ . '/core.php';

/**
 * ينشئ حملة ويوزّع أرقامها على المواعيد:
 *  - mode=now: كلها جاهزة للإرسال فوراً.
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
    // الحملة الفورية ما تحتاج إشعار "صار موعدها" — المستخدم قدّامها هسه
    $notifiedDay = $mode === 'now' ? 1 : 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q(
            'INSERT INTO campaigns (owner_id, account_id, body_text, link, message, delay_seconds, mode, total, days, per_day, start_at, send_time, country_code, status, notified_day, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?)',
            [$user['id'], $acc['id'], $bodyText, $link, $message, $delay, $mode, count($numbers), $days, $perDay, $startAt, $sendTime, $opts['country_code'], $notifiedDay, now_dt()]
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
        notify($user['id'], 'تمت جدولة حملة', count($numbers) . ' رسالة جاهزة للإرسال بموعد ' . substr($startAt, 0, 16), 'info', $acc['name']);
    }

    return [
        'id' => $campaignId,
        'total' => count($numbers),
        'mode' => $mode,
        'days' => $days,
        'per_day' => $perDay,
        'start_at' => substr($startAt, 0, 16),
        'due' => $mode === 'now' ? count($numbers) : (strtotime($startAt) <= time() ? count($schedule[1][1]) : 0),
    ];
}

/** أعداد رسائل مجموعة حملات حسب الحالة، ومنها "المستحقة الآن" (جاهزة للإرسال). */
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
                SUM(status = 'pending') AS pending, SUM(status = 'cancelled') AS cancelled,
                SUM(status = 'pending' AND scheduled_at <= ?) AS due,
                MIN(CASE WHEN status = 'pending' AND scheduled_at > ? THEN scheduled_at END) AS next_at
         FROM campaign_messages WHERE campaign_id IN ($in) GROUP BY campaign_id",
        array_merge([now_dt(), now_dt()], $campaignIds)
    );
    foreach ($rows as $r) {
        $out[(int) $r['campaign_id']] = [
            'sent' => (int) $r['sent'],
            'failed' => (int) $r['failed'],
            'pending' => (int) $r['pending'],
            'cancelled' => (int) $r['cancelled'],
            'due' => (int) $r['due'],
            'next_at' => $r['next_at'] ? substr($r['next_at'], 0, 16) : null,
        ];
    }
    return $out;
}

/** صيغة موحّدة لعرض الحملة بالواجهة. */
function campaign_public(array $c, array $counts)
{
    $empty = ['sent' => 0, 'failed' => 0, 'pending' => 0, 'cancelled' => 0, 'due' => 0, 'next_at' => null];
    $k = isset($counts[(int) $c['id']]) ? $counts[(int) $c['id']] : $empty;
    $status = $c['status'];
    if ($status === 'active' && $k['due'] === 0 && $k['next_at']) {
        $status = 'scheduled';   // ما فيه شي جاهز هسه — بانتظار موعد اليوم الجاي
    }
    return [
        'id' => (int) $c['id'],
        'account_id' => (int) $c['account_id'],
        'account_name' => isset($c['account_name']) ? $c['account_name'] : '',
        'account_phone' => isset($c['account_phone']) ? $c['account_phone'] : null,
        'owner_id' => (int) $c['owner_id'],
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
        'due' => $k['due'],
        'next_at' => $k['next_at'],
    ];
}

/** يقفل الحملة كـ"مكتملة" إذا ما بقى فيها أي رقم بانتظار الإرسال، ويرسل إشعار. */
function campaign_check_done($campaignId)
{
    $c = q_row('SELECT c.*, a.name AS account_name FROM campaigns c LEFT JOIN wa_accounts a ON a.id = c.account_id WHERE c.id = ?', [(int) $campaignId]);
    if (!$c || $c['status'] !== 'active') {
        return;
    }
    $left = (int) q_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND status = 'pending'", [(int) $campaignId]);
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
            'انرسل ' . $k['sent'] . ' من ' . $total . (($k['failed'] > 0) ? '، و' . $k['failed'] . ' رقم ما عنده واتساب' : ''),
            $k['failed'] === 0 ? 'success' : 'warning',
            $c['account_name']
        );
    }
}

function campaign_cancel($campaignId)
{
    $upd = q("UPDATE campaigns SET status = 'cancelled', finished_at = ? WHERE id = ? AND status = 'active'", [now_dt(), (int) $campaignId]);
    q("UPDATE campaign_messages SET status = 'cancelled', locked_at = NULL, locked_by = NULL WHERE campaign_id = ? AND status = 'pending'", [(int) $campaignId]);
    return $upd->rowCount() === 1;
}

function campaign_cancel_day($campaignId, $day)
{
    $n = q(
        "UPDATE campaign_messages SET status = 'cancelled', locked_at = NULL, locked_by = NULL WHERE campaign_id = ? AND day_index = ? AND status = 'pending'",
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
                SUM(status = 'pending') AS pending, SUM(status = 'cancelled') AS cancelled
         FROM campaign_messages WHERE campaign_id = ? GROUP BY day_index ORDER BY day_index",
        [(int) $campaignId]
    );
    $out = [];
    foreach ($rows as $r) {
        $pending = (int) $r['pending'];
        $sentOrFailed = (int) $r['sent'] + (int) $r['failed'];
        if ($pending === 0) {
            $state = ((int) $r['cancelled'] > 0 && $sentOrFailed === 0) ? 'cancelled' : 'done';
        } elseif (strtotime($r['scheduled_at']) <= time()) {
            $state = 'ready';
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

// --------------------------------------------------------------- الإرسال بالضغط

/**
 * الرقم الجاي الجاهز للإرسال بهذه الحملة لهذا التبويب. إذا التبويب عنده رقم
 * محجوز (مثلاً حدّث الصفحة) نرجّعه نفسه، وإلا نحجز أقدم رقم مستحق ومو محجوز
 * لجهاز ثاني.
 */
function sender_claim($campaignId, $token)
{
    $now = now_dt();
    $lockLimit = now_dt(-SEND_LOCK_SECONDS);
    // الرقم المعروض حالياً بهذا الجهاز = آخر رقم انحجز له (مثلاً بعد تراجع)، وأي رقم ثاني محجوز له نحرره
    $mine = q_row(
        "SELECT id, phone, day_index FROM campaign_messages
         WHERE campaign_id = ? AND status = 'pending' AND scheduled_at <= ? AND locked_by = ? AND locked_at >= ?
         ORDER BY locked_at DESC, id DESC LIMIT 1",
        [(int) $campaignId, $now, $token, $lockLimit]
    );
    if ($mine) {
        q('UPDATE campaign_messages SET locked_at = ? WHERE id = ?', [$now, $mine['id']]);
        q(
            "UPDATE campaign_messages SET locked_by = NULL, locked_at = NULL WHERE campaign_id = ? AND locked_by = ? AND id <> ? AND status = 'pending'",
            [(int) $campaignId, $token, $mine['id']]
        );
        return $mine;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $m = q_row(
            "SELECT id, phone, day_index FROM campaign_messages
             WHERE campaign_id = ? AND status = 'pending' AND scheduled_at <= ?
               AND (locked_by IS NULL OR locked_at IS NULL OR locked_at < ? OR locked_by = ?)
             ORDER BY skips, scheduled_at, id LIMIT 1 FOR UPDATE",
            [(int) $campaignId, $now, $lockLimit, $token]
        );
        if ($m) {
            q('UPDATE campaign_messages SET locked_at = ?, locked_by = ? WHERE id = ?', [$now, $token, $m['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $m;
}

/** حالة شاشة الإرسال: الرقم الجاي + الأعداد + موعد الدفعة الجاية. */
function sender_state(array $campaign, $token)
{
    $next = $campaign['status'] === 'active' ? sender_claim($campaign['id'], $token) : null;
    $c = campaign_public($campaign, campaign_counts([(int) $campaign['id']]));
    $busyElsewhere = 0;
    if (!$next && $c['due'] > 0) {
        // كل المستحق محجوز حالياً بأجهزة ثانية من نفس الفرع
        $busyElsewhere = $c['due'];
    }
    $nextBatch = null;
    if (!$next && $c['next_at']) {
        $nextBatch = (int) q_val(
            "SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND status = 'pending' AND scheduled_at = ?",
            [$campaign['id'], $c['next_at'] . ':00']
        );
    }
    $todayDone = 0;
    $todayTotal = 0;
    if ($next) {
        $day = q_row(
            "SELECT COUNT(*) AS total, SUM(status IN ('sent','failed')) AS done FROM campaign_messages WHERE campaign_id = ? AND day_index = ?",
            [$campaign['id'], $next['day_index']]
        );
        $todayDone = (int) $day['done'];
        $todayTotal = (int) $day['total'];
    }
    return [
        'ok' => true,
        'campaign' => $c,
        'next' => $next ? ['id' => (int) $next['id'], 'phone' => $next['phone'], 'day' => (int) $next['day_index']] : null,
        'batch_done' => $todayDone,
        'batch_total' => $todayTotal,
        'busy_elsewhere' => $busyElsewhere,
        'next_batch' => $nextBatch,
    ];
}

/**
 * يسجّل نتيجة رقم: sent (انفتح بواتساب وانرسل)، failed (ما عنده واتساب)،
 * skip (يتأجل لآخر الدور)، أو undo (تراجع عن آخر تسجيل).
 */
function sender_mark(array $campaign, $messageId, $result, $token)
{
    $m = q_row('SELECT * FROM campaign_messages WHERE id = ? AND campaign_id = ?', [(int) $messageId, (int) $campaign['id']]);
    if (!$m) {
        return 'الرقم غير موجود بهذه الحملة';
    }
    $now = now_dt();
    $recent = $m['sent_at'] && strtotime($m['sent_at']) >= time() - UNDO_SECONDS;
    switch ($result) {
        case 'sent':
            if ($m['status'] !== 'pending') {
                return null;   // تسجيل مكرر (مثلاً ضغطتين) — نتجاهله
            }
            q("UPDATE campaign_messages SET status = 'sent', sent_at = ?, error = NULL, locked_at = NULL, locked_by = NULL WHERE id = ?", [$now, $m['id']]);
            break;
        case 'failed':
            // من الرقم الحالي، أو تصحيح رقم انحسب "انرسل" بس طلع ما عنده واتساب
            if ($m['status'] === 'pending' || ($m['status'] === 'sent' && $recent)) {
                q("UPDATE campaign_messages SET status = 'failed', sent_at = ?, error = ?, locked_at = NULL, locked_by = NULL WHERE id = ?", [$now, 'الرقم ما عنده واتساب', $m['id']]);
            } else {
                return 'ما يمكن تعديل هذا الرقم';
            }
            break;
        case 'skip':
            if ($m['status'] !== 'pending') {
                return null;
            }
            // يتأجل لآخر الدور: الأرقام اللي ما انتخطّت تجي قبله
            q('UPDATE campaign_messages SET skips = LEAST(skips + 1, 250), locked_at = NULL, locked_by = NULL WHERE id = ?', [$m['id']]);
            break;
        case 'undo':
            if (!in_array($m['status'], ['sent', 'failed'], true) || !$recent || $campaign['status'] === 'cancelled') {
                return 'ما يمكن التراجع عن هذا الرقم';
            }
            // نحرر الرقم المعروض حالياً بهذا الجهاز حتى يرجع الرقم المتراجَع عنه هو المعروض
            q(
                "UPDATE campaign_messages SET locked_by = NULL, locked_at = NULL WHERE campaign_id = ? AND locked_by = ? AND status = 'pending'",
                [$campaign['id'], $token]
            );
            q("UPDATE campaign_messages SET status = 'pending', sent_at = NULL, error = NULL, locked_at = ?, locked_by = ? WHERE id = ?", [$now, $token, $m['id']]);
            q("UPDATE campaigns SET status = 'active', finished_at = NULL WHERE id = ? AND status = 'done'", [$campaign['id']]);
            return null;
        default:
            return 'إجراء غير معروف';
    }

    // إشعار انتهاء يوم من حملة مقسّمة (غير اليوم الأخير — له إشعار "اكتملت الحملة")
    if ($m['status'] === 'pending' && ($result === 'sent' || $result === 'failed')
        && (int) $campaign['days'] > 1 && (int) $m['day_index'] < (int) $campaign['days']) {
        $left = (int) q_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND day_index = ? AND status = 'pending'", [$campaign['id'], $m['day_index']]);
        if ($left === 0) {
            $k = q_row("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM campaign_messages WHERE campaign_id = ? AND day_index = ?", [$campaign['id'], $m['day_index']]);
            $accName = q_val('SELECT name FROM wa_accounts WHERE id = ?', [$campaign['account_id']]);
            notify(
                $campaign['owner_id'],
                'خلصت رسائل اليوم ' . $m['day_index'] . '/' . $campaign['days'],
                'انرسل ' . (int) $k['sent'] . ' من ' . ((int) $k['sent'] + (int) $k['failed']) . ' — الدفعة الجاية بموعدها',
                'success',
                $accName
            );
        }
    }
    campaign_check_done($campaign['id']);
    return null;
}

/**
 * ما فيه شي شغّال بالخلفية على الاستضافة، فإشعار "صار موعد الإرسال" ينضاف
 * أول ما المستخدم يفتح الموقع (الواجهة تسأل عن الإشعارات كل شوي).
 */
function notify_due_campaigns($ownerId)
{
    $rows = q_all(
        "SELECT c.id, c.mode, c.days, c.notified_day, a.name AS account_name FROM campaigns c
         LEFT JOIN wa_accounts a ON a.id = c.account_id
         WHERE c.owner_id = ? AND c.status = 'active' AND c.notified_day < c.days",
        [(int) $ownerId]
    );
    if (!$rows) {
        return;
    }
    $ids = array_map(function ($r) {
        return (int) $r['id'];
    }, $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $due = [];
    foreach (q_all("SELECT campaign_id, MAX(day_index) AS day FROM campaign_messages WHERE campaign_id IN ($in) AND scheduled_at <= ? GROUP BY campaign_id", array_merge($ids, [now_dt()])) as $r) {
        $due[(int) $r['campaign_id']] = (int) $r['day'];
    }
    foreach ($rows as $c) {
        $day = isset($due[(int) $c['id']]) ? $due[(int) $c['id']] : 0;
        if ($day <= (int) $c['notified_day']) {
            continue;
        }
        $upd = q('UPDATE campaigns SET notified_day = ? WHERE id = ? AND notified_day < ?', [$day, $c['id'], $day]);
        if ($upd->rowCount() !== 1) {
            continue;   // طلب ثاني سبقنا وأرسل الإشعار
        }
        $count = (int) q_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id = ? AND status = 'pending' AND scheduled_at <= ?", [$c['id'], now_dt()]);
        if ($count === 0) {
            continue;
        }
        $title = (int) $c['days'] > 1 ? 'جاهزة للإرسال: اليوم ' . $day . '/' . $c['days'] : 'حان موعد الحملة';
        notify($ownerId, $title, $count . ' رسالة جاهزة — افتح الحملة واضغط "إرسال"', 'info', $c['account_name']);
    }
}

// --------------------------------------------------------------- إحصائيات

/** إحصائيات لوحة المعلومات: لمستخدم واحد، أو للمنصة كلها (null). */
function stats_for($ownerId)
{
    $where = $ownerId === null ? '1=1' : 'owner_id = ?';
    $params = $ownerId === null ? [] : [(int) $ownerId];

    $accountsTotal = (int) q_val("SELECT COUNT(*) FROM wa_accounts WHERE $where", $params);
    $msg = q_row(
        "SELECT SUM(m.status = 'sent') AS sent, SUM(m.status = 'failed') AS failed,
                SUM(m.status = 'pending' AND c.status = 'active') AS pending,
                SUM(m.status = 'pending' AND c.status = 'active' AND m.scheduled_at <= ?) AS due
         FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id WHERE " . ($ownerId === null ? '1=1' : 'c.owner_id = ?'),
        array_merge([now_dt()], $params)
    );
    $sent = (int) $msg['sent'];
    $failed = (int) $msg['failed'];

    $camp = q_all("SELECT id, status FROM campaigns WHERE $where", $params);
    $counts = campaign_counts(array_map(function ($c) {
        return (int) $c['id'];
    }, $camp));
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
    $sentToday = (int) q_val(
        "SELECT COUNT(*) FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id WHERE m.status = 'sent' AND m.sent_at >= ? AND " . ($ownerId === null ? '1=1' : 'c.owner_id = ?'),
        array_merge([date('Y-m-d') . ' 00:00:00'], $params)
    );
    return [
        'accounts_total' => $accountsTotal,
        'messages_sent' => $sent,
        'messages_failed' => $failed,
        'messages_pending' => (int) $msg['pending'],
        'messages_due' => (int) $msg['due'],
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
