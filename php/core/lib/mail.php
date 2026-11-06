<?php
/**
 * البريد: عميل SMTP أصلي (SSL/TLS/STARTTLS + AUTH CRAM-MD5/PLAIN/LOGIN) يحاكي smtplib،
 * وقوالب الرسائل الثلاثة منقولة حرفياً من app.py:1459-1600، و is_valid_email (1538-1556).
 * تعطيل التحقق من الشهادة مقصود: smtplib.SMTP_SSL / starttls ببايثون يستعملان _create_stdlib_context (غير موثَّق).
 */

class SmtpError extends RuntimeException {}

/** تنسيق (code, b'msg') كما يطبعه str(e) لاستثناءات smtplib */
function smtp_resp_text(int $code, string $msg): string { return '(' . $code . ', ' . py_bytes_repr($msg) . ')'; }

/** رسالة فشل الاتصال بصيغة بايثون (socket.gaierror / ConnectionRefusedError / timeout / ssl.SSLError) */
function smtp_conn_error_text(int $errno, string $errstr): string {
    $l = strtolower($errstr);
    if (strpos($l, 'name or service not known') !== false) return '[Errno -2] Name or service not known';
    if (strpos($l, 'temporary failure in name resolution') !== false) return '[Errno -3] Temporary failure in name resolution';
    if (strpos($l, 'no address associated') !== false) return '[Errno -5] No address associated with hostname';
    if (strpos($l, 'wrong version number') !== false) return '[SSL: WRONG_VERSION_NUMBER] wrong version number (_ssl.c:1007)';
    if (strpos($l, 'ssl') !== false && strpos($l, 'handshake') !== false) return '[SSL: SSLV3_ALERT_HANDSHAKE_FAILURE] sslv3 alert handshake failure (_ssl.c:1007)';
    if ($errno === 110 || strpos($l, 'timed out') !== false) return 'timed out';
    if ($errno === 111) return '[Errno 111] Connection refused';
    if ($errno === 113) return '[Errno 113] No route to host';
    if ($errno === 101) return '[Errno 101] Network is unreachable';
    return $errstr !== '' ? "[Errno $errno] $errstr" : 'Connection failed';
}

/** smtplib: socket.getfqdn() إن احتوى نقطة وإلا العنوان بين أقواس مربعة [127.0.0.1] */
function smtp_local_hostname(): string {
    static $h = null;
    if ($h !== null) return $h;
    $name = gethostname() ?: 'localhost';
    $fq = $name;
    $ip = @gethostbyname($name);
    if ($ip && $ip !== $name) {
        $rev = @gethostbyaddr($ip);
        if ($rev && strpos($rev, '.') !== false) $fq = $rev;
    }
    if (strpos($fq, '.') !== false) return $h = $fq;
    $addr = ($ip && $ip !== $name) ? $ip : '127.0.0.1';
    return $h = '[' . $addr . ']';
}

final class SmtpClient {
    /** @var resource|null */
    private $fp = null;
    public array $features = [];
    private float $timeout;

    /** مثل smtplib.SMTP(host, port, timeout) / SMTP_SSL: host فارغ = بلا اتصال (والأوامر التالية تفشل بـ "please run connect() first") */
    public function __construct(string $host, int $port, string $mode, float $timeout = 10.0) {
        $this->timeout = $timeout;
        if ($host === '') return;
        if (!$port && substr_count($host, ':') <= 1) {          // smtplib.connect: host:port أو المنفذ الافتراضي
            $i = strrpos($host, ':');
            if ($i !== false) {
                $pp = substr($host, $i + 1);
                $host = substr($host, 0, $i);
                if (!preg_match('/^\s*[+-]?\d+\s*$/', $pp)) throw new RuntimeException('nonnumeric port');
                $port = (int)$pp;
            }
            if (!$port) $port = $mode === 'SSL' ? 465 : 25;
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
        $remote = 'tcp://' . $host . ':' . $port;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException(smtp_conn_error_text($errno, $errstr));
        if ($mode === 'SSL') {            // SMTP_SSL: مصافحة TLS قبل قراءة الترحيب (نفصلها لنلتقط سبب فشل OpenSSL)
            stream_set_timeout($fp, (int)ceil($timeout));
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $why = (string)(error_get_last()['message'] ?? '');
                @fclose($fp);
                throw new RuntimeException(smtp_conn_error_text(0, $why !== '' ? $why : 'ssl handshake failed'));
            }
        }
        $this->fp = $fp;
        stream_set_timeout($this->fp, (int)ceil($timeout));
        [$code, $msg] = $this->read();
        if ($code !== 220) { @fclose($this->fp); $this->fp = null; throw new SmtpError(smtp_resp_text($code, $msg)); }
    }

    private function needConn(): void {
        if (!$this->fp) throw new RuntimeException('please run connect() first');
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
            $msgs[] = trim(substr($line, 4), " \t\r\n");
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        return [$code, implode("\n", $msgs)];
    }

    /** يرسل أمراً ويقرأ الرد؛ $kind يحدد صيغة الاستثناء عند رمز غير متوقع: resp | sender | recipient */
    private function cmd(string $line, array $okCodes, string $kind = 'resp', ?string $addr = null): array {
        $this->needConn();
        fwrite($this->fp, $line . "\r\n");
        [$code, $msg] = $this->read();
        if (!in_array($code, $okCodes, true)) {
            if ($kind === 'sender') throw new SmtpError('(' . $code . ', ' . py_bytes_repr($msg) . ', ' . py_repr((string)$addr) . ')');
            if ($kind === 'recipient') throw new SmtpError('{' . py_repr((string)$addr) . ': (' . $code . ', ' . py_bytes_repr($msg) . ')}');
            throw new SmtpError(smtp_resp_text($code, $msg));
        }
        return [$code, $msg];
    }

    /** ehlo_or_helo_if_needed: EHLO ثم HELO عند رفضه */
    public function ehlo(): void {
        $this->needConn();
        $host = smtp_local_hostname();
        fwrite($this->fp, 'EHLO ' . $host . "\r\n");
        [$code, $msg] = $this->read();
        $this->features = [];
        if ($code < 200 || $code > 299) {
            fwrite($this->fp, 'HELO ' . $host . "\r\n");
            [$code, $msg] = $this->read();
            if ($code < 200 || $code > 299) throw new SmtpError(smtp_resp_text($code, $msg));
            return;
        }
        foreach (explode("\n", $msg) as $i => $l) {
            if ($i === 0) continue;
            $parts = preg_split('/\s+/', trim($l), 2);
            $this->features[strtolower($parts[0])] = $parts[1] ?? '';
        }
    }

    public function starttls(): void {
        $this->needConn();
        if (!isset($this->features['starttls'])) throw new RuntimeException('STARTTLS extension not supported by server.');
        $this->cmd('STARTTLS', [220]);
        if (!@stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('[SSL: WRONG_VERSION_NUMBER] wrong version number (_ssl.c:1007)');
        $this->features = [];
    }

    /** smtplib.login: يجرّب CRAM-MD5 ثم PLAIN ثم LOGIN (المعلَنة فقط) وينتقل للتالي عند الرفض */
    public function login(string $user, string $pass): void {
        $this->needConn();
        if (!isset($this->features['auth'])) throw new RuntimeException('SMTP AUTH extension not supported by server.');
        $adv = preg_split('/\s+/', strtoupper($this->features['auth']));
        $last = null;
        $tried = false;
        foreach (['CRAM-MD5', 'PLAIN', 'LOGIN'] as $m) {
            if (!in_array($m, $adv, true)) continue;
            $tried = true;
            try {
                $count = 0;
                if ($m === 'CRAM-MD5') {
                    [$code, $resp] = $this->cmdAny('AUTH CRAM-MD5');
                } elseif ($m === 'PLAIN') {
                    [$code, $resp] = $this->cmdAny('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass));
                    $count = 1;
                } else {
                    [$code, $resp] = $this->cmdAny('AUTH LOGIN ' . base64_encode($user));
                    $count = 1;
                }
                while ($code === 334) {
                    $count++;
                    $challenge = (string)base64_decode($resp);
                    if ($m === 'CRAM-MD5') $answer = $user . ' ' . hash_hmac('md5', $challenge, $pass);
                    elseif ($m === 'PLAIN') $answer = "\0" . $user . "\0" . $pass;
                    else $answer = $count < 2 ? $user : $pass;
                    [$code, $resp] = $this->cmdAny(base64_encode($answer));
                    if ($count > 5) throw new RuntimeException('Server AUTH mechanism infinite loop. Last response: ' . smtp_resp_text($code, $resp));
                }
                if ($code === 235 || $code === 503) return;
                throw new SmtpError(smtp_resp_text($code, $resp));
            } catch (SmtpError $e) {
                $last = $e;
            }
        }
        if (!$tried) throw new RuntimeException('No suitable authentication method found.');
        throw $last;
    }

    /** يرسل سطراً ويرجع [code, msg] بلا تحقق */
    private function cmdAny(string $line): array {
        fwrite($this->fp, $line . "\r\n");
        return $this->read();
    }

    /** _rset(): يتجاهل أي خطأ */
    private function rset(): void {
        try { $this->cmdAny('RSET'); } catch (Throwable $e) {}
    }

    /** smtplib.sendmail لمستلم واحد: عند رفض المرسل/المستلم/البيانات يُرسَل RSET ثم يُرمى الاستثناء بصيغة بايثون */
    public function send(string $from, string $to, string $data): void {
        $this->needConn();
        [$code, $msg] = $this->cmdAny('MAIL FROM:<' . $from . '>');
        if ($code !== 250) {
            if ($code === 421) $this->close(); else $this->rset();
            throw new SmtpError('(' . $code . ', ' . py_bytes_repr($msg) . ', ' . py_repr($from) . ')');
        }
        [$code, $msg] = $this->cmdAny('RCPT TO:<' . $to . '>');
        if ($code !== 250 && $code !== 251) {
            if ($code === 421) $this->close(); else $this->rset();
            throw new SmtpError('{' . py_repr($to) . ': (' . $code . ', ' . py_bytes_repr($msg) . ')}');
        }
        [$code, $msg] = $this->cmdAny('DATA');
        if ($code !== 354) throw new SmtpError(smtp_resp_text($code, $msg));
        // dot-stuffing + CRLF (بدون إضافة سطر فارغ إن كانت الرسالة تنتهي أصلاً بـ CRLF)
        $data = preg_replace('/\r\n|\r|\n/', "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);
        if (substr($data, -2) !== "\r\n") $data .= "\r\n";
        fwrite($this->fp, $data . ".\r\n");
        [$code, $msg] = $this->read();
        if ($code !== 250) {
            if ($code === 421) $this->close(); else $this->rset();
            throw new SmtpError(smtp_resp_text($code, $msg));
        }
    }

    /** إغلاق بدون QUIT (مثل ترك الكائن بعد استثناء في smtplib) */
    public function close(): void {
        if ($this->fp) { @fclose($this->fp); $this->fp = null; }
    }

    public function quit(): void {
        if (!$this->fp) return;
        try { fwrite($this->fp, "QUIT\r\n"); $this->read(); } catch (Throwable $e) {}
        @fclose($this->fp);
        $this->fp = null;
    }
}

/** ترميز ترويسة RFC 2047 عند وجود محارف غير ASCII */
function mime_encode_header(string $v): string {
    if (!preg_match('/[^\x20-\x7e]/', $v)) return $v;
    return mb_encode_mimeheader($v, 'UTF-8', 'B', "\r\n", 0);
}

/**
 * يبني رسالة multipart/alternative فيها جزء HTML واحد كما يولّدها email.generator ببايثون
 * (MIMEMultipart('alternative') + MIMEText(html,'html')): بلا Date/Message-ID، وسطر فارغ قبل delimiter الإغلاق.
 */
function build_html_mail(string $fromName, string $fromEmail, string $to, string $subject, string $html): string {
    $boundary = '===============' . random_int(1000000000000000000, 9223372036854775806) . '==';
    $fromHdr = (preg_match('/[^\x20-\x7e]/', $fromName) ? mime_encode_header($fromName) : $fromName) . ' <' . $fromEmail . '>';
    $h = [
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'MIME-Version: 1.0',
        'From: ' . $fromHdr,
        'To: ' . $to,
        'Subject: ' . mime_encode_header($subject),
    ];
    $body = "--$boundary\r\nContent-Type: text/html; charset=\"utf-8\"\r\nMIME-Version: 1.0\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html), 76, "\r\n")
          . "\r\n--$boundary--\r\n";
    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

/** رسالة multipart/mixed بجزء نصي واحد (MIMEMultipart() + MIMEText(text,'plain','utf-8')) بلا Date/Message-ID كما بـ smtplib.send_message */
function build_plain_mail(string $fromHeader, string $to, string $subject, string $text): string {
    $boundary = '===============' . random_int(1000000000000000000, 9223372036854775806) . '==';
    $enc = fn(string $v) => preg_match('/[^\x20-\x7e]/', $v) ? mime_encode_header($v) : $v;
    $h = [
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        'MIME-Version: 1.0',
        'From: ' . $enc($fromHeader),
        'To: ' . $to,
        'Subject: ' . $enc($subject),
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=\"utf-8\"\r\nMIME-Version: 1.0\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text), 76, "\r\n")
          . "\r\n--$boundary--\r\n";
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
        $c->close();
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
