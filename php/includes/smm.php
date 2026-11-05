<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/**
 * طبقة التواصل مع API مزوّدي SMM — مكافئ لـ get_all_providers()/get_provider_api()/
 * _find_service_provider()/get_smm_services()/get_all_smm_services() بنسخة Python،
 * مع دعم تعدد المزودين.
 */

function get_all_providers() {
    return db()->query('SELECT * FROM providers ORDER BY is_default DESC, id ASC')->fetchAll();
}

function get_default_provider() {
    $row = db()->query('SELECT * FROM providers WHERE is_default=1 AND active=1 LIMIT 1')->fetch();
    if (!$row) {
        $row = db()->query('SELECT * FROM providers WHERE active=1 LIMIT 1')->fetch();
    }
    return $row ?: null;
}

function get_provider($pid) {
    if (!$pid) return get_default_provider();
    $stmt = db()->prepare('SELECT * FROM providers WHERE id = ?');
    $stmt->execute([$pid]);
    return $stmt->fetch() ?: get_default_provider();
}

/** يرجع (api_url, api_key, prov_id) للمزوّد الذي استُوردت منه هذه الخدمة، أو الافتراضي */
function find_service_provider($serviceId) {
    $stmt = db()->prepare("SELECT prov_id FROM service_filters WHERE filter_type='service' AND filter_key=? AND hidden=0 LIMIT 1");
    $stmt->execute([(string)$serviceId]);
    $row = $stmt->fetch();
    if ($row && $row['prov_id']) {
        return get_provider((int)$row['prov_id']);
    }
    return get_default_provider();
}

function smm_api_post($url, $fields, $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) return null;
    return json_decode($resp, true);
}

/** يجلب خدمات مزوّد واحد مع كاش ملفي قصير (60 ثانية) */
function fetch_provider_services($provider) {
    if (!$provider || empty($provider['api_url']) || empty($provider['api_key'])) return [];
    $cacheFile = sys_get_temp_dir() . '/fastcrand_smm_services_' . (int)$provider['id'] . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $data = smm_api_post($provider['api_url'], ['key' => $provider['api_key'], 'action' => 'services']);
    if (is_array($data) && count($data) > 0) {
        @file_put_contents($cacheFile, json_encode($data));
        return $data;
    }
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    return [];
}

/** يجمع خدمات كل المزودين الفعّالين المعروضين بالمتجر، مع وسم كل خدمة بمعرّف مزوّدها */
function fetch_all_services() {
    $cacheFile = sys_get_temp_dir() . '/fastcrand_smm_services_all.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 120) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    $all = [];
    foreach (get_all_providers() as $prov) {
        if (!$prov['active'] || !$prov['show_in_store']) continue;
        foreach (fetch_provider_services($prov) as $s) {
            $s['_prov_id'] = (int)$prov['id'];
            $all[] = $s;
        }
    }
    if (count($all) > 0) {
        @file_put_contents($cacheFile, json_encode($all));
    } elseif (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }
    return $all;
}

function invalidate_services_cache() {
    foreach (glob(sys_get_temp_dir() . '/fastcrand_smm_services_*.json') as $f) {
        @unlink($f);
    }
}

/**
 * يبني كتالوج الخدمات مجمّعاً حسب الفئة، بعد تطبيق الإخفاء ونسبة الربح
 * من جدول service_filters (مطابق لمنطق _build_services_catalog() المبسّط).
 */
function build_services_catalog() {
    $services = fetch_all_services();
    if (!is_array($services)) return [];

    $filters = [];
    $stmt = db()->query("SELECT filter_key, hidden, profit_pct, custom_name, custom_category FROM service_filters WHERE filter_type='service'");
    foreach ($stmt as $f) {
        $filters[(string)$f['filter_key']] = $f;
    }
    $defaultProfit = (float)get_setting('default_profit_pct', '20');

    $catalog = [];
    foreach ($services as $s) {
        $sid = (string)($s['service'] ?? '');
        if ($sid === '') continue;
        $f = $filters[$sid] ?? null;
        if ($f && (int)$f['hidden'] === 1) continue;

        $rate = (float)($s['rate'] ?? 0);
        $profitPct = ($f && $f['profit_pct'] !== null) ? (float)$f['profit_pct'] : $defaultProfit;
        $siteRate = round($rate * (1 + $profitPct / 100), 4);

        $name = ($f && $f['custom_name']) ? $f['custom_name'] : ($s['name'] ?? '');
        $category = ($f && $f['custom_category']) ? $f['custom_category'] : (string)($s['category'] ?? 'عام');

        if (!isset($catalog[$category])) $catalog[$category] = [];
        $catalog[$category][] = [
            'id' => $sid,
            'name' => $name,
            'rate' => $siteRate,
            'min' => (int)($s['min'] ?? 1),
            'max' => (int)($s['max'] ?? 1000000),
            'refill' => $s['refill'] ?? false,
            'prov_id' => (int)($s['_prov_id'] ?? 0),
        ];
    }
    return $catalog;
}

function find_service($serviceId) {
    foreach (fetch_all_services() as $s) {
        if ((string)($s['service'] ?? '') === (string)$serviceId) return $s;
    }
    return null;
}
