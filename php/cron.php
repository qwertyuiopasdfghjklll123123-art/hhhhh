<?php
/**
 * مشغّل المهام الدورية (بديل threads بايثون). يعمل من سطر الأوامر فقط.
 *
 *   * * * * * php /path/to/php/cron.php >/dev/null 2>&1     ← كرون كل دقيقة (يبقى حياً ~55 ثانية فيحقق فواصل 30 ثانية)
 *   php cron.php --daemon                                    ← حلقة دائمة بدل الكرون (systemd/supervisor/nohup)
 *   php cron.php --list                                      ← عرض المهام وآخر تشغيل
 *   php cron.php --job=queued                                ← تشغيل مهمة واحدة الآن بغض النظر عن الجدول
 *   php cron.php --once                                      ← تشغيل المهام المستحقة مرة واحدة ثم الخروج
 *
 * كل مهمة تعمل بعملية مستقلة (proc_open) مع قفل خاص بها، فلا تعطّل مهمة بطيئة غيرها (مثل threads بايثون).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/app/bootstrap.php';
@set_time_limit(0);

$opts = getopt('', ['daemon', 'list', 'once', 'job:', 'help']);
$JOBS = cron_jobs();

if (isset($opts['help'])) { echo "usage: php cron.php [--daemon|--once|--list|--job=NAME]\n"; exit(0); }

/** تشغيل مهمة واحدة داخل هذه العملية (بقفلها) */
function cron_run_job(string $name, array $job): void {
    $lock = file_lock_try('job_' . $name);
    if (!$lock) return;                       // تعمل حالياً
    $t0 = microtime(true); $err = '';
    try {
        ($job['fn'])();
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . $e->getMessage();
        job_log("[$name] ERROR $err @ " . basename($e->getFile()) . ':' . $e->getLine());
        log_app_error('CRON', "$name: $err", $e->getFile(), $e->getLine(), $e->getTraceAsString());
    }
    Conn::shutdown();
    $now = time();
    $st = state_get('cron', []);
    state_update('cron', [$name => [
        'last' => $now, 'next' => $now + $job['every'], 'ms' => (int)round((microtime(true) - $t0) * 1000),
        'runs' => (int)($st[$name]['runs'] ?? 0) + 1, 'error' => $err, 'running' => false,
    ]]);
    file_lock_release($lock);
}

/** هل يمكن تشغيل عمليات فرعية؟ */
function cron_can_spawn(): bool {
    if (!function_exists('proc_open')) return false;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('proc_open', $dis, true);
}

/** يشغّل المهام المستحقة الآن */
function cron_tick(array $JOBS, bool $spawn): void {
    $now = time();
    $st = state_get('cron', []);
    foreach ($JOBS as $name => $job) {
        if (!isset($st[$name]['next'])) {
            state_update('cron', [$name => ['next' => $now + $job['delay'], 'last' => 0, 'runs' => 0, 'error' => '', 'running' => false]]);
            continue;
        }
        if ($st[$name]['next'] > $now) continue;
        if ($spawn) {
            // احجز الموعد التالي فوراً كي لا تُطلَق المهمة مرتين أثناء تشغيلها
            state_update('cron', [$name => array_merge($st[$name], ['next' => $now + max(60, $job['every']), 'running' => true])]);
            $cmd = [PHP_BINARY, __FILE__, '--job=' . $name];
            $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', DATA_DIR . '/logs/cron.log', 'a']], $pipes);
            if (is_resource($p)) { /* يعمل بالخلفية؛ لا ننتظره */ }
            else cron_run_job($name, $job);
        } else {
            cron_run_job($name, $job);
        }
    }
}

if (isset($opts['list'])) {
    $st = state_get('cron', []);
    printf("%-15s %-9s %-8s %-19s %-19s %s\n", 'job', 'every(s)', 'runs', 'last', 'next', 'desc');
    foreach ($JOBS as $name => $job) {
        $s = $st[$name] ?? [];
        printf("%-15s %-9d %-8d %-19s %-19s %s%s\n", $name, $job['every'], $s['runs'] ?? 0,
            !empty($s['last']) ? date('Y-m-d H:i:s', $s['last']) : '-', !empty($s['next']) ? date('Y-m-d H:i:s', $s['next']) : '-',
            $job['desc'], !empty($s['error']) ? '  [ERR: ' . $s['error'] . ']' : '');
    }
    exit(0);
}

if (isset($opts['job'])) {
    $name = (string)$opts['job'];
    if (!isset($JOBS[$name])) { fwrite(STDERR, "unknown job: $name\n"); exit(1); }
    cron_run_job($name, $JOBS[$name]);
    exit(0);
}

// جدولة: قفل عام واحد حتى لا يعمل مجدولان معاً
$lock = file_lock_try('cron_scheduler');
if (!$lock) exit(0);
$spawn = cron_can_spawn();
if (isset($opts['once'])) { cron_tick($JOBS, $spawn); exit(0); }
$daemon = isset($opts['daemon']);
$deadline = $daemon ? PHP_INT_MAX : time() + 55;
while (time() < $deadline) {
    cron_tick($JOBS, $spawn);
    Conn::shutdown();
    sleep(2);
}
file_lock_release($lock);
