<?php
/**
 * الطلبات: إنشاء طلب، قائمة الطلبات، إحصائيات المستخدم، حالة الطلب، التعويض، واجهة API v2 ومفاتيح الـ API
 * منقولة من app.py:3493-3931 و 5934-5977 و 17162-17297.
 */

const _DEAD_KEYWORDS = ['invalid service', 'service not found', 'not found', 'does not exist',
    'service is not', 'incorrect service', 'service unavailable',
    'service has been', 'service was removed', 'service deleted',
    'invalid service id', 'bad service'];

/** _add_referral_commission  (app.py:5934) */
function _add_referral_commission($user_id, $order_id, $cost): void {
    try {
        $conn = get_db();
        $ref = $conn->execute('SELECT * FROM referrals WHERE user_id=?', [$user_id])->fetchone();
        if ($ref && py_truthy($ref['referred_by'])) {
            $referrer_ref = $conn->execute('SELECT * FROM referrals WHERE user_id=?', [$ref['referred_by']])->fetchone();
            if ($referrer_ref) {
                $commission = py_round($cost * REFERRAL_COMMISSION / 100, 4);
                if ($commission > 0) {
                    $conn->execute('UPDATE referrals SET pending=pending+? WHERE user_id=?', [$commission, $ref['referred_by']]);
                    $conn->execute('INSERT INTO referral_history(referrer_id,amount,from_order) VALUES(?,?,?)', [$ref['referred_by'], $commission, sql_str($order_id)]);
                    $conn->commit();
                }
            }
        }
    } catch (Throwable $e) {}
}

route('/api/admin/referral', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false, 'error' => 'unauthorized'], 401);
    $conn = get_db();
    // SQLite: COALESCE(SUM(...),0) يرجع 0 (int) لجدول فارغ؛ MySQL يرجع 0.0 — نحاكي النوع بالدمج في PHP
    $total_referrals = py_or_null($conn->execute('SELECT SUM(ref_count) FROM referrals')->fetchone()[0], 0);
    $total_earnings = py_or_null($conn->execute('SELECT SUM(earnings) FROM referrals')->fetchone()[0], 0);
    $total_pending = py_or_null($conn->execute('SELECT SUM(pending) FROM referrals')->fetchone()[0], 0);
    $total_users = $conn->execute('SELECT COUNT(*) FROM referrals')->fetchone()[0];
    $top_rows = $conn->execute('SELECT r.*, u.name as user_name, u.email as user_email
        FROM referrals r LEFT JOIN users u ON r.user_id=u.id
        WHERE r.ref_count > 0
        ORDER BY r.ref_count DESC, r.id ASC LIMIT 10')->fetchall();
    $top = [];
    foreach ($top_rows as $t) {
        $top[] = ['uid' => $t['user_id'], 'name' => py_or($t['user_name'], ''), 'email' => py_or($t['user_email'], ''),
            'code' => $t['code'], 'count' => $t['ref_count'],
            'earnings' => py_round($t['earnings'], 2), 'pending' => py_round($t['pending'], 2)];
    }
    return jsonify(['ok' => true, 'commission' => REFERRAL_COMMISSION, 'min_withdraw' => REFERRAL_MIN_WITHDRAW,
        'total_referrals' => $total_referrals, 'total_earnings' => py_round($total_earnings, 2),
        'total_pending' => py_round($total_pending, 2), 'total_users' => $total_users, 'top' => $top]);
}, ['POST']);

/** يجد الخدمة في قائمة المزوّد الافتراضي (أول تطابق) — يرجع الصف أو null */
function _find_default_service($service): ?array {
    foreach (get_smm_services() ?: [] as $sc) {
        if (py_str(dget($sc, 'service', '')) === py_str($service)) return $sc;
    }
    return null;
}

/* '/api/order'  (app.py:3493) */
route('/api/order', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'يرجى تسجيل الدخول'], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data_strict();
    $service = dget($data, 'service');
    $svc_name = dget($data, 'service_name', '');
    $link = dget($data, 'link', '');
    $quantity = py_int(dget($data, 'quantity', 0));
    $site_price = py_float(dget($data, 'price', 0));
    $comments = py_strip(py_or(dget($data, 'comments'), ''));
    if (!py_truthy($service) || !py_truthy($link) || !$quantity) {
        return jsonify(['ok' => false, 'msg' => 'جميع الحقول مطلوبة'], 400);
    }
    $_lv_disc = _get_user_level_discount($uid);
    if ($_lv_disc > 0 && $site_price > 0) {
        $site_price = py_round($site_price * (1 - $_lv_disc / 100.0), 4);
    }
    try {
        foreach (get_smm_services() ?: [] as $_sc) {
            if (py_str(dget($_sc, 'service', '')) === py_str($service)) {
                $_mn = py_int(dget($_sc, 'min', 1));
                $_mx = py_int(dget($_sc, 'max', 1000000));
                if ($quantity < $_mn) {
                    return jsonify(['ok' => false, 'msg' => 'الحد الأدنى للكمية ' . number_format($_mn)]);
                }
                if ($quantity > $_mx) {
                    return jsonify(['ok' => false, 'msg' => 'الحد الأقصى للكمية ' . number_format($_mx)]);
                }
                break;
            }
        }
    } catch (Throwable $e) {}
    $conn = get_db();
    $dup = $conn->execute("SELECT id, status FROM orders WHERE user_id=? AND service=? AND link=? AND status NOT IN ('Completed','Canceled','Partial') ORDER BY id DESC LIMIT 1",
        [$uid, sql_str($service), sql_str($link)])->fetchone();
    if ($dup) {
        return jsonify(['ok' => false, 'msg' => 'يوجد طلب نشط لنفس الرابط ونفس الخدمة. انتظر حتى يكتمل الطلب السابق.']);
    }
    $user = $conn->execute('SELECT balance,is_admin FROM users WHERE id=?', [$uid])->fetchone();
    if (!$user) {
        return jsonify(['ok' => false, 'msg' => 'مستخدم غير موجود'], 400);
    }
    $bal = py_float($user['balance']);
    if (!py_truthy($user['is_admin']) && $site_price > 0 && $bal < $site_price) {
        return jsonify(['ok' => false, 'msg' => 'رصيد غير كافي. المطلوب: $' . py_fmt($site_price) . ' — رصيدك: $' . py_fmt($bal)]);
    }
    $_refill_days = '';
    try {
        foreach (get_smm_services() ?: [] as $_sv) {
            if (py_str(dget($_sv, 'service', '')) === py_str($service)) {
                $_rf = py_str(dget($_sv, 'refill', ''));
                if (py_truthy($_rf) && !in_array($_rf, ['0', 'False', 'false'], true)) {
                    $_refill_days = $_rf;
                }
                break;
            }
        }
    } catch (Throwable $e) {}
    if (!py_truthy($user['is_admin']) && $site_price > 0) {
        // خصم الرصيد ذرّياً (يمنع الإنفاق المزدوج عند طلبين متزامنين) ثم commit قبل نداءات المزوّد البطيئة
        $cur = $conn->execute('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?', [$site_price, $uid, $site_price]);
        if ($cur->rowcount < 1) {
            $conn->rollback();
            return jsonify(['ok' => false, 'msg' => 'رصيد غير كافي. المطلوب: $' . py_fmt($site_price) . ' — رصيدك: $' . py_fmt($bal)]);
        }
        $conn->commit();
    }
    [$_api_url, $_api_key, $_prov_id] = _find_service_provider($service);
    $provider_has_balance = false;
    try {
        $_br = requests_post($_api_url, ['data' => ['key' => $_api_key, 'action' => 'balance'], 'timeout' => 10]);
        $_bd = py_dict($_br->json());
        $provider_bal = py_float(dget($_bd, 'balance', 0));
        $est_cost = 0;
        try {
            foreach (get_smm_services() ?: [] as $_sv2) {
                if (py_str(dget($_sv2, 'service', '')) === py_str($service)) {
                    $est_cost = py_float(dget($_sv2, 'rate', 0)) * $quantity / 1000;
                    break;
                }
            }
        } catch (Throwable $e) {}
        if ($provider_bal >= $est_cost) {
            $provider_has_balance = true;
        }
    } catch (Throwable $e) {
        $provider_has_balance = true;
    }

    if ($provider_has_balance) {
        try {
            $_order_data = ['key' => $_api_key, 'action' => 'add', 'service' => py_str($service), 'link' => py_str($link), 'quantity' => $quantity];
            if (py_truthy($comments)) {
                $_order_data['comments'] = $comments;
            }
            $r = requests_post($_api_url, ['data' => $_order_data, 'timeout' => 15]);
            $result = py_dict($r->json());
            if (array_key_exists('order', $result)) {
                $provider_charge = py_float(dget($result, 'charge', 0));
                $_did = _next_display_id();
                $conn->execute('INSERT INTO orders(user_id,order_id,service,service_name,link,quantity,charge,site_charge,status,refill_days,display_id,provider_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$uid, py_str($result['order']), sql_str($service), sql_str($svc_name), sql_str($link), $quantity, py_str($provider_charge), $site_price, 'Processing', $_refill_days, $_did, $_prov_id]);
                $conn->commit();
                $new_bal = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone()['balance'];
                try { _add_referral_commission($uid, py_str($result['order']), $provider_charge); } catch (Throwable $e) {}
                push_user_notif($uid, 'order', "تم إرسال طلب #$_did", py_str($svc_name) . " — الكمية: $quantity — $" . py_fmt($site_price), 'order', "order:$_did");
                send_ai_notif($uid, '🤖 طلبك قيد التنفيذ الآن',
                    'أهلاً! استلمت طلبك لخدمة "' . py_str($svc_name) . '" وهو الآن قيد التنفيذ الفعلي. إذا عندك أي سؤال عن سرعة التنفيذ أو أي استفسار آخر أنا هنا للمساعدة 😊');
                try {
                    foreach (get_db()->execute('SELECT id FROM users WHERE is_admin=1')->fetchall() as $_adm) {
                        push_user_notif($_adm['id'], 'admin', "طلب جديد #$_did", py_str($_SESSION['user_name'] ?? 'مستخدم') . ' — ' . py_str($svc_name) . ' — $' . py_fmt($site_price), 'order');
                    }
                } catch (Throwable $_e) {}
                return jsonify(['ok' => true, 'msg' => 'تم إرسال الطلب بنجاح!', 'order_id' => $_did, 'balance' => py_round($new_bal, 2)]);
            } elseif (array_key_exists('error', $result)
                      && (strpos(mb_strtolower(py_str(dget($result, 'error', '')), 'UTF-8'), 'balance') !== false
                          || strpos(mb_strtolower(py_str(dget($result, 'error', '')), 'UTF-8'), 'fund') !== false)) {
                $provider_has_balance = false;
            } else {
                $_err_msg = mb_strtolower(py_str(dget($result, 'error', '')), 'UTF-8');
                $_is_dead = false;
                foreach (_DEAD_KEYWORDS as $kw) { if (strpos($_err_msg, $kw) !== false) { $_is_dead = true; break; } }
                if ($_is_dead) {
                    // رد الرصيد للمستخدم
                    if (!py_truthy($user['is_admin']) && $site_price > 0) {
                        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$site_price, $uid]);
                        $conn->commit();
                    }
                    // إخفاء الخدمة من service_filters
                    $conn->execute("UPDATE service_filters SET hidden=1 WHERE filter_type='service' AND filter_key=?", [py_str($service)]);
                    $conn->commit();
                    _bump_svc_version();
                    return jsonify(['ok' => false, 'msg' => 'هذه الخدمة تم حذفها من المزود ولم تعد متوفرة. تم إرجاع رصيدك.', 'service_removed' => true]);
                }
                return jsonify(['ok' => false, 'msg' => dget($result, 'error', 'حدث خطأ')]);
            }
        } catch (Throwable $e) {
            log_app_error('HANDLED', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            return jsonify(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    if (!$provider_has_balance) {
        $_did = _next_display_id();
        $conn->execute('INSERT INTO orders(user_id,order_id,service,service_name,link,quantity,charge,site_charge,status,refill_days,display_id,provider_id,comments) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$uid, '', sql_str($service), sql_str($svc_name), sql_str($link), $quantity, '0', $site_price, 'Queued', $_refill_days, $_did, $_prov_id, py_or($comments, '')]);
        $conn->commit();
        $new_bal = $conn->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone()['balance'];
        push_user_notif($uid, 'order', 'تم استلام طلبك بنجاح', py_str($svc_name) . ' — طلبك قيد التنفيذ وسيتم معالجته قريباً', 'order');
        send_ai_notif($uid, '🤖 طلبك بانتظار المعالجة',
            'استلمنا طلبك لخدمة "' . py_str($svc_name) . '" وهو قيد المراجعة وسيبدأ التنفيذ قريباً. إذا احتجت مساعدة أو عندك استفسار، تكلم معي مباشرة هنا!');
        try {
            foreach (get_db()->execute('SELECT id FROM users WHERE is_admin=1')->fetchall() as $_adm) {
                push_user_notif($_adm['id'], 'admin', 'طلب بانتظار رصيد المزود', py_str($_SESSION['user_name'] ?? 'مستخدم') . ' — ' . py_str($svc_name) . ' — $' . py_fmt($site_price), 'warning');
            }
        } catch (Throwable $_e) {}
        return jsonify(['ok' => true, 'msg' => 'تم استلام طلبك بنجاح! جاري المعالجة...', 'order_id' => $_did, 'queued' => true, 'balance' => py_round($new_bal, 2)]);
    }
}, ['POST']);

/* '/api/orders'  (app.py:3658) */
route('/api/orders', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'login'], 401);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC, id ASC LIMIT 200', [$_SESSION['user_id']])->fetchall();
    $orders = [];
    foreach ($rows as $r) {
        $_st = $r['status'];
        if ($_st === 'Queued') $_st = 'Pending';
        $orders[] = [
            'id' => $r['id'],
            'order' => py_or($r['display_id'], (string)$r['id']), 'service' => $r['service'],
            'service_name' => $r['service_name'], 'link' => $r['link'],
            'quantity' => $r['quantity'],
            'charge' => ($r->has('site_charge') && py_truthy($r['site_charge'])) ? py_str(py_or($r['site_charge'], 0)) : $r['charge'],
            'status' => $_st,
            'provider_order' => py_or($r['order_id'], ''),
            'refill_days' => $r->has('refill_days') ? $r['refill_days'] : '',
            'created_at' => py_or($r['created_at'], 0),
            'last_refill_at' => $r->has('last_refill_at') ? $r['last_refill_at'] : 0,
        ];
    }
    return jsonify(['ok' => true, 'orders' => $orders]);
});

/* '/api/user-stats'  (app.py:3684) */
route('/api/user-stats', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $user = $conn->execute('SELECT balance, public_id FROM users WHERE id=?', [$uid])->fetchone();
    $bal = $user ? py_float($user['balance']) : 0;
    $_pub_id = ($user && $user->has('public_id')) ? $user['public_id'] : '';
    $row = $conn->execute("SELECT COALESCE(SUM(CAST(charge AS REAL)),0) as total, COUNT(*) as cnt FROM orders WHERE user_id=? AND status='Completed'", [$uid])->fetchone();
    $total_spent = $row ? py_float($row['total']) : 0;
    $completed = $row ? py_int($row['cnt']) : 0;
    $active = $conn->execute("SELECT COUNT(*) FROM orders WHERE user_id=? AND status IN ('Processing','In progress','Queued')", [$uid])->fetchone()[0];
    $pending = $conn->execute("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='Pending'", [$uid])->fetchone()[0];
    $tickets = $conn->execute("SELECT COUNT(*) FROM tickets WHERE user_id=? AND status IN ('open','waiting')", [$uid])->fetchone()[0];
    $site_total_orders = $conn->execute("SELECT display_id FROM orders WHERE display_id!='' AND display_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchone();
    $site_total_orders = $site_total_orders ? $site_total_orders['display_id'] : '0';
    $lv_rows = $conn->execute('SELECT * FROM levels WHERE active=1 ORDER BY min_spent ASC, id ASC')->fetchall();
    $levels = [];
    foreach ($lv_rows as $lv) {
        $benefits = [];
        foreach (explode("\n", py_or($lv['benefits'], '')) as $b) { $b = py_strip($b); if (py_truthy($b)) $benefits[] = $b; }
        $levels[] = [
            'name' => $lv['name'], 'name_en' => $lv['name_en'], 'min' => py_float($lv['min_spent']),
            'discount' => py_float($lv['discount']), 'color' => $lv['color'], 'icon' => $lv['icon'],
            'benefits' => $benefits,
        ];
    }
    if (!$levels) {
        $levels = [['name' => 'عضو', 'name_en' => 'MEMBER', 'min' => 0, 'discount' => 0, 'color' => '#78716c', 'icon' => 'fa-solid fa-user', 'benefits' => []]];
    }
    $current_level = $levels[0];
    $next_level = count($levels) > 1 ? $levels[1] : null;
    foreach ($levels as $i => $lv) {
        if ($total_spent >= $lv['min']) {
            $current_level = $lv;
            $next_level = $i + 1 < count($levels) ? $levels[$i + 1] : null;
        }
    }
    $remaining = $next_level ? py_round($next_level['min'] - $total_spent, 2) : 0;
    if ($next_level) {
        $rng = $next_level['min'] - $current_level['min'];
        $progress = $rng > 0 ? (int)((($total_spent - $current_level['min']) / $rng) * 100) : 100;
    } else {
        $progress = 100;
    }
    return jsonify(['ok' => true, 'balance' => $bal, 'total_spent' => py_round($total_spent, 2), 'completed' => $completed,
        'active' => $active, 'pending' => $pending, 'tickets' => $tickets,
        'public_id' => $_pub_id,
        'level' => $current_level['name_en'], 'level_ar' => $current_level['name'],
        'level_color' => dget($current_level, 'color', '#78716c'), 'level_icon' => dget($current_level, 'icon', ''),
        'level_discount' => dget($current_level, 'discount', 0), 'level_benefits' => dget($current_level, 'benefits', []),
        'next_level' => $next_level ? $next_level['name'] : null,
        'next_level_en' => $next_level ? $next_level['name_en'] : null,
        'next_level_color' => $next_level ? dget($next_level, 'color', '') : '',
        'remaining' => $remaining, 'progress' => min($progress, 100),
        'site_total_orders' => $site_total_orders,
        'all_levels' => $levels]);
});

/* '/api/order-status/<order_id>'  (app.py:3738) */
route('/api/order-status/<order_id>', function ($order_id) {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $_row = $conn->execute('SELECT order_id, provider_id FROM orders WHERE display_id=? AND user_id=?', [$order_id, $uid])->fetchone();
    if (!$_row) {
        $_row = $conn->execute('SELECT order_id, provider_id FROM orders WHERE order_id=? AND user_id=?', [$order_id, $uid])->fetchone();
    }
    if (!$_row || !py_truthy($_row['order_id'])) {
        return jsonify(['ok' => false, 'msg' => 'order not found']);
    }
    $provider_oid = $_row['order_id'];
    $_ord_pid = $_row->has('provider_id') ? $_row['provider_id'] : 0;
    try {
        if (py_truthy($_ord_pid)) { [$_api_url, $_api_key, $_prov_id] = get_provider_api(py_int($_ord_pid)); }
        else { [$_api_url, $_api_key, $_prov_id] = get_provider_api(); }
        $r = requests_post($_api_url, ['data' => ['key' => $_api_key, 'action' => 'status', 'order' => $provider_oid], 'timeout' => 15]);
        $result = $r->json();
        if (is_array($result) && !is_list_array($result) && array_key_exists('status', $result)) {
            $conn = get_db();
            $old = $conn->execute('SELECT * FROM orders WHERE order_id=? AND user_id=?', [$provider_oid, $uid])->fetchone();
            $new_status = dget($result, 'status', '');
            $conn->execute('UPDATE orders SET status=?, charge=? WHERE order_id=? AND user_id=?',
                [sql_str($new_status), sql_str(dget($result, 'charge', '0')), $provider_oid, $uid]);
            if ($old && !py_truthy($old['refunded'])) {
                $site_charge = py_float(py_or($old['site_charge'], 0));
                $qty = py_int(py_or($old['quantity'], 0));
                $remains = py_int(dget($result, 'remains', 0));
                if ($new_status === 'Canceled' && $site_charge > 0) {
                    $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$site_charge, $uid]);
                    $conn->execute('UPDATE orders SET refunded=1 WHERE order_id=? AND user_id=?', [$provider_oid, $uid]);
                    push_user_notif($uid, 'system', 'تم استرجاع $' . py_fmt($site_charge), "طلب #$order_id تم إلغاؤه — تم إرجاع المبلغ لرصيدك", 'wallet', 'wallet:');
                } elseif ($new_status === 'Partial' && $site_charge > 0 && $qty > 0 && $remains > 0) {
                    $refund_amount = py_round($site_charge * ($remains / $qty), 2);
                    if ($refund_amount > 0) {
                        $conn->execute('UPDATE users SET balance=balance+? WHERE id=?', [$refund_amount, $uid]);
                        $conn->execute('UPDATE orders SET refunded=1 WHERE order_id=? AND user_id=?', [$provider_oid, $uid]);
                        push_user_notif($uid, 'system', 'استرجاع جزئي $' . py_fmt($refund_amount), "طلب #$order_id مكتمل جزئياً — تم إرجاع الفرق", 'wallet', 'wallet:');
                    }
                }
            }
            $conn->commit();
        }
        return jsonify(['ok' => true, 'data' => $result]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false]);
    }
});

/* '/api/refill'  (app.py:3868) */
route('/api/refill', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'يرجى تسجيل الدخول'], 401);
    $uid = $_SESSION['user_id'];
    $data = req_data_strict();
    $order_id = dget($data, 'order_id', '');
    if (!py_truthy($order_id)) {
        return jsonify(['ok' => false, 'msg' => 'order_id مطلوب']);
    }
    $conn = get_db();
    $o = $conn->execute('SELECT * FROM orders WHERE display_id=? AND user_id=?', [sql_str($order_id), $uid])->fetchone();
    if (!$o) {
        $o = $conn->execute('SELECT * FROM orders WHERE order_id=? AND user_id=?', [sql_str($order_id), $uid])->fetchone();
    }
    if (!$o) return jsonify(['ok' => false, 'msg' => 'الطلب غير موجود']);
    $rf = py_str(py_or($o['refill_days'], ''));
    if (!py_truthy($rf) || in_array($rf, ['0', 'False', 'false'], true)) {
        return jsonify(['ok' => false, 'msg' => 'هذا الطلب لا يدعم التعويض']);
    }
    if (!in_array($o['status'], ['Completed', 'Partial'], true)) {
        return jsonify(['ok' => false, 'msg' => 'التعويض متاح فقط للطلبات المكتملة']);
    }
    $now = microtime(true);
    $created = py_float(py_or($o['created_at'], 0));
    if ($created && $now - $created < 24 * 3600) {
        return jsonify(['ok' => false, 'msg' => 'التعويض يفتح بعد 24 ساعة من وقت الطلب']);
    }
    $last_rf = py_float(py_or($o['last_refill_at'], 0));
    if ($last_rf && $now - $last_rf < 23 * 3600) {
        return jsonify(['ok' => false, 'msg' => 'تم طلب تعويض مؤخراً، حاول لاحقاً']);
    }
    if (!in_array(mb_strtolower($rf, 'UTF-8'), ['true', '∞'], true)) {
        try {
            $rf_days = py_int($rf);
            if ($created && $now - $created > $rf_days * 86400) {
                return jsonify(['ok' => false, 'msg' => 'انتهت فترة التعويض']);
            }
        } catch (Throwable $e) {}
    }
    try {
        try { $_ord_pid = py_int(py_or($o['provider_id'], 0)); } catch (Throwable $e) { $_ord_pid = 0; }
        if ($_ord_pid) { [$_api_url, $_api_key, $_prov_id] = get_provider_api($_ord_pid); }
        else { [$_api_url, $_api_key, $_prov_id] = get_provider_api(); }
        $r = requests_post($_api_url, ['data' => ['key' => $_api_key, 'action' => 'refill', 'order' => py_str($order_id)], 'timeout' => 15]);
        $res0 = $r->json();
        $res = py_dict($res0);
        if (array_key_exists('refill', $res)) {
            $conn->execute('UPDATE orders SET last_refill_at=?, refill_count=refill_count+1 WHERE id=?', [$now, $o['id']]);
            $conn->commit();
            return jsonify(['ok' => true, 'msg' => 'تم طلب التعويض بنجاح!', 'refill_id' => dget($res, 'refill')]);
        }
        return jsonify(['ok' => false, 'msg' => dget($res, 'error', py_repr($res0))]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => $e->getMessage()]);
    }
}, ['POST']);

/* ---------------------- التعويض التلقائي (app.py:3786-3866) ---------------------- */

const AUTO_REFILL_LOG_MAX = 30;

function _run_auto_refill(): void {
    $lock = file_lock_try('auto_refill');
    if (!$lock) return;
    try { _run_auto_refill_impl(); } finally { file_lock_release($lock); }
}

function _run_auto_refill_impl(): void {
    $now = microtime(true); $total = 0; $success = 0; $failed = 0; $skipped = 0; $results = [];
    $_prov_api_cache = [];
    $_get_api = function ($pid) use (&$_prov_api_cache) {
        $pid = py_int(py_or($pid, 0));
        if (isset($_prov_api_cache[$pid])) return $_prov_api_cache[$pid];
        $ret = $pid ? get_provider_api($pid) : get_provider_api();
        return $_prov_api_cache[$pid] = $ret;
    };
    $conn = get_db();
    try {
        $orders = $conn->execute("SELECT * FROM orders WHERE status IN ('Completed','Partial') AND refill_days!='' AND refill_days IS NOT NULL")->fetchall();
        foreach ($orders as $o) {
            try {
                $rf_val = py_str(py_or($o['refill_days'], ''));
                if (!py_truthy($rf_val) || in_array($rf_val, ['0', 'False', 'false'], true)) continue;
                $_base = ['order' => $o['order_id'], 'user_id' => $o['user_id'], 'service' => py_slice(py_str(py_or($o['service_name'], '')), null, 60), 'rf' => $rf_val];
                $last_rf = py_float(py_or($o['last_refill_at'], 0));
                if ($now - $last_rf < 23 * 3600) {
                    $skipped++; $results[] = $_base + ['status' => 'skipped', 'reason' => 'تم تعويضه مؤخراً']; continue;
                }
                $created = py_float(py_or($o['created_at'], 0));
                if ($created && $now - $created < 24 * 3600) {
                    $skipped++; $results[] = $_base + ['status' => 'skipped', 'reason' => 'طلب جديد (أقل من 24 ساعة)']; continue;
                }
                if (!in_array(mb_strtolower($rf_val, 'UTF-8'), ['true', '∞'], true)) {
                    try {
                        $rf_days = py_int($rf_val);
                        if ($created && $now - $created > $rf_days * 86400) {
                            $skipped++; $results[] = $_base + ['status' => 'skipped', 'reason' => 'انتهت فترة التعويض']; continue;
                        }
                    } catch (Throwable $e) {}
                }
                $total += 1;
                try { $_ord_pid = py_int(py_or($o['provider_id'], 0)); } catch (Throwable $e) { $_ord_pid = 0; }
                [$_api_url, $_api_key, $_prov_id] = $_get_api($_ord_pid);
                try {
                    $r = requests_post($_api_url, ['data' => ['key' => $_api_key, 'action' => 'refill', 'order' => $o['order_id']], 'timeout' => 15]);
                    $res0 = $r->json();
                    $res = ($res0 instanceof stdClass) ? [] : $res0;
                    if (is_array($res) && !is_list_array($res) && array_key_exists('refill', $res)) {
                        $success++;
                        $conn->execute('UPDATE orders SET last_refill_at=?, refill_count=refill_count+1 WHERE id=?', [$now, $o['id']]);
                        $results[] = $_base + ['status' => 'success', 'refill_id' => dget($res, 'refill')];
                    } else {
                        $failed++; $results[] = $_base + ['status' => 'failed', 'error' => py_slice(py_repr($res0), null, 80)];
                    }
                } catch (Throwable $e) {
                    $failed++; $results[] = $_base + ['status' => 'failed', 'error' => py_slice($e->getMessage(), null, 80)];
                }
                sleep(2);
            } catch (Throwable $e) { continue; }
        }
        $conn->commit();
    } finally {
        // لا شيء: الاتصال يُغلق بنهاية العملية
    }
    $log = state_get('auto_refill_log', ['log' => []])['log'] ?? [];
    array_unshift($log, ['date' => date('Y-m-d H:i:s'), 'total' => $total, 'success' => $success, 'failed' => $failed, 'skipped' => $skipped, 'results' => array_slice($results, 0, 100)]);
    state_update('auto_refill_log', ['log' => array_slice($log, 0, AUTO_REFILL_LOG_MAX)], ['log' => []]);
}

/* '/api/admin/auto_refill'  (app.py:3920) */
route('/api/admin/auto_refill', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $action = dget($data, 'action', 'log');
    if ($action === 'log') {
        $log = state_get('auto_refill_log', ['log' => []])['log'] ?? [];
        return jsonify(['ok' => true, 'log' => array_slice($log, 0, 10)]);
    } elseif ($action === 'run_now') {
        defer_after_response(function () { _run_auto_refill(); });
        return jsonify(['ok' => true, 'msg' => 'بدأ التعويض التلقائي...']);
    }
    return jsonify(['ok' => false, 'msg' => 'unknown action']);
}, ['POST']);

/* ---------------------- مفاتيح API وواجهة /api/v2 ---------------------- */

route('/api/generate-key', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $new_key = 'fc_' . bin2hex(random_bytes(20));
    $conn = get_db();
    $conn->execute('UPDATE users SET api_key=? WHERE id=?', [$new_key, $_SESSION['user_id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'key' => $new_key]);
}, ['POST']);

route('/api/get-key', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false], 401);
    $conn = get_db();
    $user = $conn->execute('SELECT api_key FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
    return jsonify(['ok' => true, 'key' => $user ? $user['api_key'] : '']);
});

/* '/api/v2'  (app.py:17182) */
route('/api/v2', function () {
    $data = [];
    try { foreach ($_GET as $k => $v) $data[$k] = $v; } catch (Throwable $e) {}
    try { foreach ($_POST as $k => $v) $data[$k] = $v; } catch (Throwable $e) {}
    try {
        if (req_is_json()) {
            $j = req_json(false, true);
            if (py_truthy($j) && is_array($j) && !is_list_array($j)) {
                foreach ($j as $k => $v) $data[$k] = $v;
            }
        }
    } catch (Throwable $e) {}
    $key = dget($data, 'key', '');
    $action = dget($data, 'action', '');
    if (!py_truthy($key)) {
        return jsonify(['error' => 'invalid_api_key'], 401);
    }
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE api_key=? AND banned=0', [sql_str($key)])->fetchone();
    if (!$user) {
        return jsonify(['error' => 'invalid_api_key'], 401);
    }
    $uid = $user['id'];
    $bal = py_float($user['balance']);
    if ($action === 'services') {
        $svcs = get_smm_services();
        $conn2 = get_db();
        $frows = $conn2->execute('SELECT * FROM service_filters')->fetchall();
        $hidden_svcs = []; $hidden_plats = []; $cat_profits = [];
        foreach ($frows as $f) {
            $ft = $f['filter_type']; $fk = py_str($f['filter_key']); $fp = $f['platform'];
            if (py_truthy($f['hidden'])) {
                if ($ft === 'service') $hidden_svcs[$fk] = true;
                elseif ($ft === 'platform') $hidden_plats[$fk] = true;
            }
            if ($ft === 'category' && $f['profit_pct'] !== null) $cat_profits[py_str($fp) . '|||' . $fk] = $f['profit_pct'];
        }
        $result = [];
        foreach ($svcs as $s) {
            $cat = dget($s, 'category', ''); $name = dget($s, 'name', ''); $sid = py_str(dget($s, 'service', ''));
            $rate = py_float(py_or(dget($s, 'rate', '0'), 0));
            $platform = _detect_platform($cat, $name);
            if (isset($hidden_plats[$platform]) || isset($hidden_svcs[$sid])) continue;
            $ck = $platform . '|||' . py_str($cat);
            if (array_key_exists($ck, $cat_profits) && $cat_profits[$ck] > 0) {
                $rate = py_round($rate * (1 + $cat_profits[$ck] / 100.0), 4);
            }
            $result[] = ['service' => $sid, 'name' => $name, 'category' => $cat, 'rate' => py_str($rate),
                         'min' => py_str(dget($s, 'min', '0')), 'max' => py_str(dget($s, 'max', '0'))];
        }
        return jsonify($result);
    } elseif ($action === 'add') {
        $sid = py_str(dget($data, 'service', '')); $link = py_strip(py_str(dget($data, 'link', '')));
        try { $qty = py_int(dget($data, 'quantity', 0)); }
        catch (Throwable $e) { return jsonify(['error' => 'invalid_quantity'], 400); }
        if (!py_truthy($sid) || !py_truthy($link) || !$qty) return jsonify(['error' => 'missing_params'], 400);
        $svcs = get_all_smm_services();
        $svc = null;
        foreach ($svcs as $s) { if (py_str(dget($s, 'service', '')) === $sid) { $svc = $s; break; } }
        if (!$svc) return jsonify(['error' => 'service_not_found'], 400);
        $mn = py_int(dget($svc, 'min', 0)); $mx = py_int(dget($svc, 'max', 0));
        if ($qty < $mn || $qty > $mx) return jsonify(['error' => 'quantity_out_of_range', 'min' => $mn, 'max' => $mx], 400);
        $rate = py_float(py_or(dget($svc, 'rate', '0'), 0));
        $cat = dget($svc, 'category', '');
        $platform = _detect_platform($cat, dget($svc, 'name', ''));
        $conn2 = get_db();
        foreach ($conn2->execute("SELECT * FROM service_filters WHERE filter_type='category' AND filter_key=? AND platform=?", [sql_str($cat), $platform])->fetchall() as $f) {
            if ($f['profit_pct'] !== null && $f['profit_pct'] > 0) {
                $rate = py_round($rate * (1 + $f['profit_pct'] / 100.0), 4);
            }
        }
        $cost = py_round(($rate * $qty) / 1000.0, 4);
        if (!py_truthy($user['is_admin']) && $bal < $cost) {
            return jsonify(['error' => 'insufficient_balance', 'balance' => py_round($bal, 4), 'cost' => $cost], 400);
        }
        try {
            [$_api_url, $_api_key, $_prov_id] = _find_service_provider($sid);
            $r = requests_post($_api_url, ['data' => ['key' => $_api_key, 'action' => 'add', 'service' => py_str($svc['service']), 'link' => $link, 'quantity' => $qty], 'timeout' => 15]);
            $rec = $r->json();
            if (!(is_array($rec) && !is_list_array($rec) && array_key_exists('order', $rec))) return jsonify(['error' => 'provider_error'], 500);
            $_rf = py_str(dget($svc, 'refill', ''));
            if (in_array($_rf, ['0', 'False', 'false'], true)) $_rf = '';
            $conn3 = get_db();
            if (!py_truthy($user['is_admin'])) {
                $conn3->execute('UPDATE users SET balance=balance-? WHERE id=?', [$cost, $uid]);
            }
            $_did = _next_display_id();
            $conn3->execute('INSERT INTO orders(user_id,order_id,service,service_name,link,quantity,charge,site_charge,status,refill_days,display_id,provider_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                [$uid, py_str($rec['order']), $sid, sql_str(dget($svc, 'name', '')), $link, $qty, py_str(dget($rec, 'charge', 0)), $cost, 'Processing', $_rf, $_did, $_prov_id]);
            $conn3->commit();
            $new_bal = $conn3->execute('SELECT balance FROM users WHERE id=?', [$uid])->fetchone()['balance'];
            return jsonify(['order' => $_did, 'cost' => $cost, 'balance' => py_round($new_bal, 4)]);
        } catch (Throwable $e) {
            return jsonify(['error' => $e->getMessage()], 500);
        }
    } elseif ($action === 'status') {
        $oid = py_str(dget($data, 'order', ''));
        if (!py_truthy($oid)) return jsonify(['error' => 'missing_order_id'], 400);
        try {
            $_conn = get_db();
            $_orow = $_conn->execute('SELECT order_id, provider_id FROM orders WHERE display_id=? AND user_id=?', [$oid, $uid])->fetchone();
            if (!$_orow) {
                $_orow = $_conn->execute('SELECT order_id, provider_id FROM orders WHERE order_id=? AND user_id=?', [$oid, $uid])->fetchone();
            }
            $_poid = ($_orow && py_truthy($_orow['order_id'])) ? $_orow['order_id'] : $oid;
            $_ord_pid = 0;
            try { if ($_orow) $_ord_pid = py_int(py_or($_orow['provider_id'], 0)); } catch (Throwable $e) { $_ord_pid = 0; }
            if ($_ord_pid) { [$_api_url2, $_api_key2, $_prov_id2] = get_provider_api($_ord_pid); }
            else { [$_api_url2, $_api_key2, $_prov_id2] = get_provider_api(); }
            $r = requests_post($_api_url2, ['data' => ['key' => $_api_key2, 'action' => 'status', 'order' => $_poid], 'timeout' => 15]);
            return jsonify($r->json());
        } catch (Throwable $e) {
            return jsonify(['error' => 'status_unavailable'], 500);
        }
    } elseif ($action === 'balance') {
        return jsonify(['balance' => py_str(py_round($bal, 4)), 'currency' => 'USD']);
    } else {
        return jsonify(['error' => 'invalid_action', 'available' => ['services', 'add', 'status', 'balance']], 400);
    }
}, ['GET', 'POST']);
