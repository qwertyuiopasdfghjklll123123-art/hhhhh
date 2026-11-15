<?php
/** الصفحات - مقابل / و /app بـ webapp.py */

function wsl_welcome_html(): string {
    $s = get_site_settings();
    $siteName = $s['site_name'] ?: 'واصل';
    $html = file_get_contents(APP_DIR . '/templates/welcome.html');
    $cc = $s['default_country_code'] ?: DEFAULT_COUNTRY_CODE_FALLBACK;
    $opts = '';
    foreach (COUNTRY_CODES as [$code, $label]) {
        $sel = $code === $cc ? ' selected' : '';
        $opts .= '<option value="' . htmlspecialchars($code, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    $html = str_replace('__SITE_NAME__', htmlspecialchars($siteName, ENT_QUOTES), $html);
    $html = str_replace('__COUNTRY_CODE_OPTIONS__', $opts, $html);
    $html = str_replace('__GOOGLE_CLIENT_ID__', GOOGLE_CLIENT_ID, $html);
    return $html;
}

route('/', function () {
    if (!empty($_SESSION['user_id'])) return redirect('/app');
    return make_response(wsl_welcome_html(), 200, 'text/html; charset=utf-8');
});

route('/favicon.ico', function () { return make_response('', 204); });

/** manifest.json ديناميكي (اسم الموقع من إعدادات الأدمن) ليمكن تثبيت واصل كتطبيق (PWA) */
route('/manifest.json', function () {
    $s = get_site_settings();
    $name = $s['site_name'] ?: 'واصل';
    $manifest = [
        'name' => $name, 'short_name' => mb_substr($name, 0, 12, 'UTF-8'),
        'description' => 'منصة حملات واتساب التسويقية', 'start_url' => '/app', 'id' => '/app',
        'display' => 'standalone', 'orientation' => 'portrait', 'dir' => 'rtl', 'lang' => 'ar',
        'background_color' => '#f7f4f0', 'theme_color' => '#2563eb',
        'icons' => [
            ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];
    $r = make_response(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $r->headers['Content-Type'] = 'application/manifest+json; charset=utf-8';
    return $r;
});

/**
 * /app وكل ما تحتها (/app/accounts، /app/admin/security...) تصل لنفس الصفحة (SPA واحدة)
 * - المسار الفرعي لا يُستعمل بالخادم أصلاً، فقط يسمح بفتح/تحديث رابط مباشر لقسم معيّن.
 * جافاسكربت الواجهة هو من يقرأ location.pathname ويقرر أي قسم يعرض (راجع app.html).
 * المستخدم العادي يبقى على /app فقط (لا يغيّر الرابط عند التنقل)؛ الأدمن وحده يحصل على
 * رابط مباشر لكل قسم كما طلب المستخدم صاحب المنصة.
 */
$wsl_app_handler = function (...$args) {
    if (empty($_SESSION['user_id'])) return redirect('/');
    $s = get_site_settings();
    $html = file_get_contents(APP_DIR . '/templates/app.html');
    $html = str_replace('__IS_ADMIN__', !empty($_SESSION['is_admin']) ? 'true' : 'false', $html);
    $html = str_replace('__USERNAME__', htmlspecialchars($_SESSION['name'] ?: $_SESSION['email'] ?: '', ENT_QUOTES), $html);
    $html = str_replace('__SITE_NAME__', htmlspecialchars($s['site_name'] ?: 'واصل', ENT_QUOTES), $html);
    $html = str_replace('__COUNTRY_CODES_JSON__', json_encode(COUNTRY_CODES, JSON_UNESCAPED_UNICODE), $html);
    $cc = $s['default_country_code'] ?: DEFAULT_COUNTRY_CODE_FALLBACK;
    $html = str_replace('__DEFAULT_COUNTRY_CODE__', htmlspecialchars($cc, ENT_QUOTES), $html);
    return make_response($html, 200, 'text/html; charset=utf-8');
};
route('/app', $wsl_app_handler);
route('/app/<path:rest>', $wsl_app_handler);
