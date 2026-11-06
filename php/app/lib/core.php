<?php
/**
 * دوال النواة المنقولة من app.py (الإعدادات، التجزئة، المزوّدون، أرقام العرض/المعرّفات العامة).
 * أرقام الأسطر المذكورة هي أسطر app.py الأصلي.
 */

/* ---- get_setting / set_setting  (app.py:150-169) ---- */

function get_setting(string $key, $default = '') {
    try {
        $conn = get_db();
        $row = $conn->execute('SELECT value FROM site_settings WHERE key=?', [$key])->fetchone();
        return $row ? $row['value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function set_setting(string $key, $value): void {
    $conn = get_db();
    $conn->execute('INSERT OR REPLACE INTO site_settings(key,value) VALUES(?,?)', [$key, py_str($value)]);
    $conn->commit();
}

/* ---- hash_pw  (app.py:1289) ---- */

function hash_pw($pw): string { return hash('sha256', (string)$pw); }

/* ---- المزوّدون  (app.py:1325-1366) ---- */

function get_all_providers(): array {
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM providers ORDER BY is_default DESC, id ASC')->fetchall();
    return rows_dicts($rows);
}

function get_default_provider(): array {
    $conn = get_db();
    $row = $conn->execute('SELECT * FROM providers WHERE is_default=1 AND active=1 LIMIT 1')->fetchone();
    if (!$row) $row = $conn->execute('SELECT * FROM providers WHERE active=1 LIMIT 1')->fetchone();
    if ($row) return row_dict($row);
    return ['id' => 0, 'name' => '—', 'api_url' => SMM_API_URL, 'api_key' => SMM_API_KEY];
}

function get_provider($pid): array {
    if (!py_truthy($pid)) return get_default_provider();
    $conn = get_db();
    $row = $conn->execute('SELECT * FROM providers WHERE id=?', [$pid])->fetchone();
    if ($row) return row_dict($row);
    return get_default_provider();
}

/** @return array [api_url, api_key, provider_id] */
function get_provider_api($pid = null): array {
    $p = py_truthy($pid) ? get_provider($pid) : get_default_provider();
    return [
        py_truthy($p['api_url'] ?? null) ? $p['api_url'] : SMM_API_URL,
        py_truthy($p['api_key'] ?? null) ? $p['api_key'] : SMM_API_KEY,
        $p['id'] ?? 0,
    ];
}

/** يرجع [api_url, api_key, prov_id] للمزود الذي استُوردت منه الخدمة (أو الافتراضي) */
function _find_service_provider($service_id): array {
    try {
        $conn = get_db();
        $row = $conn->execute(
            "SELECT prov_id FROM service_filters WHERE filter_type='service' AND filter_key=? AND hidden=0 LIMIT 1",
            [py_str($service_id)]
        )->fetchone();
        if ($row && py_truthy($row['prov_id'])) return get_provider_api(py_int($row['prov_id']));
    } catch (Throwable $e) {}
    return get_provider_api();
}

/* ---- أرقام العرض للطلبات  (app.py:1368-1403) ---- */

const DISPLAY_ID_START = 2700;
const DISPLAY_ID_STEP = 7;

function _next_display_id(): string {
    $conn = get_db();
    $row = $conn->execute("SELECT display_id FROM orders WHERE display_id!='' AND display_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchone();
    if ($row && py_truthy($row['display_id'])) {
        try {
            $last = py_int($row['display_id']);
            return (string)($last + DISPLAY_ID_STEP);
        } catch (PyValueError $e) {}
    }
    return (string)DISPLAY_ID_START;
}

function _backfill_display_ids(): void {
    $conn = get_db();
    $empty = $conn->execute("SELECT id FROM orders WHERE display_id IS NULL OR display_id='' ORDER BY id ASC")->fetchall();
    if (!$empty) return;
    $row = $conn->execute("SELECT display_id FROM orders WHERE display_id!='' AND display_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchone();
    if ($row && py_truthy($row['display_id'])) {
        try { $nxt = py_int($row['display_id']) + DISPLAY_ID_STEP; }
        catch (PyValueError $e) { $nxt = DISPLAY_ID_START; }
    } else {
        $nxt = DISPLAY_ID_START;
    }
    foreach ($empty as $r) {
        $conn->execute('UPDATE orders SET display_id=? WHERE id=?', [(string)$nxt, $r['id']]);
        $nxt += DISPLAY_ID_STEP;
    }
    $conn->commit();
}

/* ---- المعرّف العام للمستخدم FC-1001 ...  (app.py:1405-1438) ---- */

const PUBLIC_ID_START = 1001;

function _next_public_id(): string {
    $conn = get_db();
    $row = $conn->execute("SELECT public_id FROM users WHERE public_id!='' AND public_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchone();
    if ($row && py_truthy($row['public_id'])) {
        try {
            $n = py_int(str_replace('FC-', '', (string)$row['public_id']));
            return 'FC-' . ($n + 1);
        } catch (PyValueError $e) {}
    }
    return 'FC-' . PUBLIC_ID_START;
}

function _backfill_public_ids(): void {
    $conn = get_db();
    $empty = $conn->execute("SELECT id FROM users WHERE public_id IS NULL OR public_id='' ORDER BY id ASC")->fetchall();
    if (!$empty) return;
    $row = $conn->execute("SELECT public_id FROM users WHERE public_id!='' AND public_id IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchone();
    if ($row && py_truthy($row['public_id'])) {
        try { $nxt = py_int(str_replace('FC-', '', (string)$row['public_id'])) + 1; }
        catch (PyValueError $e) { $nxt = PUBLIC_ID_START; }
    } else {
        $nxt = PUBLIC_ID_START;
    }
    foreach ($empty as $r) {
        $conn->execute('UPDATE users SET public_id=? WHERE id=?', ['FC-' . $nxt, $r['id']]);
        $nxt += 1;
    }
    $conn->commit();
}

/* ---- أدوات الصفحات/القوالب ---- */

/** قراءة قالب واجهة مستخرج حرفياً من app.py (انظر tools/extract_from_python.py) */
function tpl(string $name): string {
    static $cache = [];
    if (!isset($cache[$name])) $cache[$name] = (string)file_get_contents(APP_DIR . '/templates/' . $name);
    return $cache[$name];
}

/** _get_google_client_id  (app.py:686) */
function _get_google_client_id(): string {
    $v = get_setting('google_client_id', '');
    return py_truthy($v) ? $v : GOOGLE_CLIENT_ID;
}

/** get_ai_icon_data_uri  (app.py:1839) */
function get_ai_icon_data_uri(): string {
    $custom = get_setting('ai_logo', '');
    if (py_truthy($custom)) return $custom;
    return 'data:image/png;base64,' . trim(tpl('ai_icon_default.b64'));
}

/* ---- مهام مؤجلة بعد الاستجابة (بديل الـ threads/ThreadPoolExecutor بالأصل) ---- */

$GLOBALS['__deferred'] = [];

function defer_after_response(callable $fn): void {
    $GLOBALS['__deferred'][] = $fn;
    if (PHP_SAPI === 'cli' && empty($GLOBALS['__deferred_registered'])) {   // cron/CLI: شغّلها عند الانتهاء
        $GLOBALS['__deferred_registered'] = true;
        register_shutdown_function('run_deferred');
    }
}

function run_deferred(): void {
    @set_time_limit(120);
    while (!empty($GLOBALS['__deferred'])) {
        $fn = array_shift($GLOBALS['__deferred']);
        try { $fn(); }
        catch (Throwable $e) { log_app_error('DEFERRED', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()); }
        Conn::shutdown();
    }
}
