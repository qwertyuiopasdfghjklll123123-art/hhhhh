<?php
/**
 * نظام تتبّع الأخطاء: يمسك كل أخطاء/تحذيرات/استثناءات PHP (بما فيها
 * الأخطاء القاتلة Fatal) ويسجّلها بملف logs/app_errors.log بصيغة JSON Lines،
 * مع عرضها من admin/errors.php. المجلد logs/ محجوب من الوصول المباشر
 * بالمتصفح عبر .htaccess.
 */

define('ERROR_LOG_FILE', __DIR__ . '/../logs/app_errors.log');
define('ERROR_LOG_MAX_BYTES', 5 * 1024 * 1024); // 5MB — يُقلّم تلقائياً بعد هذا الحجم

function log_app_error($level, $message, $file = '', $line = 0, $trace = '') {
    $entry = [
        'time' => time(),
        'level' => $level,
        'message' => $message,
        'file' => $file,
        'line' => $line,
        'trace' => $trace,
        'url' => $_SERVER['REQUEST_URI'] ?? '',
    ];
    $line_json = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(ERROR_LOG_FILE, $line_json, FILE_APPEND | LOCK_EX);

    if (@filesize(ERROR_LOG_FILE) > ERROR_LOG_MAX_BYTES) {
        $lines = @file(ERROR_LOG_FILE);
        if ($lines) {
            $keep = array_slice($lines, (int)(count($lines) / 2));
            @file_put_contents(ERROR_LOG_FILE, implode('', $keep), LOCK_EX);
        }
    }
}

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $levels = [
        E_WARNING => 'WARNING', E_NOTICE => 'NOTICE', E_USER_ERROR => 'ERROR',
        E_USER_WARNING => 'WARNING', E_USER_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'DEPRECATED', E_STRICT => 'STRICT',
    ];
    log_app_error($levels[$errno] ?? 'ERROR', $errstr, $errfile, $errline);
    return false; // يخلي PHP يكمّل معالجته الطبيعية (عرض/تجاهل) بعد التسجيل
});

set_exception_handler(function ($e) {
    log_app_error('EXCEPTION', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    http_response_code(500);
    echo 'حدث خطأ غير متوقع. تم تسجيله وسيتم مراجعته.';
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_app_error('FATAL', $err['message'], $err['file'], $err['line']);
    }
});
