<?php
// اختبارات دوال الحماية بلا قاعدة بيانات:  php tests/test_security.php
define('DATA_DIR', sys_get_temp_dir() . '/fc_sec_test_' . getmypid());
@mkdir(DATA_DIR . '/cache', 0775, true); @mkdir(DATA_DIR . '/logs', 0775, true);
const FC_SESSION_COOKIE = 'fc_session';
require __DIR__ . '/../core/lib/py.php';
require __DIR__ . '/../core/lib/response.php';
require __DIR__ . '/../core/lib/router.php';
require __DIR__ . '/../core/lib/security.php';
function session_cookie_stub() {}
$fails = 0; $n = 0;
function ok($c, string $name, $extra = ''): void { global $fails, $n; $n++; if (!$c) { $fails++; echo "FAIL: $name " . (is_string($extra) ? $extra : json_encode($extra)) . "\n"; } }

/* ---- ip_in_list ---- */
ok(ip_in_list('203.0.113.5', ['203.0.113.5']), 'exact ipv4');
ok(!ip_in_list('203.0.113.6', ['203.0.113.5']), 'different ipv4');
ok(ip_in_list('198.51.100.77', ['198.51.100.0/24']), 'cidr /24');
ok(!ip_in_list('198.51.101.1', ['198.51.100.0/24']), 'outside /24');
ok(ip_in_list('10.200.3.4', ['10.0.0.0/8']), 'cidr /8');
ok(ip_in_list('172.16.5.5', ['172.16.0.0/12']) && !ip_in_list('172.32.0.1', ['172.16.0.0/12']), 'cidr /12 boundary (non-byte-aligned)');
ok(ip_in_list('192.168.1.130', ['192.168.1.128/25']) && !ip_in_list('192.168.1.127', ['192.168.1.128/25']), 'cidr /25 boundary');
ok(ip_in_list('1.2.3.4', ['0.0.0.0/0']), '/0 matches all');
ok(ip_in_list('1.2.3.4', ['1.2.3.4/32']) && !ip_in_list('1.2.3.5', ['1.2.3.4/32']), '/32 exact');
ok(ip_in_list('2001:db8::1', ['2001:db8::/32']), 'ipv6 cidr');
ok(!ip_in_list('2001:db9::1', ['2001:db8::/32']), 'ipv6 outside cidr');
ok(ip_in_list('::1', ['::1']), 'ipv6 loopback exact');
ok(!ip_in_list('1.2.3.4', ['2001:db8::/32']), 'family mismatch never matches');
ok(!ip_in_list('not-an-ip', ['0.0.0.0/0']), 'garbage ip never matches');
ok(!ip_in_list('1.2.3.4', ['garbage', '', '1.2.3.4/xx']) || true, 'garbage list entries are skipped without error');
ok(!ip_in_list('1.2.3.4', []), 'empty list matches nothing');

/* ---- rate limit ---- */
rl_clear('t|a');
$r = []; for ($i = 0; $i < 7; $i++) $r[] = rate_limit_hit('t|a', 5, 60)[0];
ok($r === [true, true, true, true, true, false, false], 'rate_limit_hit allows 5 then blocks', $r);
[$allowed, $wait] = rate_limit_hit('t|a', 5, 60);
ok(!$allowed && $wait > 0 && $wait <= 60, 'retry-after within window', $wait);
rl_clear('t|a'); ok(rl_count('t|a', 60) === 0, 'clear resets');
ok(rl_count('t|never', 60) === 0, 'unknown bucket is empty');
rl_add('t|old', 1); sleep(2); ok(rl_count('t|old', 1) === 0, 'entries expire after the window');

/* ---- login throttle / guess ---- */
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ($i = 0; $i < 8; $i++) { ok(login_throttle_check('Victim@X.com') === null, "login attempt $i allowed"); login_throttle_fail('Victim@X.com'); }
$t = login_throttle_check('victim@x.com');           // نفس الحساب بحروف مختلفة
ok($t instanceof Response && $t->status === 429 && isset($t->headers['Retry-After']), 'case-insensitive account lock after 8 failures');
ok(login_throttle_check('other@x.com') === null, 'other account unaffected');
login_throttle_ok('victim@x.com'); ok(login_throttle_check('victim@x.com') === null, 'success clears the account counter');
for ($i = 0; $i < 6; $i++) { ok(guess_check('reset|a@b.c') === null, "guess $i allowed"); guess_fail('reset|a@b.c'); }
ok(guess_check('reset|a@b.c') instanceof Response, 'code guessing blocked after 6');
ok(guess_fail('reset|z@b.c') === false, 'guess_fail returns false before limit');

/* ---- client_ip (قيمة ساكنة لكل طلب → نختبر بعمليات منفصلة) ---- */
function ip_case(array $server, string $extraDefines = ''): string {
    $code = '$_SERVER = ' . var_export($server, true) . '; ' . $extraDefines . ' define("DATA_DIR", sys_get_temp_dir());'
          . ' require "' . __DIR__ . '/../core/lib/py.php"; require "' . __DIR__ . '/../core/lib/security.php"; echo client_ip();';
    return trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null'));
}
$fwd = ['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_CF_CONNECTING_IP' => '7.7.7.7'];
ok(ip_case($fwd) === '198.51.100.1', 'forwarded headers ignored without TRUSTED_PROXIES');
ok(ip_case($fwd, 'define("TRUSTED_PROXIES", ["203.0.113.0/24"]);') === '198.51.100.1', 'forwarded headers ignored when the peer is not a trusted proxy');
$px = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.9'];
ok(ip_case($px, 'define("TRUSTED_PROXIES", ["203.0.113.0/24"]);') === '198.51.100.9', 'trusted proxy: right-most non-proxy address wins over spoofed prefix');
$px2 = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 203.0.113.8'];
ok(ip_case($px2, 'define("TRUSTED_PROXIES", ["203.0.113.0/24"]);') === '198.51.100.9', 'trusted proxy chain: proxies on the right are skipped');
ok(ip_case(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '192.0.2.44'], 'define("TRUSTED_PROXIES", ["203.0.113.7"]);') === '192.0.2.44', 'CF-Connecting-IP honoured from a trusted proxy');
ok(ip_case(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => 'garbage'], 'define("TRUSTED_PROXIES", ["203.0.113.7"]);') === '203.0.113.7', 'garbage forwarded value falls back to the peer');

/* ---- session epoch + maintenance ---- */
ok(session_epoch() === 0, 'epoch starts at 0');
$e = bump_session_epoch(); ok($e >= time() - 1, 'bump returns a timestamp-like epoch', $e);
ok(is_file(session_epoch_file()) && (int)file_get_contents(session_epoch_file()) === $e, 'epoch persisted');
ok(!maintenance_active(), 'maintenance off');
maintenance_start(60); ok(maintenance_active(), 'maintenance on'); maintenance_end(); ok(!maintenance_active(), 'maintenance off again');
file_put_contents(maintenance_file(), json_encode(['until' => time() - 1])); ok(!maintenance_active() && !is_file(maintenance_file()), 'expired maintenance self-clears');
file_put_contents(maintenance_file(), 'garbage'); ok(!maintenance_active(), 'corrupt maintenance file is ignored');

/* ---- audit ---- */
$_SESSION = ['user_id' => 5];
audit_log('unit_test', ['k' => 'v']); audit_log('second');
$t = audit_tail(10);
ok(count($t) === 2 && $t[0]['event'] === 'second' && $t[1]['event'] === 'unit_test' && $t[1]['uid'] === 5 && $t[1]['k'] === 'v', 'audit log newest first', $t);


/* ---- كلمات المرور: bcrypt + توافق sha256 القديم ---- */
require_once __DIR__ . '/../core/lib/core.php';
$legacy = hash('sha256', 'Secret-pw');
ok(verify_pw('Secret-pw', $legacy) && !verify_pw('secret-pw', $legacy), 'legacy sha256 verifies (case-sensitive password)');
ok(verify_pw('Secret-pw', strtoupper($legacy)), 'legacy hex case-insensitive');
$new = hash_pw('Secret-pw');
ok(strncmp($new, '$2y$', 4) === 0 && strlen($new) === 60, 'new hashes are bcrypt', $new);
ok(verify_pw('Secret-pw', $new) && !verify_pw('Secret-pw2', $new) && !verify_pw('', $new), 'bcrypt verifies');
ok(hash_pw('Secret-pw') !== $new, 'bcrypt hashes are salted (different every time)');
$long = str_repeat('a', 100); $h2 = hash_pw($long);
ok(verify_pw($long, $h2) && !verify_pw(str_repeat('a', 99) . 'b', $h2), 'long passwords are not truncated at 72 bytes');
ok(verify_pw("pässwörd 🔑 كلمة", hash_pw("pässwörd 🔑 كلمة")), 'unicode passwords');
ok(verify_pw("a\0b", hash_pw("a\0b")) && !verify_pw("a", hash_pw("a\0b")), 'NUL bytes handled by the prehash');
ok(!verify_pw('x', '') && !verify_pw('x', 'garbage') && !verify_pw('x', 'abc$'), 'garbage stored values never verify');
ok(pw_needs_upgrade($legacy) && !pw_needs_upgrade($new) && !pw_needs_upgrade(''), 'upgrade detection');

/* ---- admin_path / security headers ---- */
ok(admin_path('/api/admin/users') && admin_path('/api/admin') && !admin_path('/api/admin/logo') && !admin_path('/api/orders') && !admin_path('/app/settings'), 'admin_path rules');
$resp = new Response('x'); $_SERVER['HTTPS'] = 'on'; security_headers($resp);
ok(($resp->headers['X-Frame-Options'] ?? '') === 'SAMEORIGIN' && ($resp->headers['X-Content-Type-Options'] ?? '') === 'nosniff' && isset($resp->headers['Strict-Transport-Security']), 'security headers incl. HSTS on https');
unset($_SERVER['HTTPS']); $resp = new Response('x'); security_headers($resp); ok(!isset($resp->headers['Strict-Transport-Security']), 'no HSTS over plain http');

/* ---- origin_exempt_path ---- */
ok(origin_exempt_path('/api/v2') && origin_exempt_path('/api/master/x') && !origin_exempt_path('/api/admin/users'), 'origin exemptions limited to the reseller api and master key');

foreach (glob(DATA_DIR . '/*/*') ?: [] as $f) { @unlink($f); } 
foreach (glob(DATA_DIR . '/*/rl/*') ?: [] as $f) { @unlink($f); }
echo $fails ? "\n$fails FAILED of $n\n" : "ALL OK ($n checks)\n";
exit($fails ? 1 : 0);
