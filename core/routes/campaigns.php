<?php
/**
 * حملات الإرسال الجماعي - مقابل routes الحملات بـ webapp.py. الفرق الجوهري: webapp.py يرسل
 * من ثريد بالذاكرة يبقى شغال طول الحملة؛ هنا كل طلب PHP ينتهي فوراً، فالإرسال الفعلي
 * (بفاصل زمني بين كل رقم) يصير بخلفية عبر cron.php (راجعه) - هذا الملف بس يدير الطابور
 * بقاعدة البيانات (campaigns + campaign_numbers) والأوامر (إيقاف/استئناف/إلغاء/استثناء).
 *
 * ناقص حالياً (مؤجّل لجولة قادمة): رفع ملف Excel للأرقام، إرفاق صورة/وسائط بالرسالة
 * (wasel-gateway يرسل نص فقط حالياً)، والجدولة لوقت لاحق (send_at) - نص + فاصل زمني بس.
 */

route('/campaign/preview_numbers', function () {
    if (($r = require_login()) !== null) return $r;
    $text = (string)req_form('numbers_text', '');
    return jsonify(['ok' => true, 'numbers' => numbers_from_text($text)]);
}, ['POST']);

route('/accounts/<accId>/campaign', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $user = current_user();
    if (!$user || !user_has_access($user)) {
        return jsonify(['ok' => false, 'error' => 'انتهت فترتك التجريبية، فعّل الاشتراك من قسم الإعدادات للمتابعة', 'needs_subscription' => true], 402);
    }
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 400);
    $st = gateway_status($accId);
    if (($st['status'] ?? '') !== 'connected') {
        return jsonify(['ok' => false, 'error' => 'تأكد من تسجيل الدخول أولاً'], 400);
    }
    $conn = get_db();
    $active = $conn->execute("SELECT id FROM campaigns WHERE account_id=? AND status IN ('running','paused')", [$accId])->fetchone();
    if ($active) return jsonify(['ok' => false, 'error' => 'فيه حملة شغالة أو موقوفة مؤقتاً حالياً على هذا الحساب'], 400);

    $numbers = numbers_from_text((string)req_form('numbers_text', ''));
    $countryCode = trim((string)req_form('country_code', ''));
    $known = array_column(COUNTRY_CODES, 0);
    if (!in_array($countryCode, $known, true)) $countryCode = DEFAULT_COUNTRY_CODE_FALLBACK;
    $numbers = array_values(array_unique(array_map(fn($n) => apply_country_code($n, $countryCode), $numbers)));
    if (!$numbers) return jsonify(['ok' => false, 'error' => 'ما لقيت أي رقم صالح (اكتب أرقام)'], 400);

    $text = trim((string)req_form('text', '')) ?: 'هلوو';
    $delay = max(1, (int)req_form('delay', 15));

    $ins = $conn->execute(
        'INSERT INTO campaigns (account_id, owner, status, text, delay_seconds, total, next_send_at, started_at, created_at) '
        . "VALUES (?,?,'running',?,?,?,?,?,?)",
        [$accId, $_SESSION['user_id'], $text, $delay, count($numbers), time(), now_str(), now_str()]
    );
    $campaignId = $ins->lastrowid; // لازم تُقرأ من الـ Cursor قبل أي commit (lastInsertId تصفر بعد commit بهذا السائق)
    $conn->commit();
    foreach ($numbers as $n) $conn->execute('INSERT INTO campaign_numbers (campaign_id, phone, status) VALUES (?,?,?)', [$campaignId, $n, 'pending']);
    $conn->commit();

    return jsonify(['ok' => true, 'total' => count($numbers), 'scheduled_for' => null]);
}, ['POST']);

/** يبني شكل استجابة الحالة (نفس حقول acc["campaign"] بـ webapp.py قدر الإمكان) */
function wsl_campaign_state_json(?Row $c): array {
    if (!$c) {
        return ['total' => 0, 'sent' => 0, 'failed' => 0, 'running' => false, 'paused' => false, 'scheduled_for' => null, 'pending_numbers' => []];
    }
    $pending = get_db()->execute("SELECT phone FROM campaign_numbers WHERE campaign_id=? AND status='pending'", [$c['id']])->fetchall();
    return [
        'total' => (int)$c['total'], 'sent' => (int)$c['sent'], 'failed' => (int)$c['failed'],
        'running' => $c['status'] === 'running', 'paused' => $c['status'] === 'paused',
        'scheduled_for' => null, 'text' => $c['text'],
        'pending_numbers' => array_map(fn($r) => $r['phone'], $pending),
    ];
}

function wsl_active_campaign(string $accId): ?Row {
    return get_db()->execute("SELECT * FROM campaigns WHERE account_id=? AND status IN ('running','paused') ORDER BY id DESC LIMIT 1", [$accId])->fetchone();
}

route('/accounts/<accId>/campaign_status', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(wsl_campaign_state_json(null));
    return jsonify(wsl_campaign_state_json(wsl_active_campaign($accId)));
}, ['GET']);

route('/accounts/<accId>/campaigns', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify([]);
    $rows = get_db()->execute(
        "SELECT * FROM campaigns WHERE account_id=? AND status IN ('completed','cancelled') ORDER BY id DESC LIMIT 20", [$accId]
    )->fetchall();
    return jsonify(array_map(fn($c) => [
        'time' => $c['started_at'], 'total' => (int)$c['total'], 'sent' => (int)$c['sent'],
        'failed' => (int)$c['failed'], 'text' => $c['text'],
    ], $rows));
}, ['GET']);

route('/accounts/<accId>/campaign/stop', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 400);
    get_db()->execute("UPDATE campaigns SET status='paused' WHERE account_id=? AND status='running'", [$accId]);
    get_db()->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/accounts/<accId>/campaign/cancel', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 400);
    get_db()->execute("UPDATE campaigns SET status='cancelled' WHERE account_id=? AND status IN ('running','paused')", [$accId]);
    get_db()->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/accounts/<accId>/campaign/resume', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 400);
    $st = gateway_status($accId);
    if (($st['status'] ?? '') !== 'connected') return jsonify(['ok' => false, 'error' => 'تأكد من تسجيل الدخول لهذا الحساب أولاً'], 400);
    $conn = get_db();
    $c = $conn->execute("SELECT * FROM campaigns WHERE account_id=? AND status='paused' ORDER BY id DESC LIMIT 1", [$accId])->fetchone();
    if (!$c) return jsonify(['ok' => false, 'error' => 'ما فيه حملة موقوفة مؤقتاً قابلة للاستئناف على هذا الحساب'], 400);
    $conn->execute("UPDATE campaigns SET status='running', next_send_at=? WHERE id=?", [time(), $c['id']]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/accounts/<accId>/campaign/exclude', function ($accId) {
    if (($r = require_login()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'حساب غير موجود'], 400);
    $number = preg_replace('/\D/', '', (string)(req_data()['number'] ?? ''));
    if ($number === '') return jsonify(['ok' => false, 'error' => 'رقم غير صالح'], 400);
    $c = wsl_active_campaign($accId);
    if ($c) {
        get_db()->execute("UPDATE campaign_numbers SET status='excluded' WHERE campaign_id=? AND phone=? AND status='pending'", [$c['id'], $number]);
        get_db()->commit();
    }
    return jsonify(['ok' => true]);
}, ['POST']);

/* ---------------------------------------------------------------- لوحة المعلومات */

route('/dashboard/stats', function () {
    if (($r = require_login()) !== null) return $r;
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $accTotal = (int)$conn->execute('SELECT COUNT(*) AS n FROM wa_accounts WHERE owner=?', [$uid])->fetchone()['n'];
    $accIds = array_map(fn($r) => $r['id'], $conn->execute('SELECT id FROM wa_accounts WHERE owner=?', [$uid])->fetchall());
    $connected = 0;
    foreach ($accIds as $id) if ((gateway_status($id)['status'] ?? '') === 'connected') $connected++;

    $hist = $accIds ? $conn->execute(
        "SELECT sent, failed FROM campaigns WHERE account_id IN (" . implode(',', array_fill(0, count($accIds), '?')) . ") AND status IN ('completed','cancelled')",
        $accIds
    )->fetchall() : [];
    $sent = array_sum(array_map(fn($h) => (int)$h['sent'], $hist));
    $failed = array_sum(array_map(fn($h) => (int)$h['failed'], $hist));
    $totalMsgs = $sent + $failed;
    return jsonify([
        'accounts_total' => $accTotal, 'accounts_connected' => $connected, 'messages_sent' => $sent,
        'success_rate' => $totalMsgs ? (int)round(($sent / $totalMsgs) * 100) : 0,
        'campaigns_total' => count($hist),
        'campaigns_success' => count(array_filter($hist, fn($h) => (int)$h['failed'] === 0)),
        'campaigns_with_failures' => count(array_filter($hist, fn($h) => (int)$h['failed'] > 0)),
    ]);
}, ['GET']);

route('/dashboard/campaigns', function () {
    if (($r = require_login()) !== null) return $r;
    $rows = get_db()->execute(
        "SELECT c.*, a.name AS account_name FROM campaigns c JOIN wa_accounts a ON a.id=c.account_id "
        . "WHERE c.owner=? AND c.status IN ('completed','cancelled') ORDER BY c.id DESC LIMIT 20",
        [$_SESSION['user_id']]
    )->fetchall();
    return jsonify(array_map(fn($c) => [
        'time' => $c['started_at'], 'total' => (int)$c['total'], 'sent' => (int)$c['sent'],
        'failed' => (int)$c['failed'], 'text' => $c['text'], 'account_name' => $c['account_name'],
    ], $rows));
}, ['GET']);

route('/dashboard/running_campaigns', function () {
    if (($r = require_login()) !== null) return $r;
    $rows = get_db()->execute(
        "SELECT c.*, a.name AS account_name FROM campaigns c JOIN wa_accounts a ON a.id=c.account_id "
        . "WHERE c.owner=? AND c.status IN ('running','paused') ORDER BY c.id DESC",
        [$_SESSION['user_id']]
    )->fetchall();
    return jsonify(array_map(function ($c) {
        return array_merge(['account_id' => $c['account_id'], 'account_name' => $c['account_name']], wsl_campaign_state_json($c));
    }, $rows));
}, ['GET']);
