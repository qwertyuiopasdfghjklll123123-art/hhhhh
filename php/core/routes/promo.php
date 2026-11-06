<?php
/**
 * الكوبونات، الهدية اليومية، المكافأة اليومية، التقييمات — منقولة من app.py:6821-7108.
 */

/* ------------------------------ الكوبونات ------------------------------ */

route('/api/admin/coupons', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM coupons ORDER BY created_at DESC, __fc_seq ASC')->fetchall();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => $r['id'], 'code' => $r['code'], 'amount' => $r['amount'],
            'max_uses' => $r['max_uses'], 'used' => $r['used'],
            'expires_at' => $r['expires_at'], 'active' => $r['active'],
            'created_at' => $r['created_at'],
        ];
    }
    return jsonify(['ok' => true, 'coupons' => $out]);
});

route('/api/admin/coupons', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $code = py_upper_s(py_strip_s(dget($data, 'code', '')));
    $amount = py_float(dget($data, 'amount', 0));
    $max_uses = py_int(dget($data, 'max_uses', 1));
    $expires_at = dget($data, 'expires_at', '');
    if (!py_truthy($code) || $amount <= 0) {
        return jsonify(['ok' => false, 'error' => 'بيانات ناقصة']);
    }
    $cid = uuid8();
    $conn = get_db();
    try {
        $conn->execute('INSERT INTO coupons(id,code,amount,max_uses,expires_at) VALUES(?,?,?,?,?)',
            [$cid, $code, $amount, $max_uses, sql_text($expires_at)]);
        $conn->commit();
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'error' => 'الكود موجود مسبقاً']);
    }
    $notify_all = dget($data, 'notify_all', false);
    if (py_truthy($notify_all)) {
        $users = $conn->execute('SELECT id FROM users WHERE is_admin=0 ORDER BY id ASC')->fetchall();
        foreach ($users as $u) {
            $nid = uuid8();
            $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
                [$nid, $u['id'], 'system', 'كوبون هدية جديد ', "استخدم الكود {$code} واحصل على $" . py_fmt($amount, 2) . ' رصيد مجاني!', 'gift']);
        }
        $conn->commit();
    }
    return jsonify(['ok' => true, 'id' => $cid]);
}, ['POST']);

route('/api/admin/coupons/<cid>', function ($cid) {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $conn->execute('DELETE FROM coupons WHERE id=?', [$cid]);
    $conn->execute('DELETE FROM coupon_uses WHERE coupon_id=?', [$cid]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['DELETE']);

route('/api/redeem-coupon', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'error' => 'سجل دخولك أولاً'], 401);
    $data = req_data();
    $code = py_upper_s(py_strip_s(dget($data, 'code', '')));
    if (!py_truthy($code)) return jsonify(['ok' => false, 'error' => 'أدخل كود الكوبون']);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $coupon = $conn->execute('SELECT * FROM coupons WHERE code=? AND active=1', [$code])->fetchone();
    if (!$coupon) return jsonify(['ok' => false, 'error' => 'كود غير صالح أو منتهي']);
    if (py_truthy($coupon['expires_at']) && strcmp((string)$coupon['expires_at'], date('Y-m-d')) < 0) {
        return jsonify(['ok' => false, 'error' => 'الكوبون منتهي الصلاحية']);
    }
    if ($coupon['used'] >= $coupon['max_uses']) return jsonify(['ok' => false, 'error' => 'تم استخدام الكوبون بالكامل']);
    $used = $conn->execute('SELECT id FROM coupon_uses WHERE coupon_id=? AND user_id=?', [$coupon['id'], $uid])->fetchone();
    if ($used) return jsonify(['ok' => false, 'error' => 'لقد استخدمت هذا الكوبون مسبقاً']);
    // تحصين ضد التزامن (لم يكن بالأصل): الزيادة مشروطة بوجود استخدامات متبقية فلا يتجاوز used قيمة max_uses
    $g = $conn->execute('UPDATE coupons SET used=used+1 WHERE id=? AND used<max_uses', [$coupon['id']]);
    if ($g->rowcount < 1) {
        $conn->rollback();
        return jsonify(['ok' => false, 'error' => 'تم استخدام الكوبون بالكامل']);
    }
    $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$coupon['amount'], $uid]);
    $conn->execute('INSERT INTO coupon_uses(coupon_id,user_id) VALUES(?,?)', [$coupon['id'], $uid]);
    $nid = uuid8();
    $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
        [$nid, $uid, 'system', 'كوبون هدية ', 'تم إضافة $' . py_fmt($coupon['amount'], 2) . " لرصيدك بكوبون {$code}", 'wallet']);
    $conn->commit();
    $nb = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone();
    if (!$nb) throw new PyTypeError("'NoneType' object is not subscriptable");
    return jsonify(['ok' => true, 'amount' => $coupon['amount'], 'balance' => $nb['balance']]);
}, ['POST']);

/* ------------------------------ الهدية اليومية ------------------------------ */

route('/api/admin/daily-gift', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    // الأصل كان يفحص عموداً غير موجود (users.role) فيرجع 500 لكل الحسابات حتى الأدمن؛ صُحّح ليستعمل is_admin كباقي لوحة الإدارة
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $dg_amount = get_setting('daily_gift_amount', '0');
    $dg_code = get_setting('daily_gift_code', '');
    $dg_date = get_setting('daily_gift_date', '');
    $dg_max = get_setting('daily_gift_max_uses', '100');
    $dg_active = get_setting('daily_gift_active', '0');
    return jsonify(['ok' => true, 'amount' => $dg_amount, 'code' => $dg_code, 'date' => $dg_date, 'max_uses' => $dg_max, 'active' => $dg_active]);
});

route('/api/admin/daily-gift', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    // الأصل كان يفحص عموداً غير موجود (users.role) فيرجع 500 لكل الحسابات حتى الأدمن؛ صُحّح ليستعمل is_admin كباقي لوحة الإدارة
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $data = req_data();
    $amount = py_float(dget($data, 'amount', 0));
    $max_uses = py_int(dget($data, 'max_uses', 100));
    $notify = dget($data, 'notify', false);
    if ($amount <= 0) return jsonify(['ok' => false, 'error' => 'حدد مبلغ صحيح']);
    $code = 'DAILY' . py_slice((string)time(), -6);
    $today = date('Y-m-d');
    $expires = date('Y-m-d', time() + 86400);
    $cid = uuid8();
    $conn->execute('INSERT INTO coupons(id,code,amount,max_uses,expires_at) VALUES(?,?,?,?,?)', [$cid, $code, $amount, $max_uses, $expires]);
    set_setting('daily_gift_amount', py_str($amount));
    set_setting('daily_gift_code', $code);
    set_setting('daily_gift_date', $today);
    set_setting('daily_gift_max_uses', py_str($max_uses));
    set_setting('daily_gift_active', '1');
    if (py_truthy($notify)) {
        $users = $conn->execute("SELECT id FROM users WHERE COALESCE(is_admin,0)=0 ORDER BY id ASC")->fetchall();
        foreach ($users as $usr) {
            $nid = uuid8();
            $conn->execute('INSERT INTO notifications(id,user_id,type,title,text,icon) VALUES(?,?,?,?,?,?)',
                [$nid, $usr['id'], 'system', 'هدية يومية جديدة!', "استخدم الكود {$code} واحصل على $" . py_fmt($amount, 2) . ' رصيد مجاني!', 'gift']);
        }
    }
    $conn->commit();
    return jsonify(['ok' => true, 'code' => $code]);
}, ['POST']);

route('/api/daily-gift-code', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $active = get_setting('daily_gift_active', '0');
    if ($active !== '1') return jsonify(['ok' => true, 'active' => false]);
    $code = get_setting('daily_gift_code', '');
    $amount = get_setting('daily_gift_amount', '0');
    $dg_date = get_setting('daily_gift_date', '');
    $today = date('Y-m-d');
    if ($dg_date !== $today) return jsonify(['ok' => true, 'active' => false]);
    return jsonify(['ok' => true, 'active' => true, 'code' => $code, 'amount' => $amount]);
});

/* ------------------------------ المكافأة اليومية ------------------------------ */

function _daily_bonus_amount(): float {
    try { return py_float(py_or(get_setting('daily_bonus_amount', '0.5'), 0.5)); }
    catch (PyValueError $e) { return 0.5; }
}

route('/api/daily-bonus/status', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $enabled = get_setting('daily_bonus_enabled', '0') === '1';
    $amount = _daily_bonus_amount();
    $conn = get_db();
    $row = $conn->execute('SELECT last_daily_bonus FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
    $last = $row ? py_float(py_or($row['last_daily_bonus'], 0)) : 0;
    $elapsed = microtime(true) - $last;
    $can_claim = $enabled && $elapsed >= 86400;
    return jsonify(['ok' => true, 'enabled' => $enabled, 'amount' => $amount, 'can_claim' => $can_claim,
                    'seconds_left' => max(0, (int)(86400 - $elapsed))]);
});

route('/api/daily-bonus/claim', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    if (get_setting('daily_bonus_enabled', '0') !== '1') return jsonify(['ok' => false, 'msg' => 'هذه الميزة غير مفعّلة حالياً']);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    // تحصين ضد التزامن (لم يكن بالأصل): قفل صف المستخدم فلا يمكن استلام المكافأة مرتين بطلبين متوازيين
    $conn->begin();
    $row = $conn->execute('SELECT last_daily_bonus, balance FROM users WHERE id=? FOR UPDATE', [$uid])->fetchone();
    if (!$row) {
        $conn->rollback();
        return jsonify(['ok' => false, 'msg' => 'خطأ'], 404);
    }
    $last = py_float(py_or($row['last_daily_bonus'], 0));
    if (microtime(true) - $last < 86400) {
        $conn->rollback();
        return jsonify(['ok' => false, 'msg' => 'يمكنك استلام المكافأة مرة كل 24 ساعة فقط']);
    }
    $amount = _daily_bonus_amount();
    $balance = py_float(py_or($row['balance'], 0));
    $new_balance = py_round($balance + $amount, 4);
    $conn->execute('UPDATE users SET balance=?, last_daily_bonus=? WHERE id=?', [$new_balance, microtime(true), $uid]);
    $conn->commit();
    push_user_notif($uid, 'system', 'مكافأتك اليومية 🎁', 'حصلت على $' . py_fmt($amount, 2), 'gift');
    return jsonify(['ok' => true, 'amount' => $amount, 'new_balance' => $new_balance]);
}, ['POST']);

/* ------------------------------ التقييمات ------------------------------ */

route('/api/reviews', function () {
    $conn = get_db();
    $day_seed = (int)date('Ymd');
    $rows = $conn->execute('SELECT id,user_name,stars,text,created_at FROM reviews WHERE approved=1 ORDER BY id')->fetchall();
    $reviews = rows_dicts($rows);
    if (count($reviews) > 4) {
        $rng = new PyRandom($day_seed);
        $rng->shuffle($reviews);
        $reviews = array_slice($reviews, 0, 4);
    } elseif (count($reviews) > 0) {
        $reviews = array_slice($reviews, 0, 4);
    }
    $stats = $conn->execute('SELECT COUNT(*) as cnt, AVG(stars) as avg_stars FROM reviews WHERE approved=1')->fetchone();
    $total = $stats ? $stats['cnt'] : 0;
    $avg = ($stats && py_truthy($stats['avg_stars'])) ? py_round($stats['avg_stars'], 1) : 0;
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    if ($total > 0) {
        for ($s = 1; $s <= 5; $s++) {
            $c = $conn->execute('SELECT COUNT(*) as c FROM reviews WHERE approved=1 AND stars=?', [$s])->fetchone();
            $dist[$s] = $c ? py_round(($c['c'] / $total) * 100) : 0;
        }
    }
    return jsonify(['ok' => true, 'reviews' => $reviews, 'total' => $total, 'avg' => $avg, 'dist' => $dist]);
});

route('/api/reviews', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'سجّل دخول أولاً'], 401);
    try {
        $data = req_data_force();
        $stars = py_int(dget($data, 'stars', 0));
        $text = py_strip_s(py_or(dget($data, 'text', ''), ''));
        if ($stars < 1 || $stars > 5) return jsonify(['ok' => false, 'msg' => 'اختر عدد النجوم']);
        if (py_len($text) < 5) return jsonify(['ok' => false, 'msg' => 'اكتب تعليق أطول']);
        if (py_len($text) > 300) $text = py_slice($text, null, 300);
        $conn = get_db();
        // int(time.strftime('%s', time.gmtime(time.mktime(<منتصف الليل المحلي>)))) — يقابل تفسير حقول UTC كوقت محلي
        $mid = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
        $today_start = mktime((int)gmdate('G', $mid), (int)gmdate('i', $mid), (int)gmdate('s', $mid),
                              (int)gmdate('n', $mid), (int)gmdate('j', $mid), (int)gmdate('Y', $mid));
        $count = $conn->execute('SELECT COUNT(*) as c FROM reviews WHERE user_id=? AND created_at>=?', [$_SESSION['user_id'], $today_start])->fetchone();
        if ($count && $count['c'] >= 5) {
            return jsonify(['ok' => false, 'msg' => 'وصلت الحد الأقصى (5 تقييمات باليوم)']);
        }
        $uname = array_key_exists('user_name', $_SESSION) ? $_SESSION['user_name'] : 'مستخدم';
        if (py_truthy($uname)) {
            $parts = py_split_ws($uname);
            if (!$parts) throw new PyIndexError('list index out of range');
            $first_name = $parts[0];
        } else {
            $first_name = 'مستخدم';
        }
        $conn->execute('INSERT INTO reviews(user_id,user_name,stars,text,approved) VALUES(?,?,?,?,?)',
            [$_SESSION['user_id'], $first_name, $stars, $text, 1]);
        $conn->commit();
        return jsonify(['ok' => true, 'msg' => 'شكراً لتقييمك!']);
    } catch (HttpResponseException $e) {
        throw $e;
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => 'حدث خطأ: ' . $e->getMessage()]);
    }
}, ['POST']);

route('/api/admin/reviews', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM reviews ORDER BY id DESC')->fetchall();
    return jsonify(['ok' => true, 'reviews' => rows_dicts($rows)]);
});

route('/api/admin/reviews/<int:rid>/approve', function ($rid) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $conn->execute('UPDATE reviews SET approved=1 WHERE id=?', [$rid]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/admin/reviews/<int:rid>/hide', function ($rid) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $conn->execute('UPDATE reviews SET approved=0 WHERE id=?', [$rid]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/admin/reviews/<int:rid>/delete', function ($rid) {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $conn->execute('DELETE FROM reviews WHERE id=?', [$rid]);
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['DELETE']);
