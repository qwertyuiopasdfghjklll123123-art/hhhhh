<?php
// اختبار قارئ جمل SQL (بلا قاعدة بيانات):  php tests/test_sqlsplit.php
require __DIR__ . '/../core/lib/sqlsplit.php';

$fails = 0; $n = 0;
function eq($got, $want, string $name): void {
    global $fails, $n; $n++;
    if ($got !== $want) { $fails++; echo "FAIL: $name\n  got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n  want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$S = fn(string $sql) => SqlSplitter::splitString($sql);

eq($S("SELECT 1; SELECT 2;"), ['SELECT 1', 'SELECT 2'], 'two statements');
eq($S("INSERT INTO t VALUES ('a;b');"), ["INSERT INTO t VALUES ('a;b')"], 'semicolon inside single quotes');
eq($S("INSERT INTO t VALUES ('it\\'s; ok');"), ["INSERT INTO t VALUES ('it\\'s; ok')"], 'backslash-escaped quote');
eq($S("INSERT INTO t VALUES ('it''s; ok');"), ["INSERT INTO t VALUES ('it''s; ok')"], 'doubled quote');
eq($S("INSERT INTO t VALUES (\"a;b\");"), ["INSERT INTO t VALUES (\"a;b\")"], 'double quotes');
eq($S("SELECT `a;b` FROM t;"), ["SELECT `a;b` FROM t"], 'backticks');
eq($S("SELECT `a``;b` FROM t;"), ["SELECT `a``;b` FROM t"], 'doubled backtick');
eq($S("-- c1; x\nSELECT 1; # c2; y\nSELECT 2; /* c3; */ SELECT 3;"), ['SELECT 1', 'SELECT 2', 'SELECT 3'], 'comments skipped');
eq($S("/*!40101 SET NAMES utf8 */;\nSELECT 1;"), ['SELECT 1'], 'conditional comment is ignored, never executed');
eq($S("/*!50000 DROP TABLE users */;"), [], 'dangerous conditional comment ignored');
eq($S("SELECT 1--1;"), ['SELECT 1--1'], '-- without space is not a comment');
eq($S("SELECT 1-- c\n;"), ['SELECT 1'], '-- before newline is a comment');
eq($S("SELECT 'x -- y', '/* z */', '# w';"), ["SELECT 'x -- y', '/* z */', '# w'"], 'comment markers inside strings are text');
eq($S("SELECT 1; SELECT 2"), ['SELECT 1', 'SELECT 2'], 'last statement without semicolon');
eq($S("INSERT INTO t VALUES ('line1;\nline2');\nSELECT 9;"), ["INSERT INTO t VALUES ('line1;\nline2')", 'SELECT 9'], 'multi-line string');
eq($S("SELECT 1;\r\nSELECT 2;\r\n"), ['SELECT 1', 'SELECT 2'], 'CRLF');
eq($S("INSERT INTO t VALUES ('a\\\nb;c');SELECT 1;"), ["INSERT INTO t VALUES ('a\\\nb;c')", 'SELECT 1'], 'backslash at end of line inside string');
eq($S("SELECT 'a\\\\';SELECT 2;"), ["SELECT 'a\\\\'", 'SELECT 2'], 'escaped backslash before closing quote');
eq($S("  \n ;; ;"), [], 'empty statements dropped');
eq($S("SELECT 1 /* a;\nb */ ;SELECT 2;"), ['SELECT 1', 'SELECT 2'], 'multi-line block comment');
eq($S("SELECT 'arabic ؛ حروف ; x';"), ["SELECT 'arabic ؛ حروف ; x'"], 'utf8 text');

$threw = false;
try { $S("SELECT 'abc"); } catch (RuntimeException $e) { $threw = true; }
eq($threw, true, 'unterminated quote throws');

// الملف الحقيقي: SET NAMES + 25 CREATE + 10 INSERT IGNORE
$all = SqlSplitter::eachStatement(__DIR__ . '/../sql/database.sql', function ($s) use (&$types) {
    $types[] = strtoupper(substr($s, 0, strpos($s . ' ', ' ') + 0 ?: 6) . ' ' . (preg_match('/^(\w+)\s+(\w+)/', $s, $m) ? $m[2] : ''));
});
eq($all, 36, 'database.sql statement count');
$c = array_count_values(array_map(fn($t) => explode(' ', trim($t))[0], $types));
eq($c['CREATE'] ?? 0, 25, 'database.sql CREATE count');
eq($c['INSERT'] ?? 0, 10, 'database.sql INSERT count');
eq($c['SET'] ?? 0, 1, 'database.sql SET count');

echo $fails ? "\n$fails FAILED of $n\n" : "ALL OK ($n checks)\n";
exit($fails ? 1 : 0);
