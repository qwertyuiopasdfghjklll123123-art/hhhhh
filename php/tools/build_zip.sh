#!/usr/bin/env bash
# يبني أرشيف النشر fastcrand_php.zip:
#   • الملفات في جذر الأرشيف مباشرة (بلا مجلد علوي) — يُفكّ داخل public_html فتظهر الملفات هناك
#   • core/config.php جاهز للتعبئة (من config.sample.php) بمفتاح جلسات عشوائي جديد
#   • بلا ملفات التطوير (tests/ وأدوات بايثون ...) — انظر .gitattributes
# الاستعمال:  tools/build_zip.sh [/path/to/output.zip]
set -euo pipefail
ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
PREFIX="$(git -C "$(dirname "$0")" rev-parse --show-prefix)"; PREFIX="${PREFIX%tools/}"; PREFIX="${PREFIX%/}"
OUT="$(realpath -m "${1:-fastcrand_php.zip}")"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
git -C "$ROOT" archive --format=tar "HEAD:${PREFIX}" | tar -x -C "$TMP"
SAMPLE="$(git -C "$ROOT" show "HEAD:${PREFIX:+$PREFIX/}core/config.sample.php")"
SECRET="$(python3 -c 'import secrets;print(secrets.token_hex(32))')"
printf '%s\n' "$SAMPLE" | sed "s/CHANGE_ME_TO_A_LONG_RANDOM_SECRET/${SECRET}/" > "$TMP/core/config.php"
mkdir -p "$TMP/data" && : > "$TMP/data/.gitkeep"
rm -f "$OUT"
(cd "$TMP" && zip -q -r -X "$OUT" . -x '.git/*')
echo "تم: $OUT ($(du -h "$OUT" | cut -f1))"
unzip -Z1 "$OUT" | awk -F/ '{print $1}' | sort -u | tr '\n' ' '; echo
