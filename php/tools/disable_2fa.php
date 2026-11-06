<?php
/**
 * يوقف المصادقة الثنائية (2FA) لحساب: لمن فقد هاتفه ورموزه الاحتياطية، أو بعد تغيير SECRET_KEY (يُبطل الأسرار المشفّرة).
 *   php tools/disable_2fa.php user@example.com
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../core/bootstrap.php';
$email = mb_strtolower(trim((string)($argv[1] ?? '')), 'UTF-8');
if ($email === '') { fwrite(STDERR, "الاستعمال: php tools/disable_2fa.php email@example.com\n"); exit(1); }
$conn = get_db();
$u = $conn->execute('SELECT id FROM users WHERE LOWER(email)=?', [$email])->fetchone();
if (!$u) { fwrite(STDERR, "لا يوجد حساب بهذا البريد\n"); exit(1); }
$conn->execute('UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_recovery=NULL WHERE id=?', [$u['id']]);
$conn->execute('UPDATE user_sessions SET revoked=1 WHERE user_id=?', [$u['id']]);
$conn->commit();
echo "تم إيقاف المصادقة الثنائية لـ $email وإنهاء جلساته.\n";
