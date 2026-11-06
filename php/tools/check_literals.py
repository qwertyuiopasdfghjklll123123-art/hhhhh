#!/usr/bin/env python3
"""
فحص مطابقة النصوص: يتأكد أن كل نص عربي/رموز (غير ASCII) مكتوب بملفات PHP موجود حرفياً بنسخة بايثون الأصلية
(رسائل الأخطاء، عناوين الإشعارات، ...) — يكشف أي حرف سقط أو تغيّر أثناء النقل (حتى الرموز غير المرئية).

الاستعمال:  python3 check_literals.py /path/to/app.py file1.php [file2.php ...]
"""
import ast
import re
import subprocess
import sys


def py_strings(src):
    out = set()
    tree = ast.parse(src)
    for n in ast.walk(tree):
        if isinstance(n, ast.Constant) and isinstance(n.value, str):
            out.add(n.value)
    return out


def php_literals(path):
    """يستخرج النصوص من ملف PHP عبر tokenizer الأصلي (يفك الهروب ويدعم الاستيفاء)"""
    code = r'''
$toks = token_get_all(file_get_contents($argv[1]));
$out = [];
foreach ($toks as $t) {
    if (!is_array($t)) continue;
    if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
        $s = $t[1]; $q = $s[0]; $body = substr($s, 1, -1);
        if ($q === "'") $body = str_replace(["\\\\'", "\\\\\\\\"], ["'", "\\\\"], $body);
        else $body = stripcslashes($body);
        $out[] = $body;
    } elseif ($t[0] === T_ENCAPSED_AND_WHITESPACE) {
        $out[] = stripcslashes($t[1]);
    } elseif ($t[0] === T_INLINE_HTML) {
        $out[] = $t[1];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
'''
    r = subprocess.run(['php', '-r', code, '--', path], capture_output=True, text=True)
    import json
    return json.loads(r.stdout)


def main():
    src = open(sys.argv[1], encoding='utf-8').read()
    pystrs = py_strings(src)
    big = '\n'.join(pystrs)
    bad = 0
    for path in sys.argv[2:]:
        for lit in php_literals(path):
            if not re.search(r'[^\x00-\x7f]', lit):
                continue
            if lit in big:
                continue
            # أجزاء نصوص مقسومة عبر التسلسل — جرّب كل سطر على حدة
            parts = [p for p in lit.split('\n') if re.search(r'[^\x00-\x7f]', p)]
            if parts and all(p in big for p in parts):
                continue
            bad += 1
            print('%s: غير موجود بالأصل: %r' % (path, lit[:120]))
    print('تم الفحص — %d نص غير مطابق' % bad)
    return 1 if bad else 0


if __name__ == '__main__':
    sys.exit(main())
