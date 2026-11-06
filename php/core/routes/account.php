<?php
/**
 * الحسابات: تسجيل/تفعيل/دخول/خروج/جوجل/الملف الشخصي/تغيير البريد/كلمة المرور/الصورة الشخصية
 * منقولة من app.py:2093-2556 (نفس الرسائل ورموز الحالة والمنطق).
 */

/** str(secrets.randbelow(900000) + 100000) */
function _rand_code(): string { return (string)(random_int(0, 899999) + 100000); }

/** login: يضبط جلسة المستخدم (مثل session['user_id'] = ... بكل مسار دخول) */
function _session_login(Row $user, bool $withEmail = true, ?bool $isAdmin = null): void {
    $_SESSION['_permanent'] = true;
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    if ($withEmail) $_SESSION['user_email'] = $user['email'];
    $_SESSION['is_admin'] = $isAdmin ?? (bool)$user['is_admin'];
}

const WELCOME_NOTIF_TEXT = 'تم تفعيل حسابك بنجاح. ابدأ بشحن رصيدك وطلب خدماتك الأولى!';

function _welcome_notif($uid): void {
    push_user_notif($uid, 'system', 'مرحباً بك في ' . get_setting('site_name', 'fastcrand') . '! ', WELCOME_NOTIF_TEXT, 'promo', 'recharge:');
}

/* '/api/register'  (app.py:2093) */
route('/api/register', function () {
    $data = req_data_strict();
    $name = py_strip(py_or(dget($data, 'name'), ''));
    $email = mb_strtolower(py_strip(py_or(dget($data, 'email'), '')), 'UTF-8');
    $pw = py_or(dget($data, 'password'), '');
    $pw2 = py_or(dget($data, 'password2'), '');

    if (!py_truthy($name) || !py_truthy($email) || !py_truthy($pw)) {
        return jsonify(['ok' => false, 'msg' => 'جميع الحقول مطلوبة'], 400);
    }
    if ($pw !== $pw2) {
        return jsonify(['ok' => false, 'msg' => 'كلمة المرور غير متطابقة'], 400);
    }
    if (py_len($pw) < 6) {
        return jsonify(['ok' => false, 'msg' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل'], 400);
    }
    $thr = request_throttle('register', client_ip(), defined('REGISTER_MAX_PER_HOUR') ? (int)REGISTER_MAX_PER_HOUR : 30, 1000, 3600);   // تسجيل آلي
    if ($thr !== null) return $thr;

    $skip_verify = get_setting('skip_email_verify', '0') === '1';
    $token = $skip_verify ? null : _rand_code();
    $conn = get_db();
    try {
        $_pid = _next_public_id();
        $conn->execute('INSERT INTO users(name,email,password,verify_token,verified,public_id) VALUES(?,?,?,?,?,?)',
            [$name, $email, hash_pw($pw), $token, $skip_verify ? 1 : 0, $_pid]);
        $conn->commit();
        $new_user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
        $ref_code = mb_strtoupper(py_strip(py_str(dget($data, 'ref_code', ''))), 'UTF-8');
        if (py_truthy($ref_code) && $new_user) {
            try {
                $new_uid = $new_user['id'];
                _get_or_create_referral($new_uid);
                $referrer = $conn->execute('SELECT * FROM referrals WHERE code=?', [$ref_code])->fetchone();
                if ($referrer && $referrer['user_id'] != $new_uid) {
                    $conn->execute('UPDATE referrals SET referred_by=? WHERE user_id=?', [$referrer['user_id'], $new_uid]);
                    $conn->execute('UPDATE referrals SET ref_count=ref_count+1 WHERE user_id=?', [$referrer['user_id']]);
                    $conn->commit();
                }
            } catch (Throwable $e) {}
        }
        $conn->commit();
    } catch (IntegrityError $e) {
        return jsonify(['ok' => false, 'msg' => 'البريد الإلكتروني مسجل مسبقاً'], 400);
    }

    if ($skip_verify) {
        _session_login($new_user);
        _welcome_notif($new_user['id']);
        return jsonify(['ok' => true, 'msg' => 'تم إنشاء الحساب بنجاح!', 'auto_login' => true]);
    }

    send_verify_email($email, $token);
    return jsonify(['ok' => true, 'msg' => 'تم إنشاء الحساب! تحقق من بريدك الإلكتروني']);
}, ['POST']);

/* '/api/verify-code'  (app.py:2148) */
route('/api/verify-code', function () {
    $data = req_data();
    $email = mb_strtolower(py_strip(py_or(dget($data, 'email'), '')), 'UTF-8');
    $code = py_strip(py_or(dget($data, 'code'), ''));
    if (!py_truthy($email) || !py_truthy($code)) {
        return jsonify(['ok' => false, 'msg' => 'أدخل الرمز']);
    }
    $thr = guess_check('verify|' . $email);              // الرمز 6 أرقام: بلا حد يمكن تخمينه
    if ($thr !== null) return $thr;
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE email=? AND verify_token=?', [$email, $code])->fetchone();
    if ($user) {
        guess_ok('verify|' . $email);
        $conn->execute('UPDATE users SET verified=1, verify_token=NULL WHERE id=?', [$user['id']]);
        $conn->commit();
        _session_login($user, false);
        _welcome_notif($user['id']);
        return jsonify(['ok' => true, 'msg' => 'تم تفعيل حسابك بنجاح!', 'auto_login' => true]);
    }
    if (guess_fail('verify|' . $email)) {                // بلغ الحد: أبطل الرمز الحالي
        $conn->execute('UPDATE users SET verify_token=NULL WHERE email=? AND verified=0', [$email]);
        $conn->commit();
    }
    return jsonify(['ok' => false, 'msg' => 'الرمز غير صحيح أو منتهي الصلاحية']);
}, ['POST']);

/* '/api/resend-code'  (app.py:2170) */
route('/api/resend-code', function () {
    $data = req_data();
    $email = mb_strtolower(py_strip(py_or(dget($data, 'email'), '')), 'UTF-8');
    if (!py_truthy($email)) {
        return jsonify(['ok' => false, 'msg' => 'البريد مطلوب']);
    }
    $thr = request_throttle('mailcode', $email, 5, 25);   // لا إغراق لبريد الضحية
    if ($thr !== null) return $thr;
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE email=? AND verified=0', [$email])->fetchone();
    if (!$user) {
        return jsonify(['ok' => false, 'msg' => 'الحساب غير موجود أو مفعّل مسبقاً']);
    }
    $new_code = _rand_code();
    $conn->execute('UPDATE users SET verify_token=? WHERE id=?', [$new_code, $user['id']]);
    $conn->commit();
    send_verify_email($email, $new_code);
    return jsonify(['ok' => true, 'msg' => 'تم إرسال رمز جديد']);
}, ['POST']);

/* '/api/forgot-password'  (app.py:2188) */
route('/api/forgot-password', function () {
    $data = req_data();
    $email = mb_strtolower(py_strip(py_or(dget($data, 'email'), '')), 'UTF-8');
    if (!py_truthy($email)) {
        return jsonify(['ok' => false, 'msg' => 'أدخل البريد الإلكتروني']);
    }
    $thr = request_throttle('mailcode', $email, 5, 25);   // لا إغراق لبريد الضحية
    if ($thr !== null) return $thr;
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
    if (!$user) {
        return jsonify(['ok' => false, 'msg' => 'البريد غير مسجل']);
    }
    $code = _rand_code();
    $conn->execute('UPDATE users SET verify_token=? WHERE id=?', [$code, $user['id']]);
    $conn->commit();
    send_reset_email($email, $code);
    return jsonify(['ok' => true, 'msg' => 'تم إرسال رمز التحقق إلى بريدك']);
}, ['POST']);

/* '/api/reset-password'  (app.py:2206) */
route('/api/reset-password', function () {
    $data = req_data();
    $email = mb_strtolower(py_strip(py_or(dget($data, 'email'), '')), 'UTF-8');
    $code = py_strip(py_or(dget($data, 'code'), ''));
    $new_pw = py_or(dget($data, 'password'), '');
    if (!py_truthy($email) || !py_truthy($code) || !py_truthy($new_pw)) {
        return jsonify(['ok' => false, 'msg' => 'جميع الحقول مطلوبة']);
    }
    if (py_len($new_pw) < 6) {
        return jsonify(['ok' => false, 'msg' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل']);
    }
    // الرمز 6 أرقام: كان بلا حد فيمكن تخمينه وسرقة أي حساب (ومنها حساب الأدمن) — الآن محدود ويُبطَل عند بلوغ الحد
    $thr = guess_check('reset|' . $email);
    if ($thr !== null) return $thr;
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE email=? AND verify_token=?', [$email, $code])->fetchone();
    if (!$user) {
        if (guess_fail('reset|' . $email)) {
            $conn->execute('UPDATE users SET verify_token=NULL WHERE email=?', [$email]);
            $conn->commit();
        }
        return jsonify(['ok' => false, 'msg' => 'الرمز غير صحيح أو منتهي']);
    }
    guess_ok('reset|' . $email);
    $conn->execute('UPDATE users SET password=?, verify_token=NULL, verified=1 WHERE id=?', [hash_pw($new_pw), $user['id']]);
    $conn->commit();
    _session_login($user, false);
    return jsonify(['ok' => true, 'msg' => 'تم تغيير كلمة المرور بنجاح!', 'auto_login' => true]);
}, ['POST']);

/* '/verify/<token>'  (app.py:2230) */
route('/verify/<token>', function ($token) {
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE verify_token=?', [$token])->fetchone();
    if ($user) {
        $conn->execute('UPDATE users SET verified=1, verify_token=NULL WHERE id=?', [$user['id']]);
        $conn->commit();
        _welcome_notif($user['id']);
        return redirect('/?verified=1');
    }
    return redirect('/?verified=0');
});

/* '/api/login'  (app.py:2249) */
route('/api/login', function () {
    $data = req_data_strict();
    $identifier = py_strip(py_or(py_or(dget($data, 'email'), dget($data, 'username')), ''));
    $identifier_lower = mb_strtolower($identifier, 'UTF-8');
    $pw = py_or(dget($data, 'password'), '');

    $thr = login_throttle_check($identifier_lower);     // حماية من تخمين كلمات المرور
    if ($thr !== null) return $thr;
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE LOWER(email)=? OR LOWER(username)=? ORDER BY id LIMIT 1',
        [$identifier_lower, $identifier_lower])->fetchone();

    if (!$user) {
        login_throttle_fail($identifier_lower);
        return jsonify(['ok' => false, 'msg' => 'البريد أو كلمة المرور غير صحيحة'], 401);
    }

    $pw_valid = false;
    if (py_truthy($user['password']) && verify_pw($pw, $user['password'])) {
        $pw_valid = true;
        if (pw_needs_upgrade($user['password'])) {          // ترقية كلمة المرور القديمة (sha256) إلى bcrypt بصمت عند نجاح الدخول
            $conn->execute('UPDATE users SET password=? WHERE id=?', [hash_pw($pw), $user['id']]);
            $conn->commit();
        }
    }

    if (!$pw_valid) {
        // عمود md5_password القديم (إن وُجد بقاعدة مهاجَرة من لوحة أخرى)
        $md5_col = $user->has('md5_password') ? $user['md5_password'] : null;
        if (py_truthy($md5_col)) {
            if ($md5_col === md5((string)$pw)) {
                $pw_valid = true;
                $conn->execute('UPDATE users SET password=?, md5_password=NULL WHERE id=?', [hash_pw($pw), $user['id']]);
                $conn->commit();
            }
        }
    }

    if (!$pw_valid) {
        login_throttle_fail($identifier_lower);
        return jsonify(['ok' => false, 'msg' => 'البريد أو كلمة المرور غير صحيحة'], 401);
    }
    login_throttle_ok($identifier_lower);
    if (!py_truthy($user['verified'])) {
        return jsonify(['ok' => false, 'msg' => 'يرجى تأكيد بريدك الإلكتروني أولاً'], 403);
    }

    _session_login($user);
    return jsonify(['ok' => true, 'msg' => 'تم تسجيل الدخول!', 'name' => $user['name']]);
}, ['POST']);

/* '/api/logout'  (app.py:2300) */
route('/api/logout', function () {
    $_SESSION = [];   // session.clear()
    return redirect('/');
});

/* '/api/google-login'  (app.py:2305) */
route('/api/google-login', function () {
    $data = req_data_strict();
    $credential = dget($data, 'credential', '');
    if (!py_truthy($credential)) {
        return jsonify(['ok' => false, 'msg' => 'No credential'], 400);
    }
    try {
        $_gci = _get_google_client_id();
        if (!py_truthy($_gci) || $_gci === 'YOUR_GOOGLE_CLIENT_ID.apps.googleusercontent.com') {
            return jsonify(['ok' => false, 'msg' => 'تسجيل Google غير مفعّل. تواصل مع الإدارة'], 400);
        }
        // التحقق من التوكن عبر tokeninfo من Google (بلا مكتبة google-auth)
        $gresp = requests_get('https://oauth2.googleapis.com/tokeninfo', ['params' => ['id_token' => $credential], 'timeout' => 10]);
        if ($gresp->status_code != 200) {
            return jsonify(['ok' => false, 'msg' => 'Google token invalid'], 401);
        }
        $idinfo = $gresp->json();
        if (!is_array($idinfo)) throw new PyTypeError("object has no attribute 'get'");
        if (dget($idinfo, 'aud') !== $_gci) {
            return jsonify(['ok' => false, 'msg' => 'Google token invalid'], 401);
        }
        $email = mb_strtolower(py_str(py_or(dget($idinfo, 'email'), '')), 'UTF-8');
        $name = dget($idinfo, 'name', '');
        $gid = dget($idinfo, 'sub', '');
        $picture = dget($idinfo, 'picture', '');
        if (!py_truthy($email)) {
            return jsonify(['ok' => false, 'msg' => 'No email from Google'], 400);
        }
        $conn = get_db();
        $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
        if ($user) {
            if (!py_truthy($user['google_id'])) {
                $conn->execute('UPDATE users SET google_id=?, verified=1, avatar=? WHERE id=?', [$gid, $picture, $user['id']]);
                $conn->commit();
            }
            _session_login($user);
            return jsonify(['ok' => true, 'msg' => 'تم تسجيل الدخول!', 'name' => $user['name']]);
        } else {
            $_pid = _next_public_id();
            $conn->execute('INSERT INTO users(name,email,password,verified,google_id,avatar,public_id) VALUES(?,?,?,1,?,?,?)',
                [$name, $email, '', $gid, $picture, $_pid]);
            $conn->commit();
            $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
            _session_login($user, true, false);
            return jsonify(['ok' => true, 'msg' => 'تم إنشاء الحساب وتسجيل الدخول!', 'name' => $name]);
        }
    } catch (RequestsException $e) {
        return jsonify(['ok' => false, 'msg' => 'تعذر التحقق من Google، حاول مرة أخرى'], 502);
    } catch (PyValueError | PyKeyError $e) {
        return jsonify(['ok' => false, 'msg' => 'Google token invalid'], 401);
    }
}, ['POST']);

/* '/api/update-profile'  (app.py:2361) */
route('/api/update-profile', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $data = req_data_strict();
    $name = py_strip(py_or(dget($data, 'name'), ''));
    $email = mb_strtolower(py_strip(py_or(dget($data, 'email'), '')), 'UTF-8');
    if (!py_truthy($name) || !py_truthy($email)) {
        return jsonify(['ok' => false, 'msg' => 'الاسم والبريد مطلوبين']);
    }
    if (py_len($name) < 2) {
        return jsonify(['ok' => false, 'msg' => 'الاسم قصير جداً']);
    }
    if (!is_valid_email($email)) {
        return jsonify(['ok' => false, 'msg' => 'صيغة البريد الإلكتروني غير صحيحة']);
    }
    $conn = get_db();
    $me = $conn->execute('SELECT email, google_id FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
    if (!$me) {
        return jsonify(['ok' => false, 'msg' => 'الحساب غير موجود'], 404);
    }
    $current_email = mb_strtolower(py_strip(py_or($me['email'], '')), 'UTF-8');
    // منع تغيير بريد حساب مرتبط بـ Google
    if ($email !== $current_email && py_truthy($me['google_id'])) {
        return jsonify(['ok' => false, 'msg' => 'لا يمكن تغيير بريد حساب مرتبط بـ Google. سجّل الدخول بحسابك على Google مباشرة.']);
    }
    $existing = $conn->execute('SELECT id FROM users WHERE email=? AND id!=?', [$email, $_SESSION['user_id']])->fetchone();
    if ($existing) {
        return jsonify(['ok' => false, 'msg' => 'البريد الإلكتروني مستخدم من حساب آخر']);
    }

    // الحالة 1: البريد لم يتغير → تحديث الاسم فقط
    if ($email === $current_email) {
        $conn->execute('UPDATE users SET name=? WHERE id=?', [$name, $_SESSION['user_id']]);
        $conn->commit();
        $_SESSION['user_name'] = $name;
        return jsonify(['ok' => true, 'msg' => 'تم تحديث المعلومات بنجاح!', 'email_changed' => false]);
    }

    // الحالة 2: البريد تغيّر → احفظ الاسم الآن واطلب التحقق قبل تطبيق البريد
    $conn->execute('UPDATE users SET name=? WHERE id=?', [$name, $_SESSION['user_id']]);
    $code = _rand_code();
    $conn->execute('DELETE FROM pending_email_changes WHERE user_id=?', [$_SESSION['user_id']]);
    $conn->execute('INSERT INTO pending_email_changes(user_id, new_email, code, attempts, created_at) VALUES(?,?,?,0,?)',
        [$_SESSION['user_id'], $email, $code, microtime(true)]);
    $conn->commit();
    $_SESSION['user_name'] = $name;
    $sent = send_email_change_email($email, $code);
    if (!$sent) {
        return jsonify(['ok' => false, 'msg' => 'تعذّر إرسال رمز التحقق إلى البريد الجديد. تأكد من أن البريد صحيح وحاول لاحقاً.']);
    }
    try {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $masked = (py_slice($local, null, 2) . '***' . (py_len($local) > 3 ? mb_substr($local, -1, null, 'UTF-8') : '')) . '@' . $domain;
    } catch (Throwable $e) {
        $masked = $email;
    }
    return jsonify(['ok' => true, 'msg' => 'تم إرسال رمز التحقق إلى ' . $masked, 'email_changed' => true, 'pending_email' => $email]);
}, ['POST']);

/* '/api/verify-email-change'  (app.py:2421) */
route('/api/verify-email-change', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $data = req_data();
    $code = py_strip(py_or(dget($data, 'code'), ''));
    // str.isdigit() ببايثون تقبل أرقام Unicode أيضاً
    if (!py_truthy($code) || py_len($code) !== 6 || !preg_match('/^\p{Nd}+$/u', $code)) {
        return jsonify(['ok' => false, 'msg' => 'أدخل الرمز المكوّن من 6 أرقام']);
    }
    $conn = get_db();
    $uid = $_SESSION['user_id'];
    $pend = $conn->execute('SELECT * FROM pending_email_changes WHERE user_id=?', [$uid])->fetchone();
    if (!$pend) {
        return jsonify(['ok' => false, 'msg' => 'لا يوجد طلب تغيير بريد. ابدأ من جديد.']);
    }
    // تنتهي الصلاحية بعد 30 دقيقة
    if ((microtime(true) - py_float($pend['created_at'])) > 1800) {
        $conn->execute('DELETE FROM pending_email_changes WHERE user_id=?', [$uid]);
        $conn->commit();
        return jsonify(['ok' => false, 'msg' => 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.']);
    }
    // حدّ المحاولات
    if (py_int(py_or($pend['attempts'], 0)) >= 5) {
        $conn->execute('DELETE FROM pending_email_changes WHERE user_id=?', [$uid]);
        $conn->commit();
        return jsonify(['ok' => false, 'msg' => 'تم تجاوز عدد المحاولات. اطلب رمزاً جديداً.']);
    }
    if ($code !== $pend['code']) {
        $conn->execute('UPDATE pending_email_changes SET attempts=attempts+1 WHERE user_id=?', [$uid]);
        $conn->commit();
        return jsonify(['ok' => false, 'msg' => 'الرمز غير صحيح']);
    }
    $new_email = mb_strtolower(py_strip(py_or($pend['new_email'], '')), 'UTF-8');
    // تحقق أخير من التفرّد قبل التطبيق
    $taken = $conn->execute('SELECT id FROM users WHERE email=? AND id!=?', [$new_email, $uid])->fetchone();
    if ($taken) {
        $conn->execute('DELETE FROM pending_email_changes WHERE user_id=?', [$uid]);
        $conn->commit();
        return jsonify(['ok' => false, 'msg' => 'البريد الإلكتروني أصبح مستخدماً من حساب آخر']);
    }
    $conn->execute('UPDATE users SET email=? WHERE id=?', [$new_email, $uid]);
    $conn->execute('DELETE FROM pending_email_changes WHERE user_id=?', [$uid]);
    $conn->commit();
    $_SESSION['user_email'] = $new_email;
    return jsonify(['ok' => true, 'msg' => 'تم تغيير البريد الإلكتروني بنجاح!', 'new_email' => $new_email]);
}, ['POST']);

/* '/api/cancel-email-change'  (app.py:2466) */
route('/api/cancel-email-change', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $conn = get_db();
    $conn->execute('DELETE FROM pending_email_changes WHERE user_id=?', [$_SESSION['user_id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم إلغاء طلب تغيير البريد']);
}, ['POST']);

/* '/api/resend-email-change'  (app.py:2476) */
route('/api/resend-email-change', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $conn = get_db();
    $pend = $conn->execute('SELECT * FROM pending_email_changes WHERE user_id=?', [$_SESSION['user_id']])->fetchone();
    if (!$pend) {
        return jsonify(['ok' => false, 'msg' => 'لا يوجد طلب تغيير بريد نشط']);
    }
    $new_email = $pend['new_email'];
    $code = _rand_code();
    $conn->execute('UPDATE pending_email_changes SET code=?, attempts=0, created_at=? WHERE user_id=?',
        [$code, microtime(true), $_SESSION['user_id']]);
    $conn->commit();
    $sent = send_email_change_email($new_email, $code);
    if (!$sent) {
        return jsonify(['ok' => false, 'msg' => 'تعذّر إرسال الرمز الآن. حاول لاحقاً.']);
    }
    return jsonify(['ok' => true, 'msg' => 'تم إرسال رمز جديد إلى بريدك الجديد']);
}, ['POST']);

/* '/api/change-password'  (app.py:2496) */
route('/api/change-password', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $data = req_data_strict();
    $old_pw = dget($data, 'old_password', '');
    $new_pw = dget($data, 'new_password', '');
    $conf_pw = dget($data, 'confirm_password', '');
    if (!py_truthy($old_pw) || !py_truthy($new_pw)) {
        return jsonify(['ok' => false, 'msg' => 'جميع الحقول مطلوبة']);
    }
    if ($new_pw !== $conf_pw) {
        return jsonify(['ok' => false, 'msg' => 'كلمة المرور الجديدة غير متطابقة']);
    }
    if (py_len($new_pw) < 6) {
        return jsonify(['ok' => false, 'msg' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل']);
    }
    $conn = get_db();
    $user = $conn->execute('SELECT password,google_id FROM users WHERE id=?', [$_SESSION['user_id']])->fetchone();
    if (!$user) {
        return jsonify(['ok' => false, 'msg' => 'مستخدم غير موجود']);
    }
    $thr = guess_check('chpw|' . $_SESSION['user_id']);
    if ($thr !== null) return $thr;
    if (py_truthy($user['password']) && !verify_pw($old_pw, $user['password'])) {
        guess_fail('chpw|' . $_SESSION['user_id']);
        return jsonify(['ok' => false, 'msg' => 'كلمة المرور الحالية غير صحيحة']);
    }
    guess_ok('chpw|' . $_SESSION['user_id']);
    $conn->execute('UPDATE users SET password=? WHERE id=?', [hash_pw($new_pw), $_SESSION['user_id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'msg' => 'تم تغيير كلمة المرور بنجاح!']);
}, ['POST']);

/* '/api/upload-avatar'  (app.py:2523) */
route('/api/upload-avatar', function () {
    if (!array_key_exists('user_id', $_SESSION)) return jsonify(['ok' => false, 'msg' => 'غير مصرح'], 401);
    $data = req_data_strict();
    $img_data = dget($data, 'image', '');
    if (!py_truthy($img_data)) {
        return jsonify(['ok' => false, 'msg' => 'لا توجد صورة']);
    }
    $img_data = (string)$img_data;
    if (strpos($img_data, ',') !== false) {
        $img_data = explode(',', $img_data, 2)[1];
    }
    try {
        $img_bytes = py_b64decode($img_data);
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => 'صورة غير صالحة']);
    }
    if (strlen($img_bytes) > 2 * 1024 * 1024) {
        return jsonify(['ok' => false, 'msg' => 'الصورة أكبر من 2MB']);
    }
    $fname = 'av_' . $_SESSION['user_id'] . '_' . time() . '.jpg';
    file_put_contents(AVATAR_DIR . '/' . $fname, $img_bytes);
    $avatar_url = '/api/avatars/' . $fname;
    $conn = get_db();
    $conn->execute('UPDATE users SET avatar=? WHERE id=?', [$avatar_url, $_SESSION['user_id']]);
    $conn->commit();
    return jsonify(['ok' => true, 'url' => $avatar_url]);
}, ['POST']);

/* '/api/avatars/<fname>'  (app.py:2551) */
route('/api/avatars/<fname>', function ($fname) {
    $fpath = AVATAR_DIR . '/' . $fname;
    if ($fname === '.' || $fname === '..' || !is_file($fpath)) return make_response('', 404);
    return send_file($fpath, 'image/jpeg', false, null, 86400);
});
