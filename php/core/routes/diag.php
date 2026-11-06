<?php
/**
 * تتبّع الأخطاء (إضافة بلا أثر على الواجهة): /api/admin/errors
 *   GET  ?limit=100&level=EXCEPTION   → آخر الأخطاء المسجَّلة (الأحدث أولاً) من data/logs/app_errors.log
 *   DELETE                            → تفريغ السجل
 * للأدمن فقط. الواجهة الأصلية لا تستدعيه؛ يُستعمل من curl/المتصفح لتشخيص المشاكل بعد النقل.
 */
route('/api/admin/errors', function () {
    if (!_admin_check()) return jsonify(['ok' => false], 403);
    $f = error_log_file();
    if (req_method() === 'DELETE') {
        @file_put_contents($f, '', LOCK_EX);
        return jsonify(['ok' => true]);
    }
    $limit = max(1, min(1000, (int)req_args('limit', 100)));
    $level = (string)req_args('level', '');
    $out = [];
    if (is_file($f)) {
        $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $e = json_decode($lines[$i], true);
            if (!is_array($e)) continue;
            if ($level !== '' && ($e['level'] ?? '') !== $level) continue;
            $out[] = $e;
        }
    }
    return jsonify(['ok' => true, 'count' => count($out), 'errors' => $out]);
}, ['GET', 'DELETE']);
