<?php
/**
 * قارئ جمل SQL (streaming) يعرف النصوص والتعليقات: لا يقطع عند الفاصلة المنقوطة داخل '...' أو "..." أو `...`
 * ولا يتأثر بالتعليقات (سطرية بـ -- و # ، وتعليقات الكتل بين الشرطة-نجمة ونجمة-شرطة
 * بما فيها الشرطية منها التي تبدأ بعلامة تعجب: تُهمَل ولا تُنفَّذ أبداً).
 * يُستعمل لتحميل sql/database.sql ولفحص/استيراد ملفات SQL المرفوعة بأمان (انظر transfer.php).
 *
 * لا يدعم DELIMITER (أمر خاص بعميل mysql): يبقى جزءاً من الجملة فيرفضها المدقّق لاحقاً.
 */
final class SqlSplitter {
    /** أقصى طول لسطر واحد (INSERT طويل بصفوف كثيرة) */
    public const MAX_LINE = 33554432; // 32MB

    /**
     * @param resource $fp
     * @param callable $cb function(string $statement): void|false   (إرجاع false يوقف القراءة)
     * @return int عدد الجمل التي سُلِّمت
     */
    public static function eachStatementFromStream($fp, callable $cb): int {
        $mode = 0;                 // 0 كود، 1 '...'، 2 "...", 3 `...`, 4 /* ... */
        $cur = '';
        $count = 0;
        while (($ln = fgets($fp, self::MAX_LINE)) !== false) {
            if (strlen($ln) >= self::MAX_LINE - 1 && substr($ln, -1) !== "\n") {
                throw new RuntimeException('سطر SQL أطول من الحد المسموح (' . (self::MAX_LINE >> 20) . 'MB)');
            }
            $n = strlen($ln);
            $i = 0;
            while ($i < $n) {
                if ($mode === 0) {
                    $j = $i + strcspn($ln, "'\"`;#-/", $i);
                    if ($j > $i) $cur .= substr($ln, $i, $j - $i);
                    if ($j >= $n) break;
                    $c = $ln[$j];
                    if ($c === "'") { $mode = 1; $cur .= $c; $i = $j + 1; }
                    elseif ($c === '"') { $mode = 2; $cur .= $c; $i = $j + 1; }
                    elseif ($c === '`') { $mode = 3; $cur .= $c; $i = $j + 1; }
                    elseif ($c === ';') {
                        $st = trim($cur); $cur = '';
                        if ($st !== '') { $count++; if ($cb($st) === false) return $count; }
                        $i = $j + 1;
                    }
                    elseif ($c === '#') { $i = $n; }                              // تعليق حتى نهاية السطر
                    elseif ($c === '-') {
                        $nx = $j + 1 < $n ? $ln[$j + 1] : '';
                        $n2 = $j + 2 < $n ? $ln[$j + 2] : '';
                        if ($nx === '-' && ($n2 === '' || $n2 === ' ' || $n2 === "\t" || $n2 === "\r" || $n2 === "\n")) $i = $n;   // -- تعليق
                        else { $cur .= '-'; $i = $j + 1; }
                    }
                    else { // '/'
                        if ($j + 1 < $n && $ln[$j + 1] === '*') { $mode = 4; $i = $j + 2; }          // /* ... */ و /*! ... */ تُهمَل
                        else { $cur .= '/'; $i = $j + 1; }
                    }
                } elseif ($mode === 1 || $mode === 2) {
                    $q = $mode === 1 ? "'" : '"';
                    $j = $i + strcspn($ln, $q . '\\', $i);
                    if ($j > $i) $cur .= substr($ln, $i, $j - $i);
                    if ($j >= $n) break;
                    if ($ln[$j] === '\\') {                                                           // محرف هروب: يستهلك ما بعده
                        $cur .= $ln[$j];
                        if ($j + 1 < $n) $cur .= $ln[$j + 1];
                        $i = $j + 2;
                    } elseif ($j + 1 < $n && $ln[$j + 1] === $q) { $cur .= $q . $q; $i = $j + 2; }   // '' مضاعفة
                    else { $cur .= $q; $mode = 0; $i = $j + 1; }
                } elseif ($mode === 3) {
                    $j = $i + strcspn($ln, '`', $i);
                    if ($j > $i) $cur .= substr($ln, $i, $j - $i);
                    if ($j >= $n) break;
                    if ($j + 1 < $n && $ln[$j + 1] === '`') { $cur .= '``'; $i = $j + 2; }
                    else { $cur .= '`'; $mode = 0; $i = $j + 1; }
                } else { // 4: تعليق كتلة
                    $j = strpos($ln, '*/', $i);
                    if ($j === false) { $i = $n; }
                    else { $mode = 0; $i = $j + 2; $cur .= ' '; }
                }
            }
        }
        if ($mode === 1 || $mode === 2 || $mode === 3) throw new RuntimeException('ملف SQL ناقص: نص أو معرّف غير مغلق في نهاية الملف');
        $st = trim($cur);
        if ($st !== '') { $count++; $cb($st); }
        return $count;
    }

    public static function eachStatement(string $path, callable $cb): int {
        $fp = @fopen($path, 'rb');
        if (!$fp) throw new RuntimeException('تعذّر فتح ملف SQL');
        try { return self::eachStatementFromStream($fp, $cb); }
        finally { fclose($fp); }
    }

    /** للنصوص الصغيرة (اختبارات/ملفات المخطط) */
    public static function splitString(string $sql): array {
        $fp = fopen('php://memory', 'w+b');
        fwrite($fp, $sql);
        rewind($fp);
        $out = [];
        try { self::eachStatementFromStream($fp, function ($s) use (&$out) { $out[] = $s; }); }
        finally { fclose($fp); }
        return $out;
    }
}
