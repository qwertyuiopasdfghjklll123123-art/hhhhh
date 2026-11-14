<?php
/**
 * واصل — نقطة اتصال إضافة كروم (جهاز الإرسال بكل فرع).
 *
 * الإضافة ترسل طلبات JSON بمفتاح ربط الحساب:
 *   hello  — تأكيد الربط ومعلومات الحساب
 *   next   — "شنو الرسالة الجاية؟" (يرجّع رسالة أو مدة انتظار)
 *   report — نتيجة إرسال رسالة
 *   ping   — تحديث حالة واتساب ويب فقط
 * ما يستخدم جلسات/كوكيز — المفتاح هو الهوية، ويخص حساب واتساب واحد فقط.
 */

require __DIR__ . '/inc/core.php';
require __DIR__ . '/inc/campaigns.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 600');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('POST only', 405);
}
if (!wasel_installed()) {
    json_fail('not_installed', 503);
}

try {
    wasel_boot(false);
    $acc = agent_account_by_key(input('key', ''));
    if (!$acc) {
        json_fail('invalid_key', 401);
    }
    $action = input_str('action', 20);
    $version = input_str('version', 20);
    $state = input_str('state', 20);

    switch ($action) {
        case 'hello':
            agent_touch($acc, $state !== '' ? $state : 'loading', $version);
            json_out([
                'ok' => true,
                'account' => ['id' => (int) $acc['id'], 'name' => $acc['name']],
                'site' => ['name' => site_name()],
                'server_time' => date('Y-m-d H:i:s'),
            ]);
            break;

        case 'ping':
            agent_touch($acc, $state, $version);
            json_out(['ok' => true]);
            break;

        case 'next':
            $acc = agent_touch($acc, $state !== '' ? $state : 'ready', $version);
            if ($acc['wa_state'] !== 'ready') {
                json_out(['ok' => true, 'wait' => 15, 'reason' => 'not_ready']);
            }
            $r = agent_next($acc);
            $r['ok'] = true;
            json_out($r);
            break;

        case 'report':
            $ok = input('ok', false);
            $ok = $ok === true || $ok === 1 || $ok === '1' || $ok === 'true';
            $r = agent_report($acc, input_int('job_id'), $ok, input_str('code', 40), input_str('error', 200));
            q('UPDATE wa_accounts SET last_seen_at = ? WHERE id = ?', [now_dt(), $acc['id']]);
            $r['ok'] = true;
            json_out($r);
            break;

        default:
            json_fail('unknown_action', 400);
    }
} catch (Throwable $e) {
    error_log('[wasel agent] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_fail('server_error', 500);
}
