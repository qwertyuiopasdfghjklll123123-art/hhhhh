<?php
/**
 * مركز الأمان بلوحة الأدمن (/app/security): حالة الحماية، سجل التدقيق، فحص تعرّض الملفات الخاصة، وإلغاء كل الجلسات.
 * كل النقاط: أدمن (من قاعدة البيانات) + CSRF للعمليات المغيِّرة + كلمة مرور الأدمن لإلغاء الجلسات.
 */

function security_mask_email(string $e): string {
    $p = explode('@', $e, 2);
    if (count($p) !== 2) return mb_substr($e, 0, 2, 'UTF-8') . '***';
    return mb_substr($p[0], 0, 2, 'UTF-8') . '***@' . $p[1];
}

/** يبني قائمة فحوص الأمان: [id, level(ok|warn|bad|info), title, detail] */
function security_checks(): array {
    $c = [];
    $add = function (string $id, string $level, string $title, string $detail) use (&$c) { $c[] = ['id' => $id, 'level' => $level, 'title' => $title, 'detail' => $detail]; };

    // مفتاح الجلسات
    $sk = (string)SECRET_KEY;
    if ($sk === 'fc_s3cr3t_k3y_2026_fastcrand_permanent') $add('secret', 'bad', 'مفتاح الجلسات هو المفتاح الافتراضي المعروف', 'أي شخص يعرفه (موجود بكود النسخة الأصلية) يستطيع تزوير جلسة أدمن. غيّر SECRET_KEY في core/config.php (يُخرج كل المستخدمين مرة واحدة).');
    elseif (strlen($sk) < 24) $add('secret', 'warn', 'مفتاح الجلسات قصير', 'استعمل نصاً عشوائياً من 32 محرفاً فأكثر في SECRET_KEY.');
    else $add('secret', 'ok', 'مفتاح الجلسات سرّي وقوي', 'طوله ' . strlen($sk) . ' محرفاً.');

    // HTTPS
    if (req_scheme() === 'https') $add('https', 'ok', 'الاتصال مشفّر (HTTPS)', 'كوكي الجلسة يُرسَل بعلامة Secure وتُضاف ترويسة HSTS.');
    else $add('https', 'warn', 'الموقع يُفتح بدون HTTPS', 'فعّل شهادة SSL: كلمات المرور وكوكي الأدمن تعبر الشبكة بلا تشفير.');

    // الحسابات الافتراضية المعروفة
    $conn = get_db();
    $da = $conn->execute("SELECT password FROM users WHERE email='admin@admin.com' AND is_admin=1")->fetchone();
    if ($da && verify_pw('2sebvWFr6BYwVBrV', $da['password'])) $add('default_admin', 'bad', 'حساب الأدمن الافتراضي بكلمة مروره المعروفة', 'admin@admin.com بكلمة المرور الموجودة بكود النسخة الأصلية. غيّر كلمة المرور فوراً أو أنشئ أدمن جديداً واحذف هذا الحساب.');
    else $add('default_admin', 'ok', 'لا يوجد أدمن بكلمة مرور افتراضية معروفة', '');
    $tu = $conn->execute("SELECT password FROM users WHERE email='test@test.com'")->fetchone();
    if ($tu && verify_pw('123456', $tu['password'])) $add('demo_user', 'warn', 'مستخدم تجريبي بكلمة مرور معروفة', 'test@test.com / 123456 — احذفه أو غيّر كلمة مروره.');

    // كلمة مرور الأدمن المكتوبة بالإعدادات
    if (defined('ADMIN_PASSWORD') && (string)ADMIN_PASSWORD !== '') $add('config_pw', 'warn', 'كلمة مرور الأدمن ما زالت مكتوبة في core/config.php', 'بعد أول تسجيل دخول احذف قيمة ADMIN_PASSWORD من الملف (لا تُستعمل بعد إنشاء الحساب).');

    // صيغة تخزين كلمات المرور
    $legacy = (int)$conn->execute("SELECT COUNT(*) FROM users WHERE LENGTH(password)=64")->fetchone()[0];
    $total = (int)$conn->execute('SELECT COUNT(*) FROM users')->fetchone()[0];
    if (!pw_upgrade_enabled()) $add('pwhash', 'warn', 'كلمات المرور بصيغة sha256 بلا ملح (صيغة النسخة الأصلية)', 'لو تسرّبت القاعدة أو نسخة تلغرام يمكن تخمينها بسرعة. فعّل الترقية بحذف PASSWORD_HASH_UPGRADE=false من core/config.php.');
    elseif ($legacy > 0) $add('pwhash', 'info', 'كلمات المرور محمية بـ bcrypt — ' . $legacy . ' من ' . $total . ' حساباً ما زالت بالصيغة القديمة', 'تُرقّى تلقائياً وبصمت عند تسجيل دخول كل مستخدم.');
    else $add('pwhash', 'ok', 'كل كلمات المرور محمية بـ bcrypt', '');

    // ثغرة سعر الطلب في الأصل
    if (defined('STRICT_PRICING') && STRICT_PRICING) $add('pricing', 'ok', 'التحقق من أسعار الطلبات مفعّل', 'الخادم يقارن سعر كل طلب بالكتالوج (STRICT_PRICING).');
    else $add('pricing', 'warn', 'التحقق من سعر الطلب غير مفعّل (سلوك النسخة الأصلية)', 'يأخذ الخادم سعر الطلب من المتصفح، فيمكن لمستخدم متمرّس شراء خدمة بسعر 0 وسيدفع المزوّد من رصيدك. فعّل STRICT_PRICING بقيمة true في core/config.php بعد تجربة طلب عادي.');

    // أدمن (قائمة)
    $adm = $conn->execute('SELECT email FROM users WHERE is_admin=1 ORDER BY id')->fetchall();
    $add('admins', count($adm) > 3 ? 'warn' : 'info', 'حسابات الأدمن: ' . count($adm), implode('، ', array_map(fn($a) => security_mask_email((string)$a['email']), $adm)));

    // ما هو مفعّل من الحماية
    $lim = login_limits();
    $add('throttle', 'ok', 'حماية تخمين كلمات المرور والأكواد', 'إيقاف مؤقت بعد ' . $lim['per_account'] . ' محاولات دخول فاشلة (لكل حساب+عنوان) و' . guess_limit() . ' محاولات لرموز التفعيل/الاستعادة، لمدة ' . (int)($lim['window'] / 60) . ' دقيقة.');
    $add('csrf', 'ok', 'حماية CSRF', 'تُرفض الطلبات المغيِّرة القادمة من موقع آخر، ونقاط الاستيراد/التصدير تطلب رمزاً إضافياً وكلمة مرورك.');
    $add('audit', 'ok', 'سجل تدقيق لعمليات الأدمن', 'يسجّل كل عملية تعديل بلوحة الإدارة (الوقت، العنوان، الحساب، المسار) — الجدول أدناه.');
    if (admin_ip_restricted()) $add('ip', 'ok', 'قائمة عناوين الأدمن مفعّلة', 'لوحة الإدارة مقيّدة بـ ' . count(array_filter(ADMIN_IP_ALLOWLIST)) . ' عنواناً/نطاقاً. عنوانك الحالي: ' . client_ip());
    else $add('ip', 'info', 'قائمة عناوين الأدمن غير مفعّلة', 'للتشديد: ضع ADMIN_IP_ALLOWLIST في core/config.php (مثال: [\'203.0.113.5\', \'198.51.100.0/24\']). عنوانك الحالي: ' . client_ip());
    $tp = defined('TRUSTED_PROXIES') ? array_filter((array)TRUSTED_PROXIES) : [];
    $add('proxy', 'info', $tp ? 'بروكسي موثوق مضبوط' : 'لا بروكسي موثوق', $tp ? 'عنوان الزائر يُقرأ من ترويسة البروكسي لطلبات تأتي من: ' . implode('، ', $tp) : 'إن كان موقعك خلف Cloudflare/بروكسي فاضبط TRUSTED_PROXIES كي يُحسب عنوان الزائر الحقيقي (للحظر والسجل).');

    // PHP
    $add('php', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warn', 'PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : 'إصدار PHP 8.0 انتهى دعمه الأمني: حدّث إلى 8.2 أو أحدث من لوحة الاستضافة.');
    $add('errors', ini_get('display_errors') ? 'warn' : 'ok', ini_get('display_errors') ? 'عرض أخطاء PHP مفعّل' : 'عرض الأخطاء للزوّار معطّل', ini_get('display_errors') ? 'عطّله في الاستضافة كي لا تظهر مسارات/تفاصيل داخلية.' : '');

    // ملف الإعدادات
    $cf = APP_DIR . '/config.php';
    if (is_file($cf)) {
        $perm = fileperms($cf) & 0777;
        $add('config_perm', ($perm & 0002) ? 'bad' : (($perm & 0004) ? 'info' : 'ok'), 'صلاحيات core/config.php: ' . decoct($perm),
            ($perm & 0002) ? 'قابل للكتابة من الجميع! غيّره إلى 640 أو 600.' : (($perm & 0004) ? 'مقروء لباقي مستخدمي الخادم؛ إن أتاحت استضافتك فاجعله 640.' : ''));
    }

    // النسخ الاحتياطي والكرون
    $bk = list_backups();
    $last = $bk ? max(array_column($bk, 'timestamp')) : 0;
    if (!$bk) $add('backup', 'warn', 'لا توجد نسخ احتياطية محلية', 'شغّل الكرون (cron.php) أو اضغط «نسخ احتياطي الآن».');
    else $add('backup', (time() - $last) > 172800 ? 'warn' : 'ok', 'آخر نسخة احتياطية محلية: ' . date('Y-m-d H:i', (int)$last), count($bk) . ' نسخة محفوظة.');
    $tgOn = py_truthy(get_setting('tg_bot_token', '')) && py_truthy(get_setting('tg_chat_id', ''));
    $add('tg', $tgOn ? 'ok' : 'info', $tgOn ? 'نسخ تلغرام مفعّل' : 'نسخ تلغرام غير مضبوط', $tgOn ? '' : 'اضبط Bot Token و Chat ID من إعدادات النسخ الاحتياطي لتصلك نسخة خارج الخادم.');
    $cs = state_get('cron', []);
    $lastCron = 0; foreach ($cs as $j) $lastCron = max($lastCron, (int)($j['last'] ?? 0));
    if ($lastCron === 0) $add('cron', 'warn', 'الكرون لم يعمل بعد', 'أضف مهمة Cron: * * * * * php ' . ROOT_DIR . '/cron.php');
    else $add('cron', (time() - $lastCron) > 600 ? 'warn' : 'ok', 'آخر تشغيل للكرون: ' . date('Y-m-d H:i:s', $lastCron), (time() - $lastCron) > 600 ? 'مضى أكثر من 10 دقائق: تحقق من مهمة Cron.' : '');
    $ep = session_epoch();
    $add('epoch', 'info', $ep > 0 ? 'آخر إلغاء لكل الجلسات: ' . date('Y-m-d H:i', $ep) : 'لم تُلغَ الجلسات من قبل', 'زر «إلغاء كل الجلسات» أدناه يُخرج جميع المستخدمين والأدمن (مفيد عند الشك باختراق).');
    return $c;
}

route('/api/admin/security/status', function () {
    if (($g = transfer_guard(false)) !== null) return $g;
    $c = security_checks();
    $counts = ['bad' => 0, 'warn' => 0, 'ok' => 0, 'info' => 0];
    foreach ($c as $x) $counts[$x['level']]++;
    return tj(['ok' => true, 'csrf' => csrf_token(), 'checks' => $c, 'counts' => $counts, 'audit' => audit_tail(100), 'ip' => client_ip()]);
});

/** فحص تعرّض الملفات الخاصة: يطلب (من الخادم نفسه) روابط يجب أن تُرفض */
route('/api/admin/security/probe', function () {
    if (($g = transfer_guard()) !== null) return $g;
    [$okRate] = rate_limit_hit('probe|' . $_SESSION['user_id'], 6, 600);
    if (!$okRate) return tj(['ok' => false, 'msg' => 'محاولات كثيرة، انتظر قليلاً'], 429);
    $base = req_scheme() . '://' . req_host();
    $paths = ['/core/config.php' => 'ملف الإعدادات', '/sql/database.sql' => 'ملف قاعدة البيانات', '/data/.htaccess' => 'مجلد البيانات', '/data/logs/app_errors.log' => 'سجل الأخطاء', '/cron.php' => 'ملف الكرون', '/tools/init_db.php' => 'أدوات الخادم', '/.htaccess' => 'ملف .htaccess'];
    $out = [];
    foreach ($paths as $p => $label) {
        $ch = curl_init($base . $p);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_HTTPHEADER => ['Cache-Control: no-cache']]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        $out[] = ['path' => $p, 'label' => $label, 'code' => $code, 'state' => $err ? 'unknown' : (($code === 200) ? 'exposed' : 'blocked')];
    }
    audit_log('security_probe');
    return tj(['ok' => true, 'results' => $out]);
}, ['POST']);

route('/api/admin/security/revoke-sessions', function () {
    if (($g = transfer_guard()) !== null) return $g;
    $d = req_json(false, true);
    if (($p = transfer_check_password(is_array($d) ? ($d['password'] ?? '') : '')) !== null) return $p;
    bump_session_epoch();
    audit_log('sessions_revoked');
    $_SESSION = [];     // الأدمن نفسه يخرج أيضاً
    return tj(['ok' => true, 'msg' => 'أُلغيت كل الجلسات. سجّل الدخول من جديد']);
}, ['POST']);
