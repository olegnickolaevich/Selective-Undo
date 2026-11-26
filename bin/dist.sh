#!/usr/bin/env bash
# Builds the WordPress.org package: dist/undoly/ and dist/undoly-<version>.zip.
# Top-level paths listed in .distignore are left out. The admin interface source (assets/admin,
# package.json, tsconfig.json) is kept so that the compiled build/ can be reviewed and rebuilt.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="undoly"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/$SLUG.php")"
OUT="$ROOT/dist"

if [ "${1:-}" != "--no-build" ]; then
  (cd "$ROOT" && npm run build >/dev/null)
fi

grep -q "^Stable tag: $VERSION\$" "$ROOT/readme.txt" || { echo "readme.txt Stable tag does not match version $VERSION" >&2; exit 1; }

rm -rf "$OUT" && mkdir -p "$OUT/$SLUG"
# .distignore lists top-level paths, one per line ("/name").
mapfile -t IGNORED < <(sed -n 's|^/||p' "$ROOT/.distignore")
shopt -s dotglob
for path in "$ROOT"/*; do
  name="$(basename "$path")"
  [[ " ${IGNORED[*]} " == *" $name "* ]] && continue
  cp -R "$path" "$OUT/$SLUG/"
done
# Translations come from translate.wordpress.org; the package keeps only the template.
rm -f "$OUT/$SLUG"/languages/*.po "$OUT/$SLUG"/languages/*.mo
if command -v zip >/dev/null; then
  (cd "$OUT" && zip -qr "$SLUG-$VERSION.zip" "$SLUG")
else # Windows: the bsdtar of the system writes zip files too
  (cd "$OUT" && "${SYSTEMROOT:-C:/Windows}/System32/tar.exe" -a -cf "$SLUG-$VERSION.zip" "$SLUG")
fi
echo "$OUT/$SLUG-$VERSION.zip"
