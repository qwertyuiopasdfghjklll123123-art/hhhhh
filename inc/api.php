<?php
/**
 * واصل — واجهة JSON للوحة التحكم (index.php?api=...).
 *
 * كل الطلبات تحتاج تسجيل دخول. طلبات POST تحتاج رمز CSRF بالترويسة
 * X-CSRF-Token. الأقسام الخاصة بالأدمن (الفروع، هوية الموقع، الترتيب،
 * كل الحسابات) مرفوضة لحسابات الفروع — صلاحيات الفرع: حسابات واتساب،
 * الحملات، والإشعارات فقط.
 */

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/autosend.php';

function api_dispatch($action)
{
    $routes = [
        // الاسم => [الطريقة، الدالة، للأدمن فقط]
        'accounts' => ['GET', 'api_accounts', false],
        'account_create' => ['POST', 'api_account_create', false],
        'account_delete' => ['POST', 'api_account_delete', false],
        'account_api_save' => ['POST', 'api_account_api_save', false],
        'account_api_status' => ['GET', 'api_account_api_status', false],
        'account_api_pair' => ['POST', 'api_account_api_pair', false],
        'account_api_logout' => ['POST', 'api_account_api_logout', false],
        'autosend' => ['POST', 'api_autosend', false],
        'campaign_pause' => ['POST', 'api_campaign_pause', false],
        'campaigns' => ['GET', 'api_campaigns', false],
        'campaign' => ['GET', 'api_campaign', false],
        'campaign_create' => ['POST', 'api_campaign_create', false],
        'campaign_cancel' => ['POST', 'api_campaign_cancel', false],
        'campaign_cancel_day' => ['POST', 'api_campaign_cancel_day', false],
        'events' => ['GET', 'api_events', false],
        'event_read' => ['POST', 'api_event_read', false],
        'events_read_all' => ['POST', 'api_events_read_all', false],
        'event_delete' => ['POST', 'api_event_delete', false],
        'dashboard' => ['GET', 'api_dashboard', false],
        'branches' => ['GET', 'api_branches', true],
        'branch_create' => ['POST', 'api_branch_create', true],
        'branch_delete' => ['POST', 'api_branch_delete', true],
        'branch_password' => ['POST', 'api_branch_password', true],
        'admin_users' => ['GET', 'api_admin_users', true],
        'leaderboard' => ['GET', 'api_leaderboard', true],
        'site_identity' => ['GET', 'api_site_identity', true],
        'site_identity_save' => ['POST', 'api_site_identity_save', true],
        'cron_info' => ['GET', 'api_cron_info', true],
        'gateway_info' => ['GET', 'api_gateway_info', true],
        'gateway_save' => ['POST', 'api_gateway_save', true],
        'gateway_key_reset' => ['POST', 'api_gateway_key_reset', true],
        'gateway_download' => ['GET', 'api_gateway_download', true],
        'cron_key_reset' => ['POST', 'api_cron_key_reset', true],
    ];
    if (!isset($routes[$action])) {
        json_fail('طلب غير معروف', 404);
    }
    list($method, $handler, $adminOnly) = $routes[$action];

    $user = current_user();
    if (!$user) {
        json_fail('انتهت الجلسة — سجّل الدخول من جديد', 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_fail('طريقة الطلب غير مسموحة', 405);
    }
    if ($method === 'POST' && !csrf_valid((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        json_fail('انتهت صلاحية الصفحة — حدّثها وحاول مرة ثانية', 419);
    }
    if ($adminOnly && !$user['is_admin']) {
        json_fail('هذا الإجراء لأدمن المنصة فقط', 403);
    }
    if ($method === 'GET') {
        session_write_close();   // طلبات القراءة ما تغيّر الجلسة
    }
    try {
        $handler($user);
    } catch (Throwable $e) {
        error_log('[wasel api ' . $action . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        json_fail('صار خطأ غير متوقع بالسيرفر — حاول مرة ثانية', 500);
    }
}

function text_len($s)
{
    return function_exists('mb_strlen') ? mb_strlen((string) $s, 'UTF-8') : strlen((string) $s);
}

function generate_password($length = 10)
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// --------------------------------------------------------------- حسابات واتساب

function account_public(array $a, array $stats = [])
{
    $state = isset($a['api_state']) && (string) $a['api_state'] !== '' ? $a['api_state'] : 'qr';
    return [
        'id' => (int) $a['id'],
        'name' => $a['name'],
        'phone' => isset($a['phone']) ? $a['phone'] : null,
        'due' => (int) ($stats['due'] ?? 0),
        'pending' => (int) ($stats['pending'] ?? 0),
        'sent_today' => (int) ($stats['sent_today'] ?? 0),
        'campaigns_active' => (int) ($stats['campaigns_active'] ?? 0),
        'created_at' => substr($a['created_at'], 0, 16),
        // حالة الربط بالبوابة الخاصة: connected، qr (يحتاج ربط)، starting، error، auth_error
        'api_state' => $state,
        'api_note' => gw_note_for($state, (string) ($a['api_note'] ?? '')),
        'api_phone' => isset($a['api_phone']) ? $a['api_phone'] : null,
    ];
}

/** اسم جلسة الرقم بالبوابة الخاصة (ثابت للحساب — مخفي عن المتصفح). */
function new_session_id($accountId)
{
    return 'acc' . (int) $accountId . '-' . bin2hex(random_bytes(4));
}

function api_accounts(array $user)
{
    $rows = q_all('SELECT * FROM wa_accounts WHERE owner_id = ? ORDER BY id', [$user['id']]);
    $now = now_dt();
    $today = date('Y-m-d') . ' 00:00:00';
    $items = [];
    foreach ($rows as $a) {
        $s = q_row(
            "SELECT SUM(m.status = 'pending') AS pending, SUM(m.status = 'pending' AND m.scheduled_at <= ?) AS due,
                    COUNT(DISTINCT CASE WHEN m.status = 'pending' THEN c.id END) AS campaigns_active
             FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
             WHERE m.account_id = ? AND c.status = 'active'",
            [$now, $a['id']]
        );
        $s['sent_today'] = q_val("SELECT COUNT(*) FROM campaign_messages WHERE account_id = ? AND status = 'sent' AND sent_at >= ?", [$a['id'], $today]);
        $items[] = account_public($a, $s);
    }
    json_out(['ok' => true, 'items' => $items]);
}

function api_account_create(array $user)
{
    $count = (int) q_val('SELECT COUNT(*) FROM wa_accounts WHERE owner_id = ?', [$user['id']]);
    if ($count >= 30) {
        json_fail('وصلت للحد الأقصى من حسابات واتساب (30)');
    }
    $name = input_str('name', 60);
    if ($name === '') {
        $name = 'حساب ' . ($count + 1);
    }
    $phone = null;
    $rawPhone = input_str('phone', 30);
    if ($rawPhone !== '') {
        $phone = clean_number($rawPhone, DEFAULT_COUNTRY_CODE);
        if ($phone === null) {
            json_fail('رقم الواتساب غير صحيح — اكتبه مثل 07701234567');
        }
    }
    q("INSERT INTO wa_accounts (owner_id, name, phone, provider, created_at) VALUES (?, ?, ?, 'self', ?)", [$user['id'], $name, $phone, now_dt()]);
    $id = (int) db()->lastInsertId();
    q('UPDATE wa_accounts SET api_instance = ? WHERE id = ?', [new_session_id($id), $id]);
    $acc = q_row('SELECT * FROM wa_accounts WHERE id = ?', [$id]);
    json_out(['ok' => true, 'account' => account_public($acc), 'gateway_ready' => self_ready()]);
}

function delete_accounts_data(array $accountIds)
{
    if (!$accountIds) {
        return;
    }
    $in = implode(',', array_fill(0, count($accountIds), '?'));
    q("DELETE FROM campaign_messages WHERE account_id IN ($in)", $accountIds);
    q("DELETE FROM campaigns WHERE account_id IN ($in)", $accountIds);
    q("DELETE FROM wa_accounts WHERE id IN ($in)", $accountIds);
}

function api_account_delete(array $user)
{
    $acc = owned_account($user, input_int('id'));
    if (!$acc) {
        json_fail('حساب غير موجود', 404);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        delete_accounts_data([(int) $acc['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    json_out(['ok' => true]);
}

// --------------------------------------------------------------- الإرسال التلقائي (ربط الحساب بخدمة API)

/**
 * يبدأ ربط الرقم بالبوابة الخاصة: يتأكد إن البوابة شغّالة ويرجّع حالة الرقم،
 * ومعها رمز QR إذا الرقم يحتاج ربط.
 */
function api_account_api_save(array $user)
{
    $acc = owned_account($user, input_int('id'));
    if (!$acc) {
        json_fail('حساب غير موجود', 404);
    }
    if (!self_ready()) {
        json_fail('البوابة الخاصة مو مفعّلة بعد — ' . ($user['is_admin'] ? 'فعّلها من لوحة التحكم ← البوابة الخاصة' : 'اطلب من أدمن المنصة يفعّلها من لوحة التحكم'));
    }
    if ($acc['provider'] !== 'self' || empty($acc['api_instance'])) {
        q("UPDATE wa_accounts SET provider = 'self', api_instance = ?, api_url = NULL, api_token = NULL, api_phone = NULL, next_send_at = NULL WHERE id = ?", [new_session_id($acc['id']), $acc['id']]);
        $acc = q_row('SELECT * FROM wa_accounts WHERE id = ?', [$acc['id']]);
    }
    $st = gw_check_state($acc);
    if ($st['state'] === 'auth_error' || $st['state'] === 'error') {
        account_store_state($acc, $st, false);
        json_fail(gw_note_for($st['state'], $st['note']));
    }
    $out = ['ok' => true, 'state' => $st['state'], 'note' => $st['note'], 'qr' => null];
    if ($st['state'] === 'qr') {
        $qr = gw_qr($acc);
        $out['qr'] = $qr['qr'];
        $st = gw_state($qr['state'], $qr['note'] !== '' ? $qr['note'] : $st['note']);
        $out['state'] = $st['state'];
        $out['note'] = $st['note'];
    }
    account_store_state($acc, $st, false);
    $out['account'] = account_public(q_row('SELECT * FROM wa_accounts WHERE id = ?', [$acc['id']]));
    json_out($out);
}

/** حالة الربط (والواجهة تسأل كل كم ثانية وهي تعرض رمز QR لحد ما يصير "متصل"). */
function api_account_api_status(array $user)
{
    $acc = owned_account($user, (int) ($_GET['id'] ?? 0));
    if (!$acc) {
        json_fail('حساب غير موجود', 404);
    }
    if ($acc['provider'] !== 'self' || empty($acc['api_instance'])) {
        json_out(['ok' => true, 'state' => 'qr', 'note' => '', 'qr' => null, 'account' => account_public($acc)]);
    }
    $st = gw_check_state($acc);
    $out = ['ok' => true, 'state' => $st['state'], 'note' => $st['note'], 'qr' => null];
    if ($st['state'] === 'qr' && !empty($_GET['qr'])) {
        $qr = gw_qr($acc);
        $out['qr'] = $qr['qr'];
        $st = gw_state($qr['state'], $qr['note'] !== '' ? $qr['note'] : $st['note']);
        $out['state'] = $st['state'];
        $out['note'] = $st['note'];
    }
    // الخطأ المؤقت أثناء عرض الرمز ما يغيّر حالة الحساب المحفوظة (يمكن الاتصال تقطّع لحظة)
    if ($st['state'] !== 'error' || $acc['api_state'] !== 'connected') {
        $acc = account_store_state($acc, $st, false);
    }
    $out['note'] = gw_note_for($out['state'], $out['note']);
    $out['account'] = account_public(q_row('SELECT * FROM wa_accounts WHERE id = ?', [$acc['id']]));
    json_out($out);
}

/** ربط برمز من 8 خانات بدل QR — للي فاتح الموقع من نفس الجوال اللي فيه الواتساب (ما يقدر يمسح شاشته). */
function api_account_api_pair(array $user)
{
    $acc = owned_account($user, input_int('id'));
    if (!$acc || $acc['provider'] !== 'self' || empty($acc['api_instance'])) {
        json_fail('حساب غير موجود', 404);
    }
    $phone = clean_number(input_str('phone', 30), DEFAULT_COUNTRY_CODE);
    if ($phone === null) {
        json_fail('اكتب رقم الواتساب اللي تريد تربطه (مثل 07701234567)');
    }
    $r = gw_pair_code($acc, $phone);
    if (!$r['ok']) {
        json_fail(gw_note_for($r['state'], $r['note']));
    }
    if (empty($acc['phone'])) {
        q('UPDATE wa_accounts SET phone = ? WHERE id = ?', [$phone, $acc['id']]);
    }
    json_out(['ok' => true, 'code' => $r['code'], 'phone' => $phone]);
}

/** يفصل رقم الواتساب عن الخدمة (حتى تربط رقم ثاني بنفس الاشتراك). */
function api_account_api_logout(array $user)
{
    $acc = owned_account($user, input_int('id'));
    if (!$acc || $acc['provider'] !== 'self' || empty($acc['api_instance'])) {
        json_fail('حساب غير موجود', 404);
    }
    if (!gw_logout($acc)) {
        json_fail('تعذّر فصل الرقم — حاول مرة ثانية، أو افصله من جوالك: واتساب ← الأجهزة المرتبطة');
    }
    q('UPDATE wa_accounts SET api_phone = NULL WHERE id = ?', [$acc['id']]);
    $acc['api_phone'] = null;
    account_store_state($acc, gw_state('qr', 'تم فصل الرقم — امسح رمز QR لربط رقم'), false);
    json_out(['ok' => true, 'account' => account_public(q_row('SELECT * FROM wa_accounts WHERE id = ?', [$acc['id']]))]);
}

/**
 * المتصفح المفتوح يكمّل الإرسال التلقائي (لما ما فيه Cron): كل طلب يرسل
 * لحد AUTO_TICK_BUDGET ثانية. القفل يضمن تشغيل واحد بس بنفس الوقت.
 */
function api_autosend(array $user)
{
    session_write_close();
    ignore_user_abort(true);
    @set_time_limit(AUTO_TICK_BUDGET + 90);
    $r = auto_send_run(AUTO_TICK_BUDGET);
    json_out($r);
}

// --------------------------------------------------------------- الحملات

function api_campaigns(array $user)
{
    $where = ['c.owner_id = ?'];
    $params = [$user['id']];
    if ($user['is_admin'] && ($_GET['scope'] ?? '') === 'all') {
        $where = ['1=1'];
        $params = [];
    }
    if (!empty($_GET['account'])) {
        $where[] = 'c.account_id = ?';
        $params[] = (int) $_GET['account'];
    }
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $rows = q_all(
        'SELECT c.*, a.name AS account_name, a.phone AS account_phone, a.provider AS account_provider, a.api_state AS account_state,
                COALESCE(NULLIF(u.name, \'\'), u.email) AS owner_name
         FROM campaigns c LEFT JOIN wa_accounts a ON a.id = c.account_id LEFT JOIN users u ON u.id = c.owner_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT ' . $limit,
        $params
    );
    $counts = campaign_counts(array_map(function ($r) {
        return (int) $r['id'];
    }, $rows));
    $items = [];
    foreach ($rows as $r) {
        $items[] = campaign_public($r, $counts);
    }
    json_out(['ok' => true, 'items' => $items]);
}

/** حملة يقدر المستخدم يشوفها: صاحبها، أو الأدمن (يشوف حملات كل الفروع). */
function visible_campaign(array $user, $id)
{
    $c = q_row(
        'SELECT c.*, a.name AS account_name, a.phone AS account_phone, a.provider AS account_provider, a.api_state AS account_state,
                COALESCE(NULLIF(u.name, \'\'), u.email) AS owner_name
         FROM campaigns c LEFT JOIN wa_accounts a ON a.id = c.account_id LEFT JOIN users u ON u.id = c.owner_id WHERE c.id = ?',
        [(int) $id]
    );
    if (!$c || ((int) $c['owner_id'] !== (int) $user['id'] && !$user['is_admin'])) {
        return null;
    }
    return $c;
}

function api_campaign(array $user)
{
    $c = visible_campaign($user, (int) ($_GET['id'] ?? 0));
    if (!$c) {
        json_fail('حملة غير موجودة', 404);
    }
    $data = campaign_public($c, campaign_counts([(int) $c['id']]));
    $data['days_detail'] = campaign_day_rows($c['id']);
    $data['failures'] = q_all(
        "SELECT phone, error FROM campaign_messages WHERE campaign_id = ? AND status = 'failed' ORDER BY id LIMIT 300",
        [$c['id']]
    );
    $data['can_cancel'] = $c['status'] === 'active';
    $data['can_pause'] = $c['status'] === 'active';
    $data['ok'] = true;
    json_out($data);
}

function api_campaign_create(array $user)
{
    $acc = owned_account($user, input_int('account_id'));
    if (!$acc) {
        json_fail('اختر حساب واتساب من حساباتك أولاً');
    }
    $cc = preg_replace('/\D/', '', input_str('country_code', 4));
    if ($cc === '') {
        $cc = DEFAULT_COUNTRY_CODE;
    }

    $numbers = numbers_from_text(input_str('numbers_text', 500000), $cc);
    if (!empty($_FILES['numbers_file']) && (int) $_FILES['numbers_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $numbers = array_merge($numbers, numbers_from_upload($_FILES['numbers_file'], $cc));
        } catch (RuntimeException $e) {
            json_fail($e->getMessage());
        }
    }
    $numbers = array_values(array_unique($numbers));
    if (!$numbers) {
        json_fail('ما لقيت أي رقم صالح (اكتب أرقام أو ارفع ملف Excel)');
    }
    if (count($numbers) > MAX_NUMBERS_PER_CAMPAIGN) {
        json_fail('الحد الأقصى ' . MAX_NUMBERS_PER_CAMPAIGN . ' رقم بالحملة الواحدة — قسّم القائمة على أكثر من حملة');
    }

    $text = input_str('text', 4000);
    if ($text === '') {
        $text = DEFAULT_MESSAGE;
    }
    $link = input_str('link', 1000);
    if ($link !== '' && !preg_match('#^[a-z][a-z0-9+.\-]*://#i', $link)) {
        $link = 'https://' . $link;
    }
    $delay = max(MIN_DELAY, min(3600, input_int('delay', DEFAULT_DELAY)));

    $opts = ['country_code' => $cc];
    $splitMode = input_str('split_mode', 10);
    if ($splitMode === 'count' || $splitMode === 'days') {
        $value = input_int('split_value', 0);
        if ($value < 1) {
            json_fail('أدخل رقم صحيح أكبر من صفر للتقسيم');
        }
        $start = input_str('start_date', 10);
        if ($start === '') {
            $start = date('Y-m-d');
        } else {
            $d = DateTime::createFromFormat('Y-m-d', $start);
            if (!$d || $d->format('Y-m-d') !== $start) {
                json_fail('تاريخ البداية غير صحيح');
            }
        }
        $time = input_str('send_time', 5);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = DEFAULT_SPLIT_TIME;
        }
        $opts += ['mode' => 'split', 'split_mode' => $splitMode, 'split_value' => $value, 'start_date' => $start, 'send_time' => $time];
    } else {
        $opts['mode'] = 'now';
        $sendAt = input_str('send_at', 25);
        if ($sendAt !== '') {
            $ts = strtotime(str_replace('T', ' ', $sendAt));
            if ($ts === false) {
                json_fail('موعد الإرسال غير صحيح');
            }
            if ($ts > time() + 30) {
                $opts['mode'] = 'once';
                $opts['send_at'] = date('Y-m-d H:i:s', $ts);
            }
        }
    }

    $result = campaign_create($user, $acc, $numbers, $text, $link, $delay, $opts);
    $result['ok'] = true;
    json_out($result);
}

function api_campaign_cancel(array $user)
{
    $c = visible_campaign($user, input_int('id'));
    if (!$c) {
        json_fail('حملة غير موجودة', 404);
    }
    if (!campaign_cancel($c['id'])) {
        json_fail('الحملة مو شغّالة حالياً (يمكن خلصت أو انلغت)');
    }
    json_out(['ok' => true]);
}

/** إيقاف مؤقت/استئناف الإرسال التلقائي لحملة. */
function api_campaign_pause(array $user)
{
    $c = visible_campaign($user, input_int('id'));
    if (!$c) {
        json_fail('حملة غير موجودة', 404);
    }
    if ($c['status'] !== 'active') {
        json_fail('الحملة مو شغّالة حالياً (يمكن خلصت أو انلغت)');
    }
    $paused = input_int('paused') ? 1 : 0;
    q('UPDATE campaigns SET paused = ? WHERE id = ?', [$paused, $c['id']]);
    json_out(['ok' => true, 'paused' => (bool) $paused]);
}

function api_campaign_cancel_day(array $user)
{
    $c = visible_campaign($user, input_int('id'));
    if (!$c) {
        json_fail('حملة غير موجودة', 404);
    }
    $n = campaign_cancel_day($c['id'], input_int('day'));
    if ($n === 0) {
        json_fail('ما فيه رسائل بانتظار الإرسال بهذا اليوم (يمكن انرسلت أو انلغت)');
    }
    json_out(['ok' => true, 'cancelled' => $n]);
}

// --------------------------------------------------------------- الإشعارات

function api_events(array $user)
{
    notify_due_campaigns($user['id']);
    $rows = q_all('SELECT * FROM notifications WHERE owner_id = ? ORDER BY id DESC LIMIT 50', [$user['id']]);
    $today = date('Y-m-d');
    $items = [];
    foreach ($rows as $r) {
        $ts = strtotime($r['created_at']);
        $items[] = [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'body' => $r['body'],
            'account' => $r['account_name'],
            'kind' => $r['kind'],
            'read' => (bool) $r['is_read'],
            'time' => date('Y-m-d', $ts) === $today ? date('H:i', $ts) : date('m/d H:i', $ts),
        ];
    }
    $unread = (int) q_val('SELECT COUNT(*) FROM notifications WHERE owner_id = ? AND is_read = 0', [$user['id']]);
    json_out(['ok' => true, 'items' => $items, 'unread' => $unread, 'auto' => auto_overview($user['is_admin'])]);
}

function api_event_read(array $user)
{
    q('UPDATE notifications SET is_read = 1 WHERE id = ? AND owner_id = ?', [input_int('id'), $user['id']]);
    json_out(['ok' => true]);
}

function api_events_read_all(array $user)
{
    q('UPDATE notifications SET is_read = 1 WHERE owner_id = ?', [$user['id']]);
    json_out(['ok' => true]);
}

function api_event_delete(array $user)
{
    q('DELETE FROM notifications WHERE id = ? AND owner_id = ?', [input_int('id'), $user['id']]);
    json_out(['ok' => true]);
}

// --------------------------------------------------------------- لوحة المعلومات

function api_dashboard(array $user)
{
    $stats = stats_for($user['is_admin'] ? null : $user['id']);
    $stats['ok'] = true;
    $stats['scope'] = $user['is_admin'] ? 'platform' : 'own';
    json_out($stats);
}

// --------------------------------------------------------------- الفروع (الأدمن فقط)

function api_branches(array $user)
{
    $rows = q_all(
        'SELECT u.id, u.email, u.name, u.created_at,
                (SELECT COUNT(*) FROM wa_accounts a WHERE a.owner_id = u.id) AS accounts,
                (SELECT COUNT(*) FROM campaigns c WHERE c.owner_id = u.id) AS campaigns
         FROM users u WHERE u.is_admin = 0 ORDER BY u.id'
    );
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'name' => $r['name'],
            'created_at' => substr($r['created_at'], 0, 16),
            'accounts' => (int) $r['accounts'],
            'campaigns' => (int) $r['campaigns'],
        ];
    }
    json_out(['ok' => true, 'items' => $items]);
}

function api_branch_create(array $user)
{
    $name = input_str('name', 120);
    $email = strtolower(input_str('email', 190));
    $password = (string) input('password', '');
    if ($name === '' || $email === '') {
        json_fail('عبّي اسم الفرع والبريد الإلكتروني');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_fail('أدخل بريد إلكتروني صحيح');
    }
    if ($email === strtolower(trim(ADMIN_EMAIL)) || q_val('SELECT id FROM users WHERE email = ?', [$email])) {
        json_fail('هذا البريد مسجّل من قبل');
    }
    if ($password === '') {
        $password = generate_password();
    } elseif (text_len($password) < 6) {
        json_fail('كلمة المرور لازم تكون 6 أحرف على الأقل');
    } elseif (strlen($password) > 72) {
        json_fail('كلمة المرور طويلة جداً');
    }
    q(
        'INSERT INTO users (email, name, password_hash, is_admin, parent_id, created_at) VALUES (?, ?, ?, 0, ?, ?)',
        [$email, $name, password_hash($password, PASSWORD_DEFAULT), $user['id'], now_dt()]
    );
    $id = (int) db()->lastInsertId();
    notify($user['id'], 'تم إنشاء فرع جديد', 'الفرع "' . $name . '" جاهز لتسجيل الدخول بحسابه المستقل', 'success');
    json_out(['ok' => true, 'id' => $id, 'email' => $email, 'password' => $password, 'name' => $name]);
}

function api_branch_delete(array $user)
{
    $branch = q_row('SELECT id, name FROM users WHERE id = ? AND is_admin = 0', [input_int('id')]);
    if (!$branch) {
        json_fail('فرع غير موجود', 404);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $accIds = array_map('intval', array_column(q_all('SELECT id FROM wa_accounts WHERE owner_id = ?', [$branch['id']]), 'id'));
        delete_accounts_data($accIds);
        q('DELETE FROM campaigns WHERE owner_id = ?', [$branch['id']]);
        q('DELETE FROM notifications WHERE owner_id = ?', [$branch['id']]);
        q('DELETE FROM users WHERE id = ? AND is_admin = 0', [$branch['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    json_out(['ok' => true]);
}

/** الأدمن يغيّر كلمة مرور فرع (أو يولّد وحدة جديدة) — والفرع يطلع من كل أجهزته. */
function api_branch_password(array $user)
{
    $branch = q_row('SELECT id, email FROM users WHERE id = ? AND is_admin = 0', [input_int('id')]);
    if (!$branch) {
        json_fail('فرع غير موجود', 404);
    }
    $password = (string) input('password', '');
    if ($password === '') {
        $password = generate_password();
    } elseif (text_len($password) < 6) {
        json_fail('كلمة المرور لازم تكون 6 أحرف على الأقل');
    } elseif (strlen($password) > 72) {
        json_fail('كلمة المرور طويلة جداً');
    }
    q(
        'UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $branch['id']]
    );
    json_out(['ok' => true, 'email' => $branch['email'], 'password' => $password]);
}

function api_admin_users(array $user)
{
    $rows = q_all('SELECT id, email, name, is_admin, created_at FROM users ORDER BY is_admin DESC, id');
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'name' => $r['name'],
            'is_admin' => (bool) $r['is_admin'],
            'created_at' => substr($r['created_at'], 0, 16),
        ];
    }
    json_out(['ok' => true, 'items' => $items]);
}

function api_leaderboard(array $user)
{
    json_out(['ok' => true, 'items' => leaderboard()]);
}

// --------------------------------------------------------------- هوية الموقع (الاسم والشعار)

function api_site_identity(array $user)
{
    $s = site_settings();
    json_out(['ok' => true, 'site_name' => $s['site_name'], 'has_logo' => !empty($s['logo_file']), 'logo_url' => site_logo_url()]);
}

function delete_logo_files($logoFile)
{
    if (!$logoFile) {
        return;
    }
    $dir = WASEL_ROOT . '/uploads/';
    $base = pathinfo($logoFile, PATHINFO_FILENAME);
    foreach ([$logoFile, $base . '-192.png', $base . '-512.png'] as $f) {
        if (preg_match('/^logo-[a-f0-9]+(-\d+)?\.(png|jpg|gif|webp)$/', $f) && is_file($dir . $f)) {
            @unlink($dir . $f);
        }
    }
}

/** أيقونات PWA مربعة (192 و512) من الشعار المرفوع، إذا مكتبة GD متوفرة. */
function make_logo_icons($src, $destBase)
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        return false;
    }
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if (!$img) {
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    foreach ([192, 512] as $size) {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagealphablending($canvas, true);
        $scale = min($size / $w, $size / $h);
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        imagecopyresampled($canvas, $img, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        imagepng($canvas, $destBase . '-' . $size . '.png');
        imagedestroy($canvas);
    }
    imagedestroy($img);
    return true;
}

function api_site_identity_save(array $user)
{
    $name = input_str('site_name', 60);
    if ($name === '') {
        $name = DEFAULT_SITE_NAME;
    }
    $s = site_settings();
    $logo = $s['logo_file'];
    $icons = (int) $s['logo_icons'];

    $file = isset($_FILES['logo']) ? $_FILES['logo'] : null;
    if ($file && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            json_fail(in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'حجم الصورة أكبر من المسموح على الاستضافة'
                : 'فشل رفع الصورة — حاول مرة ثانية');
        }
        if ($file['size'] > LOGO_MAX_BYTES) {
            json_fail('حجم الصورة أكبر من 2 ميغابايت');
        }
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !isset($types[$info[2]])) {
            json_fail('الشعار لازم يكون صورة PNG أو JPG أو WEBP أو GIF');
        }
        $dir = WASEL_ROOT . '/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_writable($dir)) {
            json_fail('مجلد uploads غير قابل للكتابة — خلّي صلاحياته 755 من مدير الملفات بـ cPanel');
        }
        $base = 'logo-' . bin2hex(random_bytes(6));
        $newFile = $base . '.' . $types[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $newFile)) {
            json_fail('تعذّر حفظ الصورة على السيرفر');
        }
        @chmod($dir . '/' . $newFile, 0644);
        delete_logo_files($logo);
        $logo = $newFile;
        $icons = make_logo_icons($dir . '/' . $newFile, $dir . '/' . $base) ? 1 : 0;
    } elseif (input_str('remove_logo', 1) === '1') {
        delete_logo_files($logo);
        $logo = null;
        $icons = 0;
    }

    q('UPDATE site_settings SET site_name = ?, logo_file = ?, logo_icons = ?, updated_at = ? WHERE id = 1', [$name, $logo, $icons, now_dt()]);
    site_settings(true);
    json_out(['ok' => true, 'site_name' => $name, 'has_logo' => (bool) $logo, 'logo_url' => site_logo_url()]);
}

// --------------------------------------------------------------- الإرسال بالخلفية (Cron) — الأدمن

function api_cron_info(array $user)
{
    $s = site_settings(true);
    $last = $s['cron_last_run'];
    $acc = q_row(
        "SELECT COUNT(*) AS auto_total, SUM(provider = 'self' AND api_state = 'connected') AS connected FROM wa_accounts"
    );
    json_out([
        'ok' => true,
        'running' => cron_recent(),
        'last_run' => $last ? substr($last, 0, 16) : null,
        'command' => cron_command(),
        'script' => home_relative(WASEL_ROOT . '/cron.php'),
        'script_abs' => WASEL_ROOT . '/cron.php',
        'panel' => hosting_panel_hint(),
        'url' => base_url() . '/cron.php?key=' . $s['cron_key'],
        'auto_accounts' => (int) $acc['auto_total'],
        'connected_accounts' => (int) $acc['connected'],
        'curl' => function_exists('curl_init'),
    ]);
}

function api_cron_key_reset(array $user)
{
    q('UPDATE site_settings SET cron_key = ? WHERE id = 1', [bin2hex(random_bytes(16))]);
    $s = site_settings(true);
    json_out(['ok' => true, 'url' => base_url() . '/cron.php?key=' . $s['cron_key']]);
}

// --------------------------------------------------------------- البوابة الخاصة (بدون اشتراك) — الأدمن

/** مسار مجلد البوابة نسبةً لمجلد المستخدم بالاستضافة (حقل Application root). */
function self_app_root_hint()
{
    return home_relative(WASEL_ROOT . '/wasel-gateway');
}

function gateway_payload($test = true)
{
    $s = site_settings(true);
    $out = [
        'ok' => true,
        'url' => (string) $s['gateway_url'],
        'configured' => self_ready(),
        'key_hint' => '••••' . substr((string) $s['gateway_key'], -4),
        'key' => (string) $s['gateway_key'],
        'folder' => self_folder() !== null,
        'app_root' => self_app_root_hint(),
        'key_file' => self_folder() !== null && self_write_key_file(),
        'self_accounts' => (int) q_val("SELECT COUNT(*) FROM wa_accounts WHERE provider = 'self'"),
        'panel' => hosting_panel_hint(),
        'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
        'zip' => class_exists('ZipArchive'),
        'status' => null,
    ];
    if ($test && $out['configured']) {
        $out['status'] = self_info();
        if (!empty($out['status']['ok'])) {
            gateway_recheck_accounts(5);
        }
    }
    return $out;
}

function api_gateway_info(array $user)
{
    json_out(gateway_payload(true));
}

function api_gateway_save(array $user)
{
    $url = self_url_normalize(input_str('url', 255));
    if ($url === null) {
        json_fail('الرابط غير صحيح — الصق رابط تطبيق Node.js مثل https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . '/wasel-gw');
    }
    q('UPDATE site_settings SET gateway_url = ? WHERE id = 1', [$url === '' ? null : $url]);
    site_settings(true);
    json_out(gateway_payload(true));
}

function api_gateway_key_reset(array $user)
{
    q('UPDATE site_settings SET gateway_key = ? WHERE id = 1', [bin2hex(random_bytes(20))]);
    site_settings(true);
    json_out(gateway_payload(true));
}

/**
 * ملف البوابة جاهز للرفع (لهوستنجر أو أي سيرفر): app.js و package.json وملف المفتاح
 * السري — فما يحتاج يضيف متغيّرات. للأدمن فقط.
 */
function api_gateway_download(array $user)
{
    $dir = self_folder();
    if (!$dir || !is_file($dir . '/package.json')) {
        json_fail('مجلد البوابة wasel-gateway مو موجود بملفات الموقع — ارفع ملفات النسخة الجديدة كاملة', 404);
    }
    if (!class_exists('ZipArchive')) {
        json_fail('إضافة zip غير مفعّلة بالاستضافة — فعّلها من إعدادات PHP، أو اضغط مجلد wasel-gateway يدوياً من مدير الملفات وأضف متغيّر WASEL_KEY', 500);
    }
    $tmp = @tempnam(sys_get_temp_dir(), 'wgw');
    if ($tmp === false) {
        $tmp = WASEL_ROOT . '/uploads/wgw-' . bin2hex(random_bytes(6)) . '.zip';
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        json_fail('تعذّر تجهيز الملف — حاول مرة ثانية', 500);
    }
    $zip->addFile($dir . '/app.js', 'app.js');
    $zip->addFile($dir . '/package.json', 'package.json');
    list(, $key) = self_conf();
    $zip->addFromString('wasel-key.php', "<?php http_response_code(404); exit; ?>\n" . $key . "\n");
    $zip->addFromString('README.txt', implode("\n", [
        'بوابة واصل الخاصة — إرسال واتساب تلقائي من استضافتك بدون اشتراك',
        '',
        'هوستنجر (hPanel): Websites <- Add Website <- Node.js <- Upload <- ارفع هذا الملف',
        '  Node.js version: 20 أو أحدث | Entry file: app.js | Start command: npm start | Build command: فاضي',
        '  اربطه بدومين فرعي (مثل gw.' . (string) ($_SERVER['HTTP_HOST'] ?? 'example.com') . ') <- Deploy',
        '',
        'cPanel / DirectAdmin: فك الضغط بمجلد، ثم Setup Node.js App:',
        '  Application root = المجلد | Application startup file = app.js <- Create <- Run NPM Install <- Restart',
        '',
        'سيرفر عادي (VPS): npm install && PORT=3000 node app.js',
        '',
        'وبعدها: موقع واصل <- لوحة التحكم <- البوابة الخاصة <- الصق رابط التطبيق <- حفظ واختبار.',
        'ملف wasel-key.php فيه المفتاح السري — لا تشارك هذا الملف مع أحد.',
        '',
    ]));
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="wasel-gateway.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}
