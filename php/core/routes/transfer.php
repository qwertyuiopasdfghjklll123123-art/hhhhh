<?php
/**
 * تصدير/استيراد قاعدة البيانات وملفات JSON من لوحة الإدارة (قسم /app/import-export).
 * كل النقاط: أدمن (يُتحقق منه من قاعدة البيانات) + رمز CSRF بترويسة X-CSRF-Token،
 * والعمليات الخطرة (تصدير/استيراد) تطلب كلمة مرور الأدمن مجدداً مع تحديد محاولات التخمين، وتُسجَّل بسجل التدقيق.
 * تفاصيل منطق الاستيراد وضماناته: lib/transfer.php
 */

/** استجابة JSON بلا تخزين مؤقت */
function tj($data, int $status = 200): Response {
    $r = jsonify($data, $status);
    $r->headers['Cache-Control'] = 'no-store';
    return $r;
}

/** أدمن + (اختيارياً) CSRF — يرجع Response للرفض أو null */
function transfer_guard(bool $csrf = true): ?Response {
    if (!_admin_check()) return tj(['ok' => false, 'msg' => 'غير مصرح'], 403);
    if ($csrf && !csrf_verify()) return tj(['ok' => false, 'msg' => 'انتهت صلاحية الصفحة، أعد تحميلها وحاول مجدداً'], 403);
    return null;
}

/** إعادة التحقق بكلمة مرور الأدمن (مع تحديد محاولات التخمين) — يرجع Response للرفض أو null */
function transfer_check_password($pw): ?Response {
    $uid = (int)$_SESSION['user_id'];
    $thr = guess_check('adminpw|' . $uid);
    if ($thr !== null) return $thr;
    $u = get_db()->execute('SELECT password FROM users WHERE id=?', [$uid])->fetchone();
    if (!$u || !py_truthy($u['password'])) return tj(['ok' => false, 'msg' => 'حسابك بلا كلمة مرور (دخول بجوجل): اضبط كلمة مرور من إعدادات الحساب أولاً'], 400);
    if (!is_string($pw) || $pw === '' || !verify_pw($pw, $u['password'])) {
        guess_fail('adminpw|' . $uid);
        audit_log('reauth_failed', ['for' => 'transfer']);
        return tj(['ok' => false, 'msg' => 'كلمة المرور غير صحيحة'], 403);
    }
    guess_ok('adminpw|' . $uid);
    return null;
}

/** ملخّص آمن للمكوّنات (بلا مسارات الخادم) */
function transfer_public_meta(array $m): array {
    $comps = [];
    foreach ($m['components'] as $c) {
        $x = ['type' => $c['type'], 'name' => $c['name'], 'size' => $c['size'], 'size_h' => tf_human_bytes((int)$c['size'])];
        foreach (['tables', 'unknown_tables', 'users', 'subscriptions', 'services', 'created', 'origin'] as $k) if (isset($c[$k])) $x[$k] = $c[$k];
        $comps[] = $x;
    }
    $dbc = array_values(array_filter($m['components'], fn($c) => in_array($c['type'], ['sql', 'sqlite', 'dbjson'], true)));
    return ['token' => $m['token'], 'source' => $m['source'], 'components' => $comps, 'warnings' => $m['warnings'],
            'has_db' => count($dbc) > 0, 'has_both_db' => count($dbc) > 1];
}

/** رسالة صديقة لأخطاء PDO أثناء الاستيراد (بلا نص SQL) */
function transfer_pdo_message(PDOException $e): string {
    $code = (int)($e->errorInfo[1] ?? 0);
    if ($code === 1062) return 'الملف يحوي صفوفاً مكرّرة بنفس المفتاح (مثل معرّف أو بريد مكرر) فلا يصلح للاستيراد';
    if (in_array($code, [1366, 1265, 1264, 1406], true)) return 'الملف يحوي قيمة لا تناسب نوع عمودها أو أطول من حدّه';
    if ($code === 1153 || $code === 2006) return 'الصف أكبر من حد الخادم (max_allowed_packet)؛ ارفع الحد أو جزّئ الملف';
    if ($code === 1142 || $code === 1044 || $code === 1227) return 'مستخدم قاعدة البيانات لا يملك صلاحية CREATE/ALTER/DROP اللازمة للاستيراد';
    return 'فشل تنفيذ الاستيراد (رمز MySQL ' . $code . ')';
}

function transfer_upload_limits(): array {
    $b = function ($v) { $v = trim((string)$v); $n = (int)$v; switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; } return $n; };
    $up = $b(ini_get('upload_max_filesize')); $post = $b(ini_get('post_max_size'));
    return ['upload_max' => $up, 'post_max' => $post, 'effective' => max(0, min($up ?: PHP_INT_MAX, $post ?: PHP_INT_MAX))];
}

/* ------------------------------ حالة الصفحة ------------------------------ */

route('/api/admin/transfer/status', function () {
    if (($g = transfer_guard(false)) !== null) return $g;
    $lim = transfer_upload_limits();
    $backups = array_map(fn($b) => ['name' => $b['name'], 'size' => $b['size'], 'time' => $b['timestamp']], array_slice(list_backups(), 0, 30));
    $tg = ['configured' => py_truthy(get_setting('tg_bot_token', '')) && py_truthy(get_setting('tg_chat_id', '')), 'items' => tg_backup_list()];
    return tj(['ok' => true, 'csrf' => csrf_token(),
        'limits' => ['max_upload' => $lim['effective'], 'max_upload_h' => tf_human_bytes($lim['effective'])],
        'caps' => ['sqlite' => extension_loaded('pdo_sqlite'), 'zip' => class_exists('ZipArchive')],
        'local_backups' => $backups, 'telegram' => $tg]);
});

/* ------------------------------ تصدير ------------------------------ */

route('/api/admin/transfer/export', function () {
    if (($g = transfer_guard()) !== null) return $g;
    $d = req_json(false, true);
    $kind = is_array($d) ? (string)($d['kind'] ?? '') : '';
    if (!in_array($kind, ['sql', 'full', 'json', 'dbjson'], true)) return tj(['ok' => false, 'msg' => 'نوع التصدير غير معروف'], 400);
    if (($p = transfer_check_password($d['password'] ?? '')) !== null) return $p;
    [$okRate, $wait] = rate_limit_hit('export|' . $_SESSION['user_id'], 10, 600);
    if (!$okRate) return tj(['ok' => false, 'msg' => 'تصدير كثير خلال وقت قصير، انتظر ' . (int)ceil($wait / 60) . ' دقيقة'], 429);
    @set_time_limit(0);
    try { [$path, $name, $mime] = transfer_export($kind); }
    catch (TransferError $e) { return tj(['ok' => false, 'msg' => $e->getMessage()], 400); }
    audit_log('export', ['kind' => $kind, 'bytes' => (int)@filesize($path)]);
    defer_after_response(function () use ($path) { @unlink($path); });
    $r = send_file($path, $mime, true, $name);
    $r->headers['Cache-Control'] = 'no-store';
    $r->headers['X-Content-Type-Options'] = 'nosniff';
    return $r;
}, ['POST']);

/* ------------------------------ رفع وتحليل ------------------------------ */

route('/api/admin/transfer/analyze', function () {
    if (($g = transfer_guard()) !== null) return $g;
    [$okRate, $wait] = rate_limit_hit('analyze|' . $_SESSION['user_id'], 30, 600);
    if (!$okRate) return tj(['ok' => false, 'msg' => 'محاولات رفع كثيرة، انتظر قليلاً'], 429);
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
        // عند تجاوز post_max_size يُسقط PHP كل الحقول والملفات بصمت (فيبدو الأمر كأن لا ملف وصل)
        $lim = transfer_upload_limits(); $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($cl > 0 && $lim['post_max'] > 0 && $cl > $lim['post_max'])
            return tj(['ok' => false, 'msg' => 'حجم الملف أكبر من الحد المسموح بالخادم (' . tf_human_bytes($lim['effective']) . '). ارفع حدّ upload_max_filesize/post_max_size أو استورد عبر سطر الأوامر'], 400);
        return tj(['ok' => false, 'msg' => 'لم يصل أي ملف'], 400);
    }
    $err = (int)$f['error'];
    if ($err !== UPLOAD_ERR_OK) {
        $lim = transfer_upload_limits();
        $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'حجم الملف أكبر من الحد المسموح بالخادم (' . tf_human_bytes($lim['effective']) . '). ارفع حدّ upload_max_filesize/post_max_size أو استورد عبر سطر الأوامر'
            : ($err === UPLOAD_ERR_NO_FILE ? 'اختر ملفاً أولاً' : 'فشل رفع الملف (رمز ' . $err . ')');
        return tj(['ok' => false, 'msg' => $msg], 400);
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) return tj(['ok' => false, 'msg' => 'ملف غير صالح'], 400);
    @set_time_limit(0);
    try {
        $m = transfer_analyze((string)$f['tmp_name'], (string)($f['name'] ?? 'upload'), (int)$_SESSION['user_id']);
    } catch (TransferError $e) {
        audit_log('import_rejected', ['file' => mb_substr((string)($f['name'] ?? ''), 0, 80, 'UTF-8'), 'why' => mb_substr($e->getMessage(), 0, 120, 'UTF-8')]);
        return tj(['ok' => false, 'msg' => $e->getMessage()], 400);
    }
    audit_log('import_uploaded', ['file' => $m['source'], 'types' => array_column($m['components'], 'type')]);
    return tj(['ok' => true] + transfer_public_meta($m));
}, ['POST']);

route('/api/admin/transfer/analyze-local', function () {
    if (($g = transfer_guard()) !== null) return $g;
    $d = req_json(false, true);
    $name = is_array($d) ? (string)($d['name'] ?? '') : '';
    try { $m = transfer_analyze_local($name, (int)$_SESSION['user_id']); }
    catch (TransferError $e) { return tj(['ok' => false, 'msg' => $e->getMessage()], 400); }
    audit_log('import_local_selected', ['backup' => $name]);
    return tj(['ok' => true] + transfer_public_meta($m));
}, ['POST']);

/* ------------------------------ تنفيذ الاستيراد ------------------------------ */

route('/api/admin/transfer/apply', function () {
    if (($g = transfer_guard()) !== null) return $g;
    $d = req_json(false, true);
    if (!is_array($d)) return tj(['ok' => false, 'msg' => 'طلب غير صالح'], 400);
    if (($d['confirm'] ?? '') !== 'RESTORE') return tj(['ok' => false, 'msg' => 'اكتب كلمة RESTORE للتأكيد'], 400);
    if (($p = transfer_check_password($d['password'] ?? '')) !== null) return $p;
    $token = (string)($d['token'] ?? '');
    $opts = ['restore_db' => !empty($d['restore_db']), 'restore_json' => !empty($d['restore_json'])];
    if (in_array($d['prefer'] ?? '', ['sql', 'sqlite', 'dbjson'], true)) $opts['prefer'] = $d['prefer'];
    try {
        transfer_read_meta($token, (int)$_SESSION['user_id']);           // ملكية المهمة
        audit_log('import_started', ['token' => substr($token, 0, 8)] + $opts);
        $rep = transfer_apply($token, $opts);
    } catch (TransferError $e) {
        audit_log('import_failed', ['why' => mb_substr($e->getMessage(), 0, 160, 'UTF-8')]);
        return tj(['ok' => false, 'msg' => $e->getMessage() . ' — لم يتغيّر شيء في بياناتك الحالية'], 400);
    } catch (PDOException $e) {
        log_app_error('EXCEPTION', 'import: ' . $e->getMessage(), $e->getFile(), $e->getLine());
        audit_log('import_failed', ['code' => $e->errorInfo[1] ?? 0]);
        return tj(['ok' => false, 'msg' => transfer_pdo_message($e) . ' — لم يتغيّر شيء في بياناتك الحالية'], 400);
    } catch (Throwable $e) {
        log_app_error('EXCEPTION', 'import: ' . get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        audit_log('import_failed', ['why' => get_class($e)]);
        return tj(['ok' => false, 'msg' => 'فشل الاستيراد لسبب غير متوقع (التفاصيل بسجل الأخطاء). بياناتك الحالية لم تتغيّر'], 500);
    }
    audit_log('import_done', ['tables' => count($rep['tables']), 'json' => count($rep['json']), 'backup' => $rep['safety_backup']]);
    $relogin = $opts['restore_db'] ?? false;
    return tj(['ok' => true, 'report' => $rep, 'relogin' => (bool)$relogin]);
}, ['POST']);

route('/api/admin/transfer/cancel', function () {
    if (($g = transfer_guard()) !== null) return $g;
    $d = req_json(false, true);
    try {
        $token = is_array($d) ? (string)($d['token'] ?? '') : '';
        transfer_read_meta($token, (int)$_SESSION['user_id']);
        _rrmdir(transfer_job_dir($token));
    } catch (TransferError $e) { /* منتهية أصلاً */ }
    return tj(['ok' => true]);
}, ['POST']);

/* ------------------------------ نسخ تلغرام: جلب الملف عبر البوت ------------------------------ */

route('/api/admin/transfer/telegram-fetch', function () {
    if (($g = transfer_guard()) !== null) return $g;
    $d = req_json(false, true);
    $fid = is_array($d) ? (string)($d['file_id'] ?? '') : '';
    $known = array_column(tg_backup_list(), null, 'file_id');
    if ($fid === '' || !isset($known[$fid])) return tj(['ok' => false, 'msg' => 'هذه النسخة غير موجودة بقائمة نسخ تلغرام'], 404);
    $token = (string)get_setting('tg_bot_token', '');
    if (!py_truthy($token)) return tj(['ok' => false, 'msg' => 'لم يُضبط Bot Token في إعدادات النسخ الاحتياطي'], 400);
    [$okRate] = rate_limit_hit('tgfetch|' . $_SESSION['user_id'], 10, 600);
    if (!$okRate) return tj(['ok' => false, 'msg' => 'محاولات كثيرة، انتظر قليلاً'], 429);
    @set_time_limit(0);
    $tmp = null;
    try {
        $r = requests_get('https://api.telegram.org/bot' . $token . '/getFile', ['params' => ['file_id' => $fid], 'timeout' => 20]);
        $j = $r->status_code === 200 ? $r->json() : null;
        if (!is_array($j) || empty($j['ok']) || empty($j['result']['file_path'])) {
            $why = is_array($j) ? (string)($j['description'] ?? '') : '';
            return tj(['ok' => false, 'msg' => 'تلغرام لم يسلّم الملف' . ($why !== '' ? ': ' . mb_substr($why, 0, 100, 'UTF-8') : '') . '. (الحد الأقصى للجلب عبر البوت 20MB — نزّل الملف من تلغرام يدوياً وارفعه هنا)'], 400);
        }
        $size = (int)($j['result']['file_size'] ?? 0);
        if ($size > 20 * 1048576) return tj(['ok' => false, 'msg' => 'الملف أكبر من 20MB: نزّله من تلغرام يدوياً وارفعه هنا'], 400);
        $dl = requests_get('https://api.telegram.org/file/bot' . $token . '/' . $j['result']['file_path'], ['timeout' => 120]);
        if ($dl->status_code !== 200 || $dl->content === '') return tj(['ok' => false, 'msg' => 'تعذّر تنزيل الملف من تلغرام'], 400);
        $tmp = transfer_dir() . '/tg_' . bin2hex(random_bytes(6));
        file_put_contents($tmp, $dl->content);
        unset($dl);
        $m = transfer_analyze($tmp, (string)($known[$fid]['name'] ?? 'telegram_backup.zip'), (int)$_SESSION['user_id']);
    } catch (TransferError $e) {
        return tj(['ok' => false, 'msg' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        log_app_error('EXCEPTION', 'telegram-fetch: ' . preg_replace('#bot\d+:[A-Za-z0-9_-]+#', 'bot<token>', $e->getMessage()), $e->getFile(), $e->getLine());
        return tj(['ok' => false, 'msg' => 'تعذّر الاتصال بتلغرام الآن'], 502);
    } finally {
        if ($tmp && is_file($tmp)) @unlink($tmp);
    }
    audit_log('import_telegram_fetched', ['file' => $m['source']]);
    return tj(['ok' => true] + transfer_public_meta($m));
}, ['POST']);
