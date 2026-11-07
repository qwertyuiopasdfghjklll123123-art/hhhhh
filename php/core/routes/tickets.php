<?php
/**
 * إدارة المستخدمين (قائمة/رصيد/حظر) + نظام التذاكر (نص/صوت/صور) للمستخدم والأدمن
 * منقولة من app.py:5102-5595.
 */

/** تنظيف رسائل التذكرة للعرض (مشترك بين كل المسارات) */
function _clean_ticket_msgs(array $msgs): array {
    $clean = [];
    foreach ($msgs as $m) {
        $cm = ['from' => $m['sender'], 'text' => $m['text'], 'time' => fmt_time('%d/%m %H:%M', $m['created_at'])];
        if ($m['msg_type'] === 'voice') {
            $cm['type'] = 'voice';
            $cm['url'] = $m['voice_url'];
            $cm['duration'] = $m['duration'];
        } elseif ($m['msg_type'] === 'image') {
            $cm['type'] = 'image';
            $cm['url'] = $m['voice_url'];
        }
        $clean[] = $cm;
    }
    return $clean;
}

function _ticket_msgs(Conn $conn, $tid): array {
    return $conn->execute('SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY created_at ASC, id ASC', [$tid])->fetchall();
}

function _notify_admins(Conn $conn, string $title, string $text, string $icon, string $goto): void {
    foreach ($conn->execute('SELECT id FROM users WHERE is_admin=1')->fetchall() as $a) {
        push_user_notif($a['id'], 'admin', $title, $text, $icon, $goto);
    }
}

/* ------------------------------- إدارة المستخدمين ------------------------------- */

route('/api/admin/users', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT id,name,email,balance,banned,is_admin,public_id FROM users ORDER BY created_at DESC, id ASC LIMIT 5000')->fetchall();
    $users = [];
    foreach ($rows as $r) {
        $users[] = [
            'id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'],
            'balance' => py_or($r['balance'], 0), 'banned' => py_or($r['banned'], 0),
            'is_admin' => py_or($r['is_admin'], 0),
            'public_id' => $r->has('public_id') ? $r['public_id'] : '',
        ];
    }
    $total = count($users);
    $active = count(array_filter($users, fn($u) => !py_truthy($u['banned'])));
    $banned = count(array_filter($users, fn($u) => py_truthy($u['banned'])));
    return jsonify(['ok' => true, 'users' => $users, 'total' => $total, 'active' => $active, 'banned' => $banned]);
});

route('/api/admin/users/<int:uid>/balance', function ($uid) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_strict();
    $action = dget($data, 'action', '');
    $amount = py_float(dget($data, 'amount', 0));
    if ($amount <= 0) {
        return jsonify(['ok' => false, 'msg' => 'المبلغ يجب أن يكون أكبر من 0']);
    }
    $conn = get_db();
    $user = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone();
    if (!$user) {
        return jsonify(['ok' => false, 'msg' => 'المستخدم غير موجود']);
    }
    $current = py_or($user['balance'], 0);
    if ($action === 'add') {
        $new_bal = $current + $amount;
    } elseif ($action === 'sub') {
        $new_bal = max(0, $current - $amount);
    } else {
        return jsonify(['ok' => false, 'msg' => 'إجراء غير صالح']);
    }
    $conn->execute('UPDATE users SET balance=? WHERE id=?', [$new_bal, $uid]);
    $conn->commit();
    if ($action === 'add') {
        push_user_notif($uid, 'recharge', 'تم إضافة $' . py_fmt($amount) . ' لرصيدك', 'رصيدك الجديد: $' . py_fmt($new_bal), 'wallet');
    } else {
        push_user_notif($uid, 'system', 'تم خصم $' . py_fmt($amount) . ' من رصيدك', 'رصيدك الجديد: $' . py_fmt($new_bal), 'wallet');
    }
    return jsonify(['ok' => true, 'msg' => 'تم تحديث الرصيد', 'new_balance' => $new_bal]);
}, ['POST']);

route('/api/admin/users/<int:uid>/ban', function ($uid) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data_strict();
    $ban = py_truthy(dget($data, 'ban', true)) ? 1 : 0;
    $conn = get_db();
    $conn->execute('UPDATE users SET banned=? WHERE id=?', [$ban, $uid]);
    $conn->commit();
    if ($ban) {
        push_user_notif($uid, 'system', 'تم حظر حسابك ⛔', 'تم حظر حسابك من قبل الإدارة. تواصل مع الدعم للمزيد.', 'lock');
    } else {
        push_user_notif($uid, 'system', 'تم إلغاء حظر حسابك ', 'يمكنك الآن استخدام حسابك بشكل طبيعي.', 'check');
    }
    $msg = $ban ? 'تم حظر المستخدم' : 'تم إلغاء الحظر';
    return jsonify(['ok' => true, 'msg' => $msg]);
}, ['POST']);

/* ------------------------------- تذاكر المستخدم ------------------------------- */

route('/api/tickets', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM tickets WHERE user_id=? ORDER BY created_at DESC, id ASC', [$uid])->fetchall();
    $result = [];
    foreach ($rows as $t) {
        $result[] = [
            'id' => $t['id'], 'subject' => $t['subject'], 'category' => $t['category'],
            'status' => $t['status'], 'date' => fmt_time('%d/%m %H:%M', $t['created_at']),
            'messages' => _clean_ticket_msgs(_ticket_msgs($conn, $t['id'])),
        ];
    }
    return jsonify(['ok' => true, 'tickets' => $result]);
}, ['POST']);

route('/api/tickets/create', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data();
    $subj = py_strip(dget($data, 'subject', ''));
    $desc = py_strip(dget($data, 'description', ''));
    $cat = dget($data, 'category', 'عام');
    if (!py_truthy($subj) || !py_truthy($desc)) {
        return jsonify(['ok' => false, 'error' => 'عبّي كل الحقول']);
    }
    $conn = get_db();
    $cur = $conn->execute('INSERT INTO tickets(user_id,subject,category) VALUES(?,?,?)', [$uid, $subj, sql_text($cat)]);
    $tid = $cur->lastrowid;
    $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)', [$tid, 'user', $desc]);
    $conn->commit();
    push_user_notif($uid, 'system', "تم إنشاء تذكرة #TK-$tid", "$subj — سيتم الرد عليك قريباً", 'ticket', "ticket:$tid");
    _notify_admins($conn, "تذكرة جديدة #TK-$tid", 'من ' . py_str($_SESSION['user_name'] ?? 'مستخدم') . " — $subj", 'ticket', "ticket:$tid");
    return jsonify(['ok' => true, 'ticket_id' => $tid]);
}, ['POST']);

/** تذكرة طلب لعبة/خدمة مخصصة انتهى طلبها (مكتمل/ملغي): تُقفل المحادثة على العميل (الأدمن يبقى يراها) */
function _ticket_locked($conn, $tid): bool {
    try {
        return (bool)$conn->execute("SELECT 1 FROM custom_orders WHERE ticket_id=? AND status IN ('completed','canceled') LIMIT 1", [sql_int($tid)])->fetchone();
    } catch (Throwable $e) { return false; }
}
const TICKET_LOCKED_MSG = 'اكتمل الطلب — المحادثة مغلقة';

route('/api/tickets/chat', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $data = req_data();
    $tid = dget($data, 'ticket_id', '');
    $conn = get_db();
    $t = $conn->execute('SELECT * FROM tickets WHERE id=? AND user_id=?', [sql_int($tid), $_SESSION['user_id']])->fetchone();
    if (!$t) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    if (_ticket_locked($conn, $tid)) return jsonify(['ok' => false, 'error' => 'order_closed', 'msg' => TICKET_LOCKED_MSG]);
    $result = ['id' => $t['id'], 'subject' => $t['subject'], 'category' => $t['category'], 'status' => $t['status'],
        'date' => fmt_time('%d/%m %H:%M', $t['created_at']), 'messages' => _clean_ticket_msgs(_ticket_msgs($conn, sql_int($tid)))];
    return jsonify(['ok' => true, 'ticket' => $result]);
}, ['POST']);

route('/api/tickets/reply', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $data = req_data();
    $tid = dget($data, 'ticket_id', '');
    $msg = py_strip(dget($data, 'message', ''));
    if (!py_truthy($msg)) {
        return jsonify(['ok' => false, 'error' => 'empty']);
    }
    $conn = get_db();
    $t = $conn->execute('SELECT * FROM tickets WHERE id=? AND user_id=?', [sql_int($tid), $_SESSION['user_id']])->fetchone();
    if (!$t) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    if (_ticket_locked($conn, $tid)) return jsonify(['ok' => false, 'error' => 'order_closed', 'msg' => TICKET_LOCKED_MSG]);
    $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)', [sql_int($tid), 'user', $msg]);
    $conn->execute('UPDATE tickets SET status=? WHERE id=?', ['waiting', sql_int($tid)]);
    $conn->commit();
    _notify_admins($conn, 'رد جديد على تذكرة #TK-' . py_str($tid), 'من ' . py_str($_SESSION['user_name'] ?? 'مستخدم'), 'message', 'ticket:' . py_str($tid));
    return jsonify(['ok' => true]);
}, ['POST']);

/** حفظ رسالة صوتية/صورة بقاعدة بيانات التذكرة (مشترك بين المستخدم والأدمن) */
function _ticket_voice_common(bool $admin) {
    if ($admin ? !_is_admin_session() : !array_key_exists('user_id', $_SESSION)) {
        return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $data = req_data();
    $tid = dget($data, 'ticket_id', '');
    $audio = dget($data, 'audio', '');
    $dur = dget($data, 'duration', 0);
    if (!py_truthy($audio) || !py_truthy($tid)) {
        return jsonify(['ok' => false, 'error' => 'missing data']);
    }
    if (py_len($audio) > 5000000) {
        return jsonify(['ok' => false, 'error' => 'too large']);
    }
    $conn = get_db();
    $t = $admin
        ? $conn->execute('SELECT * FROM tickets WHERE id=?', [sql_int($tid)])->fetchone()
        : $conn->execute('SELECT * FROM tickets WHERE id=? AND user_id=?', [sql_int($tid), $_SESSION['user_id']])->fetchone();
    if (!$t) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    if (!$admin && _ticket_locked($conn, $tid)) return jsonify(['ok' => false, 'error' => 'order_closed', 'msg' => TICKET_LOCKED_MSG]);
    $fname = 'v_' . py_str($tid) . '_' . substr(bin2hex(random_bytes(16)), 0, 8) . '.webm';
    try {
        file_put_contents(VOICE_DIR . '/' . $fname, py_b64decode($audio));
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'error' => 'invalid audio']);
    }
    $voice_url = '/api/voice/' . $fname;
    $sender = $admin ? 'admin' : 'user';
    $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,msg_type,voice_url,duration) VALUES(?,?,?,?,?)',
        [sql_int($tid), $sender, 'voice', $voice_url, $dur]);
    $conn->execute('UPDATE tickets SET status=? WHERE id=?', [$admin ? 'replied' : 'waiting', sql_int($tid)]);
    $conn->commit();
    if (!$admin) {
        _notify_admins($conn, 'رسالة صوتية في تذكرة #TK-' . py_str($tid), 'من ' . py_str($_SESSION['user_name'] ?? 'مستخدم'), 'message', 'ticket:' . py_str($tid));
    }
    return jsonify(['ok' => true, 'voice_url' => $voice_url]);
}

function _ticket_image_common(bool $admin) {
    if ($admin ? !_is_admin_session() : !array_key_exists('user_id', $_SESSION)) {
        return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $data = req_data();
    $tid = dget($data, 'ticket_id', '');
    $image = dget($data, 'image', '');
    $caption = py_strip(dget($data, 'caption', ''));
    if (!py_truthy($image) || !py_truthy($tid)) {
        return jsonify(['ok' => false, 'error' => 'missing data']);
    }
    if (py_len($image) > 8000000) {
        return jsonify(['ok' => false, 'error' => 'too large']);
    }
    $conn = get_db();
    $t = $admin
        ? $conn->execute('SELECT * FROM tickets WHERE id=?', [sql_int($tid)])->fetchone()
        : $conn->execute('SELECT * FROM tickets WHERE id=? AND user_id=?', [sql_int($tid), $_SESSION['user_id']])->fetchone();
    if (!$t) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    if (!$admin && _ticket_locked($conn, $tid)) return jsonify(['ok' => false, 'error' => 'order_closed', 'msg' => TICKET_LOCKED_MSG]);
    $ext = 'jpg';
    $head = py_slice((string)$image, null, 50);
    if (strpos($head, 'image/png') !== false) $ext = 'png';
    elseif (strpos($head, 'image/gif') !== false) $ext = 'gif';
    elseif (strpos($head, 'image/webp') !== false) $ext = 'webp';
    if (strpos($image, ',') !== false) $image = explode(',', $image, 2)[1];
    $fname = 'ti_' . py_str($tid) . '_' . substr(bin2hex(random_bytes(16)), 0, 8) . '.' . $ext;
    try {
        file_put_contents(TK_IMG_DIR . '/' . $fname, py_b64decode($image));
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'error' => 'invalid image']);
    }
    $img_url = '/api/ticket-img/' . $fname;
    $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,msg_type,voice_url,text) VALUES(?,?,?,?,?)',
        [sql_int($tid), $admin ? 'admin' : 'user', 'image', $img_url, $caption]);
    $conn->execute('UPDATE tickets SET status=? WHERE id=?', [$admin ? 'replied' : 'waiting', sql_int($tid)]);
    $conn->commit();
    if ($admin) {
        push_user_notif($t['user_id'], 'system', 'صورة في تذكرة #TK-' . py_str($tid), py_or($caption, 'تم إرسال صورة'), 'message', 'ticket:' . py_str($tid));
    } else {
        _notify_admins($conn, 'صورة في تذكرة #TK-' . py_str($tid), 'من ' . py_str($_SESSION['user_name'] ?? 'مستخدم'), 'message', 'ticket:' . py_str($tid));
    }
    return jsonify(['ok' => true, 'img_url' => $img_url]);
}

route('/api/tickets/voice', fn() => _ticket_voice_common(false), ['POST']);
route('/api/admin/tickets/voice', fn() => _ticket_voice_common(true), ['POST']);
route('/api/tickets/image', fn() => _ticket_image_common(false), ['POST']);
route('/api/admin/tickets/image', fn() => _ticket_image_common(true), ['POST']);

route('/api/voice/<fname>', function ($fname) {
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.webm$/D', $fname)) return make_response('not found', 404);
    $fpath = VOICE_DIR . '/' . $fname;
    if (!file_exists($fpath)) return make_response('not found', 404);
    return send_file($fpath, 'audio/webm');
});

route('/api/ticket-img/<fname>', function ($fname) {
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$/Di', $fname)) return make_response('not found', 404);
    $fpath = TK_IMG_DIR . '/' . $fname;
    if (!file_exists($fpath)) return make_response('not found', 404);
    $ext = strtolower(substr($fname, strrpos($fname, '.') + 1));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';
    $resp = send_file($fpath, $mime);
    $resp->headers['Cache-Control'] = 'public, max-age=31536000';
    return $resp;
});

/* ------------------------------- تذاكر الأدمن ------------------------------- */

route('/api/admin/tickets', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $conn = get_db();
    $rows = $conn->execute('SELECT t.*, u.name as user_name, u.email as user_email FROM tickets t LEFT JOIN users u ON t.user_id=u.id ORDER BY t.created_at DESC, t.id ASC LIMIT 2000')->fetchall();
    $result = [];
    foreach ($rows as $t) {
        $clean = _clean_ticket_msgs(_ticket_msgs($conn, $t['id']));
        $result[] = [
            'id' => $t['id'], 'uid' => $t['user_id'], 'subject' => $t['subject'],
            'category' => $t['category'], 'status' => $t['status'],
            'date' => fmt_time('%d/%m %H:%M', $t['created_at']),
            'user_name' => py_or($t['user_name'], ''), 'user_email' => py_or($t['user_email'], ''),
            'messages' => $clean, 'msg_count' => count($clean),
        ];
    }
    return jsonify(['ok' => true, 'tickets' => $result]);
}, ['POST']);

route('/api/admin/tickets/chat', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $data = req_data();
    $tid = dget($data, 'ticket_id', '');
    $conn = get_db();
    $t = $conn->execute('SELECT t.*, u.name as user_name, u.email as user_email FROM tickets t LEFT JOIN users u ON t.user_id=u.id WHERE t.id=?', [sql_int($tid)])->fetchone();
    if (!$t) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    $result = ['id' => $t['id'], 'uid' => $t['user_id'], 'subject' => $t['subject'], 'category' => $t['category'],
        'status' => $t['status'], 'date' => fmt_time('%d/%m %H:%M', $t['created_at']),
        'user_name' => py_or($t['user_name'], ''), 'user_email' => py_or($t['user_email'], ''),
        'messages' => _clean_ticket_msgs(_ticket_msgs($conn, sql_int($tid)))];
    return jsonify(['ok' => true, 'ticket' => $result]);
}, ['POST']);

route('/api/admin/tickets/reply', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $data = req_data();
    $tid = dget($data, 'ticket_id', '');
    $msg = py_strip(dget($data, 'message', ''));
    if (!py_truthy($msg)) {
        return jsonify(['ok' => false, 'error' => 'empty']);
    }
    $conn = get_db();
    $t = $conn->execute('SELECT * FROM tickets WHERE id=?', [sql_int($tid)])->fetchone();
    if (!$t) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)', [sql_int($tid), 'admin', $msg]);
    $conn->execute('UPDATE tickets SET status=? WHERE id=?', ['replied', sql_int($tid)]);
    $conn->commit();
    push_user_notif($t['user_id'], 'system', 'رد على تذكرتك #TK-' . py_str($tid), $msg, 'message', 'ticket:' . py_str($tid));
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/admin/tickets/close', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $data = req_data();
    $tid = dget($data, 'ticket_id', '');
    $conn = get_db();
    $t = $conn->execute('SELECT * FROM tickets WHERE id=?', [sql_int($tid)])->fetchone();
    if (!$t) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    $conn->execute('UPDATE tickets SET status=? WHERE id=?', ['closed', sql_int($tid)]);
    $conn->commit();
    push_user_notif($t['user_id'], 'system', 'تم إغلاق تذكرتك #TK-' . py_str($tid), 'تم إغلاق التذكرة من قبل الإدارة', 'lock', 'ticket:' . py_str($tid));
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/admin/tickets/purge_closed', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $conn = get_db();
    $closed = $conn->execute('SELECT id FROM tickets WHERE status=?', ['closed'])->fetchall();
    $count = count($closed);
    foreach ($closed as $c) {
        $conn->execute('DELETE FROM ticket_messages WHERE ticket_id=?', [$c['id']]);
        $conn->execute('DELETE FROM tickets WHERE id=?', [$c['id']]);
    }
    $conn->commit();
    return jsonify(['ok' => true, 'deleted' => $count]);
}, ['POST']);

route('/api/tickets/purge_closed', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $closed = $conn->execute('SELECT id FROM tickets WHERE user_id=? AND status=?', [$uid, 'closed'])->fetchall();
    $count = count($closed);
    foreach ($closed as $c) {
        $conn->execute('DELETE FROM ticket_messages WHERE ticket_id=?', [$c['id']]);
        $conn->execute('DELETE FROM tickets WHERE id=?', [$c['id']]);
    }
    $conn->commit();
    return jsonify(['ok' => true, 'deleted' => $count]);
}, ['POST']);

route('/api/tickets/delete', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data();
    $ids = dget($data, 'ids', []);
    if (!py_truthy($ids)) {
        return jsonify(['ok' => false, 'msg' => 'لا توجد تذاكر محددة']);
    }
    $conn = get_db();
    $count = 0;
    foreach ((array)$ids as $tid) {
        $q = sql_int($tid);
        $tk = $conn->execute('SELECT id FROM tickets WHERE id=? AND user_id=? AND status=?', [$q, $uid, 'closed'])->fetchone();
        if ($tk) {
            $conn->execute('DELETE FROM ticket_messages WHERE ticket_id=?', [$q]);
            $conn->execute('DELETE FROM tickets WHERE id=?', [$q]);
            $count += 1;
        }
    }
    $conn->commit();
    return jsonify(['ok' => true, 'deleted' => $count]);
}, ['POST']);
