<?php
/**
 * صفحات الواجهة + PWA — منقولة من app.py (الأسطر المذكورة بجانب كل مسار).
 * القوالب (landing/dash/order_page/api_docs/banned/sw.js) تُخدَّم حرفياً من app/templates.
 */

/* '/'  (app.py:7232) */
route('/', function () {
    if (array_key_exists('user_id', $_SESSION)) return redirect('/app');
    $site_name = get_setting('site_name', 'fastcrand');
    $html = tpl('landing.html');
    $html = str_replace('__GOOGLE_CLIENT_ID__', _get_google_client_id(), $html);
    $html = str_replace('__SITE_NAME__', $site_name, $html);
    $html = str_replace('__PWA_ICON_VER__', get_setting('pwa_icon_version', '2'), $html);
    $html = str_replace('__AI_ICON__', get_ai_icon_data_uri(), $html);
    $resp = make_response($html);
    $resp->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
    return $resp;
});

/** صفحة الحظر (app.py:7983 _banned_page) — الاستبدال بلا تهريب HTML تماماً كما بالأصل */
function _banned_page($name, $email, $site_name): string {
    return strtr(tpl('banned.html'), [
        '__FC_SITE_NAME__' => (string)$site_name,
        '__FC_NAME__' => (string)$name,
        '__FC_EMAIL__' => (string)$email,
    ]);
}

/* '/api/banned-contact'  (app.py:8054) */
route('/api/banned-contact', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $uid = $_SESSION['user_id'];
    $conn = get_db();
    $u = $conn->execute('SELECT banned,name FROM users WHERE id=?', [$uid])->fetchone();
    if (!$u || !py_truthy($u['banned'])) {
        return jsonify(['ok' => false, 'msg' => 'الحساب غير محظور']);
    }
    $data = py_or(req_json(), []);
    $msg = py_slice(py_strip(py_or(dget($data, 'message'), '')), null, 500);
    if (!py_truthy($msg)) {
        return jsonify(['ok' => false, 'msg' => 'اكتب رسالتك']);
    }
    $cur = $conn->execute('INSERT INTO tickets(user_id,subject,category,status) VALUES(?,?,?,?)',
        [$uid, 'طلب إلغاء حظر', 'حظر', 'open']);
    $tid = $cur->lastrowid;
    $conn->execute('INSERT INTO ticket_messages(ticket_id,sender,text) VALUES(?,?,?)', [$tid, 'user', $msg]);
    $conn->commit();
    foreach ($conn->execute('SELECT id FROM users WHERE is_admin=1')->fetchall() as $a) {
        push_user_notif($a['id'], 'admin', "طلب إلغاء حظر #TK-$tid", 'من ' . $u['name'], 'warning', "ticket:$tid");
    }
    return jsonify(['ok' => true, 'msg' => 'تم إرسال رسالتك بنجاح']);
}, ['POST']);

/* '/app'  (app.py:8080) */
route('/app', function () {
    if (!array_key_exists('user_id', $_SESSION)) return redirect('/');
    $conn = get_db();
    $_bu = $conn->execute('SELECT banned,name,email FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
    if ($_bu && py_truthy($_bu['banned'])) {
        $site_name = get_setting('site_name', 'fastcrand');
        return make_response(_banned_page(py_truthy($_bu['name']) ? $_bu['name'] : '', py_truthy($_bu['email']) ? $_bu['email'] : '', $site_name));
    }
    $uname = $_SESSION['user_name'] ?? '';
    $uinit = py_truthy($uname) ? mb_substr((string)$uname, 0, 1, 'UTF-8') : '?';
    $uemail = $_SESSION['user_email'] ?? '';
    $is_admin = $_SESSION['is_admin'] ?? false;
    $conn = get_db();
    $urow = $conn->execute('SELECT avatar, last_seen FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
    // نحدّث last_seen فقط إذا مضى أكثر من 10 دقائق
    if ($urow && microtime(true) - py_float(py_truthy($urow['last_seen']) ? $urow['last_seen'] : 0) > 600) {
        $conn->execute('UPDATE users SET last_seen=? WHERE id=?', [microtime(true), $_SESSION['user_id']]);
        $conn->commit();
    }
    $avatar_url = py_or(($urow && py_truthy($urow['avatar'])) ? $urow['avatar'] : '', '');
    $site_name = get_setting('site_name', 'fastcrand');
    $_snc_raw = get_setting('site_name_color', '');
    $_brand_bg = 'linear-gradient(135deg,var(--primary),#3b82f6)';
    if (py_truthy($_snc_raw)) {
        try {
            $_snc = json_loads($_snc_raw);
            if (is_array($_snc) && !is_list_array($_snc)) {
                if (dget($_snc, 'type') === 'solid') {
                    $_brand_bg = py_str(dget($_snc, 'color', '#2563eb'));
                } elseif (dget($_snc, 'type') === 'gradient') {
                    $_brand_bg = 'linear-gradient(' . py_str(dget($_snc, 'angle', 135)) . 'deg,' . py_str(dget($_snc, 'from', '#2563eb')) . ',' . py_str(dget($_snc, 'to', '#3b82f6')) . ')';
                }
            }
        } catch (Throwable $e) {}
    }
    $html = tpl('dash.html');
    $html = str_replace('__UNAME__', py_str($uname), $html);
    $html = str_replace('__UINIT__', $uinit, $html);
    $html = str_replace('__UEMAIL__', py_str($uemail), $html);
    $html = str_replace('__AVATAR_URL__', py_str($avatar_url), $html);
    $html = str_replace('__SITE_NAME__', $site_name, $html);
    $html = str_replace('__BRAND_COLOR_BG__', $_brand_bg, $html);
    $html = str_replace('__PWA_ICON_VER__', get_setting('pwa_icon_version', '2'), $html);
    $html = str_replace('__AI_ICON__', get_ai_icon_data_uri(), $html);
    if (py_truthy($is_admin)) {
        $html = str_replace('__ADMIN_DISPLAY__', '', $html);
        $html = str_replace('__NONADMIN_DISPLAY__', 'display:none !important;', $html);
        $html = str_replace('__ADMIN_CLASS__', '', $html);
    } else {
        $html = str_replace('__ADMIN_DISPLAY__', 'display:none !important;', $html);
        $html = str_replace('__NONADMIN_DISPLAY__', '', $html);
        $html = str_replace('__ADMIN_CLASS__', 'hide-admin', $html);
    }
    $resp = make_response($html);
    $resp->headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
    $resp->headers['Pragma'] = 'no-cache';
    $resp->headers['Expires'] = '0';
    return $resp;
});

/* '/order/<platform>'  (app.py:17299) */
route('/order/<platform>', function ($platform) {
    if (!array_key_exists('user_id', $_SESSION)) return redirect('/');
    $platform = str_replace('%20', ' ', $platform);
    $html = str_replace('__PLATFORM__', $platform, tpl('order_page.html'));
    $html = str_replace('__SITE_NAME__', get_setting('site_name', 'fastcrand'), $html);
    return make_response($html);
});

/* '/api/docs'  (app.py:17551) */
route('/api/docs', function () {
    $base = rtrim(req_host_url(), '/');
    $html = str_replace('__BASE__', $base, tpl('api_docs.html'));
    $html = str_replace('__SITE_NAME__', get_setting('site_name', 'fastcrand'), $html);
    return make_response($html);
});

/* ---------------------------------- PWA ---------------------------------- */

/** SVG الافتراضي للأيقونة (app.py:1855-1888) — نفس الحسابات الصحيحة بالضبط */
function pwa_icon_svg(int $s): string {
    $r = (int)($s * 0.22);
    $bx = (int)($s * 0.25); $by = (int)($s * 0.28); $bs = (int)($s * 0.28); $br = (int)($bs * 0.25);
    $ax1 = $bx + (int)($bs * 0.25); $ax2 = $bx + (int)($bs * 0.75); $ay = $by + intdiv($bs, 2);
    $ah = (int)($bs * 0.22);
    $sw = max(2, (int)($s * 0.025));
    $fs = (int)($s * 0.35);
    $tx = (int)($s * 0.65);
    $ty = (int)($s * 0.58);
    $lsw = max(1, (int)($s * 0.015));
    $sfs = (int)($s * 0.065);
    $sy = (int)($s * 0.88);
    $i = fn($v) => (int)($s * $v);
    $ls = (int)($s * 0.02);
    $h = intdiv($s, 2);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $s . '" height="' . $s . '" viewBox="0 0 ' . $s . ' ' . $s . '">
    <defs>
      <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#0f1c3f"/><stop offset="100%" stop-color="#1d4ed8"/></linearGradient>
    </defs>
    <rect width="' . $s . '" height="' . $s . '" rx="' . $r . '" fill="url(#bg)"/>
    <line x1="' . $i(0.05) . '" y1="' . $i(0.26) . '" x2="' . $i(0.38) . '" y2="' . $i(0.26) . '" stroke="rgba(96,165,250,0.18)" stroke-width="' . $lsw . '" stroke-linecap="round"/>
    <line x1="' . $i(0.03) . '" y1="' . $i(0.42) . '" x2="' . $i(0.42) . '" y2="' . $i(0.42) . '" stroke="rgba(96,165,250,0.12)" stroke-width="' . $lsw . '" stroke-linecap="round"/>
    <line x1="' . $i(0.08) . '" y1="' . $i(0.58) . '" x2="' . $i(0.32) . '" y2="' . $i(0.58) . '" stroke="rgba(96,165,250,0.08)" stroke-width="' . $lsw . '" stroke-linecap="round"/>
    <line x1="' . $i(0.55) . '" y1="' . $i(0.72) . '" x2="' . $i(0.92) . '" y2="' . $i(0.72) . '" stroke="rgba(96,165,250,0.14)" stroke-width="' . $lsw . '" stroke-linecap="round"/>
    <line x1="' . $i(0.60) . '" y1="' . $i(0.82) . '" x2="' . $i(0.95) . '" y2="' . $i(0.82) . '" stroke="rgba(96,165,250,0.08)" stroke-width="' . $lsw . '" stroke-linecap="round"/>
    <rect x="' . $bx . '" y="' . $by . '" width="' . $bs . '" height="' . $bs . '" rx="' . $br . '" fill="#fbbf24"/>
    <line x1="' . $ax1 . '" y1="' . $ay . '" x2="' . $ax2 . '" y2="' . $ay . '" stroke="#0f1c3f" stroke-width="' . $sw . '" stroke-linecap="round"/>
    <line x1="' . $ax2 . '" y1="' . $ay . '" x2="' . ($ax2 - $ah) . '" y2="' . ($ay - $ah) . '" stroke="#0f1c3f" stroke-width="' . $sw . '" stroke-linecap="round"/>
    <line x1="' . $ax2 . '" y1="' . $ay . '" x2="' . ($ax2 - $ah) . '" y2="' . ($ay + $ah) . '" stroke="#0f1c3f" stroke-width="' . $sw . '" stroke-linecap="round"/>
    <text x="' . $tx . '" y="' . $ty . '" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="' . $fs . '" font-weight="900" fill="white">fc</text>
    <text x="' . $h . '" y="' . $sy . '" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="' . $sfs . '" font-weight="600" fill="rgba(255,255,255,0.2)" letter-spacing="' . $ls . '">FASTCRAND</text>
    </svg>';
}

/* '/pwa-icon-192.png' و '/pwa-icon-512.png'  (app.py:1846) */
$_serve_pwa_icon = function () {
    $size = strpos(req_path(), '512') !== false ? 512 : 192;
    $custom_path = PWA_ICON_DIR . "/icon-$size.png";
    if (file_exists($custom_path)) {
        $resp = send_file($custom_path, 'image/png');
        $resp->headers['Cache-Control'] = 'public, max-age=3600';
        return $resp;
    }
    $svg = pwa_icon_svg($size);
    // نظير cairosvg: إن وُجد Imagick نُرجع PNG، وإلا نُرجع SVG كما يفعل الأصل عند غياب cairosvg
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setBackgroundColor(new ImagickPixel('transparent'));
            $im->readImageBlob($svg);
            $im->setImageFormat('png32');
            $im->resizeImage($size, $size, Imagick::FILTER_LANCZOS, 1);
            $png = $im->getImageBlob();
            $resp = make_response($png);
            $resp->headers['Content-Type'] = 'image/png';
            $resp->headers['Cache-Control'] = 'public, max-age=604800';
            return $resp;
        } catch (Throwable $e) {}
    }
    $resp = make_response($svg);
    $resp->headers['Content-Type'] = 'image/svg+xml';
    return $resp;
};
route('/pwa-icon-192.png', $_serve_pwa_icon);
route('/pwa-icon-512.png', $_serve_pwa_icon);

/* '/favicon.ico' و '/favicon.svg'  (app.py:1894-1900) */
route('/favicon.ico', fn() => redirect('/pwa-icon-192.png', 301));
route('/favicon.svg', fn() => redirect('/pwa-icon-192.png', 301));

/* '/manifest.json'  (app.py:1903) */
route('/manifest.json', function () {
    $sn = get_setting('site_name', 'fastcrand');
    $icon_v = get_setting('pwa_icon_version', '2');
    $manifest = [
        'name' => "$sn — SMM Panel",
        'short_name' => $sn,
        'description' => "أسرع لوحة SMM — $sn",
        'start_url' => '/app',
        'display' => 'standalone',
        'background_color' => '#000000',
        'theme_color' => '#000000',
        'orientation' => 'portrait',
        'lang' => 'ar',
        'dir' => 'rtl',
        'categories' => ['business', 'social'],
        'icons' => [
            ['src' => "/pwa-icon-192.png?v=$icon_v", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => "/pwa-icon-512.png?v=$icon_v", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ];
    $resp = make_response(py_json_dumps($manifest, false));
    $resp->headers['Content-Type'] = 'application/manifest+json';
    $resp->headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
    return $resp;
});

/* '/sw.js'  (app.py:1930) */
route('/sw.js', function () {
    $resp = make_response(str_replace('__SW_SITE_NAME__', get_setting('site_name', 'fastcrand'), tpl('sw.js')));
    $resp->headers['Content-Type'] = 'application/javascript';
    $resp->headers['Service-Worker-Allowed'] = '/';
    $resp->headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
    return $resp;
});
