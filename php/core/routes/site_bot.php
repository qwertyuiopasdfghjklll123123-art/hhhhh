<?php
/**
 * بوت تليجرام الموقع: بوت تفاعلي مستقل (منفصل عن بوت نجوم تليجرام الخاص بالدفع) يرفع الأدمن توكنه من
 * لوحة الإدارة فيتفعّل فوراً (يُسجَّل Webhook تلقائياً). عند /start يرد بثلاثة أزرار: زيارة الموقع،
 * تسجيل دخول مباشر بحساب تليجرام (ينشئ حساباً تلقائياً إن كانت أول مرة)، والدعم عبر واتساب.
 */

const SITE_BOT_LOGIN_TTL = 86400; // صلاحية رابط الدخول المباشر: يوم كامل من وقت إرساله بالبوت (كان 10 دقائق فقط — قصير جداً عملياً، يجعل الرابط ينتهي قبل أن يضغطه المستخدم غالباً)

/** صفحة خطأ صغيرة مخصّصة (بدل العودة للموقع الكامل) تظهر داخل متصفح تليجرام المدمج عند فشل رابط الدخول لأي سبب */
function _site_bot_login_error_page(string $title, string $msg): Response {
    $bot_username = get_setting('site_bot_username', '');
    $btn = py_truthy($bot_username)
        ? '<a href="https://t.me/' . htmlspecialchars($bot_username, ENT_QUOTES) . '" style="display:inline-block;margin-top:18px;padding:12px 28px;background:#229ed9;color:#fff;border-radius:12px;font-weight:700;text-decoration:none">العودة للبوت</a>'
        : '';
    $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f1b35;color:#fff;font-family:"IBM Plex Sans Arabic",Tahoma,sans-serif;text-align:center;padding:24px;box-sizing:border-box}'
        . '.box{max-width:360px}h1{font-size:18px;margin:0 0 10px}p{font-size:13px;line-height:1.8;color:#b9c3dd;margin:0}</style></head>'
        . '<body><div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1><p>' . htmlspecialchars($msg, ENT_QUOTES) . '</p>' . $btn . '</div></body></html>';
    return make_response($html, 200);
}

/** يوقّع [tid, fn, un, exp] بـ HMAC مبني على SECRET_KEY — بلا حاجة لتخزين توكنات الدخول بقاعدة البيانات */
function site_bot_sign_login(int $tid, string $first_name, string $username): string {
    $payload = json_encode(['tid' => $tid, 'fn' => $first_name, 'un' => $username, 'exp' => time() + SITE_BOT_LOGIN_TTL], JSON_UNESCAPED_UNICODE);
    $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64, SECRET_KEY, true)), '+/', '-_'), '=');
    return $b64 . '.' . $sig;
}

/** @return array|null الحمولة إن كان التوقيع صحيحاً وغير منتهي الصلاحية، وإلا null */
function site_bot_verify_login(string $token): ?array {
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) return null;
    [$b64, $sig] = $parts;
    $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $b64, SECRET_KEY, true)), '+/', '-_'), '=');
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64_decode(strtr($b64, '-_', '+/')), true);
    if (!is_array($payload) || !isset($payload['tid'], $payload['exp'])) return null;
    if (time() > (int)$payload['exp']) return null;
    return $payload;
}

/* '/api/admin/site-bot/status' */
route('/api/admin/site-bot/status', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    return jsonify(['ok' => true, 'active' => py_truthy(get_setting('site_bot_token', '')), 'bot_username' => get_setting('site_bot_username', '')]);
});

/* '/api/site-bot-info': عامة بلا جلسة — فقط اسم مستخدم البوت (غير حساس، يظهر لأي أحد يراسل البوت أصلاً) لبناء زر "تسجيل الدخول عبر تليجرام" بصفحة الدخول */
route('/api/site-bot-info', function () {
    $active = py_truthy(get_setting('site_bot_token', ''));
    return jsonify(['ok' => true, 'active' => $active, 'bot_username' => $active ? get_setting('site_bot_username', '') : '']);
});

/* '/api/admin/site-bot/save': يحفظ التوكن بعد التحقق منه عبر getMe، ثم يسجّل الـ Webhook فوراً — تفعيل بخطوة واحدة */
route('/api/admin/site-bot/save', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $data = req_data();
    $token = py_strip(py_or(dget($data, 'token'), ''));
    if (!py_truthy($token)) return jsonify(['ok' => false, 'msg' => 'أدخل توكن البوت أولاً']);
    try {
        $r = requests_get("https://api.telegram.org/bot$token/getMe", ['timeout' => 10]);
        $rj = $r->json();
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => 'تعذر الاتصال بتليجرام: ' . py_slice($e->getMessage(), null, 80)]);
    }
    if (!py_truthy(dget($rj, 'ok'))) {
        return jsonify(['ok' => false, 'msg' => 'توكن غير صالح: ' . py_str(dget($rj, 'description', 'تأكد من نسخه كاملاً من BotFather'))]);
    }
    $bot_username = py_str(dget(dget($rj, 'result', []), 'username', ''));
    set_setting('site_bot_token', $token);
    set_setting('site_bot_username', $bot_username);
    // النص الظاهر فوق زر "ابدأ" الأساسي بتليجرام (الزر نفسه ثابت من تليجرام ولا يمكن لأي بوت تغيير تسميته)
    $site_name = get_setting('site_name', 'المتجر');
    try {
        requests_post("https://api.telegram.org/bot$token/setMyShortDescription",
            ['json' => ['short_description' => "اضغط ابدأ لتسجيل الدخول لموقع $site_name أو إنشاء حساب جديد فوراً"], 'timeout' => 10]);
    } catch (Throwable $e) {}
    $webhook_url = 'https://' . req_host() . '/api/telegram/site-bot-webhook';
    // سر عشوائي يرسله تليجرام مع كل تحديث بترويسة X-Telegram-Bot-Api-Secret-Token — يثبت أن الطلب قادم من تليجرام فعلاً وليس مزوَّراً مباشرة لهذا الرابط العام
    $wh_secret = bin2hex(random_bytes(24));
    set_setting('site_bot_webhook_secret', $wh_secret);
    try {
        $wr = requests_post("https://api.telegram.org/bot$token/setWebhook",
            ['json' => ['url' => $webhook_url, 'secret_token' => $wh_secret, 'allowed_updates' => ['message']], 'timeout' => 15]);
        $wrj = $wr->json();
    } catch (Throwable $e) {
        return jsonify(['ok' => false, 'msg' => 'تم حفظ التوكن لكن تعذر تفعيل الاستقبال: ' . py_slice($e->getMessage(), null, 80)]);
    }
    if (!py_truthy(dget($wrj, 'ok'))) {
        return jsonify(['ok' => false, 'msg' => 'تم حفظ التوكن لكن فشل تفعيل الاستقبال: ' . py_str(dget($wrj, 'description', ''))]);
    }
    return jsonify(['ok' => true, 'msg' => 'تم تفعيل البوت بنجاح: @' . $bot_username, 'bot_username' => $bot_username]);
}, ['POST']);

/* '/api/admin/site-bot/remove' */
route('/api/admin/site-bot/remove', function () {
    if (!_is_admin_session()) return jsonify(['ok' => false], 403);
    $token = get_setting('site_bot_token', '');
    if (py_truthy($token)) {
        try { requests_post("https://api.telegram.org/bot$token/deleteWebhook", ['timeout' => 10]); } catch (Throwable $e) {}
    }
    set_setting('site_bot_token', '');
    set_setting('site_bot_username', '');
    return jsonify(['ok' => true, 'msg' => 'تم إيقاف البوت']);
}, ['POST']);

/* '/api/telegram/site-bot-webhook': يستقبل تحديثات تليجرام (بلا جلسة) ويرد عند /start بالأزرار الثلاثة */
route('/api/telegram/site-bot-webhook', function () {
    $token = get_setting('site_bot_token', '');
    if (!py_truthy($token)) return jsonify(['ok' => true]);
    $expected_secret = get_setting('site_bot_webhook_secret', '');
    if (py_truthy($expected_secret) && !hash_equals((string)$expected_secret, req_header('X-Telegram-Bot-Api-Secret-Token'))) {
        return jsonify(['ok' => true]);
    }
    $update = req_data();
    $msg = dget($update, 'message', null);
    if (!is_array($msg)) return jsonify(['ok' => true]);
    $chat = dget($msg, 'chat', []);
    $chat_id = dget($chat, 'id', null);
    $from = dget($msg, 'from', []);
    if (!$chat_id || !is_array($from)) return jsonify(['ok' => true]);
    $tid = py_int(py_or(dget($from, 'id'), 0));
    if ($tid <= 0) return jsonify(['ok' => true]);
    $fn = py_str(dget($from, 'first_name', ''));
    $un = py_str(dget($from, 'username', ''));

    $site_name = get_setting('site_name', 'المتجر');
    $site_url = 'https://' . req_host();
    $login_token = site_bot_sign_login($tid, $fn, $un);
    $login_url = $site_url . '/api/telegram/login?token=' . urlencode($login_token);

    // web_app (بدل url عادي): يفتح الرابط داخل متصفح تليجرام المدمج دائماً (بكل المنصات)، وليس متصفحاً خارجياً —
    // مدعوم فقط بالمحادثات الخاصة بين المستخدم والبوت (وهذا بالضبط سياقنا هنا)
    $rows = [[['text' => '🌐 الدخول للموقع', 'web_app' => ['url' => $login_url]]]];
    $wa = preg_replace('/[^0-9]/', '', (string)get_setting('support_whatsapp', ''));
    if (py_truthy($wa)) $rows[] = [['text' => '💬 الدعم', 'url' => 'https://wa.me/' . $wa]];

    try {
        requests_post("https://api.telegram.org/bot$token/sendMessage", [
            'json' => [
                'chat_id' => $chat_id,
                'text' => "مرحباً بك في $site_name! 👋\nاستخدم الأزرار بالأسفل للتنقل أو لتسجيل الدخول مباشرة بحسابك.",
                'reply_markup' => ['inline_keyboard' => $rows],
            ],
            'timeout' => 10,
        ]);
    } catch (Throwable $e) {}
    return jsonify(['ok' => true]);
}, ['POST']);

/* '/api/telegram/login': رابط الدخول المباشر من البوت — يسجّل الدخول أو ينشئ حساباً تلقائياً لأول مرة */
route('/api/telegram/login', function () {
    $token = req_args('token', '');
    $payload = py_truthy($token) ? site_bot_verify_login((string)$token) : null;
    if (!$payload) return _site_bot_login_error_page('انتهت صلاحية الرابط', 'رابط الدخول هذا لم يعد صالحاً. ارجع إلى محادثة البوت واضغط /start من جديد للحصول على رابط دخول جديد.');
    $tid = (int)$payload['tid'];
    if ($tid <= 0) return _site_bot_login_error_page('رابط غير صالح', 'تعذّر التحقق من هذا الرابط. ارجع إلى محادثة البوت واضغط /start من جديد.');

    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE telegram_chat_id=?', [(string)$tid])->fetchone();
    if ($user) {
        if (user_has_2fa($user)) {
            return _site_bot_login_error_page('التحقق بخطوتين مفعّل', 'هذا الحساب محمي بالتحقق بخطوتين، فلا يمكن الدخول المباشر عبر تليجرام. افتح الموقع من متصفحك وسجّل الدخول بكلمة المرور ثم أدخل رمز التحقق.');
        }
        _session_login($user);
        return redirect('/app?tg=1');
    }

    $fn = py_str(py_or(dget($payload, 'fn'), ''));
    $un = py_str(py_or(dget($payload, 'un'), ''));
    $name = py_truthy($fn) ? $fn : (py_truthy($un) ? $un : 'مستخدم تليجرام');
    $email = 'tg' . $tid . '@telegram.local';
    $pw_hash = hash_pw(bin2hex(random_bytes(16)));
    $_pid = _next_public_id();
    $conn->execute('INSERT INTO users(name,email,password,verified,telegram_chat_id,public_id) VALUES(?,?,?,1,?,?)',
        [$name, $email, $pw_hash, (string)$tid, $_pid]);
    $conn->commit();
    $user = $conn->execute('SELECT * FROM users WHERE email=?', [$email])->fetchone();
    _session_login($user, true, false);
    return redirect('/app?tg=1');
});
