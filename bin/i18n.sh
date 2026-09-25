#!/usr/bin/env bash
# Translation workflow, following the WordPress.org conventions:
# English source strings, text domain = plugin slug, translations delivered as
# language packs from translate.wordpress.org into wp-content/languages/plugins.
#
#   bin/i18n.sh pot                 regenerate languages/selective-undo.pot (run after `npm run build`)
#   bin/i18n.sh update              regenerate the POT and merge it into languages/*.po
#   bin/i18n.sh compile <dir>       build .mo, .l10n.php and JS .json files from languages/*.po into <dir>
#   bin/i18n.sh install-test-site   compile into the test site's wp-content/languages/plugins
#
# languages/*.po are the source for importing translations into translate.wordpress.org.
# The plugin does not load them itself: WordPress loads language packs just in time.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WPCLI="${SU_WPCLI:-$ROOT/.work/wp-cli.phar}"
SLUG="selective-undo"

wp() { php "$WPCLI" --allow-root "$@"; }

make_pot() {
  [ -f "$ROOT/build/index.js" ] || { echo "build/index.js is missing; run npm run build first" >&2; exit 1; }
  wp i18n make-pot "$ROOT" "$ROOT/languages/$SLUG.pot" \
    --slug="$SLUG" --domain="$SLUG" \
    --exclude=node_modules,.work,tests,assets,vendor,dist \
    --headers='{"Report-Msgid-Bugs-To":"https://github.com/olegnickolaevich/Selective-Undo/issues"}'
}

compile() {
  local out="$1" tmp
  mkdir -p "$out"
  # No dot in the name: WP-CLI treats a destination with an extension as a file.
  tmp="$(mktemp -d "${TMPDIR:-/tmp}/su-i18n-XXXXXX")"
  trap 'rm -rf "$tmp"' RETURN
  cp "$ROOT"/languages/"$SLUG"-*.po "$tmp"/
  wp i18n make-mo "$tmp" "$tmp"
  wp i18n make-php "$tmp" "$tmp"
  # JS translations are keyed by md5 of the script path relative to the plugin (build/index.js).
  wp i18n make-json "$tmp" "$tmp" --no-purge
  cp "$tmp"/*.mo "$tmp"/*.l10n.php "$tmp"/*.json "$out"/
}

case "${1:-}" in
  pot) make_pot ;;
  update)
    make_pot
    wp i18n update-po "$ROOT/languages/$SLUG.pot" "$ROOT/languages"
    ;;
  compile)
    [ -n "${2:-}" ] || { echo "usage: bin/i18n.sh compile <dir>" >&2; exit 1; }
    compile "$2"
    ;;
  install-test-site) compile "${SU_TEST_SITE:-$ROOT/.work/site}/wp-content/languages/plugins" ;;
  *) sed -n '2,12p' "$0"; exit 1 ;;
esac
