<?php
/**
 * المشتركين (CRM) - مقابل routes /subscribers و /subscription_settings بـ webapp.py.
 * هذا عن مشتركي المستخدم نفسه (عملاؤه)، لا علاقة له باشتراك المستخدم بمنصة واصل.
 */

function wsl_find_sending_account(int $ownerId): ?string {
    $rows = get_db()->execute('SELECT id FROM wa_accounts WHERE owner=?', [$ownerId])->fetchall();
    foreach ($rows as $r) if ((gateway_status($r['id'])['status'] ?? '') === 'connected') return $r['id'];
    return null;
}

route('/subscribers', function () {
    if (($r = require_login()) !== null) return $r;
    $today = new DateTime('today');
    $rows = get_db()->execute('SELECT * FROM subscribers WHERE owner=? ORDER BY id DESC', [$_SESSION['user_id']])->fetchall();
    $out = [];
    foreach ($rows as $row) {
        $expiryStr = null; $daysLeft = null;
        try {
            $expiry = (new DateTime($row['subscribed_at']))->modify('+' . (int)$row['duration_days'] . ' days');
            $daysLeft = (int)$today->diff($expiry)->format('%r%a');
            $expiryStr = $expiry->format('Y-m-d');
        } catch (Exception $e) {}
        $out[] = [
            'id' => (int)$row['id'], 'name' => $row['name'], 'phone' => $row['phone'],
            'subscribed_at' => $row['subscribed_at'], 'duration_days' => (int)$row['duration_days'],
            'expiry' => $expiryStr, 'days_left' => $daysLeft,
        ];
    }
    return jsonify($out);
}, ['GET']);

route('/subscribers', function () {
    if (($r = require_login()) !== null) return $r;
    $data = req_data();
    $name = mb_substr(trim((string)($data['name'] ?? '')), 0, 60, 'UTF-8');
    $phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
    if ($name === '' || strlen($phone) < 8) return jsonify(['ok' => false, 'error' => 'أدخل اسم ورقم واتساب صحيحين'], 400);
    $subscribedAt = trim((string)($data['subscribed_at'] ?? '')) ?: date('Y-m-d');
    if (!DateTime::createFromFormat('Y-m-d', $subscribedAt)) return jsonify(['ok' => false, 'error' => 'تاريخ الاشتراك غير صحيح'], 400);

    $settings = get_db()->execute('SELECT * FROM subscription_settings WHERE owner=?', [$_SESSION['user_id']])->fetchone();
    $defaultDuration = $settings ? (int)$settings['default_duration_days'] : 30;
    $durationRaw = $data['duration_days'] ?? null;
    $duration = ($durationRaw === null || $durationRaw === '') ? $defaultDuration : (int)$durationRaw;
    if ($duration < 1) return jsonify(['ok' => false, 'error' => 'عدد أيام الاشتراك لازم يكون رقم أكبر من صفر'], 400);

    $conn = get_db();
    $ins = $conn->execute('INSERT INTO subscribers (owner, name, phone, subscribed_at, duration_days, created_at) VALUES (?,?,?,?,?,?)',
        [$_SESSION['user_id'], $name, $phone, $subscribedAt, $duration, now_str()]);
    $subId = (int)$ins->lastrowid;
    $conn->commit();

    $sender = wsl_find_sending_account($_SESSION['user_id']);
    if ($sender) {
        $msg = ($settings['start_message'] ?? '') ?: 'مرحباً {name}، تم تفعيل اشتراكك بنجاح لمدة {days} يوم.';
        $msg = str_replace(['{name}', '{days}'], [$name, (string)$duration], $msg);
        try { gateway_send($sender, $phone, $msg); } catch (GatewayException $e) {
            log_app_error('WARNING', 'subscriber start message failed: ' . $e->getMessage());
        }
    }
    return jsonify(['ok' => true, 'id' => $subId]);
}, ['POST']);

route('/subscribers/<int:subId>/delete', function ($subId) {
    if (($r = require_login()) !== null) return $r;
    get_db()->execute('DELETE FROM subscribers WHERE id=? AND owner=?', [$subId, $_SESSION['user_id']]);
    get_db()->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/subscribers/<int:subId>/renew', function ($subId) {
    if (($r = require_login()) !== null) return $r;
    $sub = get_db()->execute('SELECT * FROM subscribers WHERE id=? AND owner=?', [$subId, $_SESSION['user_id']])->fetchone();
    if (!$sub) return jsonify(['ok' => false, 'error' => 'الاشتراك غير موجود'], 404);
    $data = req_data();
    $subscribedAt = trim((string)($data['subscribed_at'] ?? '')) ?: date('Y-m-d');
    if (!DateTime::createFromFormat('Y-m-d', $subscribedAt)) return jsonify(['ok' => false, 'error' => 'تاريخ الاشتراك غير صحيح'], 400);
    $durationRaw = $data['duration_days'] ?? null;
    $duration = ($durationRaw === null || $durationRaw === '') ? (int)$sub['duration_days'] : (int)$durationRaw;
    if ($duration < 1) return jsonify(['ok' => false, 'error' => 'عدد أيام الاشتراك لازم يكون رقم أكبر من صفر'], 400);
    get_db()->execute('UPDATE subscribers SET subscribed_at=?, duration_days=?, reminder_sent=0, expired_sent=0 WHERE id=?',
        [$subscribedAt, $duration, $subId]);
    get_db()->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/subscription_settings', function () {
    if (($r = require_login()) !== null) return $r;
    $row = get_db()->execute('SELECT * FROM subscription_settings WHERE owner=?', [$_SESSION['user_id']])->fetchone();
    return jsonify([
        'default_duration_days' => $row ? (int)$row['default_duration_days'] : 30,
        'reminder_days_before' => $row ? (int)$row['reminder_days_before'] : 3,
        'start_message' => ($row['start_message'] ?? '') ?: 'مرحباً {name}، تم تفعيل اشتراكك بنجاح لمدة {days} يوم.',
        'reminder_message' => ($row['reminder_message'] ?? '') ?: 'مرحباً {name}، اشتراكك سينتهي خلال {days} أيام. تواصل معنا للتجديد.',
        'expired_message' => ($row['expired_message'] ?? '') ?: 'مرحباً {name}، اشتراكك انتهى اليوم. تواصل معنا للتجديد.',
    ]);
}, ['GET']);

route('/subscription_settings', function () {
    if (($r = require_login()) !== null) return $r;
    $data = req_data();
    $defaultDuration = (int)($data['default_duration_days'] ?? 30);
    $reminderDays = (int)($data['reminder_days_before'] ?? 3);
    if ($defaultDuration < 1 || $reminderDays < 0) return jsonify(['ok' => false, 'error' => 'القيم غير صحيحة'], 400);
    $start = mb_substr(trim((string)($data['start_message'] ?? '')), 0, 500, 'UTF-8') ?: 'مرحباً {name}، تم تفعيل اشتراكك بنجاح لمدة {days} يوم.';
    $reminder = mb_substr(trim((string)($data['reminder_message'] ?? '')), 0, 500, 'UTF-8') ?: 'مرحباً {name}، اشتراكك سينتهي خلال {days} أيام. تواصل معنا للتجديد.';
    $expired = mb_substr(trim((string)($data['expired_message'] ?? '')), 0, 500, 'UTF-8') ?: 'مرحباً {name}، اشتراكك انتهى اليوم. تواصل معنا للتجديد.';
    $conn = get_db();
    $conn->execute(
        "INSERT INTO subscription_settings (owner, default_duration_days, reminder_days_before, start_message, reminder_message, expired_message) "
        . "VALUES (?,?,?,?,?,?) ON CONFLICT(owner) DO UPDATE SET default_duration_days=excluded.default_duration_days, "
        . "reminder_days_before=excluded.reminder_days_before, start_message=excluded.start_message, "
        . "reminder_message=excluded.reminder_message, expired_message=excluded.expired_message",
        [$_SESSION['user_id'], $defaultDuration, $reminderDays, $start, $reminder, $expired]
    );
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);
