<?php
/**
 * واصل — الإرسال التلقائي بالخلفية (حتى لو ما أحد فاتح الموقع).
 *
 * الطريقة 1 (الأفضل) — cPanel ← Cron Jobs ← Once Per Minute (* * * * *) والأمر:
 *     /usr/local/bin/php -q /home/اسم_حسابك/public_html/cron.php >/dev/null 2>&1
 *   (الأمر الصحيح بمسار موقعك مكتوب جاهز بلوحة التحكم ← الإرسال التلقائي)
 *
 * الطريقة 2 — بدون Cron الاستضافة: أي خدمة Cron مجانية (مثل cron-job.org)
 * تفتح هذا الرابط كل دقيقة (المفتاح السري بلوحة التحكم):
 *     https://دومينك/cron.php?key=المفتاح
 *
 * بدون الطريقتين الإرسال التلقائي يشتغل بس ما دام الموقع مفتوح بأي متصفح.
 * ويبقي البوابة الخاصة (تطبيق Node.js) شغّالة والأرقام متصلة على مدار الساعة.
 */

require __DIR__ . '/inc/core.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}

if (!wasel_installed()) {
    if (!$cli) {
        http_response_code(503);
    }
    echo "not installed\n";
    exit(1);
}

try {
    wasel_boot(false);
} catch (PDOException $e) {
    error_log('[wasel cron] DB connection failed: ' . $e->getMessage());
    if (!$cli) {
        http_response_code(503);
    }
    echo "database error\n";
    exit(1);
}

require __DIR__ . '/inc/autosend.php';

if (!$cli) {
    $key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    $real = (string) (site_settings()['cron_key'] ?? '');
    if ($real === '' || !hash_equals($real, $key)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
    ignore_user_abort(true);
}

$budget = $cli ? AUTO_CRON_BUDGET : AUTO_URL_BUDGET;
@set_time_limit($cli ? 0 : $budget + 90);
q('UPDATE site_settings SET cron_last_run = ? WHERE id = 1', [now_dt()]);

// البوابة الخاصة: نناديها كل دقيقة حتى تبقى شغّالة والأرقام متصلة (cPanel يطفّي تطبيق Node.js الخامل)
if (self_ready() && (int) q_val("SELECT COUNT(*) FROM wa_accounts WHERE provider = 'self'") > 0) {
    self_info();
}

try {
    $r = auto_send_run($budget);
} catch (Throwable $e) {
    error_log('[wasel cron] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!$cli) {
        http_response_code(500);
    }
    echo "error\n";
    exit(1);
}

$line = ($r['busy'] ? 'busy' : 'ok') . ' sent=' . $r['sent'] . ' failed=' . $r['failed']
    . ' next_in=' . ($r['next_in'] === null ? '-' : $r['next_in']) . "\n";
// تشغيل cPanel يرسل أي مخرجات بالإيميل — فما نطبع شي إلا إذا طلبته (-v)
if (!$cli || in_array('-v', isset($argv) ? $argv : [], true)) {
    echo $line;
}
