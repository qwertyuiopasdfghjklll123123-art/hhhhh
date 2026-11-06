<?php
// اختبار طبقة قاعدة البيانات على MySQL/MariaDB:  php tests/test_db.php
define('DB_HOST', getenv('FC_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('FC_DB_PORT') ?: 3306));
define('DB_NAME', getenv('FC_DB_NAME') ?: 'fc_test');
define('DB_USER', getenv('FC_DB_USER') ?: 'fc');
define('DB_PASS', getenv('FC_DB_PASS') ?: 'fcpass');
// تحذير: هذا الاختبار يحذف بيانات جداول users/orders/notifications... — لا يعمل إلا على قاعدة اسمها يحتوي "test"
if (stripos(DB_NAME, 'test') === false) { fwrite(STDERR, "رفض التشغيل: القاعدة '" . DB_NAME . "' ليست قاعدة اختبار (سيُفرَّغ محتواها). استعمل FC_DB_NAME=fc_test\n"); exit(2); }
require __DIR__ . '/../app/lib/py.php';
require __DIR__ . '/../app/lib/db.php';

$fails = 0; $n = 0;
function ok($cond, string $name, $extra = ''): void {
    global $fails, $n; $n++;
    if (!$cond) { $fails++; echo "FAIL: $name " . (is_string($extra) ? $extra : var_export($extra, true)) . "\n"; }
}
function eq($a, $b, string $name): void { ok($a === $b, $name, 'got ' . var_export($a, true) . ' expected ' . var_export($b, true)); }

// ---------- ترجمة SQL ----------
eq(sql_translate("INSERT OR REPLACE INTO site_settings(key,value) VALUES(?,?)"), "REPLACE INTO site_settings(`key`,value) VALUES(?,?)", 'insert or replace + key');
eq(sql_translate("INSERT OR IGNORE INTO t(a) VALUES(1)"), "INSERT IGNORE INTO t(a) VALUES(1)", 'insert or ignore');
eq(sql_translate("SELECT value FROM site_settings WHERE key=?"), "SELECT value FROM site_settings WHERE `key`=?", 'where key');
eq(sql_translate("SELECT api_key, key_x, s.key FROM t"), "SELECT api_key, key_x, s.key FROM t", 'key not touched in api_key/key_x/s.key');
eq(sql_translate("SELECT 'key' FROM t WHERE a='LIMIT -1 key'"), "SELECT 'key' FROM t WHERE a='LIMIT -1 key'", 'quoted untouched');
eq(sql_translate("SELECT id FROM t ORDER BY id LIMIT -1 OFFSET 15"), "SELECT id FROM t ORDER BY id LIMIT 18446744073709551615 OFFSET 15", 'limit -1');
eq(sql_translate("SELECT SUM(CAST(charge AS REAL)) FROM orders"), "SELECT SUM(CAST(charge AS DOUBLE)) FROM orders", 'cast real');
eq(sql_translate("INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct) VALUES('service',?,?,0,?) ON CONFLICT(filter_type,filter_key,platform) DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct"),
   "INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct) VALUES('service',?,?,0,?) ON DUPLICATE KEY UPDATE hidden=0, profit_pct=VALUES(profit_pct)", 'on conflict update');
eq(sql_translate("PRAGMA journal_mode=WAL"), null, 'pragma ignored');
eq(sql_translate("SELECT last_insert_rowid()"), "SELECT LAST_INSERT_ID()", 'last_insert_rowid');

// ---------- اتصال وCRUD ----------
$db = get_db();
$db->execute("DELETE FROM users");
$db->execute("DELETE FROM site_settings");
$db->execute("DELETE FROM recharge_requests");
$db->execute("DELETE FROM service_filters");
$db->commit();

$cur = $db->execute("INSERT INTO users(name,email,password,verified) VALUES(?,?,?,1)", ['حمزة', 'a@a.com', 'x']);
ok($cur->lastrowid > 0, 'lastrowid', $cur->lastrowid);
$uid = $cur->lastrowid;
$db->commit();

$r = $db->execute("SELECT * FROM users WHERE id=?", [$uid])->fetchone();
eq($r['name'], 'حمزة', 'utf8 roundtrip');
eq($r['balance'], 0.0, 'REAL default is float');
ok(is_float($r['balance']), 'balance float type');
eq($r['verified'], 1, 'int column int');
ok(is_int($r['verified']), 'verified int type');
eq($r['avatar'], '', 'longtext default empty string');
eq($r['verify_token'], null, 'null column');
ok($r['created_at'] > 1700000000, 'created_at default now', $r['created_at']);
ok(is_float($r['created_at']) || is_int($r['created_at']), 'created_at numeric');
eq($r[0], $uid, 'index access');
ok(in_array('email', $r->keys(), true), 'keys()');
ok(!in_array('__fc_ext_uq', $r->keys(), true), 'no hidden col in users');

// IntegrityError على email مكرر
$thrown = false;
try { $db->execute("INSERT INTO users(name,email,password) VALUES(?,?,?)", ['b', 'a@a.com', 'y']); }
catch (IntegrityError $e) { $thrown = true; }
ok($thrown, 'IntegrityError on duplicate email');
$db->rollback();

// site_settings (key محجوز) + INSERT OR REPLACE
$db->execute('INSERT OR REPLACE INTO site_settings(key,value) VALUES(?,?)', ['k1', 'v1']);
$db->execute('INSERT OR REPLACE INTO site_settings(key,value) VALUES(?,?)', ['k1', 'v2']);
$db->commit();
$r = $db->execute("SELECT value FROM site_settings WHERE key=?", ['k1'])->fetchone();
eq($r['value'], 'v2', 'replace into key');
eq($db->execute("SELECT COUNT(*) FROM site_settings")->fetchone()[0], 1, 'count(*) int');

// ON CONFLICT upsert
$sql = "INSERT INTO service_filters(filter_type,filter_key,platform,hidden,profit_pct,custom_category,prov_id)
        VALUES('service',?,?,0,?,?,?) ON CONFLICT(filter_type,filter_key,platform)
        DO UPDATE SET hidden=0, profit_pct=excluded.profit_pct, custom_category=excluded.custom_category, prov_id=excluded.prov_id";
$db->execute($sql, ['100', 'instagram', 25.5, 'cat1', 1]);
$db->execute($sql, ['100', 'instagram', 30.25, 'cat2', 2]);
$db->commit();
$rows = $db->execute("SELECT * FROM service_filters")->fetchall();
eq(count($rows), 1, 'upsert single row');
eq($rows[0]['profit_pct'], 30.25, 'upsert updated profit');
eq($rows[0]['custom_category'], 'cat2', 'upsert updated cat');

// rowcount = matched rows
$cur = $db->execute("UPDATE service_filters SET hidden=0 WHERE platform=?", ['instagram']);
eq($cur->rowcount, 1, 'rowcount matched even when unchanged');
$db->commit();

// recharge ext_ref semantics
$db->execute("INSERT INTO recharge_requests(user_id,amount,method) VALUES(?,?,?)", [$uid, 5, 'a']);
$db->execute("INSERT INTO recharge_requests(user_id,amount,method) VALUES(?,?,?)", [$uid, 6, 'b']);
$db->execute("INSERT INTO recharge_requests(user_id,amount,method,ext_ref) VALUES(?,?,?,?)", [$uid, 7, 'c', 'TX9']);
$db->commit();
$rr = $db->execute("SELECT * FROM recharge_requests ORDER BY id")->fetchall();
eq($rr[0]['ext_ref'], '', 'ext_ref default empty');
ok(!in_array('__fc_ext_uq', $rr[0]->keys(), true), 'hidden col removed from SELECT *');
$thrown = false;
try { $db->execute("INSERT INTO recharge_requests(user_id,amount,method,ext_ref) VALUES(?,?,?,?)", [$uid, 8, 'd', 'TX9']); }
catch (IntegrityError $e) { $thrown = true; }
ok($thrown, 'ext_ref unique violation');
$db->rollback();

// SUM على INT => رقم لا نص ؛ SUM(CAST AS REAL) => float
$db->execute("INSERT INTO orders(user_id,order_id,service,link,quantity,charge,status) VALUES(?,?,?,?,?,?,?)", [$uid, 'o1', '5', 'http://x', 100, '1.5', 'Completed']);
$db->execute("INSERT INTO orders(user_id,order_id,service,link,quantity,charge,status) VALUES(?,?,?,?,?,?,?)", [$uid, 'o2', '5', 'http://x', 200, '2.25', 'Completed']);
$db->commit();
$r = $db->execute("SELECT COALESCE(SUM(quantity),0) as q, COALESCE(SUM(CAST(charge AS REAL)),0) as total, COUNT(*) as cnt FROM orders WHERE user_id=? AND status='Completed'", [$uid])->fetchone();
eq($r['q'], 300, 'SUM(int) is int');
ok(is_int($r['q']), 'SUM int type', gettype($r['q']));
eq($r['total'], 3.75, 'SUM(cast real)');
ok(is_float($r['total']), 'SUM float type');
eq($r['cnt'], 2, 'count');

// COALESCE(SUM(...),0) على جدول فارغ
$r = $db->execute("SELECT COALESCE(SUM(CAST(charge AS REAL)),0) as total FROM orders WHERE user_id=?", [999999])->fetchone();
ok($r['total'] == 0, 'empty sum zero', $r['total']);

// sqlite3.Row مع أعمدة مكررة: الاسم يرجع أول تطابق
$r = $db->execute("SELECT o.id AS oid, o.order_id, u.id, u.email FROM orders o JOIN users u ON u.id=o.user_id LIMIT 1")->fetchone();
eq($r['email'], 'a@a.com', 'join row');

$r = $db->execute("SELECT u.id, o.id FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.id LIMIT 1")->fetchone();
eq($r['id'], $uid, 'duplicate column name -> first wins');
eq($r->keys(), ['id', 'id'], 'duplicate column names kept in keys()');
eq(count($r), 2, 'duplicate column positional count');

// LIMIT -1 OFFSET
$rows = $db->execute("SELECT id FROM orders WHERE user_id=? ORDER BY id LIMIT -1 OFFSET 1", [$uid])->fetchall();
eq(count($rows), 1, 'limit -1 offset 1');

// LIMIT ? مع عدد صحيح
$rows = $db->execute("SELECT id FROM orders WHERE user_id=? ORDER BY id LIMIT ?", [$uid, 1])->fetchall();
eq(count($rows), 1, 'limit ? int');

// float binding دقة
$db->execute("UPDATE users SET balance=? WHERE id=?", [0.1 + 0.2, $uid]);
$db->commit();
$r = $db->execute("SELECT balance FROM users WHERE id=?", [$uid])->fetchone();
eq($r['balance'], 0.1 + 0.2, 'float exact roundtrip');

// rollback عند نهاية الطلب بدون commit
$db->execute("UPDATE users SET balance=999 WHERE id=?", [$uid]);
Conn::shutdown();
$db = get_db();
$r = $db->execute("SELECT balance FROM users WHERE id=?", [$uid])->fetchone();
eq($r['balance'], 0.1 + 0.2, 'rollback at shutdown without commit');

// قراءة الكتابة داخل نفس المعاملة
$db->execute("UPDATE users SET balance=5 WHERE id=?", [$uid]);
$r = $db->execute("SELECT balance FROM users WHERE id=?", [$uid])->fetchone();
eq($r['balance'], 5.0, 'read own writes');
$db->commit();

// null params
$db->execute("UPDATE users SET verify_token=? WHERE id=?", [null, $uid]);
$db->commit();

// UNIX_TIMESTAMP/created_at بدقة
$db->execute("INSERT INTO notifications(id,user_id,type,title,text,icon,goto) VALUES(?,?,?,?,?,?,?)", ['abcd1234', $uid, 'order', 'عنوان', 'نص', 'check', 'order:1']);
$db->commit();
$r = $db->execute("SELECT * FROM notifications WHERE id=?", ['abcd1234'])->fetchone();
eq($r['goto'], 'order:1', 'notif goto');

// تنظيف
$db->execute("DELETE FROM notifications"); $db->execute("DELETE FROM orders"); $db->execute("DELETE FROM recharge_requests");
$db->execute("DELETE FROM service_filters"); $db->execute("DELETE FROM site_settings"); $db->execute("DELETE FROM users");
$db->commit();

echo ($fails === 0 ? "ALL OK" : "FAILED $fails") . " ($n checks)\n";
exit($fails === 0 ? 0 : 1);
