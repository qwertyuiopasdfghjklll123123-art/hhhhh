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

route('/app', function () {
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
});
