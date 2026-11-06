<?php
// اختبارات فحص ملفات الاستيراد (بلا قاعدة بيانات):  php tests/test_transfer.php
define('DATA_DIR', sys_get_temp_dir() . '/fc_tf_test_' . getmypid());
@mkdir(DATA_DIR . '/cache', 0775, true);
require __DIR__ . '/../core/lib/py.php';
require __DIR__ . '/../core/lib/sqlsplit.php';
require __DIR__ . '/../core/lib/transfer.php';
require __DIR__ . '/../core/lib/backup.php';   // _rrmdir

$fails = 0; $n = 0;
function ok($c, string $name, $extra = ''): void { global $fails, $n; $n++; if (!$c) { $fails++; echo "FAIL: $name " . (is_string($extra) ? $extra : json_encode($extra, JSON_UNESCAPED_UNICODE)) . "\n"; } }
function throws(callable $f, string $name, string $needle = ''): void {
    global $fails, $n; $n++;
    try { $f(); $fails++; echo "FAIL: $name (no exception)\n"; }
    catch (TransferError $e) { if ($needle !== '' && strpos($e->getMessage(), $needle) === false) { $fails++; echo "FAIL: $name wrong message: " . $e->getMessage() . "\n"; } }
    catch (Throwable $e) { $fails++; echo "FAIL: $name unexpected " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
function scan(string $sql, ?callable $h = null): array { $fp = fopen('php://memory', 'w+b'); fwrite($fp, $sql); rewind($fp); try { return tf_scan_sql_stream($fp, $h); } finally { fclose($fp); } }

/* ---------- tf_validate_values ---------- */
ok(tf_validate_values("(1,'a',NULL)", $w) === 1 && $w === 3, 'basic row');
ok(tf_validate_values("(1,2),(3,4),(5,6)") === 3, 'three rows');
ok(tf_validate_values("(-5, +3.5, .25, 1e10, -2.5E-3, 0x1F, X'ab', b'101', TRUE, false, null)", $w) === 1 && $w === 11, 'all literal kinds', $w);
ok(tf_validate_values("('it\\'s; ok','x''y',\"d\\\"q\")", $w) === 1 && $w === 3, 'escaped quotes');
ok(tf_validate_values("(_binary 'abc', _utf8mb4'نص عربي')", $w) === 1 && $w === 2, 'charset introducers', $w);
ok(tf_validate_values("('a;b -- c /* d */ # e')") === 1, 'comment markers inside string are data');
ok(tf_validate_values("('x')  ,  ('y')\n") === 1 + 1, 'whitespace and newlines');
foreach (["(NOW())", "(1, (SELECT 1))", "(1+1)", "(abc)", "(1) UNION SELECT 1", "(1); DROP TABLE t", "(1),", "(1", "('x)", "(1) ON DUPLICATE KEY UPDATE a=1",
          "(LOAD_FILE('/etc/passwd'))", "(CONCAT('a','b'))", "(1,2),(3)", "(0x)", "(@var)", "(DEFAULT)", "(`col`)", "(1 2)", "()", "(1,,2)"] as $bad) {
    throws(fn() => tf_validate_values($bad), 'reject: ' . $bad);
}

/* ---------- tf_scan_sql_stream ---------- */
$good = "-- dump\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS `users`;\nCREATE TABLE `users` (\n  `id` int NOT NULL,\n  `name` varchar(255),\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB;\nLOCK TABLES `users` WRITE;\n"
      . "INSERT INTO `users` (`id`,`name`) VALUES (1,'a'),(2,'b');\nUNLOCK TABLES;\nINSERT INTO `levels` VALUES (1,'x');\n/*!40101 SET x=1 */;\nCOMMIT;\n";
$cap = [];
$r = scan($good, function ($t, $cols, $vals, $rows, $verb) use (&$cap) { $cap[] = [$t, $cols, $rows, $verb]; });
ok($r['tables']['users']['rows'] === 2 && $r['tables']['levels']['rows'] === 1, 'good dump rows', $r);
ok($cap[0][0] === 'users' && $cap[0][1] === ['id', 'name'], 'explicit columns', $cap[0]);
ok($cap[1][0] === 'levels' && $cap[1][1] === [], 'column-less insert for a table without CREATE in dump (no pdo => empty cols)', $cap[1]);
$r = scan("CREATE TABLE `users` (`id` int, `name` text, `email` varchar(9), KEY k (`id`));\nINSERT INTO `users` VALUES (1,'n','e');", function ($t, $cols) use (&$cap) { $cap = $cols; });
ok($cap === ['id', 'name', 'email'], 'column-less insert uses CREATE TABLE column order', $cap);
throws(fn() => scan("CREATE TABLE `users` (`id` int, `name` text);\nINSERT INTO `users` VALUES (1);"), 'width mismatch vs CREATE', 'لا يطابق');
$r = scan("INSERT INTO `wp_users` (a) VALUES (1);\nINSERT INTO `users` (`id`) VALUES (1);");
ok($r['unknown_tables'] === ['wp_users'] && isset($r['tables']['users']) && !isset($r['tables']['wp_users']), 'foreign tables are ignored, never counted', $r);

foreach ([
    "UPDATE users SET is_admin=1;", "DELETE FROM users;", "TRUNCATE TABLE users;", "GRANT ALL ON *.* TO 'x'@'%';", "CREATE USER 'x'@'%';",
    "DROP DATABASE fc;", "DROP TABLE users;", "ALTER TABLE users ADD COLUMN x int;", "CREATE TRIGGER t BEFORE INSERT ON users FOR EACH ROW SET NEW.is_admin=1;",
    "CREATE PROCEDURE p() SELECT 1;", "CREATE VIEW v AS SELECT 1;", "CALL p();", "SELECT * FROM users;", "SELECT 1 INTO OUTFILE '/tmp/x';",
    "LOAD DATA INFILE '/etc/passwd' INTO TABLE users;", "INSERT INTO users (a) SELECT password FROM users;", "INSERT INTO users SET a=1;",
    "INSERT INTO users (id) VALUES (1) ON DUPLICATE KEY UPDATE is_admin=1;", "INSERT INTO users (id) VALUES ((SELECT 1));", "SHUTDOWN;", "KILL 1;",
    "INSERT INTO users (id) VALUES (1); DROP TABLE users;", "SET GLOBAL general_log=1; UPDATE x SET y=1;", "DELIMITER //",
    "REPLACE INTO users (id) VALUES (NOW());", "INSERT INTO `db`.`users` (id) VALUES (1);", "INSERT INTO users (id; DROP TABLE x) VALUES (1);",
] as $bad) {
    throws(fn() => scan($bad), 'reject statement: ' . substr($bad, 0, 50));
}

// نص يحوي ; و DROP كبيانات فقط (داخل نص مُغلق بشكل صحيح) — مقبول كجملة واحدة
$r = scan("INSERT INTO `users` (`name`) VALUES ('x''); DROP TABLE users; --');");
ok($r['statements'] === 1 && $r['tables']['users']['rows'] === 1, 'dangerous text inside a quoted literal stays data', $r);
$r = scan("INSERT INTO `users` (`name`) VALUES ('a\\'); DROP TABLE users; --');");
ok($r['statements'] === 1, 'backslash-escaped quote keeps text as data', $r);
// خروج من النص بحقن: النص ينتهي ثم تأتي جملة DROP → تُرفض
throws(fn() => scan("INSERT INTO `users` (`name`) VALUES ('x'); DROP TABLE users; INSERT INTO `users` (`name`) VALUES ('y');"), 'injection after a closed literal is a separate statement and is rejected');

/* ---------- تصنيف JSON ---------- */
ok(tf_classify_json(['private' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n", 'public' => 'BNx']) === 'vapid', 'vapid');
ok(tf_classify_json(['private' => 'x', 'public' => 'y']) === null, 'vapid without PEM is rejected');
ok(tf_classify_json(['data' => [['service' => '1']], 'time' => 1700000000.5]) === 'services_cache', 'services cache');
ok(tf_classify_json(['1' => [['endpoint' => 'https://x/y', 'keys' => ['p256dh' => 'a', 'auth' => 'b']]]]) === 'push_subs', 'push subs');
ok(tf_classify_json(['7' => ['endpoint' => 'https://x/y']]) === 'push_subs', 'legacy single-sub dict');
ok(tf_classify_json([]) === 'push_subs', 'empty object = empty subscriptions');
ok(tf_classify_json(['abc' => [1, 2]]) === null, 'non numeric user ids rejected');
ok(tf_classify_json([1, 2, 3]) === null, 'plain list rejected');
ok(tf_classify_json(['1' => [['nope' => 1]]]) === null, 'subscription without endpoint rejected');

/* ---------- ZIP + التحليل الكامل ---------- */
function mkzip(array $entries): string {
    $p = tempnam(sys_get_temp_dir(), 'tfz'); $z = new ZipArchive(); $z->open($p, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $data) { $z->addFromString($name, $data); }
    $z->close(); return $p;
}
$sqlDump = "SET NAMES utf8mb4;\nINSERT INTO `users` (`id`,`name`,`is_admin`) VALUES (1,'مدير',1),(2,'u',0);\nINSERT INTO `site_settings` (`key`,`value`) VALUES ('site_name','موقعي');\n";
$vapid = json_encode(['private' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n", 'public' => 'BNx']);

$zp = mkzip(['fastcrand.sql' => $sqlDump, 'fc_vapid.json' => $vapid, 'fc_push_subs.json' => '{}', 'readme.txt' => 'x', 'sub/dir/' => '', 'fc_services_cache.json' => '{"data":[{"service":"1"}],"time":1.5}']);
$m = transfer_analyze($zp, 'fastcrand_cloud_backup_20260101_000000.zip', 7);
$types = array_column($m['components'], 'type');
sort($types);
ok($types === ['push_subs', 'services_cache', 'sql', 'vapid'], 'zip components found', $types);
ok(count(array_filter($m['warnings'], fn($w) => strpos($w, 'readme.txt') !== false)) === 1, 'unknown files are ignored with a warning', $m['warnings']);
$sqlC = tf_component($m, 'sql');
ok($sqlC['tables']['users']['rows'] === 2 && $sqlC['tables']['site_settings']['rows'] === 1, 'sql summary in zip', $sqlC);
ok(is_file(transfer_job_dir($m['token']) . '/meta.json'), 'meta written');
ok(transfer_read_meta($m['token'], 7)['source'] === 'fastcrand_cloud_backup_20260101_000000.zip', 'meta readable by owner');
throws(fn() => transfer_read_meta($m['token'], 8), 'other admin cannot use my job', 'ليس من جلستك');
throws(fn() => transfer_job_dir('../../etc'), 'bad token rejected', 'غير صالح');
throws(fn() => transfer_job_dir(str_repeat('g', 32)), 'non-hex token rejected', 'غير صالح');

// مسارات خبيثة داخل ZIP: لا تُستخرج بمسار الأرشيف أبداً (نقرأ المحتوى فقط ونكتبه باسمنا داخل مجلد المهمة)
$zp = mkzip(['../../evil.sql' => $sqlDump, '/abs/path/other.sql' => $sqlDump]);
$m2 = transfer_analyze($zp, 'x.zip', 7);
$dirFiles = array_map('basename', glob(transfer_job_dir($m2['token']) . '/*'));
sort($dirFiles);
ok($dirFiles === ['c_1', 'c_2', 'meta.json'], 'zip-slip names never create paths (only c_N files inside the job dir)', $dirFiles);
ok(!is_file(sys_get_temp_dir() . '/evil.sql') && !is_file(DATA_DIR . '/evil.sql') && !is_file('/abs/path/other.sql'), 'nothing written outside job dir');
$zp = mkzip(['a/fastcrand.sql' => $sqlDump, 'b/fastcrand.sql' => $sqlDump]);
$m2 = transfer_analyze($zp, 'x.zip', 7);
ok(count($m2['components']) === 1 && count($m2['warnings']) === 1, 'duplicate basename ignored with a warning', $m2['warnings']);

// قنبلة ضغط: ملف 40MB أصفار ينضغط لأقل من 50KB ⇒ نسبة > 1000
$zp = mkzip(['fastcrand.sql' => "SET NAMES utf8mb4;\n-- " . str_repeat('0', 40 * 1048576) . "\nINSERT INTO `users` (`id`) VALUES (1);"]);
throws(fn() => transfer_analyze($zp, 'bomb.zip', 7), 'compression bomb rejected', 'قنبلة');
// ملفات لا تخص الموقع
throws(fn() => transfer_analyze(mkzip(['a.txt' => 'hi']), 'x.zip', 7), 'zip without importable files', 'لم أجد');
// SQL خبيث ضمن zip
throws(fn() => transfer_analyze(mkzip(['fastcrand.sql' => "INSERT INTO users (id) VALUES (1);\nUPDATE users SET is_admin=1;"]), 'x.zip', 7), 'malicious sql inside zip rejected', 'غير مسموحة');
throws(fn() => transfer_analyze(mkzip(['fastcrand.sql' => "SET NAMES utf8mb4;"]), 'x.zip', 7), 'sql without rows rejected', 'لا يحوي أي INSERT');

// ملفات مفردة
$f = tempnam(sys_get_temp_dir(), 'tfs'); file_put_contents($f, $sqlDump);
$m3 = transfer_analyze($f, 'dump.sql', 7);
ok($m3['components'][0]['type'] === 'sql', 'plain .sql accepted');
$f = tempnam(sys_get_temp_dir(), 'tfj'); file_put_contents($f, $vapid);
ok(transfer_analyze($f, 'fc_vapid.json', 7)['components'][0]['type'] === 'vapid', 'plain vapid json accepted');
$f = tempnam(sys_get_temp_dir(), 'tfj'); file_put_contents($f, '{"hello":"world"}');
throws(fn() => transfer_analyze($f, 'x.json', 7), 'unknown json rejected', 'JSON غير معروف');
$f = tempnam(sys_get_temp_dir(), 'tfj'); file_put_contents($f, "\x00\x01binary garbage \xff\xfe");
throws(fn() => transfer_analyze($f, 'x.bin', 7), 'binary garbage rejected');
$f = tempnam(sys_get_temp_dir(), 'tfj'); file_put_contents($f, '');
throws(fn() => transfer_analyze($f, 'empty.sql', 7), 'empty file rejected');
$f = tempnam(sys_get_temp_dir(), 'tfj'); file_put_contents($f, "#!/bin/sh\nrm -rf /\n");
throws(fn() => transfer_analyze($f, 'x.sh', 7), 'shell script rejected');
$f = tempnam(sys_get_temp_dir(), 'tfj'); file_put_contents($f, "<?php system(\$_GET['c']);");
throws(fn() => transfer_analyze($f, 'shell.php', 7), 'php file rejected');

if (extension_loaded('pdo_sqlite')) {
    $db = tempnam(sys_get_temp_dir(), 'tfdb'); $pdo = new PDO('sqlite:' . $db);
    $pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, is_admin INT); INSERT INTO users(name,is_admin) VALUES ('a',1),('b',0); CREATE TABLE site_settings(key TEXT PRIMARY KEY, value TEXT); CREATE TABLE other_app(x INT);");
    $pdo = null;
    $dbBytes = file_get_contents($db);
    $m4 = transfer_analyze($db, 'fastcrand.db', 7);
    $c = $m4['components'][0];
    ok($c['type'] === 'sqlite' && $c['tables']['users']['rows'] === 2 && $c['unknown_tables'] === ['other_app'], 'sqlite file summary', $c);
    $zp = mkzip(['fastcrand.db' => $dbBytes, 'fc_vapid.json' => $vapid]);
    $m5 = transfer_analyze($zp, 'fastcrand_cloud_backup_x.zip', 7);
    ok(array_column($m5['components'], 'type') === ['sqlite', 'vapid'], 'python-style zip (sqlite + json)', array_column($m5['components'], 'type'));
    $nd = tempnam(sys_get_temp_dir(), 'tfdb'); $p2 = new PDO('sqlite:' . $nd); $p2->exec('CREATE TABLE t(x INT)'); $p2 = null;
    throws(fn() => transfer_analyze($nd, 'other.db', 7), 'foreign sqlite db rejected', 'ليس قاعدة موقع');
} else { echo "(pdo_sqlite غير متوفر: تخطّي اختبارات SQLite)\n"; }

// قائمة الجداول الثابتة تطابق sql/database.sql بالضبط
$fromSql = [];
SqlSplitter::eachStatement(__DIR__ . '/../sql/database.sql', function ($st) use (&$fromSql) { if (preg_match('/^CREATE TABLE IF NOT EXISTS `(\w+)`/', $st, $m) && $m[1] !== 'user_sessions') $fromSql[] = $m[1]; });   // user_sessions: جلسات أجهزة مرتبطة بكوكيات هذا الموقع، لا تدخل في النسخ/الاستيراد
sort($fromSql); $mine = TRANSFER_TABLES; sort($mine);
ok($fromSql === $mine, 'TRANSFER_TABLES matches sql/database.sql', array_diff($fromSql, $mine) + array_diff($mine, $fromSql));

_rrmdir(DATA_DIR);
echo $fails ? "\n$fails FAILED of $n\n" : "ALL OK ($n checks)\n";
exit($fails ? 1 : 0);
