<?php
/**
 * تسجيل الدخول/الخروج - مقابل routes المصادقة بـ webapp.py.
 *
 * ثلاث طرق دخول:
 *   1) واتساب برمز تحقق (الطريقة الرئيسية، مطابقة لنسخة بايثون) - /auth/whatsapp/*
 *      يحتاج مزوّد واتساب مُهيّأ (core/lib/wa_provider.php) ما عدا حساب MASTER_ADMIN.
 *   2) جوجل (جديدة، أضيفت بطلب صاحب المنصة) - /api/google-login
 *   3) بريد + كلمة مرور (احتياطي قديم من نسخة بايثون، بلا أي زر بالواجهة) - /login/email و /signup/email
 */

/* ---------------------------------------------------------------- عام */

route('/login', function () { return redirect('/'); });
route('/signup', function () { return redirect('/login'); });

route('/logout', function () {
    $_SESSION = [];
    return redirect('/login');
}, ['POST']);

/* ---------------------------------------------------------------- بريد + كلمة مرور (احتياطي) */

/** صفحة دخول/تسجيل بالبريد - منفذ احتياطي حقيقي (مو Placeholder): يحتاجه أول أدمن
 * لتسجيل دخوله بـ ADMIN_EMAIL/ADMIN_PASSWORD قبل ما يربط أي حساب واتساب (ولا طريقة
 * دخول ثانية تشتغل قبل ربط أول حساب - واتساب يحتاج مُرسِل رموز متصل أصلاً، وجوجل يحتاج
 * GOOGLE_CLIENT_ID مُعبّى). */
function wsl_auth_email_html(string $mode): string {
    $s = get_site_settings();
    $html = file_get_contents(APP_DIR . '/templates/auth_email.html');
    $isSignup = $mode === 'signup';
    $installed = !$isSignup && req_args('installed') === '1';
    $tokens = [
        '__SITE_NAME__' => htmlspecialchars($s['site_name'] ?: 'واصل', ENT_QUOTES),
        '__TITLE__' => $isSignup ? 'إنشاء حساب' : 'تسجيل الدخول',
        '__INSTALLED_NOTE__' => $installed ? '<div class="note">✓ تم تثبيت موقعك بنجاح - سجّل دخولك بالبريد وكلمة المرور اللي كتبتهم بالتثبيت</div>' : '',
        '__ACTION__' => $isSignup ? '/signup/email' : '/login/email',
        '__BUTTON_TEXT__' => $isSignup ? 'إنشاء الحساب' : 'دخول',
        '__MIN_PW__' => $isSignup ? '8' : '1',
        '__NAME_FIELD__' => $isSignup ? '<label>الاسم</label><input id="name" name="name" type="text" required autocomplete="name">' : '',
        '__SWITCH_HTML__' => $isSignup
            ? 'عندك حساب؟ <a href="/login/email">سجّل الدخول</a>'
            : 'ما عندك حساب؟ <a href="/signup/email">أنشئ حساب</a>',
    ];
    return str_replace(array_keys($tokens), array_values($tokens), $html);
}

route('/login/email', function () {
    if (req_method() === 'GET') return make_response(wsl_auth_email_html('login'), 200, 'text/html; charset=utf-8');
    $email = mb_strtolower(trim((string)req_form('email', '')), 'UTF-8');
    $password = (string)req_form('password', '');
    if (($thr = login_throttle_check($email)) !== null) return $thr;
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
    if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
        login_throttle_fail($email);
        return jsonify(['ok' => false, 'error' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة'], 400);
    }
    login_throttle_ok($email);
    wsl_login_user($user);
    return jsonify(['ok' => true]);
}, ['GET', 'POST']);

route('/signup/email', function () {
    if (req_method() === 'GET') return make_response(wsl_auth_email_html('signup'), 200, 'text/html; charset=utf-8');
    $name = trim((string)req_form('name', ''));
    $email = mb_strtolower(trim((string)req_form('email', '')), 'UTF-8');
    $password = (string)req_form('password', '');
    if ($name === '' || $email === '' || $password === '') {
        return jsonify(['ok' => false, 'error' => 'عبّي كل الحقول'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return jsonify(['ok' => false, 'error' => 'أدخل بريد إلكتروني صحيح'], 400);
    }
    if (strlen($password) < 8) {
        return jsonify(['ok' => false, 'error' => 'كلمة المرور 8 محارف على الأقل'], 400);
    }
    $conn = get_db();
    if ($conn->execute('SELECT id FROM users WHERE email=?', [$email])->fetchone()) {
        return jsonify(['ok' => false, 'error' => 'هذا البريد مسجّل من قبل'], 400);
    }
    $isAdmin = (int)$conn->execute('SELECT COUNT(*) AS n FROM users')->fetchone()['n'] === 0;
    $conn->execute(
        'INSERT INTO users (email, name, password_hash, is_admin, created_at, trial_ends_at) VALUES (?,?,?,?,?,?)',
        [$email, $name, password_hash($password, PASSWORD_BCRYPT), $isAdmin ? 1 : 0, now_str(),
         gmdate('Y-m-d H:i', time() + TRIAL_DAYS * 86400)]
    );
    $conn->commit();
    $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
    wsl_login_user($user);
    return jsonify(['ok' => true]);
}, ['GET', 'POST']);

/** يسجّل $user بالجلسة الحالية - مشترك بين كل طرق الدخول */
function wsl_login_user(Row $user): void {
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['email'] = $user['email'] ?: $user['phone'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['is_admin'] = py_truthy($user['is_admin']);
}

/* ---------------------------------------------------------------- جوجل (جديد) */

route('/api/google-login', function () {
    if (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === '') {
        return jsonify(['ok' => false, 'error' => 'الدخول بجوجل غير مفعّل حالياً'], 400);
    }
    $idToken = (string)(req_data()['credential'] ?? '');
    if ($idToken === '') return jsonify(['ok' => false, 'error' => 'لم يصل رمز جوجل'], 400);
    try {
        $res = requests_get('https://oauth2.googleapis.com/tokeninfo', ['params' => ['id_token' => $idToken], 'timeout' => 10]);
    } catch (RequestsException $e) {
        return jsonify(['ok' => false, 'error' => 'تعذر التحقق من جوجل حالياً، حاول مرة أخرى'], 502);
    }
    if (!$res->ok()) return jsonify(['ok' => false, 'error' => 'رمز جوجل غير صالح'], 401);
    $info = json_decode($res->text, true);
    if (!is_array($info) || ($info['aud'] ?? '') !== GOOGLE_CLIENT_ID || ($info['email_verified'] ?? 'false') !== 'true' || empty($info['email'])) {
        return jsonify(['ok' => false, 'error' => 'رمز جوجل غير صالح'], 401);
    }
    $email = mb_strtolower((string)$info['email'], 'UTF-8');
    $name = (string)($info['name'] ?? $email);
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
    if (!$user) {
        $isAdmin = (int)$conn->execute('SELECT COUNT(*) AS n FROM users')->fetchone()['n'] === 0;
        $conn->execute(
            'INSERT INTO users (email, name, is_admin, created_at, trial_ends_at) VALUES (?,?,?,?,?)',
            [$email, $name, $isAdmin ? 1 : 0, now_str(), gmdate('Y-m-d H:i', time() + TRIAL_DAYS * 86400)]
        );
        $conn->commit();
        $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
    }
    wsl_login_user($user);
    return jsonify(['ok' => true]);
}, ['POST']);

/* ---------------------------------------------------------------- واتساب (رمز تحقق) */

function wsl_otp_set(string $phone, string $code): void {
    $conn = get_db();
    $conn->execute(
        'INSERT INTO otp_codes (phone, code, expires_at, attempts) VALUES (?,?,?,0) '
        . 'ON CONFLICT(phone) DO UPDATE SET code=excluded.code, expires_at=excluded.expires_at, attempts=0',
        [$phone, $code, time() + 600]
    );
    $conn->commit();
}

/** حساب واتساب المصمَّم لإرسال رموز الدخول (otp_sender=1 بجدول wa_accounts) إن كان متصلاً حالياً */
function wsl_find_otp_sender_account(): ?string {
    $row = get_db()->execute('SELECT id FROM wa_accounts WHERE otp_sender=1 LIMIT 1')->fetchone();
    if (!$row) return null;
    return (gateway_status($row['id'])['status'] ?? '') === 'connected' ? $row['id'] : null;
}

/** يسجّل دخول صاحب هذا الرقم بالجلسة الحالية - ينشئ حسابه لو ما كان موجود (ويرقّيه أدمن لو is_master) */
function wsl_login_by_phone(string $phone, bool $isMaster): bool {
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE phone=?', [$phone])->fetchone();
    $isNew = $user === null;
    if (!$user) {
        $isAdmin = $isMaster || (int)$conn->execute('SELECT COUNT(*) AS n FROM users')->fetchone()['n'] === 0;
        $conn->execute(
            'INSERT INTO users (phone, is_admin, created_at, trial_ends_at) VALUES (?,?,?,?)',
            [$phone, $isAdmin ? 1 : 0, now_str(), gmdate('Y-m-d H:i', time() + TRIAL_DAYS * 86400)]
        );
        $conn->commit();
        $user = $conn->execute('SELECT * FROM users WHERE phone=?', [$phone])->fetchone();
    } elseif ($isMaster && !py_truthy($user['is_admin'])) {
        $conn->execute('UPDATE users SET is_admin=1 WHERE id=?', [$user['id']]);
        $conn->commit();
        $user = $conn->execute('SELECT * FROM users WHERE id=?', [$user['id']])->fetchone();
    }
    wsl_login_user($user);
    return $isNew;
}

route('/auth/whatsapp/send_code', function () {
    $phone = preg_replace('/\D/', '', (string)(req_data()['phone'] ?? ''));
    if (strlen($phone) < 8) return jsonify(['ok' => false, 'error' => 'أدخل رقم واتساب صحيح مع مفتاح الدولة'], 400);

    if (MASTER_ADMIN_PHONE !== '' && $phone === MASTER_ADMIN_PHONE) {
        // حساب أدمن ثابت يدخل مباشرة بدون أي رمز - يفيد لدخول صاحب المنصة نفسه حتى لو ما
        // فيه حساب واتساب متصل بعد لإرسال الرموز (نفس سلوك webapp.py بالضبط)
        wsl_login_by_phone($phone, true);
        return jsonify(['ok' => true, 'master' => true]);
    }
    if (($thr = request_throttle('otpsend', $phone, 5, 20)) !== null) return $thr;

    $sender = wsl_find_otp_sender_account();
    if (!$sender) {
        return jsonify(['ok' => false, 'error' => 'تسجيل الدخول عبر واتساب غير مفعّل حالياً، تواصل مع إدارة المنصة'], 400);
    }
    $code = (string)random_int(100000, 999999);
    wsl_otp_set($phone, $code);
    try {
        gateway_send($sender, $phone, "رمز التحقق الخاص بك في واصل: $code\nصالح لمدة 10 دقائق.");
    } catch (GatewayException $e) {
        log_app_error('WARNING', 'otp send_code failed: ' . $e->getMessage());
        return jsonify(['ok' => false, 'error' => 'تعذر إرسال الرمز، تأكد إن الرقم يستخدم واتساب وحاول مرة أخرى'], 502);
    }
    return jsonify(['ok' => true, 'sending' => false]);
}, ['POST']);

route('/auth/whatsapp/verify', function () {
    $data = req_data();
    $phone = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
    $code = trim((string)($data['code'] ?? ''));
    $isMaster = MASTER_ADMIN_PHONE !== '' && $phone === MASTER_ADMIN_PHONE && MASTER_ADMIN_CODE !== '' && $code === MASTER_ADMIN_CODE;

    if (!$isMaster) {
        if (($g = guess_check('otp|' . $phone)) !== null) return $g;
        $conn = get_db();
        $row = $conn->execute('SELECT * FROM otp_codes WHERE phone=?', [$phone])->fetchone();
        if (!$row || (int)$row['expires_at'] < time() || !hash_equals((string)$row['code'], $code)) {
            guess_fail('otp|' . $phone);
            return jsonify(['ok' => false, 'error' => 'الرمز غير صحيح أو منتهي الصلاحية'], 400);
        }
        guess_ok('otp|' . $phone);
        $conn->execute('DELETE FROM otp_codes WHERE phone=?', [$phone]);
        $conn->commit();
    }

    $isNew = wsl_login_by_phone($phone, $isMaster);
    return jsonify(['ok' => true, 'is_new' => $isNew]);
}, ['POST']);

route('/auth/set_name', function () {
    if (($r = require_login()) !== null) return $r;
    $name = trim((string)(req_data()['name'] ?? ''));
    if ($name === '') return jsonify(['ok' => false, 'error' => 'اكتب اسمك'], 400);
    get_db()->execute('UPDATE users SET name=? WHERE id=?', [$name, $_SESSION['user_id']]);
    get_db()->commit();
    $_SESSION['name'] = $name;
    return jsonify(['ok' => true]);
}, ['POST']);
