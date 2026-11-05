<?php
require_once __DIR__ . '/db.php';

/**
 * طبقة التواصل مع API مزوّد SMM — مكافئ مبسّط لـ get_smm_services()/_find_service_provider()
 * بنسخة Python. المرحلة الحالية: مزود افتراضي واحد (متعدد المزودين يُضاف لاحقاً).
 */

function get_default_provider() {
    $row = db()->query("SELECT * FROM providers WHERE is_default=1 AND active=1 LIMIT 1")->fetch();
    if (!$row) {
        $row = db()->query("SELECT * FROM providers WHERE active=1 LIMIT 1")->fetch();
    }
    return $row ?: null;
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
    $json = json_decode($resp, true);
    return $json;
}

/** يجلب خدمات المزوّد مع كاش ملفي قصير (60 ثانية) لتخفيف الضغط على API المزوّد */
function fetch_provider_services($provider) {
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

/**
 * يبني كتالوج الخدمات مجمّعاً حسب الفئة، بعد تطبيق الإخفاء ونسبة الربح
 * من جدول service_filters (مطابق لمنطق _build_services_catalog() المبسّط).
 */
function build_services_catalog($provider) {
    $services = fetch_provider_services($provider);
    if (!is_array($services)) return [];

    $filters = [];
    $stmt = db()->query("SELECT filter_key, hidden, profit_pct FROM service_filters WHERE filter_type='service'");
    foreach ($stmt as $f) {
        $filters[(string)$f['filter_key']] = $f;
    }

    $catalog = [];
    foreach ($services as $s) {
        $sid = (string)($s['service'] ?? '');
        if ($sid === '') continue;
        $f = $filters[$sid] ?? null;
        if ($f && (int)$f['hidden'] === 1) continue;

        $rate = (float)($s['rate'] ?? 0);
        $profitPct = ($f && $f['profit_pct'] !== null) ? (float)$f['profit_pct'] : (float)get_setting('default_profit_pct', '20');
        $siteRate = round($rate * (1 + $profitPct / 100), 4);

        $category = (string)($s['category'] ?? 'عام');
        if (!isset($catalog[$category])) $catalog[$category] = [];
        $catalog[$category][] = [
            'id' => $sid,
            'name' => $s['name'] ?? '',
            'rate' => $siteRate,
            'min' => (int)($s['min'] ?? 1),
            'max' => (int)($s['max'] ?? 1000000),
            'refill' => $s['refill'] ?? false,
        ];
    }
    return $catalog;
}

function find_service($provider, $serviceId) {
    foreach (fetch_provider_services($provider) as $s) {
        if ((string)($s['service'] ?? '') === (string)$serviceId) return $s;
    }
    return null;
}
