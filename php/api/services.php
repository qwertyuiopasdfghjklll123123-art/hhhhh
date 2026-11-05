<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/smm.php';
require_login();

$provider = get_default_provider();
if (!$provider) {
    json_response(['ok' => false, 'msg' => 'لا يوجد مزوّد فعّال']);
}

json_response(['ok' => true, 'categories' => build_services_catalog($provider)]);
