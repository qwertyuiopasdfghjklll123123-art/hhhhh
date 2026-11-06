<?php
/**
 * نقل بيانات موقع بايثون (SQLite + ملفات /root/fc_*) إلى MySQL + مجلد data/.
 *
 *   php tools/migrate_from_sqlite.php --sqlite=/root/fastcrand.db [--files=/root] [--truncate] [--no-files] [--dry-run]
 *
 *   --sqlite=PATH   ملف قاعدة بيانات بايثون (الافتراضي /root/fastcrand.db)
 *   --files=DIR     المجلد الذي يحوي fc_voices و fc_avatars ... و fc_vapid.json (الافتراضي /root)
 *   --truncate      يفرّغ جداول MySQL الهدف قبل النسخ (بدونه يرفض إن وجد بيانات)
 *   --no-files      ينسخ الجداول فقط بلا الملفات
 *   --dry-run       يفحص ويطبع التقرير دون كتابة أي شيء
 *
 * يحافظ على: معرّفات الصفوف (id) وترتيب الإدراج (مهم لترتيب الإشعارات/الكوبونات المتساوية بالوقت)،
 * وكلمات المرور (نفس sha256)، ومفاتيح VAPID واشتراكات Web Push ليستمر المشتركون.
 * شغّله بعد install.php (أو tools/init_db.php) وقبل تحويل الدومين إلى نسخة PHP.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../core/bootstrap.php';
@set_time_limit(0);

$o = getopt('', ['sqlite::', 'files::', 'truncate', 'no-files', 'dry-run', 'help']);
if (isset($o['help'])) { echo file_get_contents(__FILE__, false, null, 0, 1500); exit(0); }
$sqlitePath = (string)($o['sqlite'] ?? '/root/fastcrand.db');
$filesRoot = rtrim((string)($o['files'] ?? '/root'), '/');
$dry = isset($o['dry-run']);
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "امتداد pdo_sqlite غير مفعّل بـ PHP (مطلوب لقراءة قاعدة بايثون)\n"); exit(1); }
if (!is_file($sqlitePath)) { fwrite(STDERR, "ملف SQLite غير موجود: $sqlitePath\n"); exit(1); }

$lite = new PDO('sqlite:' . $sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$my = Conn::shared();
$pdo = $my->pdo;
if (!$dry) create_schema($pdo);

function out(string $s): void { echo $s . "\n"; }

/** أعمدة جدول MySQL غير المولَّدة: [name => data_type] */
function my_columns(PDO $pdo, string $t): array {
    $st = $pdo->prepare('SELECT column_name, data_type, extra FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    $st->execute([$t]);
    $cols = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$n, $dt, $extra]) {
        if (preg_match('/\b(VIRTUAL|STORED)\s+GENERATED\b|\bPERSISTENT\b/i', (string)$extra)) continue;
        $cols[$n] = strtolower((string)$dt);
    }
    return $cols;
}

/** تحويل قيمة SQLite (مرنة النوع) إلى قيمة مناسبة لعمود MySQL */
function conv($v, string $type, int &$warn) {
    if ($v === null) return null;
    static $int = ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'];
    static $flt = ['double', 'float', 'decimal'];
    if (in_array($type, $int, true)) {
        if (is_int($v)) return $v;
        if (is_float($v)) return (int)$v;
        if (is_string($v) && is_numeric(trim($v))) return (int)(float)trim($v);
        $warn++; return null;
    }
    if (in_array($type, $flt, true)) {
        if (is_int($v) || is_float($v)) return (float)$v;
        if (is_string($v) && is_numeric(trim($v))) return (float)trim($v);
        $warn++; return null;
    }
    // أعمدة نصية: SQLite يخزّن الأعداد بعمود TEXT كنص أصلاً، لكن نضمن التحويل بنفس صيغته
    if (is_float($v)) return py_sqlite_float_text($v);
    if (is_int($v)) return (string)$v;
    $s = (string)$v;
    if (!mb_check_encoding($s, 'UTF-8')) { $warn++; $s = (string)iconv('UTF-8', 'UTF-8//IGNORE', $s); }
    return $s;
}

$mysqlTables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
$liteTables = $lite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
$seqs = [];
try { foreach ($lite->query('SELECT name, seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_NUM) as [$n, $s]) $seqs[$n] = (int)$s; } catch (Throwable $e) {}

$report = []; $problems = 0;
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($mysqlTables as $t) {
    if (!in_array($t, $liteTables, true)) { $report[] = [$t, '-', '-', 'غير موجود بقاعدة بايثون (يُترك فارغاً)']; continue; }
    $cols = my_columns($pdo, $t);
    $liteInfo = $lite->query("PRAGMA table_info(`$t`)")->fetchAll(PDO::FETCH_ASSOC);
    $liteCols = array_column($liteInfo, 'name');
    // أعمدة موجودة بقاعدة بايثون القديمة ولا يعرفها المخطط الجديد (مثل users.role بمخططات أقدم): نضيفها بدل إسقاط بياناتها
    foreach ($liteInfo as $ci) {
        if (isset($cols[$ci['name']]) || strncmp($ci['name'], '__fc_', 5) === 0) continue;
        $decl = strtoupper((string)$ci['type']);
        $mt = strpos($decl, 'INT') !== false ? 'INT' : (preg_match('/REAL|FLOA|DOUB/', $decl) ? 'DOUBLE' : 'LONGTEXT');
        $dflt = (string)($ci['dflt_value'] ?? '');
        $defSql = '';
        if ($dflt !== '' && preg_match("/^('([^']|'')*'|-?\\d+(\\.\\d+)?)$/", $dflt) && $mt !== 'LONGTEXT') $defSql = ' DEFAULT ' . $dflt;
        elseif ($dflt !== '' && preg_match("/^('([^']|'')*'|-?\\d+(\\.\\d+)?)$/", $dflt)) $defSql = ' DEFAULT (' . $dflt . ')';
        if ($dry) { out("  [dry-run] سيُضاف العمود $t.{$ci['name']} ($mt)"); continue; }
        $pdo->exec("ALTER TABLE `$t` ADD COLUMN `{$ci['name']}` $mt NULL$defSql");
        out("  أُضيف عمود غير موجود بالمخطط: $t.{$ci['name']} ($mt)");
        $cols[$ci['name']] = strtolower($mt);
    }
    $common = array_values(array_filter(array_keys($cols), fn($c) => in_array($c, $liteCols, true)));
    $srcCount = (int)$lite->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $dstBefore = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    if ($dstBefore > 0 && !$dry) {
        if (!isset($o['truncate'])) { fwrite(STDERR, "الجدول $t بـ MySQL يحوي $dstBefore صفاً. استعمل --truncate لتفريغه أولاً.\n"); exit(1); }
        $pdo->exec("DELETE FROM `$t`");
    }
    if ($dry) { $report[] = [$t, $srcCount, '-', 'dry-run']; continue; }

    $colList = '`' . implode('`,`', $common) . '`';
    $sel = $lite->query("SELECT $colList FROM `$t` ORDER BY rowid");
    $warn = 0; $done = 0;
    $batch = []; $perRow = count($common); $maxRows = max(1, (int)floor(60000 / max(1, $perRow)));
    $flush = function () use (&$batch, $pdo, $t, $colList, $perRow, &$done) {
        if (!$batch) return;
        $ph = '(' . implode(',', array_fill(0, $perRow, '?')) . ')';
        $st = $pdo->prepare("INSERT INTO `$t` ($colList) VALUES " . implode(',', array_fill(0, count($batch), $ph)));
        $flat = [];
        foreach ($batch as $row) foreach ($row as $v) $flat[] = $v;
        $i = 1;
        foreach ($flat as $v) {
            if ($v === null) $st->bindValue($i, null, PDO::PARAM_NULL);
            elseif (is_int($v)) $st->bindValue($i, $v, PDO::PARAM_INT);
            elseif (is_float($v)) $st->bindValue($i, var_export($v, true), PDO::PARAM_STR);
            else $st->bindValue($i, $v, PDO::PARAM_STR);
            $i++;
        }
        $st->execute();
        $done += count($batch);
        $batch = [];
    };
    $pdo->beginTransaction();
    try {
        while (($r = $sel->fetch(PDO::FETCH_NUM)) !== false) {
            $row = [];
            foreach ($common as $i => $c) $row[] = conv($r[$i], $cols[$c], $warn);
            $batch[] = $row;
            if (count($batch) >= $maxRows) $flush();
        }
        $flush();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "فشل نسخ الجدول $t: " . $e->getMessage() . "\n");
        exit(1);
    }
    // لا إعادة استعمال لمعرّفات صفوف حُذفت بالأصل (AUTOINCREMENT): نضبط العدّاد على sqlite_sequence
    if (isset($seqs[$t]) && in_array('id', $common, true)) {
        $max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM `$t`")->fetchColumn();
        $next = max($seqs[$t], $max) + 1;
        $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = $next");
    }
    $dstCount = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $note = $warn ? "$warn قيمة تم تحويلها/إهمالها" : 'OK';
    if ($srcCount !== $dstCount) { $note = "عدم تطابق!"; $problems++; }
    $report[] = [$t, $srcCount, $dstCount, $note];
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
foreach ($liteTables as $t) if (!in_array($t, $mysqlTables, true)) $report[] = [$t, '?', '-', 'غير موجود بمخطط MySQL (تجاهل)'];

out(sprintf("%-24s %8s %8s  %s", 'الجدول', 'SQLite', 'MySQL', 'ملاحظة'));
foreach ($report as [$t, $a, $b, $n]) out(sprintf("%-24s %8s %8s  %s", $t, $a, $b, $n));

// ---- الملفات ----
if (!isset($o['no-files'])) {
    $copy = function (string $src, string $dst) use (&$copy, $dry): int {
        $n = 0;
        if (is_dir($src)) {
            if (!$dry && !is_dir($dst)) @mkdir($dst, 0775, true);
            foreach (scandir($src) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                $n += $copy("$src/$f", "$dst/$f");
            }
        } elseif (is_file($src)) {
            if (!$dry) { @mkdir(dirname($dst), 0775, true); copy($src, $dst); }
            $n++;
        }
        return $n;
    };
    out("\nالملفات من $filesRoot إلى " . DATA_DIR . ':');
    foreach (['fc_voices', 'fc_ticket_images', 'fc_avatars', 'fc_pm_images', 'fc_pwa_icon', 'fc_logo'] as $d) {
        if (is_dir("$filesRoot/$d")) out(sprintf("  %-18s %d ملف", $d, $copy("$filesRoot/$d", DATA_DIR . "/$d")));
    }
    foreach (['fc_vapid.json', 'fc_push_subs.json', 'fc_services_cache.json'] as $f) {
        if (is_file("$filesRoot/$f")) { out("  $f"); $copy("$filesRoot/$f", DATA_DIR . "/$f"); }
    }
}
out($dry ? "\n(dry-run: لم يُكتب شيء)" : ($problems ? "\nانتهى مع $problems جدول غير متطابق — راجع التقرير" : "\nتم النقل بنجاح. راجع README: SECRET_KEY، الكرون، ثم حوّل الدومين."));
exit($problems ? 2 : 0);
