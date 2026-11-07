<?php
/**
 * المصادقة الثنائية (TOTP — RFC 6238) + جلسات الأجهزة + ترقية المخطط للقواعد القديمة.
 *
 * • TOTP: يعمل مع Google Authenticator / Authy / Microsoft Authenticator (SHA-1، 6 أرقام، 30 ثانية).
 * • جلسات الأجهزة: كل تسجيل دخول يُسجَّل بصف في user_sessions ويُخزَّن معرّفه _sid داخل الكوكي الموقّع؛
 *   إنهاء جلسة = وضع revoked=1 فتُرفض في الطلب التالي (الكوكي نفسه يبقى stateless كما بفلاسك).
 */

/* ------------------------------ ترقية المخطط للقواعد القديمة ------------------------------ */

/** أعمدة أضيفت بعد النسخة الأولى: تُضاف تلقائياً مرة واحدة (علم schema_v4.done) إن كانت القاعدة مستوردة من ملف أقدم */
function schema_upgrade_columns(): array {
    return [
        'users' => ['totp_secret' => 'VARCHAR(255) NULL', 'totp_enabled' => 'INT NOT NULL DEFAULT 0', 'totp_recovery' => 'LONGTEXT NULL'],
        'custom_orders' => ['ticket_id' => 'INT NULL'],
        'reviews' => ['likes' => 'INT NOT NULL DEFAULT 0', 'liked_by' => 'LONGTEXT NULL'],
    ];
}

function ensure_schema_upgrade(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = DATA_DIR . '/cache/schema_v4.done';
    if (is_file($flag)) return;
    try {
        $pdo = get_db()->pdo;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT, `user_id` INT NOT NULL, `sid` VARCHAR(64) NOT NULL, `ip` VARCHAR(64) DEFAULT '', `ua` VARCHAR(255) DEFAULT '',
  `device` VARCHAR(120) DEFAULT '', `created_at` DOUBLE DEFAULT 0, `last_seen` DOUBLE DEFAULT 0, `revoked` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_us_sid` (`sid`), KEY `idx_us_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
        foreach (schema_upgrade_columns() as $t => $cols) {
            $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute([$t]);
            $have = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$have) return;                         // الجداول لم تُستورد بعد (تثبيت جديد): لا شيء الآن
            foreach ($cols as $c => $def) if (!in_array($c, $have, true)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
        }
        @file_put_contents($flag, date('c'));
    } catch (Throwable $e) {
        log_app_error('ERROR', 'schema upgrade: ' . $e->getMessage());
    }
}

/* ------------------------------ TOTP ------------------------------ */

const B32_ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string {
    $bits = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= B32_ALPHA[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function b32_decode(string $s): string {
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $ch) { $p = strpos(B32_ALPHA, $ch); if ($p !== false) $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}
function totp_new_secret(): string { return b32_encode(random_bytes(20)); }
/** السرّ يُخزَّن مشفَّراً (AES-256-GCM بمفتاح مشتق من SECRET_KEY): تسرّب قاعدة البيانات/النسخ الاحتياطية وحده لا يكفي لتوليد الرموز */
function totp_secret_key(): string { return hash('sha256', 'totp-secret|' . SECRET_KEY, true); }
function totp_secret_enc(string $s): string {
    $iv = random_bytes(12);
    $ct = openssl_encrypt($s, 'aes-256-gcm', totp_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function totp_secret_dec(string $s): string {
    if (strncmp($s, 'v1:', 3) !== 0) return $s;                 // قيمة قديمة غير مشفّرة
    $raw = base64_decode(substr($s, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', totp_secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? '' : $pt;                            // SECRET_KEY تغيّر: لا يمكن فك السرّ (استعمل tools/disable_2fa.php)
}
function totp_secret_get(Row $u): string { return totp_secret_dec((string)($u['totp_secret'] ?? '')); }
function totp_code(string $secret, ?int $time = null, int $step = 30): string {
    $c = intdiv($time ?? time(), $step);
    $h = hash_hmac('sha1', pack('J', $c), b32_decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($v % 1000000), 6, '0', STR_PAD_LEFT);
}
/** يقبل الرمز الحالي والسابق والتالي (فرق الساعة)، ولا يقبل الرمز نفسه مرتين (منع إعادة الاستعمال) */
function totp_verify(string $secret, string $code, int $uid): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code) || $secret === '') return false;
    $now = time();
    for ($i = -1; $i <= 1; $i++) {
        $t = $now + $i * 30;
        if (hash_equals(totp_code($secret, $t), $code)) {
            [$fresh] = rate_limit_hit('totp_used|' . $uid . '|' . intdiv($t, 30), 1, 120);
            return $fresh;
        }
    }
    return false;
}
function totp_uri(string $secret, string $account): string {
    $issuer = (string)get_setting('site_name', 'fastcrand');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}
function recovery_hash(string $code): string { return hash_hmac('sha256', strtolower(preg_replace('/[^a-z0-9]/i', '', $code)), SECRET_KEY); }
/** 8 رموز احتياطية: يرجع [الرموز الصريحة (تُعرض مرة واحدة), JSON للتخزين (هاشات)] */
function recovery_generate(): array {
    $plain = []; $hashes = [];
    for ($i = 0; $i < 8; $i++) { $c = substr(bin2hex(random_bytes(4)), 0, 4) . '-' . substr(bin2hex(random_bytes(4)), 0, 4); $plain[] = $c; $hashes[] = recovery_hash($c); }
    return [$plain, json_encode($hashes)];
}
/** يتحقق من رمز التطبيق أو رمز احتياطي (يُستهلك). يرجع true عند النجاح */
function twofa_check_code(Row $user, string $code): bool {
    $code = trim($code);
    if ($code === '') return false;
    if (totp_verify(totp_secret_get($user), $code, (int)$user['id'])) return true;
    $list = json_decode((string)($user['totp_recovery'] ?? '[]'), true);
    if (!is_array($list)) return false;
    $h = recovery_hash($code);
    foreach ($list as $i => $x) {
        if (hash_equals((string)$x, $h)) {
            unset($list[$i]);
            $conn = get_db();
            $conn->execute('UPDATE users SET totp_recovery=? WHERE id=?', [json_encode(array_values($list)), $user['id']]);
            $conn->commit();
            return true;
        }
    }
    return false;
}
function user_has_2fa(Row $user): bool { return $user->has('totp_enabled') && (int)$user['totp_enabled'] === 1 && (string)$user['totp_secret'] !== ''; }

/* ------------------------------ تسجيل الدخول بخطوتين ------------------------------ */

const TWOFA_PENDING_TTL = 300;

/** كلمة المرور صحيحة لكن الحساب محمي بـ 2FA: لا جلسة بعد — ننتظر الرمز (محفوظ داخل الكوكي الموقّع) */
function twofa_begin(Row $user, bool $withEmail = true, ?bool $isAdmin = null): Response {
    $_SESSION = ['_2fa_uid' => (int)$user['id'], '_2fa_ts' => time(), '_2fa_email' => $withEmail, '_2fa_admin' => $isAdmin];
    return jsonify(['ok' => false, 'need_2fa' => true, 'msg' => 'أدخل رمز تطبيق المصادقة (6 أرقام) أو رمزاً احتياطياً']);
}

/** يكمل الدخول بعد التحقق من الرمز */
function twofa_complete(string $code): Response {
    $uid = (int)($_SESSION['_2fa_uid'] ?? 0);
    if ($uid <= 0 || time() - (int)($_SESSION['_2fa_ts'] ?? 0) > TWOFA_PENDING_TTL) {
        unset($_SESSION['_2fa_uid'], $_SESSION['_2fa_ts'], $_SESSION['_2fa_email'], $_SESSION['_2fa_admin']);
        return jsonify(['ok' => false, 'msg' => 'انتهت مهلة التحقق، سجّل الدخول من جديد', 'restart' => true], 401);
    }
    $thr = guess_check('2fa|' . $uid);
    if ($thr !== null) return $thr;
    $conn = get_db();
    $user = $conn->execute('SELECT * FROM users WHERE id=?', [$uid])->fetchone();
    if (!$user || !user_has_2fa($user)) return jsonify(['ok' => false, 'msg' => 'تعذّر التحقق', 'restart' => true], 401);
    if (!twofa_check_code($user, $code)) {
        if (guess_fail('2fa|' . $uid)) { $_SESSION = []; return jsonify(['ok' => false, 'msg' => 'محاولات كثيرة، سجّل الدخول من جديد', 'restart' => true], 429); }
        return jsonify(['ok' => false, 'msg' => 'الرمز غير صحيح'], 401);
    }
    guess_ok('2fa|' . $uid);
    $withEmail = (bool)($_SESSION['_2fa_email'] ?? true); $isAdmin = $_SESSION['_2fa_admin'] ?? null;
    $_SESSION = [];
    _session_login($user, $withEmail, $isAdmin === null ? null : (bool)$isAdmin);
    return jsonify(['ok' => true, 'msg' => 'تم تسجيل الدخول!', 'name' => $user['name']]);
}

/* ------------------------------ جلسات الأجهزة ------------------------------ */

/** وصف مختصر للجهاز من User-Agent: «iPhone · Safari» */
function ua_device(string $ua): string {
    $os = 'جهاز';
    if (preg_match('/iPhone/i', $ua)) $os = 'iPhone';
    elseif (preg_match('/iPad/i', $ua)) $os = 'iPad';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) $os = 'Mac';
    elseif (preg_match('/CrOS/i', $ua)) $os = 'ChromeOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    $br = 'متصفح';
    if (preg_match('/Edg(e|A|iOS)?\//i', $ua)) $br = 'Edge';
    elseif (preg_match('/OPR\/|Opera/i', $ua)) $br = 'Opera';
    elseif (preg_match('/SamsungBrowser/i', $ua)) $br = 'Samsung Internet';
    elseif (preg_match('/Firefox|FxiOS/i', $ua)) $br = 'Firefox';
    elseif (preg_match('/Chrome|CriOS/i', $ua)) $br = 'Chrome';
    elseif (preg_match('/Safari/i', $ua)) $br = 'Safari';
    return $os . ' · ' . $br;
}
function ua_kind(string $ua): string { return preg_match('/iPhone|iPad|Android|Mobile/i', $ua) ? 'phone' : 'desktop'; }

function usess_register(int $uid): void {
    try {
        $conn = get_db();
        $ua = mb_substr((string)req_header('User-Agent'), 0, 250, 'UTF-8'); $ip = client_ip(); $now = microtime(true);
        // طلبان متزامنان لنفس الكوكي القديم: أعد استعمال صف حديث بنفس الجهاز بدل تكرار الجلسة
        $dup = $conn->execute('SELECT sid FROM user_sessions WHERE user_id=? AND ip=? AND ua=? AND revoked=0 AND created_at>? ORDER BY id DESC LIMIT 1', [$uid, $ip, $ua, $now - 20])->fetchone();
        if ($dup) { $_SESSION['_sid'] = (string)$dup['sid']; return; }
        $sid = bin2hex(random_bytes(16));
        $conn->execute('INSERT INTO user_sessions(user_id,sid,ip,ua,device,created_at,last_seen) VALUES(?,?,?,?,?,?,?)', [$uid, $sid, $ip, $ua, ua_device($ua), $now, $now]);
        // تنظيف: المنتهية/الملغاة القديمة، والإبقاء على آخر 30 جلسة لكل مستخدم
        $conn->execute('DELETE FROM user_sessions WHERE user_id=? AND (last_seen<? OR (revoked=1 AND last_seen<?))', [$uid, $now - 90 * 86400, $now - 7 * 86400]);
        $conn->commit();
        $_SESSION['_sid'] = $sid;
    } catch (Throwable $e) { /* الجدول غير موجود بعد (قاعدة قديمة): لا نمنع الدخول */ }
}

/** يُستدعى لكل طلب من مستخدم مسجّل: جلسة ملغاة ⇒ خروج فوري. يرجع Response أو null */
function usess_enforce(string $path): ?Response {
    if (!array_key_exists('user_id', $_SESSION)) return null;
    try {
        $sid = (string)($_SESSION['_sid'] ?? '');
        if ($sid === '') { usess_register((int)$_SESSION['user_id']); return null; }   // كوكي قديم: سجّله الآن
        $conn = get_db();
        $row = $conn->execute('SELECT revoked,last_seen,user_id FROM user_sessions WHERE sid=?', [$sid])->fetchone();
        if (!$row) { usess_register((int)$_SESSION['user_id']); return null; }          // نُظّف الصف لخمول طويل: أعد تسجيله
        if ((int)$row['revoked'] === 1 || (int)$row['user_id'] !== (int)$_SESSION['user_id']) {
            $_SESSION = [];
            if (strncmp($path, '/api/', 5) === 0) return jsonify(['ok' => false, 'msg' => 'انتهت هذه الجلسة، سجّل الدخول من جديد', 'logout' => true], 401);
            return redirect('/');
        }
        if (microtime(true) - (float)$row['last_seen'] > 60) {
            $conn->execute('UPDATE user_sessions SET last_seen=?, ip=? WHERE sid=?', [microtime(true), client_ip(), $sid]);
            $conn->commit();
        }
    } catch (Throwable $e) {}
    return null;
}

function usess_list(int $uid, string $current): array {
    $conn = get_db();
    $rows = $conn->execute('SELECT * FROM user_sessions WHERE user_id=? AND revoked=0 AND last_seen>? ORDER BY last_seen DESC, id DESC', [$uid, microtime(true) - 30 * 86400])->fetchall();
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'device' => (string)$r['device'], 'kind' => ua_kind((string)$r['ua']), 'ip' => (string)$r['ip'],
                  'created_at' => (float)$r['created_at'], 'last_seen' => (float)$r['last_seen'], 'current' => hash_equals($current, (string)$r['sid'])];
    }
    return $out;
}
