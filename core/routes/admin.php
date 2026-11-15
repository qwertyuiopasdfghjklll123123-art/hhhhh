<?php
/**
 * لوحة الأدمن - مقابل routes /admin/* بـ webapp.py. مؤجّل لجولة قادمة: الشعار المرفوع
 * (branding logo upload)، موافقة/رفض طلبات الدفع اليدوية (payments) - site_settings هنا
 * تغطي الاسم/الدومين/الأرقام والسعر، وهذا يكفي لتشغيل المنصة فعلياً بدونها.
 */

route('/admin/site_settings', function () {
    if (($r = require_admin()) !== null) return $r;
    $s = get_site_settings();
    return jsonify([
        'domain' => $s['domain'], 'site_name' => $s['site_name'],
        'default_country_code' => $s['default_country_code'],
        'whatsapp_subscribe_number' => $s['whatsapp_subscribe_number'],
        'whatsapp_support_number' => $s['whatsapp_support_number'],
        'plan_price_iqd' => $s['plan_price_iqd'] !== null ? (int)$s['plan_price_iqd'] : PLAN_PRICE_IQD,
    ]);
}, ['GET']);

route('/admin/site_settings', function () {
    if (($r = require_admin()) !== null) return $r;
    get_site_settings(); // يضمن وجود الصف
    $data = req_data();
    $known = array_column(COUNTRY_CODES, 0);
    $cc = trim((string)($data['default_country_code'] ?? DEFAULT_COUNTRY_CODE_FALLBACK));
    if (!in_array($cc, $known, true)) $cc = DEFAULT_COUNTRY_CODE_FALLBACK;
    $conn = get_db();
    $conn->execute(
        'UPDATE site_settings SET site_name=?, default_country_code=?, whatsapp_subscribe_number=?, whatsapp_support_number=?, plan_price_iqd=? WHERE id=1',
        [
            mb_substr(trim((string)($data['site_name'] ?? '')), 0, 60, 'UTF-8'), $cc,
            preg_replace('/\D/', '', (string)($data['whatsapp_subscribe_number'] ?? '')),
            preg_replace('/\D/', '', (string)($data['whatsapp_support_number'] ?? '')),
            max(0, (int)($data['plan_price_iqd'] ?? PLAN_PRICE_IQD)),
        ]
    );
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

route('/admin/customers', function () {
    if (($r = require_admin()) !== null) return $r;
    $rows = get_db()->execute('SELECT * FROM users ORDER BY id ASC')->fetchall();
    return jsonify(array_map(fn($u) => [
        'id' => (int)$u['id'], 'email' => $u['email'], 'phone' => $u['phone'], 'name' => $u['name'],
        'is_admin' => py_truthy($u['is_admin']), 'plan_active' => effective_plan_active($u),
        'trial_ends_at' => $u['trial_ends_at'], 'plan_ends_at' => $u['plan_ends_at'], 'created_at' => $u['created_at'],
    ], $rows));
}, ['GET']);

route('/admin/customers/<int:userId>/plan', function ($userId) {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data();
    $conn = get_db();
    if (py_truthy($data['active'] ?? false)) {
        $days = max(1, (int)($data['days'] ?? PLAN_ACTIVATION_DAYS));
        $conn->execute('UPDATE users SET plan_active=1, plan_ends_at=? WHERE id=?',
            [gmdate('Y-m-d H:i', time() + $days * 86400), $userId]);
        $conn->commit();
        $u = $conn->execute('SELECT * FROM users WHERE id=?', [$userId])->fetchone();
        if ($u && $u['phone']) {
            $sender = wsl_find_sending_account($userId) ?? null;
            // الأدمن نفسه قد ما يملك حساب واتساب متصل بعد - نرسل من أي حساب أدمن متصل لو موجود
            if (!$sender) {
                $anyConnected = $conn->execute("SELECT id FROM wa_accounts")->fetchall();
                foreach ($anyConnected as $a) if ((gateway_status($a['id'])['status'] ?? '') === 'connected') { $sender = $a['id']; break; }
            }
            if ($sender) {
                try { gateway_send($sender, $u['phone'], "تم تفعيل اشتراكك بمنصة واصل لمدة $days يوم. نورتنا 🎉"); }
                catch (GatewayException $e) { log_app_error('WARNING', 'admin plan message failed: ' . $e->getMessage()); }
            }
        }
    } else {
        $conn->execute('UPDATE users SET plan_active=0 WHERE id=?', [$userId]);
        $conn->commit();
    }
    return jsonify(['ok' => true]);
}, ['POST']);

route('/admin/announcement/<accId>', function ($accId) {
    if (($r = require_admin()) !== null) return $r;
    $acc = wsl_owned_account($accId, $_SESSION['user_id']);
    if (!$acc) return jsonify(['ok' => false, 'error' => 'تأكد من تسجيل الدخول لهذا الحساب أولاً'], 400);
    if ((gateway_status($accId)['status'] ?? '') !== 'connected') return jsonify(['ok' => false, 'error' => 'تأكد من تسجيل الدخول لهذا الحساب أولاً'], 400);
    $conn = get_db();
    $active = $conn->execute("SELECT id FROM campaigns WHERE account_id=? AND status IN ('running','paused')", [$accId])->fetchone();
    if ($active) return jsonify(['ok' => false, 'error' => 'فيه حملة أو إعلان شغال حالياً على هذا الحساب'], 400);

    $numbers = array_values(array_unique(array_filter(array_map(
        fn($u) => $u['phone'], $conn->execute('SELECT phone FROM users WHERE phone IS NOT NULL')->fetchall()
    ))));
    if (!$numbers) return jsonify(['ok' => false, 'error' => 'ما فيه مستخدمين عندهم رقم واتساب مسجل'], 400);
    $text = trim((string)req_form('text', ''));
    if ($text === '') return jsonify(['ok' => false, 'error' => 'اكتب نص الإعلان'], 400);
    $delay = max(1, (int)req_form('delay', 15));

    $ins = $conn->execute(
        "INSERT INTO campaigns (account_id, owner, status, text, delay_seconds, total, next_send_at, started_at, created_at) VALUES (?,?,'running',?,?,?,?,?,?)",
        [$accId, $_SESSION['user_id'], $text, $delay, count($numbers), time(), now_str(), now_str()]
    );
    $campaignId = $ins->lastrowid;
    $conn->commit();
    foreach ($numbers as $n) $conn->execute('INSERT INTO campaign_numbers (campaign_id, phone, status) VALUES (?,?,?)', [$campaignId, $n, 'pending']);
    $conn->commit();
    return jsonify(['ok' => true, 'total' => count($numbers)]);
}, ['POST']);

route('/admin/ai_settings', function () {
    if (($r = require_admin()) !== null) return $r;
    $row = get_db()->execute('SELECT * FROM ai_settings WHERE id=1')->fetchone();
    return jsonify(['api_key_set' => (bool)($row['api_key'] ?? ''), 'knowledge_base' => $row['knowledge_base'] ?? '']);
}, ['GET']);

route('/admin/ai_settings', function () {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data();
    $existing = get_db()->execute('SELECT * FROM ai_settings WHERE id=1')->fetchone();
    $apiKey = trim((string)($data['api_key'] ?? ''));
    if ($apiKey === '' && $existing) $apiKey = $existing['api_key'];
    $kb = (string)($data['knowledge_base'] ?? '');
    $conn = get_db();
    $conn->execute(
        "INSERT INTO ai_settings (id, api_key, knowledge_base) VALUES (1,?,?) "
        . "ON CONFLICT(id) DO UPDATE SET api_key=excluded.api_key, knowledge_base=excluded.knowledge_base",
        [$apiKey, $kb]
    );
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);
