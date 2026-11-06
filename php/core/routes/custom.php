<?php
/**
 * الخدمات المخصصة + طلباتها + المفضلة — منقولة من app.py:5979-6294.
 */

/** str(uuid.uuid4())[:8] */
function uuid8(): string { return substr(uuid4(), 0, 8); }

/** حارس الأدمن بمسارات الخدمات المخصصة: تسجيل دخول ثم فحص is_admin من قاعدة البيانات */
function _admin_db_gate(): ?Response {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $conn = get_db();
    $u = $conn->execute('SELECT is_admin FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
    if (!$u || !py_truthy($u['is_admin'])) return jsonify(['ok' => false], 403);
    return null;
}

/** dict(row) لخدمة مخصصة مع إعادة تسمية الحقول كما بالأصل */
function _custom_service_dict(Row $r): array {
    $svc = row_dict($r);
    $svc['desc'] = array_key_exists('desc_text', $svc) ? $svc['desc_text'] : '';
    unset($svc['desc_text']);
    $svc['min'] = array_key_exists('min_qty', $svc) ? $svc['min_qty'] : 100;
    unset($svc['min_qty']);
    $svc['max'] = array_key_exists('max_qty', $svc) ? $svc['max_qty'] : 100000;
    unset($svc['max_qty']);
    try { $svc['packages'] = json_loads(py_or($svc['packages'] ?? null, '[]')); }
    catch (Throwable $e) { $svc['packages'] = []; }
    return $svc;
}

route('/api/custom_services', function () {
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM custom_services WHERE active=1 ORDER BY __fc_seq ASC')->fetchall();
    $services = [];
    foreach ($rows as $r) {
        $svc = _custom_service_dict($r);
        $services[(string)$svc['id']] = $svc;
    }
    return jsonify(['ok' => true, 'services' => jobj($services)]);
});

route('/api/favorites', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM favorites WHERE user_id=? ORDER BY created_at DESC, id ASC', [$_SESSION['user_id']])->fetchall();
    return jsonify(['ok' => true, 'favorites' => rows_dicts($rows)]);
});

route('/api/favorites/toggle', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data();
    $service_id = py_strip(py_str(dget($data, 'service_id', '')));
    if (!py_truthy($service_id)) {
        return jsonify(['ok' => false, 'msg' => 'خدمة غير صالحة']);
    }
    $conn = get_db();
    $existing = $conn->execute('SELECT id FROM favorites WHERE user_id=? AND service_id=?', [$uid, $service_id])->fetchone();
    if ($existing) {
        $conn->execute('DELETE FROM favorites WHERE id=?', [$existing['id']]);
        $conn->commit();
        return jsonify(['ok' => true, 'favorited' => false]);
    }
    try {
        $conn->execute('INSERT INTO favorites(user_id,service_id,service_name,platform,category,rate,min_qty,max_qty,custom)
                         VALUES(?,?,?,?,?,?,?,?,?)',
            [$uid, $service_id, py_slice(py_str(dget($data, 'service_name', '')), null, 200), py_slice(py_str(dget($data, 'platform', '')), null, 100),
             py_slice(py_str(dget($data, 'category', '')), null, 100), py_str(dget($data, 'rate', '0')), py_int(py_or(dget($data, 'min'), 0)),
             py_int(py_or(dget($data, 'max'), 0)), py_truthy(dget($data, 'custom')) ? 1 : 0]);
        $conn->commit();
    } catch (IntegrityError $e) {}
    return jsonify(['ok' => true, 'favorited' => true]);
}, ['POST']);

route('/api/admin/custom_services', function () {
    if ($g = _admin_db_gate()) return $g;
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM custom_services ORDER BY created_at DESC, __fc_seq ASC')->fetchall();
    $services = [];
    foreach ($rows as $r) {
        $svc = _custom_service_dict($r);
        $services[(string)$svc['id']] = $svc;
    }
    return jsonify(['ok' => true, 'services' => jobj($services)]);
}, ['POST']);

route('/api/admin/custom_services/add', function () {
    if ($g = _admin_db_gate()) return $g;
    $conn = get_db();
    $data = req_data();
    $stype = dget($data, 'type', '');
    $name = py_strip(py_or(dget($data, 'name'), ''));
    $desc = py_strip(py_or(dget($data, 'desc'), ''));
    $icon = dget($data, 'icon', '');
    $color = dget($data, 'color', '#4A90FF');
    $image = dget($data, 'image', '');
    if (!py_truthy($name) || !py_truthy($stype)) {
        return jsonify(['ok' => false, 'error' => 'missing fields']);
    }
    $sid = 'cs_' . time() . '_' . bin2hex(random_bytes(3));
    $packages = '[]'; $rate = 0; $min_q = 100; $max_q = 100000;
    if ($stype === 'subscription') {
        $packages = py_json_dumps(dget($data, 'packages', []), false);
    } else {
        $rate = py_float(dget($data, 'rate', 0));
        $min_q = py_int(dget($data, 'min', 100));
        $max_q = py_int(dget($data, 'max', 100000));
    }
    $conn->execute('INSERT INTO custom_services(id,name,type,desc_text,icon,color,image,rate,min_qty,max_qty,packages,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)',
        [$sid, $name, sql_text($stype), $desc, sql_text($icon), sql_text($color), sql_text($image), $rate, $min_q, $max_q, $packages]);
    $conn->commit();
    return jsonify(['ok' => true, 'id' => $sid]);
}, ['POST']);

route('/api/admin/custom_services/edit', function () {
    if ($g = _admin_db_gate()) return $g;
    $conn = get_db();
    $data = req_data();
    $sid = dget($data, 'id', '');
    $svc = $conn->execute('SELECT * FROM custom_services WHERE id=?', [sql_str($sid)])->fetchone();
    if (!$svc) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    $sets = []; $vals = [];
    foreach (['name', 'icon', 'color', 'image'] as $k) {
        if (array_key_exists($k, $data)) { $sets[] = "$k=?"; $vals[] = sql_text($data[$k]); }
    }
    if (array_key_exists('desc', $data)) { $sets[] = 'desc_text=?'; $vals[] = sql_text($data['desc']); }
    if (array_key_exists('active', $data)) { $sets[] = 'active=?'; $vals[] = py_truthy($data['active']) ? 1 : 0; }
    if ($svc['type'] === 'subscription' && array_key_exists('packages', $data)) {
        $sets[] = 'packages=?';
        $vals[] = py_json_dumps($data['packages'], false);
    } elseif ($svc['type'] === 'social') {
        foreach (['rate', 'min', 'max'] as $k) {
            if (array_key_exists($k, $data)) {
                $col = $k === 'min' ? 'min_qty' : ($k === 'max' ? 'max_qty' : $k);
                $sets[] = "$col=?";
                $vals[] = $k === 'rate' ? py_float($data[$k]) : py_int($data[$k]);
            }
        }
    }
    if ($sets) {
        $vals[] = sql_str($sid);
        $conn->execute('UPDATE custom_services SET ' . implode(',', $sets) . ' WHERE id=?', $vals);
        $conn->commit();
    }
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/admin/custom_services/delete', function () {
    if ($g = _admin_db_gate()) return $g;
    $conn = get_db();
    $data = req_data();
    $sid = dget($data, 'id', '');
    $conn->execute('DELETE FROM custom_services WHERE id=?', [sql_str($sid)]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

/* '/api/custom_order'  (app.py:6153) */
route('/api/custom_order', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $data = req_data();
    $uid = $_SESSION['user_id'];
    $sid = dget($data, 'service_id', '');
    $conn = get_db();
    $svc_row = $conn->execute('SELECT * FROM custom_services WHERE id=? AND active=1', [sql_str($sid)])->fetchone();
    if (!$svc_row) {
        return jsonify(['ok' => false, 'error' => 'service_not_found']);
    }
    $svc = row_dict($svc_row);
    try { $svc['packages'] = json_loads(py_or($svc['packages'] ?? null, '[]')); }
    catch (Throwable $e) { $svc['packages'] = []; }
    $user = $conn->execute('SELECT * FROM users WHERE id=?', [$uid])->fetchone();
    $oid = 'CO-' . time() . '-' . bin2hex(random_bytes(2));

    if ($svc['type'] === 'subscription') {
        $pkg_index = py_int(dget($data, 'package_index', 0));
        $packages = py_or($svc['packages'] ?? null, []);
        if ($pkg_index < 0 || $pkg_index >= count($packages)) {
            return jsonify(['ok' => false, 'error' => 'invalid_package']);
        }
        $pkg = $packages[$pkg_index];
        $cost = py_float(dget($pkg, 'price', 0));
        $bal = py_float($user['balance']);
        if (!py_truthy($user['is_admin']) && $bal < $cost) {
            return jsonify(['ok' => false, 'error' => 'insufficient_balance', 'balance' => $bal, 'cost' => $cost]);
        }
        if (!py_truthy($user['is_admin'])) {
            $cur = $conn->execute('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?', [$cost, $uid, $cost]);
            if ($cur->rowcount < 1) {
                $conn->rollback();
                return jsonify(['ok' => false, 'error' => 'insufficient_balance', 'balance' => $bal, 'cost' => $cost]);
            }
        }
        $conn->execute('INSERT INTO custom_orders(id,user_id,service_id,service_name,type,package,cost,status) VALUES(?,?,?,?,?,?,?,?)',
            [$oid, $uid, sql_str($sid), $svc['name'], 'subscription', sql_str(dget($pkg, 'name', '')), $cost, 'pending']);
        $tk_subject = 'طلب اشتراك: ' . $svc['name'] . ' — ' . py_str(dget($pkg, 'name', ''));
        $conn->execute('INSERT INTO tickets(user_id,subject,category,status) VALUES(?,?,?,?)', [$uid, $tk_subject, ' طلب اشتراك', 'open']);
        $tk_id = $conn->execute('SELECT last_insert_rowid()')->fetchone()[0];
        $sys_msg = "🎫 طلب اشتراك جديد\n\n" . py_str(dget($svc, 'icon', '')) . ' ' . $svc['name'] . "\n الباقة: " . py_str(dget($pkg, 'name', '')) . "\n السعر: $" . py_fmt($cost) . "\n⏱ المدة: " . py_str(dget($pkg, 'duration', '')) . "\n\nبانتظار الإدارة لإرسال التفاصيل...";
        $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)', [$tk_id, 'system', $sys_msg]);
        $conn->commit();
        $new_bal = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone()['balance'];
        $nid = uuid8();
        $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
            [$nid, $uid, 'order', 'تم تقديم طلب اشتراك! ', $svc['name'] . ' — ' . py_str(dget($pkg, 'name', '')) . ' — $' . py_fmt($cost), 'order']);
        $conn->commit();
        return jsonify(['ok' => true, 'order' => ['id' => $oid, 'service_name' => $svc['name'], 'package' => py_str(dget($pkg, 'name', '')), 'cost' => $cost, 'status' => 'pending'],
            'ticket_id' => $tk_id, 'balance' => py_round($new_bal, 4)]);
    } else {
        $link = py_strip(py_or(dget($data, 'link'), ''));
        $qty = py_int(dget($data, 'quantity', 0));
        $comments = py_strip(py_or(dget($data, 'comments'), ''));
        $rate = py_float(dget($svc, 'rate', 0));
        $mn = py_int(dget($svc, 'min_qty', 0)); $mx = py_int(dget($svc, 'max_qty', 0));
        if (strncmp($link, 'http', 4) !== 0) {
            return jsonify(['ok' => false, 'error' => 'invalid_link']);
        }
        if ($qty < $mn || $qty > $mx) {
            return jsonify(['ok' => false, 'error' => 'quantity_out_of_range', 'min' => $mn, 'max' => $mx]);
        }
        $cost = py_round(($rate * $qty) / 1000.0, 4);
        $bal = py_float($user['balance']);
        if (!py_truthy($user['is_admin']) && $bal < $cost) {
            return jsonify(['ok' => false, 'error' => 'insufficient_balance', 'balance' => $bal, 'cost' => $cost]);
        }
        if (!py_truthy($user['is_admin'])) {
            $cur = $conn->execute('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?', [$cost, $uid, $cost]);
            if ($cur->rowcount < 1) {
                $conn->rollback();
                return jsonify(['ok' => false, 'error' => 'insufficient_balance', 'balance' => $bal, 'cost' => $cost]);
            }
        }
        $conn->execute('INSERT INTO custom_orders(id,user_id,service_id,service_name,type,link,quantity,comments,cost,status) VALUES(?,?,?,?,?,?,?,?,?,?)',
            [$oid, $uid, sql_str($sid), $svc['name'], 'social', $link, $qty, $comments, $cost, 'pending']);
        $tk_subject = 'طلب خدمة: ' . $svc['name'] . " — الكمية: $qty";
        $conn->execute('INSERT INTO tickets(user_id,subject,category,status) VALUES(?,?,?,?)', [$uid, $tk_subject, 'طلب خدمة', 'open']);
        $tk_id = $conn->execute('SELECT last_insert_rowid()')->fetchone()[0];
        $sys_msg = "🎫 طلب خدمة جديد\n\n" . $svc['name'] . "\n🔗 الرابط: $link\n📊 الكمية: $qty\n💰 السعر: $" . py_fmt($cost, 4);
        if (py_truthy($comments)) {
            $sys_msg .= "\n📝 ملاحظات: $comments";
        }
        $sys_msg .= "\n\nبانتظار الإدارة لتنفيذ الطلب...";
        $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)', [$tk_id, 'system', $sys_msg]);
        $conn->commit();
        $new_bal = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone()['balance'];
        $nid = uuid8();
        $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
            [$nid, $uid, 'order', 'تم تقديم طلبك! ', $svc['name'] . " — الكمية: $qty — $" . py_fmt($cost, 4), 'order']);
        $conn->commit();
        return jsonify(['ok' => true, 'order' => ['id' => $oid, 'service_name' => $svc['name'], 'qty' => $qty, 'cost' => $cost, 'status' => 'pending'],
            'ticket_id' => $tk_id, 'balance' => py_round($new_bal, 4)]);
    }
}, ['POST']);

route('/api/admin/custom_orders', function () {
    if ($g = _admin_db_gate()) return $g;
    $conn = get_db();
    $rows = $conn->execute('SELECT co.*, u.name as user_name, u.email as user_email
        FROM custom_orders co LEFT JOIN users u ON co.user_id=u.id
        ORDER BY co.created_at DESC, co.__fc_seq ASC')->fetchall();
    return jsonify(['ok' => true, 'orders' => rows_dicts($rows)]);
}, ['POST']);

route('/api/admin/custom_orders/update', function () {
    if ($g = _admin_db_gate()) return $g;
    $conn = get_db();
    $data = req_data();
    $oid = dget($data, 'order_id', '');
    $status = dget($data, 'status', '');
    $order = $conn->execute('SELECT * FROM custom_orders WHERE id=?', [sql_str($oid)])->fetchone();
    if (!$order) {
        return jsonify(['ok' => false, 'error' => 'not found']);
    }
    $conn->execute('UPDATE custom_orders SET status=? WHERE id=?', [sql_str($status), sql_str($oid)]);
    $co_uid = $order['user_id'];
    if ($status === 'canceled' && py_float($order['cost']) > 0 && !py_truthy($order['refunded'])) {
        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [py_float($order['cost']), $co_uid]);
        $conn->execute('UPDATE custom_orders SET refunded=1 WHERE id=?', [sql_str($oid)]);
        $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
            [uuid8(), $co_uid, 'system', 'تم إلغاء طلبك المخصص ', $order['service_name'] . ' — تم استرجاع $' . py_fmt(py_float($order['cost'])), 'wallet']);
    } elseif ($status === 'completed') {
        $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
            [uuid8(), $co_uid, 'order', 'تم اكتمال طلبك! ', $order['service_name'], 'done']);
    } elseif ($status === 'processing') {
        $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
            [uuid8(), $co_uid, 'system', 'طلبك قيد التنفيذ ⚙', $order['service_name'] . ' — الإدارة تعمل على طلبك', 'order']);
    }
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);
