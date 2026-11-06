#!/usr/bin/env python3
"""
يستخرج حرفياً قوالب الواجهة (HTML/JS/Base64) من app.py الأصلي إلى php/app/templates/
بحيث تُخدَّم الواجهة بايتاً ببايت كما كانت — لا يتغيّر فيها أي حرف.

الاستعمال:  python3 extract_from_python.py /path/to/app.py ../app/templates

يمكن إعادة تشغيله عند أي تحديث مستقبلي لواجهة نسخة بايثون لتحديث نسخة PHP تلقائياً.
"""
import ast
import hashlib
import os
import sys


def main(src_path, out_dir):
    src = open(src_path, encoding='utf-8').read()
    tree = ast.parse(src)
    os.makedirs(out_dir, exist_ok=True)
    written = {}

    def write(name, text):
        data = text.encode('utf-8')
        with open(os.path.join(out_dir, name), 'wb') as f:
            f.write(data)
        written[name] = (len(text), hashlib.sha256(data).hexdigest()[:12])

    # 1) ثوابت على مستوى الملف
    module_consts = {'LANDING_HTML': 'landing.html', 'DASH_HTML': 'dash.html', 'ORDER_PAGE_HTML': 'order_page.html',
                     'AI_ICON_DEFAULT_B64': 'ai_icon_default.b64'}
    for node in tree.body:
        if isinstance(node, ast.Assign) and isinstance(node.value, ast.Constant) and isinstance(node.value.value, str):
            for t in node.targets:
                if isinstance(t, ast.Name) and t.id in module_consts:
                    write(module_consts[t.id], node.value.value)

    funcs = {n.name: n for n in ast.walk(tree) if isinstance(n, ast.FunctionDef)}

    def biggest_const(fn):
        best = None
        for n in ast.walk(fn):
            if isinstance(n, ast.Constant) and isinstance(n.value, str):
                if best is None or len(n.value) > len(best):
                    best = n.value
        return best

    # 2) ثوابت داخل دوال: service worker + صفحة توثيق الـ API
    write('sw.js', biggest_const(funcs['pwa_sw']))
    write('api_docs.html', biggest_const(funcs['api_docs_page']))

    # 3) صفحة الحظر (f-string): نحوّل التعابير إلى علامات __FC_<NAME>__
    ban = funcs['_banned_page']
    js = [n for n in ast.walk(ban) if isinstance(n, ast.JoinedStr)][0]
    parts = []
    for v in js.values:
        if isinstance(v, ast.Constant):
            parts.append(v.value)
        else:
            expr = ast.get_source_segment(src, v.value)
            assert expr in ('site_name', 'name', 'email'), expr
            assert v.format_spec is None and v.conversion == -1
            parts.append('__FC_%s__' % expr.upper())
    write('banned.html', ''.join(parts))

    for k, (n, h) in sorted(written.items()):
        print('%-24s %9d chars  sha256:%s' % (k, n, h))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
