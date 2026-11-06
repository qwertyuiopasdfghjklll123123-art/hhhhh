<?php
/**
 * غلاف قاعدة البيانات: يحاكي واجهة sqlite3 المستعملة بنسخة بايثون الأصلية
 * (conn.execute / fetchone / fetchall / lastrowid / commit / sqlite3.Row)
 * فوق MySQL عبر PDO، مع ترجمة تلقائية للهجة SQLite الموجودة بالاستعلامات
 * (INSERT OR REPLACE, ON CONFLICT ... excluded.x, LIMIT -1, CAST AS REAL ...)
 * بحيث يبقى نقل الاستعلامات حرفياً قدر الإمكان.
 */

class IntegrityError extends RuntimeException {}   // مثل sqlite3.IntegrityError
class DBError extends RuntimeException {}

/** مثل sqlite3.Row: الوصول بالاسم $row['col'] أو بالفهرس $row[0]؛ عند تكرار الاسم يُرجَع أول عمود (كما بـ sqlite3) */
final class Row implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable {
    private array $a = [];   // اسم => قيمة (أول تطابق)
    private array $k;        // الأسماء بالترتيب
    private array $v;        // القيم بالترتيب
    public function __construct(array $names, array $values) {
        $this->k = $names;
        $this->v = $values;
        foreach ($names as $i => $n) {
            if (!array_key_exists($n, $this->a)) $this->a[$n] = $values[$i];
        }
    }
    /** بناء Row من مصفوفة associative (للاختبارات/الاستعمال الداخلي) */
    public static function fromAssoc(array $assoc): Row { return new Row(array_keys($assoc), array_values($assoc)); }
    public function offsetExists(mixed $o): bool {
        return is_int($o) ? array_key_exists($o, $this->v) : array_key_exists($o, $this->a);
    }
    public function offsetGet(mixed $o): mixed {
        if (is_int($o)) {
            if ($o < 0) $o += count($this->v);
            if (!array_key_exists($o, $this->v)) throw new PyIndexError('Index ' . $o . ' out of range');
            return $this->v[$o];
        }
        if (!array_key_exists($o, $this->a)) throw new PyIndexError('No item with that key');
        return $this->a[$o];
    }
    public function offsetSet(mixed $o, mixed $val): void { throw new PyTypeError('Row object does not support item assignment'); }
    public function offsetUnset(mixed $o): void { throw new PyTypeError('Row object does not support item deletion'); }
    public function count(): int { return count($this->v); }
    public function getIterator(): Iterator { return new ArrayIterator($this->v); }
    public function jsonSerialize(): mixed { return $this->a; }
    /** row.keys() */
    public function keys(): array { return $this->k; }
    /** dict(row) */
    public function toArray(): array { return $this->a; }
    public function has(string $k): bool { return array_key_exists($k, $this->a); }
    public function get(string $k, $default = null) { return $this->a[$k] ?? $default; }
}

/** dict(row) */
function row_dict($row): array {
    if ($row instanceof Row) return $row->toArray();
    return (array)$row;
}

/** [dict(r) for r in rows] */
function rows_dicts(array $rows): array {
    return array_map('row_dict', $rows);
}

final class Cursor implements IteratorAggregate {
    public int $lastrowid = 0;
    public int $rowcount = 0;
    private array $rows;
    private int $pos = 0;
    public function __construct(array $rows = [], int $lastrowid = 0, int $rowcount = 0) {
        $this->rows = $rows;
        $this->lastrowid = $lastrowid;
        $this->rowcount = $rowcount;
    }
    public function fetchone(): ?Row {
        return $this->pos < count($this->rows) ? $this->rows[$this->pos++] : null;
    }
    public function fetchall(): array {
        $r = array_slice($this->rows, $this->pos);
        $this->pos = count($this->rows);
        return $r;
    }
    public function getIterator(): Iterator { return new ArrayIterator($this->fetchall()); }
}

/**
 * ترجمة لهجة SQLite → MySQL. تعمل فقط خارج النصوص المقتبسة '...' حتى لا تُغيّر القيم.
 * @return string|null  null = جملة يجب تجاهلها (PRAGMA)
 */
function sql_translate(string $sql): ?string {
    static $cache = [];
    if (isset($cache[$sql])) return $cache[$sql];
    $trim = ltrim($sql);
    if (stripos($trim, 'PRAGMA') === 0) return $cache[$sql] = null;

    // قسّم إلى أجزاء: نصوص مقتبسة (تُترك كما هي) وأجزاء SQL (تُترجم)
    $parts = preg_split("/('(?:[^']|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $ignoreDup = false;
    foreach ($parts as $i => $seg) {
        if ($i % 2 === 1) { $out .= $seg; continue; } // نص مقتبس
        // INSERT OR REPLACE / IGNORE
        $seg = preg_replace('/\bINSERT\s+OR\s+REPLACE\s+INTO\b/i', 'REPLACE INTO', $seg);
        $seg = preg_replace('/\bINSERT\s+OR\s+IGNORE\s+INTO\b/i', 'INSERT IGNORE INTO', $seg);
        // last_insert_rowid()
        $seg = preg_replace('/\blast_insert_rowid\s*\(\s*\)/i', 'LAST_INSERT_ID()', $seg);
        // LIMIT -1 → أكبر قيمة ممكنة
        $seg = preg_replace('/\bLIMIT\s+-1\b/i', 'LIMIT 18446744073709551615', $seg);
        // CAST(... AS REAL / INTEGER / TEXT)
        $seg = preg_replace('/\bAS\s+REAL\b/i', 'AS DOUBLE', $seg);
        $seg = preg_replace('/\bAS\s+INTEGER\b/i', 'AS SIGNED', $seg);
        $seg = preg_replace('/\bAS\s+TEXT\b/i', 'AS CHAR', $seg);
        // العمود key محجوز بـ MySQL: اقتبسه إذا ورد كاسم مستقل (لا key_ ولا _key ولا `key`)
        $seg = preg_replace('/(?<![\w`.])key(?![\w`])/i', '`key`', $seg);
        // ON CONFLICT(cols) DO UPDATE SET a=excluded.a  →  ON DUPLICATE KEY UPDATE a=VALUES(a)
        if (preg_match('/\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+NOTHING\b/i', $seg)) {
            $seg = preg_replace('/\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+NOTHING\b/i', '', $seg);
            $ignoreDup = true;
        }
        if (preg_match('/\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+UPDATE\s+SET\b/i', $seg)) {
            $seg = preg_replace('/\bON\s+CONFLICT\s*\([^)]*\)\s*DO\s+UPDATE\s+SET\b/i', 'ON DUPLICATE KEY UPDATE', $seg);
        }
        $seg = preg_replace('/\bexcluded\.(\w+)/i', 'VALUES($1)', $seg);
        $out .= $seg;
    }
    if ($ignoreDup) $out = preg_replace('/\bINSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $out, 1);
    return $cache[$sql] = $out;
}

final class Conn {
    private static ?Conn $shared = null;
    public PDO $pdo;
    private bool $inTx = false;
    /** @var array<string,array> لكل استعلام: [فهارس الأعمدة المعروضة, أسماؤها, أعمدة DECIMAL] */
    private array $colMeta = [];

    public function __construct() {
        $this->connect();
    }

    private function connect(): void {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,   // rowcount = عدد الصفوف المطابقة (مثل sqlite3) لا المتغيّرة فقط
        ]);
        // sql_mode متساهل (مثل SQLite: بلا STRICT / ONLY_FULL_GROUP_BY) + READ COMMITTED
        $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_bin");
        $this->pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        $this->inTx = false;
    }

    /** اتصال مشترك لطلب HTTP الحالي (مثل get_db() مع flask.g) */
    public static function shared(): Conn {
        if (self::$shared === null) self::$shared = new Conn();
        return self::$shared;
    }

    /** يُستدعى بنهاية الطلب: أي تغييرات غير مثبتة تُلغى (سلوك sqlite3 عند close بلا commit) */
    public static function shutdown(): void {
        if (self::$shared !== null) {
            try { self::$shared->rollback(); } catch (Throwable $e) {}
            self::$shared = null;
        }
    }

    public function execute(string $sql, $params = []): Cursor {
        $t = sql_translate($sql);
        if ($t === null) return new Cursor();
        if ($params === null) $params = [];
        $params = array_values((array)$params);

        $isWrite = (bool)preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $t);
        $attempt = 0;
        while (true) {
            try {
                if ($isWrite && !$this->inTx) { $this->pdo->beginTransaction(); $this->inTx = true; }
                $stmt = $this->pdo->prepare($t);
                $i = 1;
                foreach ($params as $p) {
                    if ($p === null) $stmt->bindValue($i, null, PDO::PARAM_NULL);
                    elseif (is_int($p)) $stmt->bindValue($i, $p, PDO::PARAM_INT);
                    elseif (is_bool($p)) $stmt->bindValue($i, $p ? 1 : 0, PDO::PARAM_INT);
                    elseif (is_float($p)) $stmt->bindValue($i, var_export($p, true), PDO::PARAM_STR); // أقصى دقة
                    else $stmt->bindValue($i, (string)$p, PDO::PARAM_STR);
                    $i++;
                }
                $stmt->execute();
                break;
            } catch (PDOException $e) {
                $code = (int)($e->errorInfo[1] ?? 0);
                $state = (string)($e->errorInfo[0] ?? $e->getCode());
                // "MySQL server has gone away" / lost connection خارج معاملة: أعد الاتصال مرة واحدة
                if (in_array($code, [2006, 2013], true) && !$this->inTx && $attempt === 0) {
                    $attempt++;
                    $this->connect();
                    continue;
                }
                if ($code === 1213 || $code === 1205) { // deadlock/lock wait: MySQL ألغى المعاملة
                    $this->inTx = false;
                    try { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); } catch (Throwable $e2) {}
                }
                if ($state === '23000') throw new IntegrityError($e->getMessage(), 0, $e);
                throw new DBError($e->getMessage() . ' [SQL: ' . substr($t, 0, 200) . ']', 0, $e);
            }
        }

        $rows = [];
        $nCols = $stmt->columnCount();
        if ($nCols > 0) {
            $meta = $this->colMeta[$t] ?? null;
            if ($meta === null) {
                $keep = []; $names = []; $dec = [];
                for ($c = 0; $c < $nCols; $c++) {
                    $m = $stmt->getColumnMeta($c);
                    $nm = (string)($m['name'] ?? ('col' . $c));
                    if (strncmp($nm, '__fc_', 5) === 0) continue;   // أعمدة مولَّدة داخلية: تُخفى (مثل __fc_ext_uq)
                    $keep[] = $c; $names[] = $nm;
                    if (in_array($m['native_type'] ?? '', ['NEWDECIMAL', 'DECIMAL'], true)) $dec[$c] = true;
                }
                $meta = $this->colMeta[$t] = [$keep, $names, $dec];
            }
            [$keep, $names, $dec] = $meta;
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $r) {
                $vals = [];
                foreach ($keep as $c) {
                    $x = $r[$c];
                    // SUM/AVG على أعمدة صحيحة ترجع DECIMAL (نصاً) بـ MySQL؛ SQLite يرجع رقماً
                    if (isset($dec[$c]) && is_string($x)) $x = (strpos($x, '.') === false) ? (int)$x : (float)$x;
                    $vals[] = $x;
                }
                $rows[] = new Row($names, $vals);
            }
        }
        $last = 0;
        if (preg_match('/^\s*(INSERT|REPLACE)\b/i', $t)) $last = (int)$this->pdo->lastInsertId();
        return new Cursor($rows, $last, $stmt->rowCount());
    }

    public function commit(): void {
        if ($this->inTx) {
            $this->inTx = false;
            if ($this->pdo->inTransaction()) $this->pdo->commit();
        }
    }

    public function rollback(): void {
        if ($this->inTx) {
            $this->inTx = false;
            try { $this->pdo->rollBack(); } catch (Throwable $e) {}
        }
    }

    /** داخل الطلب: لا شيء (الإغلاق الفعلي بنهاية الطلب) — مثل _ManagedConn.close() */
    public function close(): void {}

    public function inTransaction(): bool { return $this->inTx; }
}

/** get_db(): اتصال الطلب الحالي */
function get_db(): Conn { return Conn::shared(); }

/** get_db_raw(): اتصال جديد مستقل (للمهام الخلفية الطويلة) */
function get_db_raw(): Conn { return new Conn(); }
