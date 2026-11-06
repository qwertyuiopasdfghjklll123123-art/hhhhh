<?php
/**
 * نظام تتبّع الأخطاء: يمسك كل تحذير/إشعار/استثناء/خطأ قاتل ويسجّله بـ DATA_DIR/logs/app_errors.log
 * (JSON Lines) — يُعرض من لوحة الإدارة عبر /api/admin/errors.
 */

function error_log_file(): string { return DATA_DIR . '/logs/app_errors.log'; }

function log_app_error(string $level, string $message, string $file = '', int $line = 0, string $trace = ''): void {
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $entry = [
        'time' => time(), 'level' => $level, 'message' => $message,
        'file' => $file, 'line' => $line, 'trace' => $trace,
        'url' => $_SERVER['REQUEST_URI'] ?? (PHP_SAPI === 'cli' ? 'cli:' . ($_SERVER['argv'][0] ?? '') : ''),
    ];
    $f = error_log_file();
    @file_put_contents($f, json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    if (@filesize($f) > 5 * 1024 * 1024) {
        $lines = @file($f);
        if ($lines) @file_put_contents($f, implode('', array_slice($lines, (int)(count($lines) / 2))), LOCK_EX);
    }
}

function install_error_handlers(): void {
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) return false;
        $levels = [E_WARNING => 'WARNING', E_NOTICE => 'NOTICE', E_USER_ERROR => 'ERROR', E_USER_WARNING => 'WARNING',
                   E_USER_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED', E_USER_DEPRECATED => 'DEPRECATED'];
        log_app_error($levels[$errno] ?? 'ERROR', $errstr, $errfile, $errline);
        return true; // لا نعرض التحذيرات للزوّار (مثل بايثون: لا تظهر بالرد)
    });
    set_exception_handler(function ($e) {
        log_app_error('EXCEPTION', get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            echo '<!doctype html>' . "\n" . '<html lang=en>' . "\n" . '<title>500 Internal Server Error</title>' . "\n" . '<h1>Internal Server Error</h1>' . "\n" . '<p>The server encountered an internal error and was unable to complete your request. Either the server is overloaded or there is an error in the application.</p>' . "\n";
        } else {
            fwrite(STDERR, 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n");
        }
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            log_app_error('FATAL', $err['message'], $err['file'], $err['line']);
        }
    });
}
