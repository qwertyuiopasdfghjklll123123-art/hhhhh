<?php
/**
 * البريد: عميل SMTP أصلي (SSL/TLS/STARTTLS + AUTH CRAM-MD5/PLAIN/LOGIN) يحاكي smtplib،
 * وقوالب الرسائل الثلاثة منقولة حرفياً من app.py:1459-1600، و is_valid_email (1538-1556).
 * تعطيل التحقق من الشهادة مقصود: smtplib.SMTP_SSL / starttls ببايثون يستعملان _create_stdlib_context (غير موثَّق).
 */

final class SmtpClient {
    /** @var resource */
    private $fp;
    public array $features = [];

    public function __construct(string $host, int $port, string $mode, float $timeout = 10.0) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $remote = ($mode === 'SSL' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException($errstr !== '' ? "[Errno $errno] $errstr" : "Connection failed to $host:$port");
        $this->fp = $fp;
        stream_set_timeout($this->fp, (int)ceil($timeout));
        [$code, $msg] = $this->read();
        if ($code !== 220) throw new RuntimeException("Server not ready ($code $msg)");
    }

    /** @return array [int code, string message] */
    private function read(): array {
        $code = 0; $msgs = [];
        while (true) {
            $line = fgets($this->fp, 8192);
            if ($line === false) {
                $meta = stream_get_meta_data($this->fp);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'timed out' : 'Connection unexpectedly closed');
            }
            $code = (int)substr($line, 0, 3);
            $msgs[] = rtrim(substr($line, 4));
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return [$code, implode("\n", $msgs)];
    }

    private function cmd(string $line, array $okCodes): array {
        fwrite($this->fp, $line . "\r\n");
        [$code, $msg] = $this->read();
        if (!in_array($code, $okCodes, true)) throw new RuntimeException("($code, $msg)");
        return [$code, $msg];
    }

    public function ehlo(): void {
        $host = gethostname() ?: 'localhost';
        [, $msg] = $this->cmd('EHLO ' . $host, [250]);
        $this->features = [];
        foreach (explode("\n", $msg) as $i => $l) {
            if ($i === 0) continue;
            $parts = preg_split('/\s+/', trim($l), 2);
            $this->features[strtolower($parts[0])] = $parts[1] ?? '';
        }
    }

    public function starttls(): void {
        if (!isset($this->features['starttls'])) throw new RuntimeException('STARTTLS extension not supported by server.');
        $this->cmd('STARTTLS', [220]);
        if (!@stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS negotiation failed');
        $this->features = [];
    }

    public function login(string $user, string $pass): void {
        if (!isset($this->features['auth'])) throw new RuntimeException('SMTP AUTH extension not supported by server.');
        $adv = preg_split('/\s+/', strtoupper($this->features['auth']));
        foreach (['CRAM-MD5', 'PLAIN', 'LOGIN'] as $m) {
            if (!in_array($m, $adv, true)) continue;
            if ($m === 'CRAM-MD5') {
                [, $ch] = $this->cmd('AUTH CRAM-MD5', [334]);
                $digest = hash_hmac('md5', (string)base64_decode($ch), $pass);
                $this->cmd(base64_encode($user . ' ' . $digest), [235]);
            } elseif ($m === 'PLAIN') {
                $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
            } else {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($user), [334]);
                $this->cmd(base64_encode($pass), [235]);
            }
            return;
        }
        throw new RuntimeException('No suitable authentication method found.');
    }

    public function send(string $from, string $to, string $data): void {
        $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd('DATA', [354]);
        // dot-stuffing + CRLF
        $data = preg_replace('/\r\n|\r|\n/', "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($this->fp, $data . "\r\n.\r\n");
        [$code, $msg] = $this->read();
        if ($code !== 250) throw new RuntimeException("($code, $msg)");
    }

    public function quit(): void {
        try { fwrite($this->fp, "QUIT\r\n"); $this->read(); } catch (Throwable $e) {}
        @fclose($this->fp);
    }
}

/** ترميز ترويسة RFC 2047 عند وجود محارف غير ASCII */
function mime_encode_header(string $v): string {
    if (!preg_match('/[^\x20-\x7e]/', $v)) return $v;
    return mb_encode_mimeheader($v, 'UTF-8', 'B', "\r\n", 0);
}

/**
 * يبني رسالة multipart/alternative فيها جزء HTML واحد (مثل MIMEMultipart('alternative') + MIMEText(html,'html')).
 */
function build_html_mail(string $fromName, string $fromEmail, string $to, string $subject, string $html): string {
    $boundary = '===============' . bin2hex(random_bytes(10)) . '==';
    $fromHdr = (preg_match('/[^\x20-\x7e]/', $fromName) ? mime_encode_header($fromName) : $fromName) . ' <' . $fromEmail . '>';
    $host = gethostname() ?: 'localhost';
    $h = [
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'MIME-Version: 1.0',
        'From: ' . $fromHdr,
        'To: ' . $to,
        'Subject: ' . mime_encode_header($subject),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
    ];
    $body = "--$boundary\r\nContent-Type: text/html; charset=\"utf-8\"\r\nMIME-Version: 1.0\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html), 76, "\r\n")
          . "--$boundary--\r\n";
    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

/** يرسل بريداً عبر SMTP؛ يرمي استثناء عند الفشل (مثل smtplib) */
function smtp_send(string $host, int $port, string $tls, string $login, string $pass, string $fromName, string $to, string $subject, string $html, ?string $fromEmail = null): void {
    $c = new SmtpClient($host, $port, $tls === 'SSL' ? 'SSL' : 'PLAIN', 10.0);
    try {
        $c->ehlo();
        if ($tls === 'TLS') { $c->starttls(); $c->ehlo(); }
        $c->login($login, $pass);
        $c->send($fromEmail ?? $login, $to, build_html_mail($fromName, $fromEmail ?? $login, $to, $subject, $html));
        $c->quit();
    } catch (Throwable $e) {
        $c->quit();
        throw $e;
    }
}

/** إعدادات SMTP الفعلية (القيمة المحفوظة أو الافتراضية كما بـ `get_setting(..) or DEFAULT`) */
function smtp_effective_settings(): array {
    return [
        'host' => py_or(get_setting('smtp_host', SMTP_HOST), SMTP_HOST),
        'port' => py_int(py_or(get_setting('smtp_port', SMTP_PORT), SMTP_PORT)),
        'tls' => py_or(get_setting('smtp_tls', 'SSL'), 'SSL'),
        'email' => py_or(get_setting('smtp_email', SMTP_USER), SMTP_USER),
        'pass' => py_or(get_setting('smtp_password', SMTP_PASS), SMTP_PASS),
        'sender' => py_or(get_setting('smtp_sender_name', 'fastcrand'), 'fastcrand'),
        'sname' => py_or(get_setting('site_name', 'fastcrand'), 'fastcrand'),
    ];
}

function _mail_log(string $line): void {
    @file_put_contents(DATA_DIR . '/logs/mail.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** قوالب الرسائل: {_sname} و {token}/{code} كما بـ f-string الأصلي (بلا تهريب HTML) */
const MAIL_TPL_VERIFY = <<<'HTML'
<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"></head><body style="margin:0;padding:40px 20px;background:#f0f1f5;font-family:IBM Plex Sans Arabic,Tajawal,Arial,sans-serif">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.08)">
<div style="background:linear-gradient(135deg,#2563eb,#3b82f6);padding:32px 24px;text-align:center">
<h1 style="color:#fff;margin:0;font-size:26px;font-family:IBM Plex Sans Arabic,sans-serif;font-weight:900">__SNAME__</h1>
<p style="color:rgba(255,255,255,.6);font-size:11px;margin:6px 0 0">أسرع لوحة SMM</p></div>
<div style="padding:36px 28px;text-align:center">
<h2 style="margin:0 0 10px;font-size:20px;color:#1a1a2e;font-weight:900">رمز التحقق الخاص بك</h2>
<p style="color:#777;font-size:13px;line-height:1.8;margin:0 0 24px">أدخل هذا الرمز في صفحة التسجيل لتأكيد حسابك:</p>
<div style="display:inline-block;padding:18px 40px;background:#f0f1f5;border-radius:14px;font-family:IBM Plex Sans Arabic,monospace;font-size:36px;font-weight:900;letter-spacing:12px;color:#2563eb">__CODE__</div>
<p style="color:#bbb;font-size:11px;margin-top:28px;line-height:1.7">الرمز صالح لمدة 24 ساعة.<br>إذا لم تقم بإنشاء حساب، تجاهل هذه الرسالة.</p>
</div>
<div style="background:#f8f9fb;padding:18px;text-align:center;font-size:10px;color:#aaa;border-top:1px solid #eef0f3">__SNAME__ &copy; 2026</div>
</div></body></html>
HTML;

const MAIL_TPL_RESET = <<<'HTML'
<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"></head><body style="margin:0;padding:40px 20px;background:#f0f1f5;font-family:IBM Plex Sans Arabic,Tajawal,Arial,sans-serif">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.08)">
<div style="background:linear-gradient(135deg,#2563eb,#3b82f6);padding:32px 24px;text-align:center">
<h1 style="color:#fff;margin:0;font-size:26px;font-family:IBM Plex Sans Arabic,sans-serif;font-weight:900">__SNAME__</h1></div>
<div style="padding:36px 28px;text-align:center">
<h2 style="margin:0 0 10px;font-size:20px;color:#1a1a2e;font-weight:900">استعادة كلمة المرور</h2>
<p style="color:#777;font-size:13px;margin:0 0 24px">أدخل هذا الرمز لإعادة تعيين كلمة المرور:</p>
<div style="display:inline-block;padding:18px 40px;background:#f0f1f5;border-radius:14px;font-family:IBM Plex Sans Arabic,monospace;font-size:36px;font-weight:900;letter-spacing:12px;color:#2563eb">__CODE__</div>
<p style="color:#bbb;font-size:11px;margin-top:28px">إذا لم تطلب إعادة تعيين، تجاهل هذه الرسالة.</p>
</div></div></body></html>
HTML;

const MAIL_TPL_EMAIL_CHANGE = <<<'HTML'
<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"></head><body style="margin:0;padding:40px 20px;background:#f0f1f5;font-family:IBM Plex Sans Arabic,Tajawal,Arial,sans-serif">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.08)">
<div style="background:linear-gradient(135deg,#2563eb,#3b82f6);padding:32px 24px;text-align:center">
<h1 style="color:#fff;margin:0;font-size:26px;font-family:IBM Plex Sans Arabic,sans-serif;font-weight:900">__SNAME__</h1>
<p style="color:rgba(255,255,255,.6);font-size:11px;margin:6px 0 0">أسرع لوحة SMM</p></div>
<div style="padding:36px 28px;text-align:center">
<h2 style="margin:0 0 10px;font-size:20px;color:#1a1a2e;font-weight:900">رمز التحقق الخاص بك</h2>
<p style="color:#777;font-size:13px;line-height:1.8;margin:0 0 24px">أدخل هذا الرمز في صفحة الإعدادات لتأكيد العملية:</p>
<div style="display:inline-block;padding:18px 40px;background:#f0f1f5;border-radius:14px;font-family:IBM Plex Sans Arabic,monospace;font-size:36px;font-weight:900;letter-spacing:12px;color:#2563eb">__CODE__</div>
<p style="color:#bbb;font-size:11px;margin-top:28px;line-height:1.7">الرمز صالح لمدة 30 دقيقة.<br>إذا لم تطلب هذا الرمز، تجاهل هذه الرسالة.</p>
</div>
<div style="background:#f8f9fb;padding:18px;text-align:center;font-size:10px;color:#aaa;border-top:1px solid #eef0f3">__SNAME__ &copy; 2026</div>
</div></body></html>
HTML;

function _send_code_mail(string $tplConst, string $subjectPrefix, string $to_email, $code, string $logTag = ''): bool {
    $c = smtp_effective_settings();
    $html = strtr($tplConst, ['__SNAME__' => (string)$c['sname'], '__CODE__' => (string)$code]);
    try {
        smtp_send($c['host'], $c['port'], $c['tls'], $c['email'], $c['pass'], $c['sender'], $to_email,
                  $subjectPrefix . $c['sname'], $html);
        if ($logTag) _mail_log("[$logTag] SUCCESS sent to $to_email");
        return true;
    } catch (Throwable $e) {
        _mail_log(($logTag ? "[$logTag] " : '') . "FAILED to $to_email: " . get_class($e) . ': ' . $e->getMessage());
        return false;
    }
}

/** send_verify_email  (app.py:1459) */
function send_verify_email($to_email, $token): bool {
    return _send_code_mail(MAIL_TPL_VERIFY, 'تأكيد حسابك في ', (string)$to_email, $token, 'verify');
}

/** send_reset_email  (app.py:1500) */
function send_reset_email($to_email, $code): bool {
    return _send_code_mail(MAIL_TPL_RESET, 'استعادة كلمة المرور — ', (string)$to_email, $code, 'reset');
}

/** send_email_change_email  (app.py:1558) */
function send_email_change_email($to_email, $code): bool {
    return _send_code_mail(MAIL_TPL_EMAIL_CHANGE, 'رمز التحقق في ', (string)$to_email, $code, 'email-change');
}

/** is_valid_email  (app.py:1541) */
function is_valid_email($email): bool {
    $email = (string)$email;
    $len = py_len($email);
    if ($email === '' || $len > 254 || $len < 5) return false;
    if (substr_count($email, '@') !== 1) return false;
    [$local, $domain] = explode('@', $email, 2);
    if ($local === '' || $domain === '' || py_len($local) > 64) return false;
    if (strpos($email, '..') !== false || $email[0] === '.' || substr($email, -1) === '.') return false;
    if ($local[0] === '.' || substr($local, -1) === '.') return false;
    if (strpos($domain, '.') === false) return false;
    return (bool)preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/D', $email);
}
