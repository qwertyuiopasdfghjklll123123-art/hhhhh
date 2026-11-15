<?php
/**
 * مركز الأمان بلوحة الأدمن: حالة الحماية الفعلية (لا قائمة عامة) + سجل تدقيق عمليات
 * الأدمن + إلغاء كل الجلسات. مبنية على بنية موجودة أصلاً بـ core/lib/security.php
 * (audit_log/audit_tail من مرجع fastcrand، session_epoch لإلغاء الجلسات) - هذا الملف
 * فقط يعرضها ويضيف فحوصاً خاصة بواصل (GATEWAY_TOKEN، تشغيل الكرون).
 */

function security_mask_email(string $e): string {
    $p = explode('@', $e, 2);
    if (count($p) !== 2) return mb_substr($e, 0, 2, 'UTF-8') . '***';
    return mb_substr($p[0], 0, 2, 'UTF-8') . '***@' . $p[1];
}

function cron_heartbeat_file(): string { return DATA_DIR . '/cache/cron_last_run'; }

/** قائمة فحوص الأمان: [id, level(ok|warn|bad|info), title, detail] */
function wsl_security_checks(): array {
    $c = [];
    $add = function (string $id, string $level, string $title, string $detail) use (&$c) { $c[] = ['id' => $id, 'level' => $level, 'title' => $title, 'detail' => $detail]; };

    $sk = (string)SECRET_KEY;
    if ($sk === 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET') $add('secret', 'bad', 'مفتاح الجلسات لم يُغيَّر عن القيمة الافتراضية', 'أي شخص يعرف القيمة الافتراضية يستطيع تزوير جلسة أدمن. غيّر SECRET_KEY في core/config.php فوراً (يُخرج كل المستخدمين مرة واحدة).');
    elseif (strlen($sk) < 24) $add('secret', 'warn', 'مفتاح الجلسات قصير', 'استعمل نصاً عشوائياً من 32 محرفاً فأكثر في SECRET_KEY بـ core/config.php.');
    else $add('secret', 'ok', 'مفتاح الجلسات سرّي وقوي', 'طوله ' . strlen($sk) . ' محرفاً.');

    $gt = defined('GATEWAY_TOKEN') ? (string)GATEWAY_TOKEN : '';
    if (!py_truthy($gt)) $add('gateway_token', 'warn', 'لم يُضبط GATEWAY_TOKEN', 'بدونه أي شخص يعرف رابط wasel-gateway يقدر يرسل من حساباتك ويقرأ رسائلك. اضبطه بنفس القيمة بـ core/config.php وبإعدادات تطبيق Node.js بـ DirectAdmin.');
    elseif (strlen($gt) < 20) $add('gateway_token', 'warn', 'GATEWAY_TOKEN قصير', 'استعمل نصاً عشوائياً أطول (32 محرفاً فأكثر).');
    else $add('gateway_token', 'ok', 'GATEWAY_TOKEN مضبوط وقوي', '');

    if (req_scheme() === 'https') $add('https', 'ok', 'الاتصال مشفّر (HTTPS)', 'كوكي الجلسة يُرسَل بعلامة Secure.');
    else $add('https', 'warn', 'الموقع يُفتح بدون HTTPS', 'فعّل شهادة SSL من لوحة الاستضافة: كلمات المرور وكوكي تسجيل الدخول تعبر الشبكة بلا تشفير بدونها.');

    if (defined('ADMIN_PASSWORD') && (string)ADMIN_PASSWORD !== '') {
        $add('config_pw', 'warn', 'كلمة مرور الأدمن ما زالت مكتوبة في core/config.php', 'بعد أول تسجيل دخول احذف قيمة ADMIN_PASSWORD من الملف - لا تُستعمل بعد إنشاء الحساب، وبقاؤها نصاً صريحاً بالملف خطر لو تسرّب.');
    } else {
        $add('config_pw', 'ok', 'كلمة مرور الأدمن غير موجودة بنص صريح بالإعدادات', '');
    }

    $conn = get_db();
    $adm = $conn->execute('SELECT email FROM users WHERE is_admin=1 ORDER BY id')->fetchall();
    $add('admins', count($adm) > 3 ? 'warn' : 'info', 'حسابات الأدمن: ' . count($adm),
        implode('، ', array_map(fn($a) => security_mask_email((string)py_or($a['email'], '')), $adm)));

    $lim = login_limits();
    $add('throttle', 'ok', 'حماية تخمين كلمات المرور وأكواد التحقق', 'إيقاف مؤقت بعد ' . $lim['per_account'] . ' محاولة دخول فاشلة لكل حساب+عنوان، لمدة ' . (int)($lim['window'] / 60) . ' دقيقة.');
    $add('csrf', 'ok', 'حماية CSRF مفعّلة', 'تُرفض الطلبات المغيِّرة القادمة من موقع آخر غير موقعك.');
    $add('audit', 'ok', 'سجل تدقيق لعمليات الأدمن مفعّل', 'يسجّل كل عملية تعديل بلوحة الإدارة (الوقت، العنوان، المسار) - الجدول أدناه.');

    $add('php', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warn', 'PHP ' . PHP_VERSION,
        version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : 'إصدار PHP هذا قديم أمنياً، حدّثه إلى 8.2 أو أحدث من لوحة الاستضافة.');
    $add('errors', ini_get('display_errors') ? 'warn' : 'ok', ini_get('display_errors') ? 'عرض أخطاء PHP مفعّل للزوّار' : 'عرض الأخطاء للزوّار معطّل',
        ini_get('display_errors') ? 'عطّله من لوحة الاستضافة (PHP Selector) كي لا تظهر مسارات/تفاصيل داخلية لأي زائر.' : '');

    $cf = APP_DIR . '/config.php';
    if (is_file($cf)) {
        $perm = fileperms($cf) & 0777;
        $add('config_perm', ($perm & 0002) ? 'bad' : (($perm & 0004) ? 'info' : 'ok'), 'صلاحيات core/config.php: ' . decoct($perm),
            ($perm & 0002) ? 'قابل للكتابة من الجميع! غيّره إلى 640 من مدير الملفات بلوحة الاستضافة.' : (($perm & 0004) ? 'مقروء لباقي مستخدمي الخادم المشترك؛ إن سمحت استضافتك فاجعله 640.' : ''));
    }

    $hb = cron_heartbeat_file();
    $lastCron = is_file($hb) ? (int)trim((string)@file_get_contents($hb)) : 0;
    if ($lastCron === 0) $add('cron', 'warn', 'الكرون لم يعمل بعد', 'أضف مهمة Cron من لوحة الاستضافة: * * * * * php ' . ROOT_DIR . '/cron.php - بدونها لا تُرسَل الحملات ولا تذكيرات المشتركين.');
    else $add('cron', (time() - $lastCron) > 600 ? 'warn' : 'ok', 'آخر تشغيل للكرون: ' . gmdate('Y-m-d H:i:s', $lastCron) . ' UTC',
        (time() - $lastCron) > 600 ? 'مضى أكثر من 10 دقائق بدون تشغيل، تحقق من مهمة Cron بلوحة الاستضافة.' : '');

    $ep = session_epoch();
    $add('epoch', 'info', $ep > 0 ? 'آخر إلغاء لكل الجلسات: ' . gmdate('Y-m-d H:i', $ep) . ' UTC' : 'لم تُلغَ الجلسات من قبل',
        'زر "إلغاء كل الجلسات" أدناه يُخرج جميع المستخدمين والأدمن فوراً - مفيد عند الشك باختراق.');

    return $c;
}

route('/admin/security/status', function () {
    if (($r = require_admin()) !== null) return $r;
    $checks = wsl_security_checks();
    $counts = ['bad' => 0, 'warn' => 0, 'ok' => 0, 'info' => 0];
    foreach ($checks as $x) $counts[$x['level']]++;
    return jsonify(['ok' => true, 'checks' => $checks, 'counts' => $counts, 'audit' => audit_tail(100), 'ip' => client_ip()]);
}, ['GET']);

route('/admin/security/revoke-sessions', function () {
    if (($r = require_admin()) !== null) return $r;
    $data = req_data();
    $password = py_str(py_or(dget($data, 'password'), ''));
    $me = current_user();
    if (!$me || !$me['password_hash'] || !password_verify($password, $me['password_hash'])) {
        return jsonify(['ok' => false, 'msg' => 'كلمة المرور غير صحيحة']);
    }
    bump_session_epoch();
    audit_log('sessions_revoked');
    $_SESSION = []; // الأدمن نفسه يخرج أيضاً
    return jsonify(['ok' => true, 'msg' => 'أُلغيت كل الجلسات. سجّل الدخول من جديد']);
}, ['POST']);
