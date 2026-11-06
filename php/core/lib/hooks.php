<?php
/**
 * الخطافات قبل كل طلب (before_request) — منقولة حرفياً من app.py:
 *   _make_session_permanent (34-60): جلسة دائمة + فحص الحظر كل 30 ثانية
 *   _master_key_auth (1294-1323): تسجيل دخول الأدمن بالمفتاح الرئيسي X-Master-Key
 * تُرجع Response لإيقاف الطلب أو null للمتابعة.
 */
function run_before_request_hooks(): ?Response {
    $path = req_path();

    // ---- حماية (security.php): صيانة أثناء الاستيراد، CSRF، قائمة IP للأدمن ----
    if (maintenance_active() && strncmp($path, '/api/admin/transfer/', 20) !== 0) {
        $r = strncmp($path, '/api/', 5) === 0
            ? jsonify(['ok' => false, 'msg' => 'الموقع قيد الصيانة لدقائق (استيراد بيانات). حاول بعد قليل'], 503)
            : new Response('<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="20"><title>صيانة</title></head><body style="font-family:Tahoma,Arial,sans-serif;background:#0f1220;color:#e8eaf6;text-align:center;padding:60px 20px"><h2>الموقع قيد الصيانة لدقائق</h2><p>نقوم بتحديث البيانات، تعود الخدمة قريباً.</p></body></html>', 503);
        $r->headers['Retry-After'] = '30';
        $r->headers['Cache-Control'] = 'no-store';
        return $r;
    }
    if (($csrf = origin_check()) !== null) return $csrf;
    if (admin_path($path) && !admin_ip_allowed()) {
        audit_log('admin_ip_denied', ['path' => $path]);
        return jsonify(['ok' => false, 'msg' => 'غير مسموح من هذا العنوان'], 403);
    }

    // ---- _make_session_permanent ----
    $_SESSION['_permanent'] = true;      // session.permanent = True
    if (array_key_exists('user_id', $_SESSION)) {
        static $skip = ['/', '/api/login', '/api/logout', '/api/register', '/api/banned-contact',
                        '/api/google-login', '/api/verify-code', '/manifest.json',
                        '/pwa-icon-192.png', '/pwa-icon-512.png', '/sw.js', '/site-logo'];
        if (!in_array($path, $skip, true) && strncmp($path, '/static', 7) !== 0) {
            if (($sr = usess_enforce($path)) !== null) return $sr;     // جلسة أُنهيت من «الأجهزة والجلسات» ⇒ خروج
            $nowTs = microtime(true);
            $lastCheck = $_SESSION['_ban_ts'] ?? 0;
            $cachedBan = $_SESSION['_ban'] ?? 0;
            if ($nowTs - $lastCheck > 30) {
                try {
                    $bc = get_db();
                    $brow = $bc->execute('SELECT banned FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
                    $cachedBan = $brow ? py_int($brow['banned']) : 0;
                    $_SESSION['_ban'] = $cachedBan;
                    $_SESSION['_ban_ts'] = $nowTs;
                } catch (Throwable $e) {}
            }
            if (py_truthy($cachedBan)) {
                if ($path === '/app' || strncmp($path, '/app/', 5) === 0) {
                    // مسموح: صفحة التطبيق تعرض شاشة الحظر
                } elseif (strncmp($path, '/api/', 5) === 0) {
                    return jsonify(['ok' => false, 'msg' => 'حسابك محظور'], 403);
                } else {
                    return redirect('/app');
                }
            }
        }
    }

    // ---- _master_key_auth ----
    $mk_header = py_strip(req_header('X-Master-Key'));
    if ($mk_header === '') return null;
    // لا نستبدل جلسة متصفح فعلية بتسجيل دخول حقيقي (عميل أو أدمن)
    if (array_key_exists('user_id', $_SESSION)) return null;
    $mk_enabled = get_setting('master_key_enabled', '0');
    $mk_value = get_setting('master_key_value', '');
    if ($mk_enabled !== '1' || !py_truthy($mk_value) || $mk_header !== $mk_value) return null;

    $conn = get_db();
    $admin = $conn->execute('SELECT id FROM users WHERE is_admin=1 ORDER BY id ASC LIMIT 1')->fetchone();
    if ($admin) {
        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['is_admin'] = true;
        try {
            $conn->execute('INSERT INTO master_key_log(endpoint,method,ip) VALUES(?,?,?)',
                           [$path, req_method(), req_remote_addr()]);
            $conn->commit();
        } catch (Throwable $e) {}
    }
    return null;
}
