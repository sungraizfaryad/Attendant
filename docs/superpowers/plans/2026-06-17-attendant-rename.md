# Attendant Plugin — Full Rename to `attendant` Slug

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename every `aicm_` / `AICM_` / `ai-chatmate` identifier to `attendant_` / `ATTENDANT_` / `attendant` so the plugin slug, text-domain, PHP prefixes, JS globals, CSS handles, REST namespace, and DB tables all match the WP.org-approved slug `attendant`. Existing local installs (media-usage-inspector site and FLP site) must have their data migrated automatically on plugin activation.

**Architecture:** Bulk sed replacements handle the mechanical in-file changes first; then files are physically renamed; then a migration function (added after sed so old option names stay intact) copies WP options and renames DB tables on activation. Tests verify the rename is clean before browser-testing the full chat flow.

**Tech Stack:** PHP 8.0, PHPUnit 11, Brain Monkey, WP 6.0+, MySQL (RENAME TABLE), Local by Flywheel. All commands use the Local-bundled PHP binary.

**Working directory for all commands:** `/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate`

---

## Rename map (reference)

| Pattern | Old | New |
|---------|-----|-----|
| PHP constants + class names | `AICM_` | `ATTENDANT_` |
| PHP functions / options / hooks | `aicm_` | `attendant_` |
| CSS handles / file-path strings | `aicm-` | `attendant-` |
| Text-domain / slug | `ai-chatmate` | `attendant` |
| Shortcode tag | `ai_chatmate` | `attendant` |
| @package docblock | `AIChatMate` | `Attendant` |
| REST namespace | `aicm/v1` | `attendant/v1` |
| HTTP nonce header | `X-AICM-Nonce` | `X-Attendant-Nonce` |
| JS globals | `aicmChat` / `aicmIndexing` / `aicmQA` / `aicmSchema` / `aicmAdmin` | `attendantChat` / … |
| localStorage key | `aicm_chats_v1` | `attendant_chats_v1` |
| DB tables | `wp_aicm_chunks/qa/logs/queue` | `wp_attendant_chunks/qa/logs/queue` |
| Composer name | `sungraiz/ai-chatmate` | `sungraiz/attendant` |
| Plugin URI | `plugins/ai-chatmate` | `plugins/attendant` |

---

## Task 1: Bulk PHP in-file replace (sed)

**Files:** All `*.php` under the plugin root (excluding `vendor/`).

- [ ] **Step 1: Run the sed replacement**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"

find . -path ./vendor -prune -o -name "*.php" -print | xargs sed -i '' \
  -e 's/AICM_/ATTENDANT_/g' \
  -e 's/aicm_/attendant_/g' \
  -e 's/aicm-/attendant-/g' \
  -e 's/ai-chatmate/attendant/g' \
  -e 's/ai_chatmate/attendant/g' \
  -e 's/AIChatMate/Attendant/g' \
  -e 's|aicm/v1|attendant/v1|g' \
  -e 's/X-AICM-Nonce/X-Attendant-Nonce/g'
```

- [ ] **Step 2: Fix the Plugin URI in main plugin file**

Open `ai-chatmate.php` (not yet renamed). Change:
```
 * Plugin URI:  https://wordpress.org/plugins/ai-chatmate/
```
to:
```
 * Plugin URI:  https://wordpress.org/plugins/attendant/
```
This line was already handled by sed (`ai-chatmate` → `attendant`) so verify it looks correct:

```bash
grep "Plugin URI" ai-chatmate.php
```
Expected: `* Plugin URI:  https://wordpress.org/plugins/attendant/`

- [ ] **Step 3: Spot-check replacements look right**

```bash
grep -r "aicm_\|AICM_\|ai-chatmate\|ai_chatmate\|AIChatMate\|aicm/v1\|X-AICM" . --include="*.php" | grep -v vendor | grep -v "migrate_from_aicm\|'aicm_"
```

Expected: zero matches (the only remaining `aicm` strings will be in the migration function we add later — that's intentional and not yet present).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: bulk rename aicm_ → attendant_ across all PHP files"
```

---

## Task 2: Bulk JS in-file replace (sed)

**Files:** All `*.js` under `admin/js/` and `public/js/`.

- [ ] **Step 1: Run the sed replacement**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"

find . -path ./vendor -prune -o -name "*.js" -print | xargs sed -i '' \
  -e 's/aicmChat/attendantChat/g' \
  -e 's/aicmIndexing/attendantIndexing/g' \
  -e 's/aicmQA/attendantQA/g' \
  -e 's/aicmSchema/attendantSchema/g' \
  -e 's/aicmAdmin/attendantAdmin/g' \
  -e 's/aicm_chats_v1/attendant_chats_v1/g' \
  -e 's/aicm_/attendant_/g' \
  -e 's/aicm-/attendant-/g'
```

- [ ] **Step 2: Verify no aicm refs remain**

```bash
grep -r "aicm" . --include="*.js" | grep -v vendor
```
Expected: zero matches.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore: bulk rename aicm → attendant across all JS files"
```

---

## Task 3: Bulk CSS in-file replace (sed)

**Files:** All `*.css` under `admin/css/` and `public/css/`.

- [ ] **Step 1: Run the sed replacement**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"

find . -path ./vendor -prune -o -name "*.css" -print | xargs sed -i '' \
  -e 's/aicm-/attendant-/g' \
  -e 's/aicm_/attendant_/g'
```

- [ ] **Step 2: Verify**

```bash
grep -r "aicm" . --include="*.css" | grep -v vendor
```
Expected: zero matches.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "chore: bulk rename aicm → attendant across all CSS files"
```

---

## Task 4: Update readme.txt and composer.json

**Files:** `readme.txt`, `composer.json`

- [ ] **Step 1: Update readme.txt**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"
sed -i '' -e 's/ai-chatmate/attendant/g' -e 's/ai_chatmate/attendant/g' readme.txt
```

Verify the Stable tag line is intact:
```bash
grep "Stable tag\|Text Domain" readme.txt
```
Expected: no text-domain line in readme.txt (WP.org reads it from the plugin header). If present, ensure it reads `attendant`.

- [ ] **Step 2: Update composer.json**

Edit `composer.json`, change `"name": "sungraiz/ai-chatmate"` to `"name": "sungraiz/attendant"`.

Result:
```json
{
  "name": "sungraiz/attendant",
  "description": "Attendant dev tooling",
  ...
}
```

- [ ] **Step 3: Commit**

```bash
git add readme.txt composer.json
git commit -m "chore: update readme.txt and composer.json to attendant slug"
```

---

## Task 5: Rename PHP files

**Files:** 45 PHP files need renaming. Every `class-aicm-*.php` and `interface-aicm-*.php` becomes `class-attendant-*.php` / `interface-attendant-*.php`. The main entry file `ai-chatmate.php` becomes `attendant.php`.

- [ ] **Step 1: Rename the main plugin file**

```bash
mv "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/ai-chatmate.php" \
   "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/attendant.php"
```

- [ ] **Step 2: Rename all class- and interface- PHP files**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"

for f in $(find . -path ./vendor -prune -o -name "class-aicm-*.php" -print -o -name "interface-aicm-*.php" -print); do
  dir=$(dirname "$f")
  base=$(basename "$f")
  newbase="${base/aicm-/attendant-}"
  mv "$f" "$dir/$newbase"
done
```

- [ ] **Step 3: Verify all files renamed**

```bash
find . -path ./vendor -prune -o \( -name "class-aicm-*.php" -o -name "interface-aicm-*.php" -o -name "ai-chatmate.php" \) -print | grep -v vendor
```
Expected: zero matches.

```bash
find . -path ./vendor -prune -o -name "class-attendant-*.php" -print | grep -v vendor | sort
```
Expected: 20+ files listed.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: rename PHP files from class-aicm-* to class-attendant-*"
```

---

## Task 6: Rename JS and CSS files

**Files:** 6 JS files, 6 CSS files.

- [ ] **Step 1: Rename admin JS files**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/admin/js"
for f in aicm-*.js; do mv "$f" "${f/aicm-/attendant-}"; done
```

- [ ] **Step 2: Rename admin CSS files**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/admin/css"
for f in aicm-*.css; do mv "$f" "${f/aicm-/attendant-}"; done
```

- [ ] **Step 3: Rename public JS and CSS files**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/public/js"
mv aicm-widget.js attendant-widget.js

cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/public/css"
mv aicm-widget.css attendant-widget.css
```

- [ ] **Step 4: Verify no old-named asset files remain**

```bash
find "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate" -name "aicm-*.js" -o -name "aicm-*.css" | grep -v vendor
```
Expected: zero matches.

- [ ] **Step 5: Commit**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"
git add -A
git commit -m "chore: rename JS and CSS asset files from aicm-* to attendant-*"
```

---

## Task 7: Run PHPUnit — verify 48 tests still pass

**Why this runs before migration:** unit tests don't touch the DB; they verify the class rename is clean.

- [ ] **Step 1: Run the full test suite**

```bash
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"
"$PHP" vendor/bin/phpunit --no-coverage
```

Expected: `OK (48 tests, 126 assertions)` — or more if tests for migration are added later.

If any test fails, fix it before proceeding. Common failure modes:
- A test file still references `AICM_PLUGIN_DIR` (now `ATTENDANT_PLUGIN_DIR`) — update `tests/bootstrap.php`
- A test instantiates `AICM_SomeClass` — should already be renamed to `Attendant_SomeClass` by sed

- [ ] **Step 2: Commit if any test files needed fixing**

```bash
git add -A
git commit -m "fix: update test references after attendant rename"
```

---

## Task 8: Add data migration function to activator

**This task is done manually after sed, so the old `aicm_*` string literals in the migration map are not accidentally renamed.**

**File:** `includes/class-attendant-activator.php`

- [ ] **Step 1: Open the file and locate the `activate()` method**

```bash
grep -n "public static function activate" \
  "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/includes/class-attendant-activator.php"
```

- [ ] **Step 2: Add `self::migrate_from_aicm();` as the first call inside `activate()`**

The `activate()` body should become:
```php
public static function activate(): void {
    self::migrate_from_aicm();   // ← ADD THIS FIRST
    self::create_tables();
    self::set_default_options();
    self::schedule_cron_events();
    update_option( 'attendant_db_version', ATTENDANT_VERSION );
    flush_rewrite_rules();
}
```

- [ ] **Step 3: Add the `migrate_from_aicm()` method to the class**

Add this private static method anywhere inside the class body (e.g. right after `activate()`):

```php
private static function migrate_from_aicm(): void {
    global $wpdb;

    if ( get_option( 'attendant_migrated_from_aicm' ) ) {
        return;
    }

    $option_map = array(
        'aicm_settings'          => 'attendant_settings',
        'aicm_api_key_openai'    => 'attendant_api_key_openai',
        'aicm_api_key_anthropic' => 'attendant_api_key_anthropic',
        'aicm_api_key_google'    => 'attendant_api_key_google',
        'aicm_index_status'      => 'attendant_index_status',
        'aicm_db_version'        => 'attendant_db_version',
        'aicm_monthly_usage'     => 'attendant_monthly_usage',
        'aicm_log_dir_key'       => 'attendant_log_dir_key',
        'aicm_field_config'      => 'attendant_field_config',
        'aicm_onboarded'         => 'attendant_onboarded',
        'aicm_index_activity'    => 'attendant_index_activity',
        'aicm_index_lock'        => 'attendant_index_lock',
        'aicm_process_key'       => 'attendant_process_key',
        'aicm_daily_usage'       => 'attendant_daily_usage',
    );

    foreach ( $option_map as $old => $new ) {
        $val = get_option( $old );
        if ( false !== $val ) {
            update_option( $new, $val );
            delete_option( $old );
        }
    }

    $table_map = array(
        $wpdb->prefix . 'aicm_chunks' => $wpdb->prefix . 'attendant_chunks',
        $wpdb->prefix . 'aicm_qa'     => $wpdb->prefix . 'attendant_qa',
        $wpdb->prefix . 'aicm_logs'   => $wpdb->prefix . 'attendant_logs',
        $wpdb->prefix . 'aicm_queue'  => $wpdb->prefix . 'attendant_queue',
    );

    foreach ( $table_map as $old_tbl => $new_tbl ) {
        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_tbl ) );
        $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_tbl ) );
        if ( $old_exists && ! $new_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "RENAME TABLE `{$old_tbl}` TO `{$new_tbl}`" );
        }
    }

    $log_key = get_option( 'attendant_log_dir_key' );
    if ( $log_key ) {
        $upload = wp_upload_dir();
        $old_d  = $upload['basedir'] . '/aicm-logs-' . $log_key;
        $new_d  = $upload['basedir'] . '/attendant-logs-' . $log_key;
        if ( is_dir( $old_d ) && ! is_dir( $new_d ) ) {
            rename( $old_d, $new_d );
        }
    }

    wp_clear_scheduled_hook( 'aicm_weekly_schema_scan' );
    wp_clear_scheduled_hook( 'aicm_process_index_queue' );

    update_option( 'attendant_migrated_from_aicm', true );
}
```

- [ ] **Step 4: Verify the file has no stray `aicm_` outside the migration function**

```bash
grep -n "aicm_\|AICM_" \
  "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/includes/class-attendant-activator.php"
```

Expected: only lines inside `migrate_from_aicm()` contain `aicm_`. No `AICM_` anywhere.

- [ ] **Step 5: Commit**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"
git add includes/class-attendant-activator.php
git commit -m "feat: add migrate_from_aicm() to copy options and rename DB tables on activation"
```

---

## Task 9: Final grep — zero `aicm` references in shipped code

- [ ] **Step 1: Search for any remaining `aicm` in non-vendor, non-migration code**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate"
grep -rn "aicm" . --include="*.php" --include="*.js" --include="*.css" \
  | grep -v vendor \
  | grep -v "migrate_from_aicm\|'aicm_\|aicm_chunks\|aicm_qa\|aicm_logs\|aicm_queue\|aicm-logs-"
```

Expected: zero matches. Any hit here is a missed rename — fix before proceeding.

- [ ] **Step 2: Verify `attendant` appears correctly in the plugin header**

```bash
head -15 attendant.php
```

Expected output:
```
<?php
/**
 * Plugin Name: Attendant - AI Site Search & Content Finder
 * Plugin URI:  https://wordpress.org/plugins/attendant/
 * ...
 * Text Domain: attendant
```

---

## Task 10: Activate plugin on local media-usage-inspector site and test migration

The plugin is currently installed as `ai-chatmate` on the media-usage-inspector WordPress site. The activation hook runs `migrate_from_aicm()` which copies options and renames DB tables.

**Site:** `http://media-usage-inspector.local`
**Admin login:** Use Local auto-login if available, otherwise WP admin.

- [ ] **Step 1: Deactivate the old plugin (still named `ai-chatmate` in WP)**

Go to `wp-admin/plugins.php`, deactivate `Attendant - AI Site Search & Content Finder` (still showing as `ai-chatmate` folder at this point — the folder rename happens in Task 12).

Or via WP-CLI:
```bash
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
WP="/opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp"
RUN="/Users/sungraizfaryad/Library/Application Support/Local/run"
SITE_RID=$(grep -rl "media-usage-inspector" "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | head -1)
SOCK="$RUN/$SITE_RID/mysql/mysqld.sock"

"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  plugin deactivate ai-chatmate
```

- [ ] **Step 2: Activate the plugin (same folder, triggers activation hook)**

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  plugin activate ai-chatmate
```

- [ ] **Step 3: Verify migration ran — check options exist under new keys**

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  option get attendant_settings --format=json | head -5

"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  option get attendant_api_key_openai | head -1
```

Expected: `attendant_settings` returns a JSON object. `attendant_api_key_openai` returns an encrypted string (not empty).

- [ ] **Step 4: Verify old `aicm_` options are gone**

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  option get aicm_settings 2>&1
```

Expected: `Error: Could not get 'aicm_settings' option.`

- [ ] **Step 5: Verify DB tables renamed**

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  db query "SHOW TABLES LIKE 'wp_attendant%';"
```

Expected: 4 rows — `wp_attendant_chunks`, `wp_attendant_qa`, `wp_attendant_logs`, `wp_attendant_queue`.

- [ ] **Step 6: Verify migration flag is set**

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  option get attendant_migrated_from_aicm
```

Expected: `1`

---

## Task 11: Browser test — media-usage-inspector site

Open `http://media-usage-inspector.local/wp-admin` in a browser.

- [ ] **Step 1: Admin settings page loads**

Navigate to `Settings → Attendant`. All 6 tabs render without PHP errors. The API key field shows the existing key (not blank — confirms migration worked).

- [ ] **Step 2: Verify REST endpoint responds under new namespace**

Open browser DevTools → Network. Navigate to a front-end page. Send a message in the chat widget. Confirm the XHR goes to `/wp-json/attendant/v1/chat` (not `/wp-json/aicm/v1/chat`).

- [ ] **Step 3: Chat widget opens on front-end**

Go to `http://media-usage-inspector.local/`. The launcher button appears in the bottom-right corner. Click it — the chat panel opens.

- [ ] **Step 4: Send a message, get a response**

Type a question. The assistant responds. No console errors. No 401 / 403 from the REST endpoint.

- [ ] **Step 5: History persists on page refresh**

Reload the page. The previous chat messages are still visible (stored in `localStorage` under `attendant_chats_v1`).

- [ ] **Step 6: History persists across pages**

Navigate to a different page. The history panel shows the previous chat session.

- [ ] **Step 7: Check browser console for errors**

Open DevTools → Console. Should be zero errors after the page loads and after sending a message.

---

## Task 12: Test on FLP site

FLP site has ~4,160 real property listings and the most complete test data.

**Auto-login:** `http://flp.local/?localwp_auto_login=36` (plain HTTP only — do not use https).
**Note:** wps-hide-login is active on FLP — use the auto-login URL, not `/wp-admin`.

- [ ] **Step 1: rsync plugin to FLP**

```bash
rsync -av --delete \
  --exclude='.git' --exclude='.github' --exclude='.gitignore' \
  --exclude='vendor' --exclude='tests' --exclude='node_modules' \
  --exclude='docs' --exclude='composer.json' --exclude='composer.lock' \
  --exclude='phpunit.xml.dist' --exclude='phpcs.xml' \
  "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate/" \
  "/Users/sungraizfaryad/Local Sites/flp/app/public/wp-content/plugins/ai-chatmate/"
```

- [ ] **Step 2: Deactivate then activate on FLP to trigger migration**

```bash
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
WP="/opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp"
RUN="/Users/sungraizfaryad/Library/Application Support/Local/run"
SITE_RID=$(grep -rl "flp" "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | sort -u | head -1)
SOCK="$RUN/$SITE_RID/mysql/mysqld.sock"
FLP_PATH="/Users/sungraizfaryad/Local Sites/flp/app/public"

"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="$FLP_PATH" plugin deactivate ai-chatmate

"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="$FLP_PATH" plugin activate ai-chatmate
```

- [ ] **Step 3: Confirm migration on FLP**

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="$FLP_PATH" option get attendant_migrated_from_aicm
```
Expected: `1`

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="$FLP_PATH" db query "SHOW TABLES LIKE 'wp_attendant%';"
```
Expected: 4 tables.

```bash
"$PHP" -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
  "$WP" --path="$FLP_PATH" option get attendant_api_key_openai | head -1
```
Expected: non-empty encrypted string.

- [ ] **Step 4: Browser test on FLP**

Open `http://flp.local/?localwp_auto_login=36`.

Check:
- Chat widget visible on front-end.
- Send a property search query (e.g. "apartments in Lisbon under 500k"). Gets a real structured search response.
- Source buttons work (link to actual listings).
- History survives page refresh.
- History survives navigating to a property detail page and back.
- Admin settings page loads — API key field populated.
- Indexing tab shows correct chunk count (should match old `wp_attendant_chunks` count after rename).

- [ ] **Step 5: Zero browser console errors**

Open DevTools → Console on FLP. No errors on page load or after chatting.

---

## Task 13: Rename the plugin folder

**Do this after all tests pass.** The folder rename changes the plugin's path on disk. WP will show a "plugin file does not exist" warning if you rename the folder while the plugin is still activated in WP's DB.

- [ ] **Step 1: Deactivate on both sites first**

```bash
# media-usage-inspector
SOCK_MUI="$RUN/$(grep -rl 'media-usage-inspector' "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | head -1)/mysql/mysqld.sock"
"$PHP" -d mysqli.default_socket="$SOCK_MUI" -d pdo_mysql.default_socket="$SOCK_MUI" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  plugin deactivate ai-chatmate

# FLP
SOCK_FLP="$RUN/$(grep -rl 'flp' "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | sort -u | head -1)/mysql/mysqld.sock"
"$PHP" -d mysqli.default_socket="$SOCK_FLP" -d pdo_mysql.default_socket="$SOCK_FLP" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/flp/app/public" \
  plugin deactivate ai-chatmate
```

- [ ] **Step 2: Rename the folder on both sites**

```bash
mv "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/ai-chatmate" \
   "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/attendant"

mv "/Users/sungraizfaryad/Local Sites/flp/app/public/wp-content/plugins/ai-chatmate" \
   "/Users/sungraizfaryad/Local Sites/flp/app/public/wp-content/plugins/attendant"
```

- [ ] **Step 3: Re-activate on both sites**

```bash
"$PHP" -d mysqli.default_socket="$SOCK_MUI" -d pdo_mysql.default_socket="$SOCK_MUI" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" \
  plugin activate attendant

"$PHP" -d mysqli.default_socket="$SOCK_FLP" -d pdo_mysql.default_socket="$SOCK_FLP" \
  "$WP" --path="/Users/sungraizfaryad/Local Sites/flp/app/public" \
  plugin activate attendant
```

- [ ] **Step 4: Quick browser smoke test after folder rename**

Visit `http://media-usage-inspector.local/` and `http://flp.local/?localwp_auto_login=36`. Chat widget should still appear and respond without errors.

- [ ] **Step 5: git — update remote origin if needed**

The git remote points to `github.com/sungraizfaryad/AI-ChatMate`. We're not renaming the GitHub repo in this task — that's optional and separate. Just commit from the new folder path:

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/attendant"
git add -A
git commit -m "chore: rename plugin folder from ai-chatmate to attendant"
```

---

## Task 14: Update CLAUDE.md and progress.md + rebuild zip

**Files:** `CLAUDE.md`, `progress.md`

- [ ] **Step 1: Update CLAUDE.md**

Update these sections in `CLAUDE.md`:
- Header: change `ai-chatmate` → `attendant` in folder/slug/text-domain/prefix references
- "Folder, slug, text-domain, and `aicm_` PHP prefix are intentionally still `ai-chatmate`/`aicm_`" → remove this note, it no longer applies
- Build ZIP path: `~/Desktop/attendant-2.0.0.zip` → already correct
- Repo layout note: update canonical path from `ai-chatmate/` to `attendant/`
- Build command: update `SRC` variable to use `attendant` folder
- File references: `class-aicm-*.php` → `class-attendant-*.php`

Updated build command (update in CLAUDE.md):
```bash
SRC="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/attendant"
BUILD=/tmp/attendant-build
ZIP="$HOME/Desktop/attendant-2.0.0.zip"
rm -rf "$BUILD" && mkdir -p "$BUILD/attendant"
cp -R "$SRC"/. "$BUILD/attendant"/
( cd "$BUILD/attendant" && rm -rf .git .github .gitignore .distignore \
    composer.json composer.lock phpunit.xml.dist phpcs.xml phpcs.xml.dist \
    vendor tests node_modules .phpunit.result.cache docs )
find "$BUILD" \( -name '.DS_Store' -o -name '.playwright-mcp' \) -exec rm -rf {} +
( cd "$BUILD" && zip -rqX "$ZIP" attendant )
```

- [ ] **Step 2: Update progress.md**

Replace the "Next steps" section with:
```markdown
## Next steps

1. Reply to WP.org reviewer: plugin renamed to `attendant` (slug/text-domain/prefixes), resubmit zip.
2. (Optional) Rename GitHub repo from AI-ChatMate to attendant.
```

- [ ] **Step 3: Run Plugin Check on the build**

```bash
SRC="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/attendant"
BUILD=/tmp/attendant-build
ZIP="$HOME/Desktop/attendant-2.0.0.zip"
rm -rf "$BUILD" && mkdir -p "$BUILD/attendant"
cp -R "$SRC"/. "$BUILD/attendant"/
( cd "$BUILD/attendant" && rm -rf .git .github .gitignore .distignore \
    composer.json composer.lock phpunit.xml.dist phpcs.xml phpcs.xml.dist \
    vendor tests node_modules .phpunit.result.cache docs )
find "$BUILD" \( -name '.DS_Store' -o -name '.playwright-mcp' \) -exec rm -rf {} +
( cd "$BUILD" && zip -rqX "$ZIP" attendant )
echo "ZIP built: $ZIP"
```

Then run Plugin Check via WP-CLI on the unzipped copy (or upload to the Plugin Check tool in WP admin).

Expected: **0 errors**.

- [ ] **Step 4: Final commit**

```bash
cd "/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/attendant"
git add CLAUDE.md progress.md
git commit -m "docs: update CLAUDE.md and progress.md for attendant rename"
```

---

## Self-review checklist

- [x] All 14 option keys in `migrate_from_aicm()` match every `aicm_*` option found by grep
- [x] All 4 DB tables in `migrate_from_aicm()` match every `$wpdb->prefix . 'aicm_*'` found
- [x] Log directory rename included in migration
- [x] Old cron hooks cleared in migration
- [x] Migration guarded by `attendant_migrated_from_aicm` flag (idempotent)
- [x] Migration runs BEFORE `create_tables()` and `set_default_options()` so dbDelta and `add_option` are no-ops on existing installs
- [x] `X-AICM-Nonce` header renamed to `X-Attendant-Nonce` in both PHP (reader) and JS (sender)
- [x] Shortcode `[ai_chatmate]` → `[attendant]` covered by sed
- [x] localStorage key `aicm_chats_v1` → `attendant_chats_v1` covered in JS sed
- [x] Folder rename deferred to Task 13, after all tests pass
- [x] FLP test covers real property search + history + cross-page retention
