<?php
/** الإشعارات - مقابل routes /events بـ webapp.py */

route('/events', function () {
    if (($r = require_login()) !== null) return $r;
    $since = (int)req_args('since', 0);
    $rows = get_db()->execute('SELECT * FROM events WHERE owner=? AND id>? ORDER BY id ASC', [$_SESSION['user_id'], $since])->fetchall();
    return jsonify(array_map(fn($e) => [
        'id' => (int)$e['id'], 'owner' => (int)$e['owner'], 'account' => $e['account'],
        'title' => $e['title'], 'body' => $e['body'], 'kind' => $e['kind'],
        'read' => py_truthy($e['is_read']), 'time' => $e['created_at'],
    ], $rows));
}, ['GET']);

route('/events/<int:eventId>/read', function ($eventId) {
    if (($r = require_login()) !== null) return $r;
    $conn = get_db();
    $row = $conn->execute('SELECT id FROM events WHERE id=? AND owner=?', [$eventId, $_SESSION['user_id']])->fetchone();
    if (!$row) return jsonify(['ok' => false, 'error' => 'غير موجود'], 404);
    $conn->execute('UPDATE events SET is_read=1 WHERE id=?', [$eventId]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/events/read_all', function () {
    if (($r = require_login()) !== null) return $r;
    get_db()->execute('UPDATE events SET is_read=1 WHERE owner=?', [$_SESSION['user_id']]);
    get_db()->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/events/<int:eventId>', function ($eventId) {
    if (($r = require_login()) !== null) return $r;
    $conn = get_db();
    $row = $conn->execute('SELECT id FROM events WHERE id=? AND owner=?', [$eventId, $_SESSION['user_id']])->fetchone();
    if (!$row) return jsonify(['ok' => false, 'error' => 'غير موجود'], 404);
    $conn->execute('DELETE FROM events WHERE id=?', [$eventId]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['DELETE']);
