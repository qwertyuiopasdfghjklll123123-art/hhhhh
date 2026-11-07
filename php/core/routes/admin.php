<?php
/**
 * لوحة الإدارة: إعدادات الموقع، المفتاح الرئيسي، الشعار وأيقونة PWA، اختبار SMTP، النسخ الاحتياطي،
 * رصيد/تصنيفات المزوّد، المستويات، البحث عن طلب — منقولة من app.py:6296-6819.
 */

/** مفاتيح site_settings التي تُعرض بصفحة الإعدادات (app.py:6311) */
const SITE_SETTINGS_READ_KEYS = [
    'site_name', 'site_name_color', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_tls', 'smtp_email', 'smtp_password', 'smtp_sender_name',
    'backup_enabled', 'backup_interval', 'tg_bot_token', 'tg_chat_id', 'provider_api_url', 'provider_api_key',
    'rate_IQD', 'rate_EUR', 'rate_SAR', 'rate_AED', 'rate_TRY', 'google_client_id',
    'support_whatsapp', 'site_terms', 'site_privacy', 'skip_email_verify',
    'nvidia_api_key', 'nvidia_model', 'ai_logo',
    'daily_bonus_enabled', 'daily_bonus_amount',
    'auto_import_enabled', 'auto_import_profit_pct',
    'ai_notif_enabled', 'ai_inactivity_days',
];

/** المفاتيح المسموح حفظها (app.py:6346) + أسعار كل العملات */
const SITE_SETTINGS_WRITE_KEYS = [
    'site_name', 'site_name_color', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_tls', 'smtp_email', 'smtp_password', 'smtp_sender_name',
    'backup_enabled', 'backup_interval', 'tg_bot_token', 'tg_chat_id', 'provider_api_url', 'provider_api_key',
    'google_client_id', 'nvidia_api_key', 'nvidia_model', 'ai_logo',
    'support_whatsapp', 'site_terms', 'site_privacy', 'skip_email_verify',
    'daily_bonus_enabled', 'daily_bonus_amount',
    'auto_import_enabled', 'auto_import_profit_pct',
    'ai_notif_enabled', 'ai_inactivity_days',
];

route('/api/admin/site-settings', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $out = [];
    $conn = get_db();
    foreach (SITE_SETTINGS_READ_KEYS as $k) {
        $row = $conn->execute('SELECT value FROM site_settings WHERE key=?', [$k])->fetchone();
        $out[$k] = $row ? $row['value'] : '';
    }
    $defaults = ['site_name' => 'fastcrand', 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => '587', 'smtp_tls' => 'TLS',
                 'provider_api_url' => SMM_API_URL, 'provider_api_key' => SMM_API_KEY,
                 'rate_IQD' => '1500', 'rate_EUR' => '0.92', 'rate_SAR' => '3.75', 'rate_AED' => '3.67', 'rate_TRY' => '38.5'];
    foreach ($defaults as $k => $v) {
        if (!py_truthy($out[$k])) $out[$k] = $v;
    }
    $out['has_logo'] = file_exists(LOGO_DIR . '/logo.png') || file_exists(LOGO_DIR . '/logo.jpg');
    $out['has_pwa_icon'] = file_exists(PWA_ICON_DIR . '/icon-192.png');
    $out['pwa_icon_version'] = get_setting('pwa_icon_version', '2');
    return jsonify(['ok' => true, 'settings' => $out]);
});

route('/api/admin/site-settings', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $allowed = array_merge(SITE_SETTINGS_WRITE_KEYS, array_map(fn($c) => "rate_$c", array_keys(CURRENCY_DEFAULT_RATES)));
    // الأصل يستعمل `if k in data` مباشرة (بلا data.get) فجسم غير قاموس لا يفشل إلا إذا طابق مفتاحاً
    $data = req_json();
    if ($data instanceof stdClass || !py_truthy($data)) $data = [];
    if (!is_array($data) || Req::$jsonKind !== '{') {
        if (is_array($data)) {                       // قائمة: k in list ثم data[k] → TypeError عند التطابق
            foreach ($allowed as $k) {
                if (in_array($k, $data, true)) throw new PyTypeError('list indices must be integers or slices, not str');
            }
        } elseif (is_string($data)) {                // نص: k in "text" ثم data[k]
            foreach ($allowed as $k) {
                if (strpos($data, $k) !== false) throw new PyTypeError('string indices must be integers, not \'str\'');
            }
        } else {
            throw new PyTypeError("argument of type '" . py_type_name($data) . "' is not iterable");
        }
        $data = [];
    }
    $conn = get_db();
    foreach ($allowed as $k) {
        if (array_key_exists($k, $data)) {
            $conn->execute('INSERT OR REPLACE INTO site_settings(key,value) VALUES(?,?)', [$k, py_str($data[$k])]);
        }
    }
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

/* ------------------------------ المفتاح الرئيسي (master key) ------------------------------ */

route('/api/admin/master-key', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $mk_value = get_setting('master_key_value', '');
    $mk_enabled = get_setting('master_key_enabled', '0');
    $mk_created = get_setting('master_key_created', '');
    $conn = get_db();
    $logs = $conn->execute('SELECT * FROM master_key_log ORDER BY created_at DESC, id ASC LIMIT 20')->fetchall();
    return jsonify(['ok' => true, 'key' => $mk_value, 'enabled' => ($mk_enabled === '1'),
                    'created_at' => $mk_created, 'logs' => rows_dicts($logs)]);
});

route('/api/admin/master-key/generate', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $new_key = 'mk_' . token_hex(24);
    set_setting('master_key_value', $new_key);
    set_setting('master_key_enabled', '1');
    set_setting('master_key_created', py_str(microtime(true)));
    $conn = get_db();
    $conn->execute('DELETE FROM master_key_log');
    $conn->commit();
    return jsonify(['ok' => true, 'key' => $new_key]);
}, ['POST']);

route('/api/admin/master-key/toggle', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $enabled = py_truthy(dget($data, 'enabled')) ? '1' : '0';
    set_setting('master_key_enabled', $enabled);
    return jsonify(['ok' => true, 'enabled' => ($enabled === '1')]);
}, ['POST']);

route('/api/admin/master-key/revoke', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    set_setting('master_key_value', '');
    set_setting('master_key_enabled', '0');
    set_setting('master_key_created', '');
    $conn = get_db();
    $conn->execute('DELETE FROM master_key_log');
    $conn->commit();
    return jsonify(['ok' => true]);
}, ['POST']);

/* ------------------------------ الشعار وأيقونة PWA ------------------------------ */

/** freq.files.get(name): كائن FileStorage يُعدّ "غير موجود" إذا كان اسم الملف فارغاً */
function _upload_file(string $field): ?array {
    $f = $_FILES[$field] ?? null;
    if (!is_array($f) || is_array($f['name'] ?? null)) return null;
    if ((string)($f['name'] ?? '') === '') return null;
    return $f;
}

function _upload_ext(string $filename, string $default): string {
    $p = strrpos($filename, '.');
    return $p === false ? $default : mb_strtolower(substr($filename, $p + 1), 'UTF-8');
}

/** f.save(path) */
function _upload_save(array $f, string $path): bool {
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || (int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) return false;
    if (is_uploaded_file($tmp)) return move_uploaded_file($tmp, $path);
    return @copy($tmp, $path);
}

route('/api/admin/upload-logo', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $f = _upload_file('logo');
    if (!$f) return jsonify(['ok' => false, 'error' => 'no file']);
    $ext = _upload_ext((string)$f['name'], 'png');
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'webp'], true)) {
        return jsonify(['ok' => false, 'error' => 'invalid format']);
    }
    foreach (glob(LOGO_DIR . '/logo.*') ?: [] as $old) @unlink($old);
    if (!_upload_save($f, LOGO_DIR . "/logo.$ext")) {
        throw new RuntimeException('upload failed (code ' . (int)($f['error'] ?? 0) . ') — تحقق من upload_max_filesize/post_max_size');
    }
    return jsonify(['ok' => true, 'url' => '/api/admin/logo?t=' . time()]);
}, ['POST']);

route('/api/admin/logo', function () {
    foreach (['png', 'jpg', 'jpeg', 'svg', 'webp'] as $ext) {
        $path = LOGO_DIR . "/logo.$ext";
        if (file_exists($path)) { $r = send_file($path, null, false, null, 86400); $r->headers['X-Content-Type-Options'] = 'nosniff'; return $r; }
    }
    return new Response('', 404);
});

route('/api/admin/delete-logo', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    foreach (glob(LOGO_DIR . '/logo.*') ?: [] as $old) @unlink($old);
    return jsonify(['ok' => true]);
}, ['POST']);

/**
 * معالجة صورة أيقونة PWA (Pillow بالأصل → GD هنا): قصّ مربع من الوسط ثم تصغير إلى 192 و512 وحفظ PNG بشفافية.
 * @throws RuntimeException إن تعذّر فتح الصورة
 */
function _pwa_make_icons(string $raw): void {
    $img = @imagecreatefromstring($raw);
    if (!$img) throw new RuntimeException('cannot identify image file <_io.BytesIO object at 0x7f3a5c1d2e50>');
    if (!imageistruecolor($img)) imagepalettetotruecolor($img);
    $w = imagesx($img); $h = imagesy($img);
    if ($w !== $h) {
        $m = min($w, $h);
        $c = imagecrop($img, ['x' => intdiv($w - $m, 2), 'y' => intdiv($h - $m, 2), 'width' => $m, 'height' => $m]);
        if (!$c) throw new RuntimeException('image crop failed');
        imagedestroy($img);
        $img = $c; $w = $h = $m;
    }
    foreach ([192, 512] as $sz) {
        $dst = imagecreatetruecolor($sz, $sz);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $sz, $sz, $w, $h);
        $out_path = PWA_ICON_DIR . "/icon-$sz.png";
        if (!imagepng($dst, $out_path, 9)) throw new RuntimeException("cannot write $out_path");
        imagedestroy($dst);
    }
    imagedestroy($img);
}

route('/api/admin/upload-pwa-icon', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $f = _upload_file('icon');
    if (!$f) return jsonify(['ok' => false, 'error' => 'no file']);
    $ext = _upload_ext((string)$f['name'], '');
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        return jsonify(['ok' => false, 'error' => 'invalid format — PNG/JPG/WEBP فقط']);
    }
    $raw = @file_get_contents((string)($f['tmp_name'] ?? ''));
    if ($raw === false || (int)($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        return jsonify(['ok' => false, 'error' => 'تعذّر قراءة الملف: upload error ' . (int)($f['error'] ?? 0)]);
    }
    if (strlen($raw) > 5 * 1024 * 1024) return jsonify(['ok' => false, 'error' => 'الملف كبير جداً — الحد 5MB']);
    if (strlen($raw) < 32) return jsonify(['ok' => false, 'error' => 'الملف فارغ أو تالف']);
    try {
        _pwa_make_icons($raw);
        $cur_v = py_int(py_or(get_setting('pwa_icon_version', '2'), '2'));
        set_setting('pwa_icon_version', py_str($cur_v + 1));
        return jsonify(['ok' => true, 'version' => $cur_v + 1, 'ts' => time(), 'warning' => null]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'error' => $e->getMessage()]);
    }
}, ['POST']);

route('/api/admin/delete-pwa-icon', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    foreach ([192, 512] as $sz) {
        $p = PWA_ICON_DIR . "/icon-$sz.png";
        if (file_exists($p)) @unlink($p);
    }
    $cur_v = py_int(py_or(get_setting('pwa_icon_version', '2'), '2'));
    set_setting('pwa_icon_version', py_str($cur_v + 1));
    return jsonify(['ok' => true, 'version' => $cur_v + 1]);
}, ['POST']);

route('/api/admin/pwa-icon-preview', function () {
    if (!_admin_check()) return new Response('', 403);
    $custom = PWA_ICON_DIR . '/icon-192.png';
    if (file_exists($custom)) return send_file($custom, 'image/png');
    return redirect('/pwa-icon-192.png?t=' . time());
});

/* ------------------------------ اختبار SMTP ------------------------------ */

route('/api/admin/smtp-test', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $host = dget($data, 'host', 'smtp.gmail.com');
    $port = py_int(dget($data, 'port', 587));
    $tls = dget($data, 'tls', 'TLS');
    $email = dget($data, 'email', '');
    $password = dget($data, 'password', '');
    $sender_name = dget($data, 'sender_name', 'fastcrand');
    $_sname = get_setting('site_name', 'fastcrand');
    try {
        $fromHeader = py_str($sender_name) . ' <' . py_str($email) . '>';
        $raw = build_plain_mail($fromHeader, py_str($email), "تجربة SMTP — {$_sname}", "هذه رسالة تجريبية من {$_sname} لفحص إعدادات SMTP ");
        $c = new SmtpClient(py_str($host), $port, $tls === 'SSL' ? 'SSL' : 'PLAIN', 10.0);
        try {
            $c->ehlo();
            if ($tls === 'TLS') { $c->starttls(); $c->ehlo(); }
            $c->login(py_str($email), py_str($password));
            $c->send(py_str($email), py_str($email), $raw);
            $c->quit();
        } catch (Throwable $e) {
            $c->close();     // الأصل لا يستدعي server.quit() عند الفشل
            throw $e;
        }
        return jsonify(['ok' => true]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'error' => $e->getMessage()]);
    }
}, ['POST']);

/* ------------------------------ النسخ الاحتياطي ------------------------------ */

route('/api/admin/backup-now', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $name = create_backup('manual');
    if ($name) {
        send_backup_telegram($name);
        return jsonify(['ok' => true, 'name' => $name]);
    }
    return jsonify(['ok' => false, 'error' => 'backup failed']);
}, ['POST']);

route('/api/admin/send-backup-tg', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $bot_token = get_setting('tg_bot_token', '');
    $chat_id = get_setting('tg_chat_id', '');
    if (!py_truthy($bot_token) || !py_truthy($chat_id)) {
        return jsonify(['ok' => false, 'error' => 'أدخل Bot Token و Chat ID أولاً']);
    }
    $sent = send_backup_telegram_blocking();
    if ($sent) return jsonify(['ok' => true, 'msg' => 'تم إرسال النسخة للتلغرام بنجاح']);
    return jsonify(['ok' => false, 'error' => 'فشل الإرسال — تأكد من التوكن والـ Chat ID أو استخدم VPN']);
}, ['POST']);

route('/api/admin/backups', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $bks = array_slice(list_backups(), 0, 10);
    return jsonify(['ok' => true, 'backups' => array_map(fn($b) => ['name' => $b['name'], 'size' => $b['size'], 'time' => $b['timestamp']], $bks)]);
});

/* ------------------------------ رصيد المزوّد وتصنيفاته ------------------------------ */

route('/api/admin/provider-balance', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    try {
        [$api_url, $api_key, $_prov_id] = get_provider_api();
        $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'balance'], 'timeout' => 15]);
        $d = py_dict($r->json());
        return jsonify(['ok' => true, 'balance' => py_float(dget($d, 'balance', 0)), 'currency' => dget($d, 'currency', 'USD')]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'error' => $e->getMessage()]);
    }
});

route('/api/admin/provider-categories', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    try {
        [$api_url, $api_key, $_prov_id] = get_provider_api();
        $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'services'], 'timeout' => 20]);
        $svcs = $r->json();
        if (!is_array($svcs) || !is_list_array($svcs)) {
            return jsonify(['ok' => false, 'error' => 'Invalid API response']);
        }
        $platforms = [];
        foreach ($svcs as $s) {
            $s = py_dict($s);
            $cat = dget($s, 'category', '');
            $name = dget($s, 'name', '');
            $platform = _detect_platform($cat, $name);
            $ck = is_string($cat) ? $cat : py_str($cat);
            if (!array_key_exists($platform, $platforms)) $platforms[$platform] = ['categories' => [], 'count' => 0];
            if (!array_key_exists($ck, $platforms[$platform]['categories'])) $platforms[$platform]['categories'][$ck] = 0;
            $platforms[$platform]['categories'][$ck] += 1;
            $platforms[$platform]['count'] += 1;
        }
        foreach ($platforms as $pn => $pv) $platforms[$pn]['categories'] = (object)$pv['categories'];
        return jsonify(['ok' => true, 'platforms' => jobj($platforms), 'total' => count($svcs)]);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'error' => $e->getMessage()]);
    }
});

route('/api/admin/provider-save', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $api_url = py_strip_s(dget($data, 'api_url', ''));
    $api_key = py_strip_s(dget($data, 'api_key', ''));
    if (!py_truthy($api_url) || !py_truthy($api_key)) {
        return jsonify(['ok' => false, 'error' => 'يجب إدخال الرابط والمفتاح']);
    }
    $conn = get_db();
    $conn->execute('INSERT OR REPLACE INTO site_settings(key,value) VALUES(?,?)', ['provider_api_url', $api_url]);
    $conn->execute('INSERT OR REPLACE INTO site_settings(key,value) VALUES(?,?)', ['provider_api_key', $api_key]);
    $defp = $conn->execute('SELECT id FROM providers WHERE is_default=1 ORDER BY id ASC LIMIT 1')->fetchone();
    if ($defp) {
        $conn->execute('UPDATE providers SET api_url=?, api_key=? WHERE id=?', [$api_url, $api_key, $defp['id']]);
    }
    $conn->commit();
    clear_services_cache(true, false);
    return jsonify(['ok' => true]);
}, ['POST']);

route('/api/admin/refresh-services', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    clear_services_cache(true, false);
    try {
        [$api_url, $api_key, $_prov_id] = get_provider_api();
        $r = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'services'], 'timeout' => 15]);
        $data = $r->json();
        if (is_array($data) && is_list_array($data) && count($data) > 0) {
            cache_file_write(svc_cache_file(), $data, microtime(true));
            return jsonify(['ok' => true, 'msg' => 'تم تحديث ' . count($data) . ' خدمة بنجاح', 'count' => count($data)]);
        }
        return jsonify(['ok' => false, 'msg' => 'لم يتم استلام بيانات من المزود']);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => $e->getMessage()]);
    }
}, ['POST']);

/* ------------------------------ المستويات (levels) ------------------------------ */

route('/api/admin/levels', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM levels ORDER BY min_spent ASC, id ASC')->fetchall();
    $spent_rows = $conn->execute("SELECT user_id, COALESCE(SUM(CAST(charge AS REAL)),0) as total FROM orders WHERE status='Completed' GROUP BY user_id")->fetchall();
    $all_users = $conn->execute('SELECT id FROM users')->fetchall();
    // user_spent: قاموس بايثون مفتاحه user_id (قد يكون None) — نحاكيه بمفتاح نصي مع علامة للقيمة الفارغة
    $user_spent = [];
    $uk = fn($id) => $id === null ? "\0null" : 'i' . $id;
    foreach ($spent_rows as $r) $user_spent[$uk($r['user_id'])] = py_float($r['total']);
    foreach ($all_users as $u) {
        if (!array_key_exists($uk($u['id']), $user_spent)) $user_spent[$uk($u['id'])] = 0.0;
    }
    $lvs = rows_dicts($rows);
    foreach ($lvs as $i => $lv) {
        $ben = [];
        foreach (explode("\n", (string)py_or($lv['benefits'], '')) as $b) {
            $b = py_strip($b);
            if ($b !== '') $ben[] = $b;
        }
        $lvs[$i]['benefits'] = $ben;
        $lvs[$i]['users'] = 0;
    }
    $active_idx = [];
    foreach ($lvs as $i => $lv) { if (py_truthy($lv['active'] ?? null)) $active_idx[] = $i; }
    usort($active_idx, fn($a, $b) => py_float($lvs[$a]['min_spent']) <=> py_float($lvs[$b]['min_spent']));
    foreach ($user_spent as $total) {
        $matched = null;
        foreach ($active_idx as $i) {
            if ($total >= py_float($lvs[$i]['min_spent'])) $matched = $i;
        }
        if ($matched !== null) $lvs[$matched]['users'] += 1;
    }
    return jsonify(['ok' => true, 'levels' => $lvs]);
});

/** يقرأ حقول المستوى من الطلب (مشترك بين الإضافة والتعديل) — يرجع [name,name_en,min_spent,discount,color,icon,benefits,active] أو Response */
function _level_fields(array $d) {
    $name = py_strip_s(dget($d, 'name', ''));
    $name_en = py_upper_s(py_strip_s(dget($d, 'name_en', '')));
    if (!py_truthy($name) || !py_truthy($name_en)) return jsonify(['ok' => false, 'msg' => 'الاسم مطلوب']);
    $min_spent = py_float(py_or(dget($d, 'min_spent', 0), 0));
    $discount = py_float(py_or(dget($d, 'discount', 0), 0));
    $color = dget($d, 'color', '#78716c');
    $icon = dget($d, 'icon', 'fa-solid fa-seedling');
    $ben = [];
    foreach (py_iter(dget($d, 'benefits', [])) as $b) {
        $bs = py_strip_s($b);
        if (py_truthy($bs)) $ben[] = $bs;
    }
    $benefits = implode("\n", $ben);
    $active = py_truthy(dget($d, 'active', true)) ? 1 : 0;
    return [$name, $name_en, $min_spent, $discount, sql_text($color), sql_text($icon), $benefits, $active];
}

route('/api/admin/levels', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $d = req_data();
    $f = _level_fields($d);
    if ($f instanceof Response) return $f;
    [$name, $name_en, $min_spent, $discount, $color, $icon, $benefits, $active] = $f;
    $conn = get_db();
    $mx = py_or($conn->execute('SELECT MAX(sort_order) as mx FROM levels')->fetchone()['mx'], 0);
    $conn->execute('INSERT INTO levels(name,name_en,min_spent,discount,color,icon,benefits,active,sort_order) VALUES(?,?,?,?,?,?,?,?,?)',
        [$name, $name_en, $min_spent, $discount, $color, $icon, $benefits, $active, $mx + 1]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تمت الإضافة']);
}, ['POST']);

route('/api/admin/levels/<int:lid>', function ($lid) {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $d = req_data();
    $f = _level_fields($d);
    if ($f instanceof Response) return $f;
    [$name, $name_en, $min_spent, $discount, $color, $icon, $benefits, $active] = $f;
    $conn = get_db();
    $conn->execute('UPDATE levels SET name=?,name_en=?,min_spent=?,discount=?,color=?,icon=?,benefits=?,active=? WHERE id=?',
        [$name, $name_en, $min_spent, $discount, $color, $icon, $benefits, $active, $lid]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم التحديث']);
}, ['PUT']);

route('/api/admin/levels/<int:lid>', function ($lid) {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $cnt = $conn->execute('SELECT COUNT(*) FROM levels')->fetchone()[0];
    if ($cnt <= 1) return jsonify(['ok' => false, 'msg' => 'لا يمكن حذف آخر مستوى']);
    $conn->execute('DELETE FROM levels WHERE id=?', [$lid]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم الحذف']);
}, ['DELETE']);

route('/api/admin/levels/toggle/<int:lid>', function ($lid) {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $conn = get_db();
    $lv = $conn->execute('SELECT active FROM levels WHERE id=?', [$lid])->fetchone();
    if (!$lv) return jsonify(['ok' => false]);
    $new_active = py_truthy($lv['active']) ? 0 : 1;
    $conn->execute('UPDATE levels SET active=? WHERE id=?', [$new_active, $lid]);
    $conn->commit();
    return jsonify(['ok' => true, 'active' => (bool)$new_active]);
}, ['POST']);

/* ------------------------------ البحث عن طلب (رقم المزوّد أو رقم العرض) ------------------------------ */

route('/api/admin/order_lookup', function () {
    if (!_admin_check()) return jsonify(['ok' => false, 'error' => 'unauthorized'], 403);
    $data = req_data();
    $order_id = py_strip(py_str(dget($data, 'order_id', '')));
    if (!py_truthy($order_id)) return jsonify(['ok' => false, 'error' => 'أدخل رقم الطلب أو رقم العرض']);
    $conn = get_db();
    // الأصل: WHERE order_id=? OR display_id=? LIMIT 1 — مسح كامل بترتيب rowid فيعود أصغر id مطابق (نثبّته صراحةً بـ ORDER BY)
    $row = $conn->execute('SELECT o.*, u.username, u.name, u.public_id
                          FROM orders o LEFT JOIN users u ON o.user_id = u.id
                          WHERE o.order_id=? OR o.display_id=? ORDER BY o.id ASC LIMIT 1', [$order_id, $order_id])->fetchone();
    $local = []; $owner = [];
    $real_provider_order_id = $order_id;
    if ($row) {
        $real_provider_order_id = py_str(py_or($row['order_id'], $order_id));
        $local = [
            'service' => py_or($row['service_name'], ''),
            'service_id' => py_str(py_or($row['service'], '')),
            'qty' => py_or($row['quantity'], 0),
            'link' => py_or($row['link'], ''),
            'cost' => py_float(py_or($row['site_charge'], 0)),
            'provider_cost' => py_truthy($row['charge']) ? py_float($row['charge']) : 0,
            'date' => $row->has('created_at') ? $row['created_at'] : '',
            'status' => py_or($row['status'], ''),
            'display_id' => py_or($row['display_id'], ''),
        ];
        $owner = [
            'uid' => py_str(py_or($row['user_id'], '')),
            'name' => py_or(py_or($row['name'], $row['username']), ''),
            'public_id' => py_or($row['public_id'], ''),
        ];
    }
    $provider = [];
    try {
        $_ord_pid = 0;
        if ($row) {
            try { $_ord_pid = py_int(py_or($row['provider_id'], 0)); } catch (Throwable $e) { $_ord_pid = 0; }
        }
        [$api_url, $api_key] = $_ord_pid ? get_provider_api($_ord_pid) : get_provider_api();
        $rr = requests_post($api_url, ['data' => ['key' => $api_key, 'action' => 'status', 'order' => $real_provider_order_id], 'timeout' => 12]);
        $rec = $rr->json();
        if ($rec instanceof stdClass) $provider = [];
        elseif (is_array($rec) && !is_list_array($rec)) $provider = $rec;
    } catch (Throwable $e) {}
    if (!py_truthy($provider) && !py_truthy($local)) return jsonify(['ok' => false, 'error' => 'الطلب غير موجود']);
    return jsonify(['ok' => true, 'provider' => jobj($provider), 'local' => jobj($local), 'owner' => jobj($owner)]);
}, ['POST']);
