<?php
/**
 * تصدير/استيراد قاعدة البيانات وملفات JSON عبر لوحة الأدمن.
 *
 * مصادر الاستيراد المدعومة (نسخ تصل من بوت تلغرام أو تُحمَّل من الموقع):
 *   • ZIP من هذا الموقع (fastcrand_cloud_backup_*.zip: fastcrand.sql + ملفات JSON)
 *   • ZIP من موقع بايثون القديم (fastcrand.db بصيغة SQLite + ملفات JSON)
 *   • ملف .sql مفرد (نسخة هذا الموقع أو تصدير phpMyAdmin/mysqldump) — يُفحص بصرامة: INSERT بقيم حرفية فقط
 *   • ملف SQLite .db مفرد (قاعدة موقع بايثون)
 *   • ملفات JSON مفردة: fc_vapid.json / fc_push_subs.json / fc_services_cache.json
 *
 * سلامة الاستيراد:
 *   1) لا يُنفَّذ أي CREATE/DROP/ALTER من الملف المرفوع؛ نستخرج منه صفوف INSERT فقط إلى جداول مؤقتة __imp_<جدول>
 *      منشأة بـ CREATE TABLE LIKE من بنية الجداول الحالية (بنية الموقع تبقى هي المرجع)، والجداول المسموحة قائمة ثابتة.
 *   2) نسخة أمان تلقائية قبل أي تغيير (backup_pre_import_*) + قفل + وضع صيانة.
 *   3) التبديل بجملة RENAME TABLE واحدة (ذرّية): إما كل الجداول أو لا شيء؛ فشل أي خطوة قبلها لا يمسّ البيانات الحالية.
 *   4) بعد النجاح: إلغاء كل الجلسات (قد يختلف المستخدمون)، مسح كاش الخدمات، إعادة ملء المعرّفات.
 */

class TransferError extends RuntimeException {}

const TRANSFER_TABLES = [
    'users', 'pending_email_changes', 'orders', 'payment_methods', 'tickets', 'ticket_messages', 'recharge_requests',
    'asiacell_flows', 'ai_conversations', 'ai_messages', 'notifications', 'referrals', 'referral_history', 'announcements',
    'custom_services', 'custom_orders', 'favorites', 'service_filters', 'site_settings', 'coupons', 'coupon_uses', 'reviews',
    'providers', 'levels', 'master_key_log',
];
const TRANSFER_JSON_FILES = ['fc_vapid.json' => 'vapid', 'fc_push_subs.json' => 'push_subs', 'fc_services_cache.json' => 'services_cache'];
const TRANSFER_MAX_ZIP_ENTRIES = 200;
const TRANSFER_MAX_ENTRY_BYTES = 1073741824;       // 1GB لكل ملف بعد فك الضغط
const TRANSFER_MAX_TOTAL_BYTES = 2147483648;       // 2GB للمجموع
const TRANSFER_JOB_TTL = 7200;

/* ------------------------------ مجلدات المهام ------------------------------ */

function transfer_dir(): string {
    $d = DATA_DIR . '/cache/transfer';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function transfer_job_dir(string $token): string {
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) throw new TransferError('رمز الجلسة غير صالح');
    return transfer_dir() . '/' . $token;
}
function transfer_cleanup_old(): void {
    foreach (glob(transfer_dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (@filemtime($d) < time() - TRANSFER_JOB_TTL) _rrmdir($d);
    }
    foreach (glob(transfer_dir() . '/export_*') ?: [] as $f) { if (is_file($f) && @filemtime($f) < time() - 3600) @unlink($f); }
}
function transfer_read_meta(string $token, ?int $uid = null): array {
    $dir = transfer_job_dir($token);
    $m = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
    if (!is_array($m)) throw new TransferError('انتهت صلاحية الملف المرفوع، ارفعه من جديد');
    if ($uid !== null && (int)($m['uid'] ?? 0) !== $uid) throw new TransferError('هذا الملف ليس من جلستك');
    return $m;
}

/* ------------------------------ أدوات عامة ------------------------------ */

function tf_human_bytes(int $n): string {
    if ($n >= 1048576) return round($n / 1048576, 1) . ' MB';
    if ($n >= 1024) return round($n / 1024, 1) . ' KB';
    return $n . ' B';
}
function tf_q(string $ident): string { return '`' . str_replace('`', '``', $ident) . '`'; }

/** أعمدة جدول MySQL غير المولَّدة: [name => data_type] */
function tf_my_columns(PDO $pdo, string $t): array {
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
function tf_conv($v, string $type, int &$warn) {
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
    if (is_float($v)) return py_sqlite_float_text($v);
    if (is_int($v)) return (string)$v;
    $s = (string)$v;
    if (!mb_check_encoding($s, 'UTF-8')) { $warn++; $s = (string)iconv('UTF-8', 'UTF-8//IGNORE', $s); }
    return $s;
}

/* ------------------------------ مدقّق صفوف SQL: قيم حرفية فقط ------------------------------ */

/**
 * يتحقق أن نص ما بعد VALUES هو (صف),(صف)... بقيم حرفية فقط (نص، رقم، NULL، TRUE/FALSE، hex/binary).
 * لا دوال ولا استعلامات فرعية ولا تعابير. يرجع عدد الصفوف، أو يرمي TransferError.
 * يُرجع أيضاً عدد القيم بالصف الأول عبر $width.
 */
function tf_validate_values(string $v, ?int &$width = null): int {
    $n = strlen($v); $i = 0; $rows = 0; $width = null;
    $ws = function () use (&$i, $n, $v) { while ($i < $n && ctype_space($v[$i])) $i++; };
    $ws();
    while (true) {
        if ($i >= $n || $v[$i] !== '(') throw new TransferError('صيغة VALUES غير صالحة');
        $i++; $cols = 0;
        while (true) {
            $ws();
            if ($i >= $n) throw new TransferError('صف غير مكتمل');
            $c = $v[$i];
            if ($c === "'" || $c === '"') {                                  // نص
                $q = $c; $i++;
                while (true) {
                    $j = $i + strcspn($v, $q . '\\', $i);
                    if ($j >= $n) throw new TransferError('نص غير مغلق');
                    if ($v[$j] === '\\') { $i = $j + 2; continue; }
                    if ($j + 1 < $n && $v[$j + 1] === $q) { $i = $j + 2; continue; }
                    $i = $j + 1; break;
                }
            } elseif (preg_match('/\G0x[0-9a-fA-F]+/A', $v, $m, 0, $i) || preg_match('/\G[xX]\'[0-9a-fA-F]*\'/A', $v, $m, 0, $i) || preg_match('/\G[bB]\'[01]*\'/A', $v, $m, 0, $i)) {
                $i += strlen($m[0]);                                         // hex / binary (قبل الأرقام: 0x1F يبدأ بـ 0)
            } elseif (preg_match('/\G(?:[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/A', $v, $m, 0, $i)) {   // رقم
                $i += strlen($m[0]);
            } elseif (preg_match('/\G(?:NULL|TRUE|FALSE)(?![A-Za-z0-9_])/Ai', $v, $m, 0, $i)) {
                $i += strlen($m[0]);
            } elseif (preg_match('/\G_(?:binary|utf8mb4|utf8)\s*(?=[\'"xX0])/Ai', $v, $m, 0, $i)) {          // مُعرِّف ترميز قبل نص/hex
                $i += strlen($m[0]);
                continue;
            } else {
                throw new TransferError('قيمة غير مسموحة داخل VALUES قرب: ' . mb_substr(substr($v, $i, 40), 0, 40, 'UTF-8'));
            }
            $cols++;
            $ws();
            if ($i >= $n) throw new TransferError('صف غير مغلق');
            if ($v[$i] === ',') { $i++; continue; }
            if ($v[$i] === ')') { $i++; break; }
            throw new TransferError('رمز غير متوقع داخل الصف');
        }
        $rows++;
        if ($width === null) $width = $cols; elseif ($width !== $cols) throw new TransferError('عدد القيم يختلف بين الصفوف');
        $ws();
        if ($i >= $n) return $rows;
        if ($v[$i] !== ',') throw new TransferError('نص زائد بعد الصفوف (غير مسموح)');
        $i++; $ws();
    }
}

/** أسماء أعمدة من CREATE TABLE (لدعم mysqldump الذي يكتب INSERT بلا قائمة أعمدة) */
function tf_create_table_columns(string $create): array {
    $p = strpos($create, '(');
    if ($p === false) return [];
    $depth = 0; $cur = ''; $items = []; $q = null;
    for ($i = $p, $n = strlen($create); $i < $n; $i++) {
        $c = $create[$i];
        if ($q !== null) { $cur .= $c; if ($c === '\\') { $cur .= $create[++$i] ?? ''; } elseif ($c === $q) $q = null; continue; }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $cur .= $c; continue; }
        if ($c === '(') { $depth++; if ($depth === 1) continue; }
        if ($c === ')') { $depth--; if ($depth === 0) { $items[] = $cur; break; } }
        if ($c === ',' && $depth === 1) { $items[] = $cur; $cur = ''; continue; }
        $cur .= $c;
    }
    $cols = [];
    foreach ($items as $it) {
        if (preg_match('/^\s*`([^`]+)`/', $it, $m)) $cols[] = $m[1];
    }
    return $cols;
}

/**
 * يقرأ ملف SQL ويفحصه جملةً جملة. $handler (اختياري) يُستدعى لكل INSERT مقبول: function(string $table, array $cols, string $valuesText, int $rows)
 * @return array ['tables'=>[t=>['rows'=>n]], 'ignored'=>int, 'unknown_tables'=>[...], 'statements'=>int]
 * @throws TransferError عند أي جملة غير مسموحة
 */
function tf_scan_sql_stream($fp, ?callable $handler = null, ?PDO $pdo = null): array {
    $tables = []; $unknown = []; $ignored = 0; $stmts = 0; $dumpCols = [];
    $known = array_flip(TRANSFER_TABLES);
    $liveCols = [];
    SqlSplitter::eachStatementFromStream($fp, function ($st) use (&$tables, &$unknown, &$ignored, &$stmts, &$dumpCols, &$liveCols, $known, $handler, $pdo) {
        $stmts++;
        // 1) جمل تُتجاهل بأمان (لا تُنفَّذ)
        if (preg_match('/^(SET|USE|LOCK\s+TABLES|UNLOCK\s+TABLES|COMMIT|START\s+TRANSACTION|BEGIN|DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+DATABASE|DROP\s+DATABASE\s+IF\s+EXISTS)\b/i', $st)) {
            // DROP DATABASE غير مسموح حتى كتجاهل: خطير ومشبوه
            if (preg_match('/^DROP\s+DATABASE/i', $st)) throw new TransferError('الملف يحوي DROP DATABASE — مرفوض');
            $ignored++; return;
        }
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $st, $m)) {     // بنية الجدول: نقرأ أسماء الأعمدة فقط
            $dumpCols[strtolower($m[1])] = tf_create_table_columns($st);
            $ignored++; return;
        }
        // 2) INSERT / REPLACE بقيم حرفية
        if (preg_match('/^(INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_]+)`?\s*(?:\(([^()]*)\))?\s*VALUES\s*/is', $st, $m)) {
            $t = strtolower($m[2]);
            $valuesText = substr($st, strlen($m[0]));
            if (!isset($known[$t])) { $unknown[$t] = true; return; }               // جدول لا يخص الموقع: يُهمَل بالكامل (لا يُنفَّذ)
            $rows = tf_validate_values($valuesText, $width);
            if (isset($m[3]) && $m[3] !== '') {
                $cols = [];
                foreach (explode(',', $m[3]) as $c) {
                    $c = trim($c);
                    if (!preg_match('/^`?([A-Za-z0-9_]+)`?$/', $c, $cm)) throw new TransferError('اسم عمود غير صالح في INSERT: ' . $c);
                    $cols[] = $cm[1];
                }
            } else {
                $cols = $dumpCols[$t] ?? null;
                if ($cols === null || !$cols) {                                   // dump بلا CREATE: نستعمل ترتيب الأعمدة الحالي
                    if ($pdo === null) { $cols = []; }
                    else {
                        $liveCols[$t] ??= array_values(array_filter(array_keys(tf_my_columns($pdo, $t)), fn($c) => strncmp($c, '__fc_', 5) !== 0));
                        $cols = $liveCols[$t];
                    }
                }
            }
            if ($cols && count($cols) !== $width) throw new TransferError("عدد قيم الصف ($width) لا يطابق عدد أعمدة الجدول $t (" . count($cols) . ')');
            $tables[$t]['rows'] = ($tables[$t]['rows'] ?? 0) + $rows;
            if ($handler) $handler($t, $cols, $valuesText, $rows, strtoupper($m[1]));
            return;
        }
        throw new TransferError('جملة SQL غير مسموحة في ملف الاستيراد: ' . mb_substr(preg_replace('/\s+/', ' ', $st), 0, 60, 'UTF-8'));
    });
    return ['tables' => $tables, 'ignored' => $ignored, 'unknown_tables' => array_keys($unknown), 'statements' => $stmts];
}

/* ------------------------------ تحليل الملف المرفوع ------------------------------ */

function tf_sniff(string $path): string {
    $fp = @fopen($path, 'rb'); if (!$fp) throw new TransferError('تعذّر قراءة الملف');
    $head = (string)fread($fp, 64); fclose($fp);
    if (strncmp($head, "PK\x03\x04", 4) === 0 || strncmp($head, "PK\x05\x06", 4) === 0) return 'zip';
    if (strncmp($head, "SQLite format 3\0", 16) === 0) return 'sqlite';
    $t = ltrim($head, "\xEF\xBB\xBF \t\r\n");
    if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) return 'json';
    return 'text';
}

/** يتحقق من شكل ملف JSON ويرجع نوعه (vapid|push_subs|services_cache) أو null */
function tf_classify_json($d): ?string {
    if (!is_array($d)) return null;
    if (isset($d['private'], $d['public']) && is_string($d['private']) && is_string($d['public'])) {
        if (strlen($d['private']) > 8192 || strlen($d['public']) > 512 || strpos($d['private'], 'PRIVATE KEY') === false) return null;
        return 'vapid';
    }
    if (array_key_exists('data', $d) && is_array($d['data']) && (is_int($d['time'] ?? 0) || is_float($d['time'] ?? 0)) && count($d) <= 4) return 'services_cache';
    if ($d === [] || !is_list_array($d)) {                                      // {user_id: [subs]} أو {} فارغ
        foreach ($d as $uid => $subs) {
            if (!is_numeric($uid) || !is_array($subs)) return null;
            $list = is_list_array($subs) ? $subs : [$subs];                     // صيغة قديمة: اشتراك واحد
            foreach ($list as $s) {
                if (!is_array($s) || !isset($s['endpoint']) || !is_string($s['endpoint'])) return null;
                if (isset($s['keys']) && !is_array($s['keys'])) return null;
            }
        }
        return 'push_subs';
    }
    return null;
}

/** يحلّل الملف المرفوع ويحفظ مكوّناته ضمن مجلد المهمة. يرجع meta */
function transfer_analyze(string $uploadedPath, string $origName, int $uid): array {
    transfer_cleanup_old();
    $token = bin2hex(random_bytes(16));
    $dir = transfer_job_dir($token);
    if (!@mkdir($dir, 0775, true)) throw new TransferError('تعذّر إنشاء مجلد مؤقت');
    $meta = ['token' => $token, 'uid' => $uid, 'created' => time(), 'source' => mb_substr(basename($origName), 0, 120, 'UTF-8'), 'components' => [], 'warnings' => []];
    try {
        $kind = tf_sniff($uploadedPath);
        if ($kind === 'zip') tf_prepare_zip($uploadedPath, $dir, $meta);
        else {
            $dest = $dir . '/c_main';
            if (!@rename($uploadedPath, $dest) && !@copy($uploadedPath, $dest)) throw new TransferError('تعذّر حفظ الملف');
            tf_register_component($dest, $kind, $origName, $dir, $meta);
        }
        if (!$meta['components']) throw new TransferError('لم أجد داخل الملف شيئاً قابلاً للاستيراد (قاعدة SQL/SQLite أو ملفات JSON المعروفة)');
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
        return $meta;
    } catch (Throwable $e) {
        _rrmdir($dir);
        throw $e instanceof TransferError ? $e : new TransferError('تعذّر تحليل الملف: ' . $e->getMessage(), 0, $e);
    }
}

/** يسجّل مكوّناً (ملف محفوظ) بعد فحصه: كل مكوّن يُفحص حسب نوعه */
function tf_register_component(string $file, string $kind, string $name, string $dir, array &$meta): void {
    $size = (int)filesize($file);
    $base = mb_substr(basename($name), 0, 80, 'UTF-8');
    if ($kind === 'sqlite') {
        if (!extension_loaded('pdo_sqlite')) throw new TransferError('هذا ملف قاعدة SQLite (من موقع بايثون) لكن امتداد pdo_sqlite غير مفعّل على استضافتك. فعّله من لوحة الاستضافة، أو حوّل القاعدة إلى SQL.');
        $lite = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $have = $lite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        $tables = []; $unknown = [];
        foreach ($have as $t) {
            if (in_array($t, TRANSFER_TABLES, true)) $tables[$t] = ['rows' => (int)$lite->query('SELECT COUNT(*) FROM ' . tf_q($t))->fetchColumn()];
            else $unknown[] = $t;
        }
        if (!isset($tables['users']) && !isset($tables['site_settings'])) throw new TransferError('ملف SQLite هذا ليس قاعدة موقع fastcrand (لا يحوي جدولي users/site_settings)');
        $meta['components'][] = ['type' => 'sqlite', 'file' => basename($file), 'name' => $base, 'size' => $size, 'tables' => $tables, 'unknown_tables' => $unknown];
        return;
    }
    if ($kind === 'json') {
        $raw = (string)file_get_contents($file, false, null, 0, 20971521);
        if (strlen($raw) > 20971520) throw new TransferError('ملف JSON أكبر من 20MB');
        $d = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true);
        $type = json_last_error() === JSON_ERROR_NONE ? tf_classify_json($d) : null;
        if ($type === null) throw new TransferError('ملف JSON غير معروف: ليس fc_vapid.json ولا fc_push_subs.json ولا fc_services_cache.json');
        $info = $type === 'push_subs' ? ['users' => count($d), 'subscriptions' => array_sum(array_map(fn($x) => is_array($x) && is_list_array($x) ? count($x) : 1, $d))]
              : ($type === 'services_cache' ? ['services' => count($d['data'])] : []);
        $meta['components'][] = ['type' => $type, 'file' => basename($file), 'name' => $base, 'size' => $size] + $info;
        return;
    }
    // نص: يجب أن يكون SQL
    $fp = fopen($file, 'rb');
    try { $scan = tf_scan_sql_stream($fp); }
    finally { fclose($fp); }
    if (!$scan['tables']) throw new TransferError('ملف SQL لا يحوي أي INSERT لجداول الموقع المعروفة');
    $meta['components'][] = ['type' => 'sql', 'file' => basename($file), 'name' => $base, 'size' => $size, 'tables' => $scan['tables'], 'unknown_tables' => $scan['unknown_tables'], 'ignored' => $scan['ignored']];
}

/** ZIP: نقرأ الأسماء المعروفة فقط من الأرشيف (لا استخراج بمسارات الأرشيف أبداً) بحدود ضد قنابل الضغط */
function tf_prepare_zip(string $zipPath, string $dir, array &$meta): void {
    if (!class_exists('ZipArchive')) throw new TransferError('امتداد zip غير مفعّل بـ PHP');
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) throw new TransferError('ملف ZIP تالف أو غير صالح');
    try {
        if ($z->numFiles > TRANSFER_MAX_ZIP_ENTRIES) throw new TransferError('عدد الملفات داخل ZIP كبير جداً');
        $total = 0; $n = 0; $seen = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            if ($st === false) continue;
            $ename = (string)$st['name'];
            if (substr($ename, -1) === '/') continue;                                  // مجلد
            $b = basename(str_replace('\\', '/', $ename));
            $lb = strtolower($b);
            $isJson = isset(TRANSFER_JSON_FILES[$lb]);
            $isDb = (bool)preg_match('/\.(sql|db|sqlite|sqlite3)$/', $lb);
            if (!$isJson && !$isDb) { $meta['warnings'][] = 'تم تجاهل الملف: ' . mb_substr($b, 0, 60, 'UTF-8'); continue; }
            if (isset($seen[$lb])) { $meta['warnings'][] = 'اسم مكرر داخل ZIP تم تجاهله: ' . mb_substr($b, 0, 60, 'UTF-8'); continue; }
            $seen[$lb] = true;
            $size = (int)$st['size']; $csize = max(1, (int)$st['comp_size']);
            if ($size > TRANSFER_MAX_ENTRY_BYTES) throw new TransferError('ملف داخل ZIP أكبر من الحد المسموح');
            if ($size > 5 * 1048576 && $size / $csize > 1000) throw new TransferError('نسبة ضغط مشبوهة (احتمال قنبلة ضغط)');
            $stream = $z->getStream($ename);
            if (!$stream) { $meta['warnings'][] = 'تعذّرت قراءة: ' . mb_substr($b, 0, 60, 'UTF-8'); continue; }
            $dest = $dir . '/c_' . (++$n);
            $out = fopen($dest, 'wb'); $written = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 262144);
                if ($chunk === false || $chunk === '') break;
                $written += strlen($chunk); $total += strlen($chunk);
                if ($written > TRANSFER_MAX_ENTRY_BYTES || $total > TRANSFER_MAX_TOTAL_BYTES) { fclose($out); fclose($stream); throw new TransferError('حجم الملفات بعد فك الضغط أكبر من الحد المسموح'); }
                fwrite($out, $chunk);
            }
            fclose($out); fclose($stream);
            $kind = $isJson ? 'json' : tf_sniff($dest);
            if ($kind === 'zip') { @unlink($dest); $meta['warnings'][] = 'تم تجاهل ZIP متداخل: ' . mb_substr($b, 0, 60, 'UTF-8'); continue; }
            tf_register_component($dest, $kind, $b, $dir, $meta);
        }
    } finally { $z->close(); }
}

/* ------------------------------ التطبيق ------------------------------ */

function tf_component(array $meta, string $type): ?array {
    foreach ($meta['components'] as $c) if ($c['type'] === $type) return $c;
    return null;
}

/** ينظّف أي جداول مؤقتة قديمة (من استيراد سابق فشل) */
function tf_drop_leftovers(PDO $pdo): void {
    foreach (TRANSFER_TABLES as $t) {
        foreach (['__imp_', '__old_'] as $p) $pdo->exec('DROP TABLE IF EXISTS ' . tf_q($p . $t));
    }
}

/**
 * ينفّذ الاستيراد. يرجع تقريراً.
 * @param array $opts ['restore_db'=>bool (افتراضي true), 'restore_json'=>bool (افتراضي true), 'prefer'=>'sql'|'sqlite']
 */
function transfer_apply(string $token, array $opts = []): array {
    $meta = transfer_read_meta($token);
    $dir = transfer_job_dir($token);
    $restoreDb = $opts['restore_db'] ?? true;
    $restoreJson = $opts['restore_json'] ?? true;
    $sqlC = tf_component($meta, 'sql'); $liteC = tf_component($meta, 'sqlite');
    $dbC = null;
    if ($restoreDb) {
        $dbC = ($opts['prefer'] ?? '') === 'sql' ? ($sqlC ?: $liteC) : ($liteC ?: $sqlC);
        if (($opts['prefer'] ?? '') === 'sqlite') $dbC = $liteC ?: $sqlC;
    }
    $jsonCs = $restoreJson ? array_values(array_filter($meta['components'], fn($c) => isset(array_flip(array_values(TRANSFER_JSON_FILES))[$c['type']]))) : [];
    if ($dbC === null && !$jsonCs) throw new TransferError('لا يوجد ما يُستورد بالخيارات المحددة');

    @set_time_limit(0);
    @ini_set('memory_limit', '1024M');
    $lock = @fopen(transfer_dir() . '/apply.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new TransferError('عملية استيراد أخرى جارية الآن، انتظر انتهاءها');
    $report = ['steps' => [], 'tables' => [], 'warnings' => $meta['warnings'] ?? [], 'json' => [], 'safety_backup' => null];
    $pdo = null; $swapped = false; $succeeded = false;
    try {
        maintenance_start(900);
        $report['steps'][] = 'بدأ وضع الصيانة';

        // 1) نسخة أمان قبل أي تغيير
        $bk = create_backup('pre_import');
        if ($bk === null) throw new TransferError('تعذّر إنشاء نسخة الأمان قبل الاستيراد — أُلغيت العملية ولم يتغيّر شيء');
        foreach (['vapid_file', 'push_subs_file', 'svc_cache_file'] as $fn) {
            $f = $fn();
            if (is_file($f)) @copy($f, BACKUP_DIR . '/' . $bk . '/' . basename($f));
        }
        $report['safety_backup'] = $bk;
        $report['steps'][] = "نسخة أمان: $bk";

        // 2) قاعدة البيانات
        if ($dbC !== null) {
            $pdo = get_db_raw()->pdo;
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            tf_drop_leftovers($pdo);
            $live = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            $live = array_map('strtolower', $live);
            $targets = array_values(array_filter(TRANSFER_TABLES, fn($t) => in_array($t, $live, true)));
            if (!in_array('users', $targets, true)) throw new TransferError('جداول الموقع غير موجودة بالقاعدة الحالية (استورد sql/database.sql أولاً)');
            $loaded = [];
            try {
                foreach ($targets as $t) $pdo->exec('CREATE TABLE ' . tf_q('__imp_' . $t) . ' LIKE ' . tf_q($t));
                $path = $dir . '/' . $dbC['file'];
                $loaded = $dbC['type'] === 'sqlite' ? tf_load_sqlite($pdo, $path, $targets, $report) : tf_load_sql($pdo, $path, $targets, $report);
                $report['steps'][] = 'حُمّلت الصفوف إلى جداول مؤقتة';

                // فحوص سلامة قبل التبديل
                if (isset($loaded['users'])) {
                    $admins = (int)$pdo->query('SELECT COUNT(*) FROM ' . tf_q('__imp_users') . ' WHERE is_admin=1')->fetchColumn();
                    if ($admins < 1) throw new TransferError('النسخة لا تحتوي أي حساب أدمن — رُفض الاستيراد حتى لا تفقد الدخول للوحة الإدارة');
                }
                if (!$loaded) throw new TransferError('لم يُحمَّل أي صف من الملف');

                // 3) التبديل الذرّي
                $parts = [];
                foreach (array_keys($loaded) as $t) { $parts[] = tf_q($t) . ' TO ' . tf_q('__old_' . $t); $parts[] = tf_q('__imp_' . $t) . ' TO ' . tf_q($t); }
                $pdo->exec('RENAME TABLE ' . implode(', ', $parts));
                $swapped = true;
                $report['steps'][] = 'تم التبديل الذرّي لـ ' . count($loaded) . ' جدولاً';
            } finally {
                // تنظيف: بعد النجاح نحذف القديمة؛ عند الفشل نحذف المؤقتة
                foreach ($targets as $t) {
                    try { $pdo->exec('DROP TABLE IF EXISTS ' . tf_q($swapped ? '__old_' . $t : '__imp_' . $t)); } catch (Throwable $e) {}
                    if ($swapped) { try { $pdo->exec('DROP TABLE IF EXISTS ' . tf_q('__imp_' . $t)); } catch (Throwable $e) {} }
                }
                try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e) {}
            }
            foreach ($loaded as $t => $n) $report['tables'][] = ['table' => $t, 'rows' => $n];
            foreach ($targets as $t) if (!isset($loaded[$t])) $report['tables'][] = ['table' => $t, 'rows' => null, 'note' => 'غير موجود بالنسخة — بقي كما هو'];
            Conn::shutdown();   // اتصال المشترك قد يحمل بيانات مخزّنة بالذاكرة
            try { clear_services_cache(); } catch (Throwable $e) {}
            try { _bump_svc_version(); } catch (Throwable $e) {}
            try { _backfill_display_ids(); _backfill_public_ids(); } catch (Throwable $e) { $report['warnings'][] = 'تعذّر إعادة ملء المعرّفات: ' . $e->getMessage(); }
            $report['steps'][] = 'انتهى استيراد قاعدة البيانات';
        }

        // 4) ملفات JSON
        foreach ($jsonCs as $c) {
            $src = $dir . '/' . $c['file'];
            $dst = ['vapid' => vapid_file(), 'push_subs' => push_subs_file(), 'services_cache' => svc_cache_file()][$c['type']];
            $tmp = $dst . '.imp' . bin2hex(random_bytes(3));
            if (@copy($src, $tmp) && @rename($tmp, $dst)) $report['json'][] = ['file' => basename($dst), 'ok' => true];
            else { @unlink($tmp); $report['json'][] = ['file' => basename($dst), 'ok' => false]; $report['warnings'][] = 'تعذّر كتابة ' . basename($dst); }
        }
        if ($jsonCs) $report['steps'][] = 'استُعيدت ملفات JSON';

        // 5) إلغاء كل الجلسات (قد يختلف المستخدمون)
        if ($dbC !== null) { bump_session_epoch(); $report['steps'][] = 'أُلغيت كل الجلسات'; }
        $report['ok'] = true;
        $succeeded = true;
        return $report;
    } finally {
        maintenance_end();
        flock($lock, LOCK_UN); fclose($lock);
        if ($succeeded) _rrmdir($dir);       // عند الفشل يبقى الملف المرفوع (ساعتين) لإعادة المحاولة بعد المعالجة
    }
}

/** يحمّل صفوف ملف SQL إلى جداول __imp_ — يرجع [جدول => عدد الصفوف] */
function tf_load_sql(PDO $pdo, string $path, array $targets, array &$report): array {
    $targetSet = array_flip($targets);
    $colsOf = [];
    $counts = [];
    // وضع صارم: قيمة غير صالحة (نص بعمود رقمي، نص أطول من العمود...) ترفض الاستيراد بدل أن تُحوَّل/تُقتطع بصمت
    $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $fp = fopen($path, 'rb');
    try {
        tf_scan_sql_stream($fp, function ($t, $cols, $valuesText, $rows, $verb) use ($pdo, $targetSet, &$colsOf, &$counts, &$report) {
            if (!isset($targetSet[$t])) { return; }
            $colsOf[$t] ??= tf_my_columns($pdo, '__imp_' . $t);
            if (!$cols) throw new TransferError("لا يمكن تحديد أعمدة الجدول $t");
            foreach ($cols as $c) if (!isset($colsOf[$t][$c])) throw new TransferError("العمود «{$c}» غير موجود في جدول {$t} بهذا الموقع");
            $colList = implode(',', array_map('tf_q', $cols));
            $pdo->exec($verb . ' INTO ' . tf_q('__imp_' . $t) . ' (' . $colList . ') VALUES ' . $valuesText);
            $counts[$t] = ($counts[$t] ?? 0) + $rows;
        }, $pdo);
    } finally { fclose($fp); }
    return $counts;
}

/** يحمّل جداول SQLite إلى __imp_ (نفس منطق tools/migrate_from_sqlite.php) */
function tf_load_sqlite(PDO $pdo, string $path, array $targets, array &$report): array {
    if (!extension_loaded('pdo_sqlite')) throw new TransferError('امتداد pdo_sqlite غير مفعّل');
    $lite = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $liteTables = $lite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    $seqs = [];
    try { foreach ($lite->query('SELECT name, seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_NUM) as [$n, $s]) $seqs[$n] = (int)$s; } catch (Throwable $e) {}
    $counts = [];
    foreach ($targets as $t) {
        if (!in_array($t, $liteTables, true)) continue;
        $stg = '__imp_' . $t;
        $cols = tf_my_columns($pdo, $stg);
        $liteInfo = $lite->query('PRAGMA table_info(' . tf_q($t) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $liteCols = array_column($liteInfo, 'name');
        // أعمدة بقاعدة بايثون القديمة لا يعرفها المخطط الحالي: تُضاف للجدول المؤقت فتبقى بياناتها بعد التبديل
        foreach ($liteInfo as $ci) {
            if (isset($cols[$ci['name']]) || strncmp($ci['name'], '__fc_', 5) === 0 || !preg_match('/^[A-Za-z0-9_]+$/', (string)$ci['name'])) continue;
            $decl = strtoupper((string)$ci['type']);
            $mt = strpos($decl, 'INT') !== false ? 'INT' : (preg_match('/REAL|FLOA|DOUB/', $decl) ? 'DOUBLE' : 'LONGTEXT');
            $pdo->exec('ALTER TABLE ' . tf_q($stg) . ' ADD COLUMN ' . tf_q($ci['name']) . " $mt NULL");
            $cols[$ci['name']] = strtolower($mt);
            $report['warnings'][] = "أُضيف عمود غير موجود بالمخطط: $t.{$ci['name']}";
        }
        $common = array_values(array_filter(array_keys($cols), fn($c) => in_array($c, $liteCols, true)));
        if (!$common) continue;
        $colList = implode(',', array_map('tf_q', $common));
        $sel = $lite->query("SELECT $colList FROM " . tf_q($t) . ' ORDER BY rowid');
        $warn = 0; $done = 0; $batch = []; $perRow = count($common); $maxRows = max(1, (int)floor(60000 / max(1, $perRow)));
        $flush = function () use (&$batch, $pdo, $stg, $colList, $perRow, &$done) {
            if (!$batch) return;
            $ph = '(' . implode(',', array_fill(0, $perRow, '?')) . ')';
            $st = $pdo->prepare('INSERT INTO ' . tf_q($stg) . " ($colList) VALUES " . implode(',', array_fill(0, count($batch), $ph)));
            $i = 1;
            foreach ($batch as $row) foreach ($row as $v) {
                if ($v === null) $st->bindValue($i, null, PDO::PARAM_NULL);
                elseif (is_int($v)) $st->bindValue($i, $v, PDO::PARAM_INT);
                elseif (is_float($v)) $st->bindValue($i, var_export($v, true), PDO::PARAM_STR);
                else $st->bindValue($i, $v, PDO::PARAM_STR);
                $i++;
            }
            $st->execute();
            $done += count($batch); $batch = [];
        };
        while (($r = $sel->fetch(PDO::FETCH_NUM)) !== false) {
            $row = [];
            foreach ($common as $i => $c) $row[] = tf_conv($r[$i], $cols[$c], $warn);
            $batch[] = $row;
            if (count($batch) >= $maxRows) $flush();
        }
        $flush();
        if (isset($seqs[$t]) && in_array('id', $common, true)) {              // لا إعادة استعمال لمعرّفات صفوف حُذفت بالأصل
            $max = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM ' . tf_q($stg))->fetchColumn();
            $pdo->exec('ALTER TABLE ' . tf_q($stg) . ' AUTO_INCREMENT = ' . (max($seqs[$t], $max) + 1));
        }
        if ($warn) $report['warnings'][] = "$t: $warn قيمة تم تحويلها/إهمالها";
        $counts[$t] = $done;
    }
    return $counts;
}

/* ------------------------------ التصدير ------------------------------ */

/** يبني ملف التصدير في مجلد مؤقت ويرجع [المسار، اسم التنزيل، نوع MIME] */
function transfer_export(string $kind): array {
    transfer_cleanup_old();
    $ts = date('Ymd_His');
    $tmp = transfer_dir() . '/export_' . bin2hex(random_bytes(6));
    if ($kind === 'sql') {
        sql_dump_to_file($tmp);
        return [$tmp, "fastcrand_db_$ts.sql", 'application/sql'];
    }
    if (!class_exists('ZipArchive')) throw new TransferError('امتداد zip غير مفعّل بـ PHP');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new TransferError('تعذّر إنشاء ملف ZIP');
    $sqlTmp = null;
    try {
        if ($kind === 'full') {
            $sqlTmp = $tmp . '.sql';
            sql_dump_to_file($sqlTmp);
            $z->addFile($sqlTmp, BACKUP_SQL_NAME);
        }
        $n = 0;
        foreach ([vapid_file(), push_subs_file(), svc_cache_file()] as $f) {
            if (is_file($f)) { $z->addFile($f, basename($f)); $n++; }
        }
        if ($kind === 'json' && $n === 0) throw new TransferError('لا توجد ملفات JSON بعد (لم يُنشأ مفتاح VAPID ولا اشتراكات إشعارات)');
        $z->close();
    } catch (Throwable $e) { @$z->close(); @unlink($tmp); if ($sqlTmp) @unlink($sqlTmp); throw $e; }
    if ($sqlTmp) @unlink($sqlTmp);                  // الملف المؤقت بعد الإغلاق (الإضافة تقرأه عند close)
    return [$tmp, ($kind === 'full' ? "fastcrand_backup_$ts.zip" : "fastcrand_json_$ts.zip"), 'application/zip'];
}

/** يجهّز استيراداً من نسخة محفوظة على الخادم (data/fc_backups/backup_*): الـ SQL + ملفات JSON الموجودة بجانبه */
function transfer_analyze_local(string $name, int $uid): array {
    if (!preg_match('/^backup_[A-Za-z0-9_\-]{1,80}$/', $name)) throw new TransferError('اسم النسخة غير صالح');
    $src = BACKUP_DIR . '/' . $name;
    if (!is_dir($src) || is_link($src)) throw new TransferError('النسخة غير موجودة');
    transfer_cleanup_old();
    $token = bin2hex(random_bytes(16));
    $dir = transfer_job_dir($token);
    if (!@mkdir($dir, 0775, true)) throw new TransferError('تعذّر إنشاء مجلد مؤقت');
    $meta = ['token' => $token, 'uid' => $uid, 'created' => time(), 'source' => $name, 'components' => [], 'warnings' => []];
    try {
        $n = 0;
        foreach ([BACKUP_SQL_NAME, 'fc_vapid.json', 'fc_push_subs.json', 'fc_services_cache.json'] as $f) {
            $p = $src . '/' . $f;
            if (!is_file($p) || is_link($p)) continue;
            $dest = $dir . '/c_' . (++$n);
            if (!@copy($p, $dest)) throw new TransferError('تعذّر قراءة ملفات النسخة');
            tf_register_component($dest, substr($f, -5) === '.json' ? 'json' : 'text', $f, $dir, $meta);
        }
        if (!$meta['components']) throw new TransferError('النسخة لا تحوي ملفات قابلة للاستيراد');
        file_put_contents($dir . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
        return $meta;
    } catch (Throwable $e) {
        _rrmdir($dir);
        throw $e instanceof TransferError ? $e : new TransferError('تعذّر تحليل النسخة: ' . $e->getMessage(), 0, $e);
    }
}
