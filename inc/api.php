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

function api_dispatch($action)
{
    $routes = [
        // الاسم => [الطريقة، الدالة، للأدمن فقط]
        'accounts' => ['GET', 'api_accounts', false],
        'account_create' => ['POST', 'api_account_create', false],
        'account_delete' => ['POST', 'api_account_delete', false],
        'account_pair_code' => ['POST', 'api_account_pair_code', false],
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

function account_public(array $a, $pending = 0, $sentToday = 0)
{
    $state = account_state($a);
    return [
        'id' => (int) $a['id'],
        'name' => $a['name'],
        'state' => $state,
        'connected' => $state === 'online',
        'paired' => !empty($a['agent_key_hash']),
        'last_seen' => $a['last_seen_at'] ? substr($a['last_seen_at'], 0, 16) : null,
        'last_seen_ago' => $a['last_seen_at'] ? max(0, time() - strtotime($a['last_seen_at'])) : null,
        'agent_version' => $a['agent_version'],
        'pending' => (int) $pending,
        'sent_today' => (int) $sentToday,
        'created_at' => substr($a['created_at'], 0, 16),
    ];
}

function api_accounts(array $user)
{
    $rows = q_all('SELECT * FROM wa_accounts WHERE owner_id = ? ORDER BY id', [$user['id']]);
    $today = date('Y-m-d') . ' 00:00:00';
    $items = [];
    foreach ($rows as $a) {
        $pending = q_val(
            "SELECT COUNT(*) FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
             WHERE m.account_id = ? AND m.status IN ('pending','sending') AND c.status = 'active'",
            [$a['id']]
        );
        $sentToday = q_val("SELECT COUNT(*) FROM campaign_messages WHERE account_id = ? AND status = 'sent' AND sent_at >= ?", [$a['id'], $today]);
        $items[] = account_public($a, $pending, $sentToday);
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
    q('INSERT INTO wa_accounts (owner_id, name, wa_state, created_at) VALUES (?, ?, \'new\', ?)', [$user['id'], $name, now_dt()]);
    $acc = q_row('SELECT * FROM wa_accounts WHERE id = ?', [(int) db()->lastInsertId()]);
    json_out(['ok' => true, 'account' => account_public($acc)]);
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

/** رمز ربط يدوي للإضافة (بديل تحميلها من جديد). يولّد مفتاح جديد فيوقف أي ربط سابق. */
function api_account_pair_code(array $user)
{
    $acc = owned_account($user, input_int('id'));
    if (!$acc) {
        json_fail('حساب غير موجود', 404);
    }
    $key = new_agent_key();
    q('UPDATE wa_accounts SET agent_key_hash = ? WHERE id = ?', [agent_key_hash($key), $acc['id']]);
    $payload = json_encode(['u' => base_url(), 'k' => $key, 'n' => $acc['name'], 's' => site_name()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $code = 'WASEL1.' . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    json_out(['ok' => true, 'code' => $code]);
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
        'SELECT c.*, a.name AS account_name, COALESCE(NULLIF(u.name, \'\'), u.email) AS owner_name
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
        'SELECT c.*, a.name AS account_name, COALESCE(NULLIF(u.name, \'\'), u.email) AS owner_name
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
    $acc = q_row('SELECT * FROM wa_accounts WHERE id = ?', [$c['account_id']]);
    $data['account_state'] = $acc ? account_state($acc) : 'deleted';
    $data['can_cancel'] = $c['status'] === 'active';
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
    $result['account_state'] = account_state($acc);
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
    json_out(['ok' => true, 'items' => $items, 'unread' => $unread]);
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
