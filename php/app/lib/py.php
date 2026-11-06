<?php
/**
 * مكتبة توافق مع سلوك بايثون — تضمن أن التحويلات (float/int/str/round) وحالات الخطأ
 * تتصرف بنفس طريقة النسخة الأصلية (app.py)، لأن PHP يختلف عنها بتفاصيل مهمة:
 *   - float('abc') يرمي PyValueError بينما (float)'abc' يعطي 0 بصمت
 *   - round(2.675, 2) = 2.67 ببايثون بينما PHP يعطي 2.68
 *   - str(2.0) = '2.0' ببايثون بينما PHP يعطي '2'
 */

class PyError extends Exception {}
class PyValueError extends PyError {}
class PyTypeError extends PyError {}
class PyIndexError extends PyError {}
class PyKeyError extends PyError {}

/** Python float() */
function py_float($v): float {
    if (is_float($v)) return $v;
    if (is_int($v) || is_bool($v)) return (float)$v;
    if ($v === null) throw new PyTypeError("float() argument must be a string or a real number, not 'NoneType'");
    if (is_string($v)) {
        $s = trim($v);
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $s)) return (float)$s;
        $l = strtolower($s);
        if (in_array($l, ['inf', '+inf', 'infinity', '+infinity'], true)) return INF;
        if (in_array($l, ['-inf', '-infinity'], true)) return -INF;
        if ($l === 'nan' || $l === '+nan' || $l === '-nan') return NAN;
        throw new PyValueError("could not convert string to float: '" . $v . "'");
    }
    throw new PyTypeError('float() argument must be a string or a real number');
}

/** Python int() */
function py_int($v): int {
    if (is_int($v)) return $v;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_float($v)) {
        if (is_nan($v)) throw new PyValueError('cannot convert float NaN to integer');
        if (is_infinite($v)) throw new PyValueError('cannot convert float infinity to integer');
        return (int)$v; // truncation toward zero, like Python
    }
    if ($v === null) throw new PyTypeError("int() argument must be a string, a bytes-like object or a real number, not 'NoneType'");
    if (is_string($v)) {
        $s = trim($v);
        if (preg_match('/^[+-]?\d+$/', $s)) return (int)$s;
        throw new PyValueError("invalid literal for int() with base 10: '" . $v . "'");
    }
    throw new PyTypeError('int() argument must be a string, a bytes-like object or a real number');
}

/** repr(float) على طريقة بايثون: أقصر تمثيل يعيد نفس القيمة، والأس العلمي خارج المجال [-4, 16) */
function py_float_repr(float $f): string {
    if (is_nan($f)) return 'nan';
    if (is_infinite($f)) return $f > 0 ? 'inf' : '-inf';
    if ($f == 0.0) return fdiv(1.0, $f) < 0 ? '-0.0' : '0.0';
    $s = var_export($f, true); // أقصر تمثيل round-trip (serialize_precision=-1)
    $sign = '';
    if ($s[0] === '-') { $sign = '-'; $s = substr($s, 1); }
    $exp = 0;
    if (($p = stripos($s, 'e')) !== false) { $exp = (int)substr($s, $p + 1); $s = substr($s, 0, $p); }
    $dot = strpos($s, '.');
    $ip = $dot === false ? $s : substr($s, 0, $dot);
    $fp = $dot === false ? '' : substr($s, $dot + 1);
    $digits = $ip . $fp;
    $decpt = strlen($ip) + $exp;
    $lead = strlen($digits) - strlen(ltrim($digits, '0'));
    $digits = ltrim($digits, '0');
    $decpt -= $lead;
    $digits = rtrim($digits, '0');
    if ($digits === '') return $sign . '0.0';
    $sciExp = $decpt - 1;
    if ($sciExp < -4 || $sciExp >= 16) {
        $m = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '');
        return $sign . $m . 'e' . ($sciExp < 0 ? '-' : '+') . str_pad((string)abs($sciExp), 2, '0', STR_PAD_LEFT);
    }
    if ($decpt <= 0) return $sign . '0.' . str_repeat('0', -$decpt) . $digits;
    if ($decpt >= strlen($digits)) return $sign . $digits . str_repeat('0', $decpt - strlen($digits)) . '.0';
    return $sign . substr($digits, 0, $decpt) . '.' . substr($digits, $decpt);
}

/** Python str() */
function py_str($v): string {
    if ($v === null) return 'None';
    if ($v === true) return 'True';
    if ($v === false) return 'False';
    if (is_float($v)) return py_float_repr($v);
    if (is_int($v)) return (string)$v;
    if (is_string($v)) return $v;
    if (is_array($v) || $v instanceof stdClass) return py_repr($v);
    return (string)$v;
}

function py_repr_str(string $s): string {
    $q = (strpos($s, "'") !== false && strpos($s, '"') === false) ? '"' : "'";
    $out = '';
    $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        $o = ord($c);
        if ($c === '\\') $out .= '\\\\';
        elseif ($c === $q) $out .= '\\' . $q;
        elseif ($c === "\n") $out .= '\\n';
        elseif ($c === "\r") $out .= '\\r';
        elseif ($c === "\t") $out .= '\\t';
        elseif ($o < 0x20 || $o === 0x7f) $out .= sprintf('\\x%02x', $o);
        else $out .= $c;
    }
    return $q . $out . $q;
}

/** repr() ببايثون للأنواع الشائعة (نص/عدد/قاموس/قائمة) */
function py_repr($v): string {
    if ($v === null) return 'None';
    if (is_bool($v)) return $v ? 'True' : 'False';
    if (is_string($v)) return py_repr_str($v);
    if (is_float($v)) return py_float_repr($v);
    if (is_int($v)) return (string)$v;
    if ($v instanceof stdClass) $v = ['__dict__' => get_object_vars($v)];
    if (is_array($v)) {
        if (isset($v['__dict__']) && count($v) === 1) { $dict = true; $v = $v['__dict__']; }
        else $dict = !is_list_array($v);
        $parts = [];
        foreach ($v as $k => $x) $parts[] = $dict ? py_repr_str((string)$k) . ': ' . py_repr($x) : py_repr($x);
        return $dict ? '{' . implode(', ', $parts) . '}' : '[' . implode(', ', $parts) . ']';
    }
    return (string)$v;
}

/** اسم نوع القيمة كما يظهر برسائل أخطاء بايثون */
function py_type_name($v): string {
    if ($v === null) return 'NoneType';
    if (is_bool($v)) return 'bool';
    if (is_int($v)) return 'int';
    if (is_float($v)) return 'float';
    if (is_string($v)) return 'str';
    if ($v instanceof stdClass) return 'dict';
    if (is_array($v)) return is_list_array($v) ? 'list' : 'dict';
    return 'object';
}

/** AttributeError لاستدعاء .get على غير قاموس */
function py_no_get_attr($v): PyTypeError { return new PyTypeError("'" . py_type_name($v) . "' object has no attribute 'get'"); }

/** يحوّل ناتج json() إلى قاموس PHP؛ غير القاموس يرمي AttributeError كما يفعل data.get(...) ببايثون */
function py_dict($v): array {
    if ($v instanceof stdClass) return [];
    if (is_array($v) && !is_list_array($v)) return $v;
    throw py_no_get_attr($v);
}

/**
 * Python round(x, nd): للأعداد الصحيحة يرجع int (مثل بايثون)، وللعشرية تقريب صحيح للقيمة الثنائية الدقيقة
 * (half-even عند التعادل الحقيقي). round(x) بلا nd يرجع int دائماً.
 */
function py_round($x, ?int $nd = null) {
    if (is_int($x) || is_bool($x)) {
        $x = (int)$x;
        if ($nd === null || $nd >= 0) return $x;
        $f = 10 ** (-$nd);
        return (int)(round($x / $f, 0, PHP_ROUND_HALF_EVEN) * $f);
    }
    $x = py_float($x);
    if (is_nan($x) || is_infinite($x)) return $nd === null ? 0 : $x;
    if ($nd === null) return (int)(float)sprintf('%.0f', $x);
    if ($nd >= 0) return (float)sprintf('%.' . $nd . 'f', $x);
    $f = 10 ** (-$nd);
    return (float)(((int)(float)sprintf('%.0f', $x / $f)) * $f);
}

/** تنسيق f"{x:.Nf}" */
function py_fmt($x, int $nd = 2): string {
    return sprintf('%.' . $nd . 'f', py_float($x));
}

/** truthiness على طريقة بايثون ('0' نص صحيح = True، [] و {} و '' و 0 و None = False) */
function py_truthy($v): bool {
    if ($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '') return false;
    if (is_array($v)) return count($v) > 0;
    if ($v instanceof stdClass) return count(get_object_vars($v)) > 0;
    if ($v instanceof Countable) return count($v) > 0;
    return true;
}

/** a or b  (على طريقة بايثون: '0' نص صحيح = True) */
function py_or($a, $b) { return py_truthy($a) ? $a : $b; }

/** str.strip() الافتراضي: يزيل كل المسافات البيضاء (بما فيها Unicode) */
function py_strip($s): string {
    return preg_replace('/^\s+|\s+$/u', '', (string)$s) ?? (string)$s;
}

/** len() لنص Unicode (عدد المحارف لا البايتات) */
function py_len($s): int {
    return is_array($s) ? count($s) : mb_strlen((string)$s, 'UTF-8');
}

/** s[a:b] لنص Unicode */
function py_slice(string $s, ?int $start = null, ?int $end = null): string {
    $len = mb_strlen($s, 'UTF-8');
    $start = $start ?? 0;
    if ($start < 0) $start = max(0, $len + $start);
    $end = $end ?? $len;
    if ($end < 0) $end = max(0, $len + $end);
    if ($end > $len) $end = $len;
    if ($start >= $end) return '';
    return mb_substr($s, $start, $end - $start, 'UTF-8');
}

/** secrets.token_hex(n) */
function token_hex(int $n = 32): string { return bin2hex(random_bytes($n)); }

/** uuid.uuid4() كنص */
function uuid4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/** time.time() */
function now(): float { return microtime(true); }

/** time.strftime('%Y-%m-%d %H:%M', time.localtime(ts)) */
function fmt_time(string $fmt, $ts = null): string {
    $ts = $ts === null ? time() : (int)$ts;
    $map = ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%M' => 'i', '%S' => 's', '%y' => 'y', '%B' => 'F', '%b' => 'M', '%A' => 'l', '%a' => 'D', '%p' => 'A', '%I' => 'h', '%j' => 'z', '%Z' => 'T'];
    $out = '';
    $n = strlen($fmt);
    for ($i = 0; $i < $n; $i++) {
        if ($fmt[$i] === '%' && $i + 1 < $n) {
            $k = '%' . $fmt[$i + 1];
            if ($k === '%%') { $out .= '%'; $i++; continue; }
            if (isset($map[$k])) { $out .= date($map[$k], $ts); $i++; continue; }
        }
        $out .= $fmt[$i];
    }
    return $out;
}

/** dict.get على مصفوفة (مع إرجاع القيمة الافتراضية للمفتاح الناقص فقط، مثل بايثون) */
function dget($arr, $key, $default = null) {
    if (!is_array($arr)) return $default;
    return array_key_exists($key, $arr) ? $arr[$key] : $default;
}

/** هل المصفوفة قائمة تسلسلية (list) أم قاموس */
function is_list_array(array $a): bool {
    $i = 0;
    foreach ($a as $k => $_) { if ($k !== $i++) return false; }
    return true;
}

/**
 * json.dumps(v, ensure_ascii=True, sort_keys=False, separators=None) — نفس مخرجات بايثون:
 * الفواصل الافتراضية ", " و ": " ، الأرقام العشرية بصيغة repr، و"/" لا يُهرَّب.
 * القاموس الفارغ يُمرَّر كـ (object)[] أو jobj()؛ المصفوفة الفارغة تصير [].
 */
function py_json_dumps($v, bool $ensure_ascii = true, bool $sort_keys = false, ?array $separators = null): string {
    [$itemSep, $keySep] = $separators ?? [', ', ': '];
    $flags = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | ($ensure_ascii ? 0 : JSON_UNESCAPED_UNICODE);
    $enc = function ($x) use (&$enc, $flags, $itemSep, $keySep, $sort_keys): string {
        if ($x === null) return 'null';
        if ($x === true) return 'true';
        if ($x === false) return 'false';
        if (is_int($x)) return (string)$x;
        if (is_float($x)) {
            if (is_nan($x)) return 'NaN';
            if (is_infinite($x)) return $x > 0 ? 'Infinity' : '-Infinity';
            return py_float_repr($x);
        }
        if (is_string($x)) { $r = json_encode($x, $flags); return $r === false ? '""' : $r; }
        if ($x instanceof JsonSerializable) return $enc($x->jsonSerialize());
        $forceObj = false;
        if ($x instanceof stdClass) { $x = get_object_vars($x); $forceObj = true; }
        if (!is_array($x)) return $enc((string)$x);
        if (!$forceObj && is_list_array($x)) {
            $parts = [];
            foreach ($x as $item) $parts[] = $enc($item);
            return '[' . implode($itemSep, $parts) . ']';
        }
        if ($sort_keys) ksort($x, SORT_STRING);
        $parts = [];
        foreach ($x as $k => $item) {
            $kk = json_encode((string)$k, $flags);
            $parts[] = $kk . $keySep . $enc($item);
        }
        return '{' . implode($itemSep, $parts) . '}';
    };
    return $enc($v);
}

/**
 * تحويل ناتج json_decode(assoc=false) إلى مصفوفات PHP مع الإبقاء على القاموس الفارغ {} ككائن stdClass
 * (حتى لا يتحول إلى [] عند إعادة التصدير كما يحدث مع assoc=true)
 */
function _json_to_php($v) {
    if ($v instanceof stdClass) {
        $arr = get_object_vars($v);
        if (!$arr) return $v;
        foreach ($arr as $k => $x) $arr[$k] = _json_to_php($x);
        return $arr;
    }
    if (is_array($v)) {
        foreach ($v as $k => $x) $v[$k] = _json_to_php($x);
        return $v;
    }
    return $v;
}

/** json.loads — يرمي PyValueError عند فشل التحليل (برسالة شبيهة ببايثون) */
function json_loads($s) {
    $s = (string)$s;
    $trim = ltrim($s, " \t\n\r");
    $off = strlen($s) - strlen($trim);
    if ($trim === '') throw new PyValueError('Expecting value: line 1 column 1 (char 0)');
    $v = json_decode($s, false, 512, JSON_BIGINT_AS_STRING);
    if ($v === null && json_last_error() !== JSON_ERROR_NONE) {
        $pre = substr($s, 0, $off);
        $line = substr_count($pre, "\n") + 1;
        $nl = strrpos($pre, "\n");
        $col = mb_strlen($nl === false ? $pre : substr($pre, $nl + 1), 'UTF-8') + 1;
        throw new PyValueError('Expecting value: line ' . $line . ' column ' . $col . ' (char ' . mb_strlen($pre, 'UTF-8') . ')');
    }
    return _json_to_php($v);
}

/** تحويل كائن أو مصفوفة إلى كائن JSON فارغ {} بدل [] عند الحاجة */
function jobj(array $a = []) { return $a === [] ? new stdClass() : $a; }

/** مقارنة نصوص آمنة للتوقيتات */
function safe_eq(string $a, string $b): bool { return hash_equals($a, $b); }

/**
 * base64.b64decode(s) بوضعه الافتراضي: يتجاهل المحارف خارج الأبجدية، ويرمي PyValueError عند
 * ترقيم/طول غير صحيح (Incorrect padding) مثل binascii.Error.
 */
function py_b64decode($s): string {
    $s = (string)$s;
    $data = ''; $pads = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '=') { $pads++; continue; }
        if (ctype_alnum($c) || $c === '+' || $c === '/') {
            if ($pads > 0 && strlen($data) % 4 === 0) { /* بعد اكتمال رباعية ينتهي الإدخال */ break; }
            $data .= $c;
        }
    }
    $rem = strlen($data) % 4;
    if ($rem === 1) throw new PyValueError('Invalid base64-encoded string: number of data characters (' . strlen($data) . ') cannot be 1 more than a multiple of 4');
    if ($rem !== 0 && $pads < 4 - $rem) throw new PyValueError('Incorrect padding');
    $r = base64_decode($data . str_repeat('=', (4 - $rem) % 4), true);
    if ($r === false) throw new PyValueError('Invalid base64-encoded string');
    return $r;
}

/** تحويل SQLite لعدد عشري إلى نص عند تخزينه بعمود TEXT: صيغة %!.15g (15 رقماً معنوياً + فاصلة عشرية دائماً) */
function py_sqlite_float_text(float $f): string {
    if (is_nan($f)) return '';
    if (is_infinite($f)) return $f > 0 ? 'Inf' : '-Inf';
    if ($f == 0.0) return '0.0';
    $e = sprintf('%.14e', abs($f));
    [$mant, $exp] = explode('e', $e);
    $exp = (int)$exp;
    $digits = rtrim(str_replace('.', '', $mant), '0');
    if ($digits === '') $digits = '0';
    $neg = $f < 0 ? '-' : '';
    if ($exp < -4 || $exp >= 15) {
        return $neg . $digits[0] . '.' . (strlen($digits) > 1 ? substr($digits, 1) : '0') . 'e' . ($exp < 0 ? '-' : '+') . str_pad((string)abs($exp), 2, '0', STR_PAD_LEFT);
    }
    if ($exp >= 0) {
        $int = str_pad(substr($digits, 0, $exp + 1), $exp + 1, '0');
        $frac = strlen($digits) > $exp + 1 ? substr($digits, $exp + 1) : '';
        return $neg . $int . '.' . ($frac === '' ? '0' : $frac);
    }
    return $neg . '0.' . str_repeat('0', -$exp - 1) . $digits;
}

/** f"{x:g}" ببايثون (6 أرقام معنوية، بلا أصفار زائدة) */
function py_fmt_g($x): string {
    $x = py_float($x);
    if ($x == 0.0) return '0';
    $exp = (int)floor(log10(abs($x)));
    if ($exp < -4 || $exp >= 6) {
        $m = sprintf('%.5e', $x);
        [$mant, $e] = explode('e', $m);
        $mant = rtrim(rtrim($mant, '0'), '.');
        return $mant . 'e' . ($e[0] === '-' ? '-' : '+') . str_pad(ltrim(substr($e, 1), '0'), 2, '0', STR_PAD_LEFT);
    }
    $s = sprintf('%.' . max(0, 5 - $exp) . 'f', $x);
    if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
    return $s;
}

/** COALESCE(x, d) على طريقة SQLite: يستبدل NULL فقط (0.0 يبقى 0.0) */
function py_or_null($v, $d) { return $v === null ? $d : $v; }
