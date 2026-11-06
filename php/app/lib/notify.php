<?php
/**
 * الإشعارات الداخلية (app.py:5800-5883): push_user_notif / send_ai_notif / فحص خمول المستخدمين.
 */

function push_user_notif($user_id, $ntype, $title, $text, $icon = 'bell', $goto = ''): void {
    try {
        $conn = get_db();
        $nid = substr(bin2hex(random_bytes(16)), 0, 8);
        $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon,goto) VALUES(?,?,?,?,?,?,?)',
            [$nid, $user_id, $ntype, $title, $text, $icon, $goto]);
        $conn->commit();
        // الاحتفاظ بآخر 50 إشعاراً فقط لكل مستخدم (LIMIT -1 OFFSET 50 بصيغة MySQL)
        $conn->execute('DELETE FROM notifications WHERE id IN (
            SELECT id FROM (SELECT id FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 18446744073709551615 OFFSET 50) AS _old
        )', [$user_id]);
        $conn->commit();
    } catch (Throwable $e) {
        return;   // مثل الأصل: فشل كتابة الإشعار لا يوقف العملية
    }
    try { send_push($user_id, $title, $text); } catch (Throwable $e) {}
}

/** إشعار من المساعد الذكي: ينشئ محادثة AI جديدة برسالة جاهزة */
function send_ai_notif($user_id, $title, $text): void {
    if (get_setting('ai_notif_enabled', '1') !== '1') return;
    try {
        $conn = get_db();
        $cur = $conn->execute('INSERT INTO ai_conversations(user_id, title, created_at, updated_at) VALUES(?,?,?,?)',
            [$user_id, py_slice((string)$title, null, 60), microtime(true), microtime(true)]);
        $conv_id = $cur->lastrowid;
        $conn->execute('INSERT INTO ai_messages(conversation_id, role, content, created_at) VALUES(?,?,?,?)',
            [$conv_id, 'assistant', $text, microtime(true)]);
        $conn->commit();
    } catch (Throwable $e) {
        return;
    }
    push_user_notif($user_id, 'ai', $title, $text, 'robot', "ai:$conv_id");
}

const AI_NUDGE_INTERVAL = 21600;  // كل 6 ساعات

function _do_ai_inactivity_check(): void {
    if (get_setting('ai_notif_enabled', '1') !== '1') return;
    try { $days = py_float(py_or(get_setting('ai_inactivity_days', '3'), 3)); }
    catch (PyValueError $e) { $days = 3; }
    $threshold = $days * 86400;
    $now = microtime(true);
    try {
        $conn = get_db();
        $rows = $conn->execute('SELECT id, last_seen, last_ai_nudge, created_at FROM users WHERE banned=0')->fetchall();
    } catch (Throwable $e) { return; }
    foreach ($rows as $u) {
        $last_seen = py_float(py_or($u['last_seen'], py_or($u['created_at'], 0)));
        $last_nudge = py_float(py_or($u['last_ai_nudge'], 0));
        if (!$last_seen || $now - $last_seen < $threshold) continue;
        if ($now - $last_nudge < $threshold) continue;
        try {
            $conn2 = get_db();
            $conn2->execute('UPDATE users SET last_ai_nudge=? WHERE id=?', [$now, $u['id']]);
            $conn2->commit();
        } catch (Throwable $e) { continue; }
        send_ai_notif($u['id'], '🤖 اشتقنا لك!',
            'لاحظنا أنك لم تزر الموقع منذ فترة 👋 إذا احتجت مساعدة باختيار خدمة أو عندك أي استفسار، أنا هنا جاهز لمساعدتك بأي وقت!');
    }
}
