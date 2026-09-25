# Selective Undo

Undo a specific content change in WordPress and keep everything else.

Selective Undo records changes of the title, content, excerpt and menu order of posts, pages and chosen custom post types, whatever made them (block editor, Quick Edit, bulk edit, REST API, WP-CLI, other plugins). You can restore individual fields later. A field that was changed again after the selected change is reported as a conflict and skipped, never overwritten.

The user-facing description, FAQ and privacy notes are in [`readme.txt`](readme.txt), in the format of the WordPress.org plugin directory.

## Requirements

* WordPress 6.8 or newer (tested up to 7.1)
* PHP 8.2 or newer with the mysqli extension
* MySQL or MariaDB with InnoDB tables; tested with MySQL 8.4 and MariaDB 10.11. Without InnoDB, restoring is blocked and recording still works.

## Repository layout

| Path | Contents |
| --- | --- |
| `selective-undo.php`, `uninstall.php` | Plugin entry points |
| `src/` | PHP code (namespace `SelectiveUndo`, PSR-4, no runtime Composer dependencies) |
| `assets/admin/` | Admin interface source (React, TypeScript) |
| `build/` | Compiled admin interface, committed so that the plugin works without a build step |
| `languages/` | `selective-undo.pot` and translations (`.po`) |
| `tests/` | Unit, integration, concurrency and E2E tests |
| `bin/` | Development scripts |
| `.wordpress-org/` | Screenshots for the plugin directory page |

## Development

```sh
npm install
npm run build          # compile assets/admin into build/
npm run typecheck
npm run lint:js
npm run lint:css
```

### Tests

The integration tests need Docker, PHP 8.2+ and WP-CLI (downloaded by the script).

```sh
bin/test-env.sh up                 # MySQL in Docker + a fresh WordPress site in .work/site
php tests/run.php all              # unit and integration tests
bin/serve.sh &                     # serve the test site on http://127.0.0.1:8899
npx playwright test                # E2E tests (set SU_CHROMIUM to use a preinstalled Chromium)
```

`SU_DB_IMAGE=mariadb:10.11 bin/test-env.sh up` runs the same site on MariaDB.

## Translations

Translations follow the WordPress.org conventions:

* source strings are in English, the text domain is the plugin slug `selective-undo`;
* PHP strings use `__()`, `_n()` and `_x()` with translator comments; JavaScript strings use `@wordpress/i18n` and are registered with `wp_set_script_translations()`;
* the plugin does not call `load_plugin_textdomain()`: WordPress loads language packs from `wp-content/languages/plugins/` just in time.

Once the plugin is in the directory, translations are managed at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/selective-undo/). `languages/selective-undo-ru_RU.po` is the complete Russian translation, ready to be imported there.

```sh
npm run build && bin/i18n.sh update     # regenerate the POT and merge new strings into languages/*.po
bin/i18n.sh compile <dir>               # build .mo, .l10n.php and JS .json files, as in a language pack
bin/i18n.sh install-test-site           # install them into the test site
```

To use a translation before its language pack is published, copy the files from `bin/i18n.sh compile` into `wp-content/languages/plugins/`.

## Release

```sh
bin/dist.sh             # builds dist/selective-undo/ and dist/selective-undo-<version>.zip
```

The package leaves out the paths listed in `.distignore`. Check it with [Plugin Check](https://wordpress.org/plugins/plugin-check/) before uploading:

```sh
wp plugin check selective-undo --include-experimental
```

Before a release, update `Version` in `selective-undo.php`, `Stable tag` and the changelog in `readme.txt`; `bin/dist.sh` refuses to build when the version and the stable tag differ.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
