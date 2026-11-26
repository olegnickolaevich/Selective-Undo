# Undoly – Undoly

Undo a specific content change in WordPress and keep everything else.

Undoly records changes of the title, content, excerpt and menu order of posts, pages and chosen custom post types, whatever made them (block editor, Quick Edit, bulk edit, REST API, WP-CLI, other plugins). You can restore individual fields later. A field that was changed again after the selected change is reported as a conflict and skipped, never overwritten.

The user-facing description, FAQ and privacy notes are in [`readme.txt`](readme.txt), in the format of the WordPress.org plugin directory.

## Requirements

* WordPress 6.8 or newer (tested up to 7.1)
* PHP 8.2 or newer with the mysqli extension
* MySQL or MariaDB with InnoDB tables; tested with MySQL 8.4 and MariaDB 10.11. Without InnoDB, restoring is blocked and recording still works.

## Repository layout

| Path | Contents |
| --- | --- |
| `undoly.php`, `uninstall.php` | Plugin entry points |
| `src/` | PHP code (namespace `SelectiveUndo`, PSR-4, no runtime Composer dependencies) |
| `assets/admin/` | Admin interface source (React, TypeScript) |
| `build/` | Compiled admin interface, committed so that the plugin works without a build step |
| `languages/` | `undoly.pot` and translations (`.po`) |
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

`SU_DB_IMAGE=mariadb:10.11 bin/test-env.sh up` runs the same site on MariaDB. Without Docker, `SU_DB_ENGINE=sqlite SU_TEST_SITE=$PWD/.work/sqlite-site bin/test-env.sh up` creates a SQLite site (compatibility mode); pass the same `SU_TEST_SITE` to `php tests/run.php`.

### Comments

Comments explain why, not what the code already says. In English, short:

* a docblock starts with one sentence; a single line (`/** Summary. */`, `/** @return list<int> */`) stays on one line;
* tags only where the signature cannot say it: array shapes and generics for PHPStan, `@throws`, `@api`; TypeScript types document parameters, so no `@param` without a description;
* a comment on its own line is a sentence (capital letter, period), a comment after code is a lowercase fragment;
* `phpcs:ignore` names the sniff and gives a reason: `// phpcs:ignore Sniff.Name -- Reason.`;
* no references to tickets, plans or internal documents.

## Translations

Translations follow the WordPress.org conventions:

* source strings are in English, the text domain is the plugin slug `undoly`;
* PHP strings use `__()`, `_n()` and `_x()` with translator comments; JavaScript strings use `@wordpress/i18n` and are registered with `wp_set_script_translations()`;
* the plugin does not call `load_plugin_textdomain()`: WordPress loads language packs from `wp-content/languages/plugins/` just in time.

Once the plugin is in the directory, translations are managed at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/undoly/). `languages/undoly-ru_RU.po` is the complete Russian translation, ready to be imported there.

```sh
npm run build && bin/i18n.sh update     # regenerate the POT and merge new strings into languages/*.po
bin/i18n.sh compile <dir>               # build .mo, .l10n.php and JS .json files, as in a language pack
bin/i18n.sh install-test-site           # install them into the test site
```

To use a translation before its language pack is published, copy the files from `bin/i18n.sh compile` into `wp-content/languages/plugins/`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
