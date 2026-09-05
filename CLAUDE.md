# Attendant — Plugin Working Notes

Read this BEFORE touching code. It captures what the codebase looks like, why
specific decisions were made, and the hard-won gotchas that aren't visible
from a `grep`. If something here disagrees with the current source, trust the
source and update this file.

## What this plugin is

WP.org submission name: **Attendant - AI Site Search & Content Finder**
Slug: `attendant`. PHP prefix: `attendant_` / `ATTENDANT_`. Text-domain: `attendant`.
Folder on disk still named `ai-chatmate` — pending rename (Task 13). GitHub:
`https://github.com/sungraizfaryad/attendant`.

A WordPress plugin that adds an opt-in, RAG-powered chat assistant to any
site. It indexes posts/CPTs into a `wp_aicm_chunks` table, retrieves relevant
chunks at chat time, and lets the model call `search_posts` / `capture_lead`
/ `suggest_choices` AI functions. The site owner gets a tabbed admin UI,
manual indexing controls, file-based chat logs, and email lead capture.

## Repo layout

- This folder (`media-usage-inspector/.../plugins/ai-chatmate/`) IS the canonical
  git repo. No separate canonical-vs-test split (unlike UNMAM). Folder rename to
  `attendant/` is pending (requires deactivate on both sites + rsync + reactivate).
- FLP install (`~/Local Sites/flp/app/public/wp-content/plugins/ai-chatmate/`) is
  test-only; rsync to it after every change. Real 4,160-property dataset.
- Build zip lives at `~/Desktop/attendant-2.2.1.zip` (Plugin Check 0 errors).

## Don't trip these mines

- **Never run `sanitize_text_field` on text that may contain `<`**. WordPress
  treats `<=` as an unclosed HTML tag and strips it to `=`. This was the
  "no properties in Spain under 2M" bug. See `class-aicm-query-builder.php`
  `normalize_numeric()` and the compare-operator whitelist.
- **Never use `GLOB_BRACE`**. Undefined on musl-libc PHP (Alpine images) → fatal
  mid-uninstall. Use `glob('*')` + explicit checks.
- **Never derive the log-dir name from `wp_salt()`**. Salt rotation orphans
  the directory. We store a random key in option `attendant_log_dir_key`.
- **Never trust the model to write a choice list as text**. gpt-4o-mini ignores
  the "use suggest_choices" instruction maybe 30% of the time. The fix is
  belt-and-braces: forced `tool_choice` on zero-result round 2 + deterministic
  bullet→chips fallback in `extract_text_choices()`. The lead-flow phone step
  gets a third net — a single "Skip" chip injected server-side when the reply
  offers to skip the phone/number step but the model produced no chips.
- **Enqueue assets with `filemtime()`, not `ATTENDANT_VERSION`**. Visitors aggressively
  cache `attendant-widget.js`; releasing a new feature without filemtime versioning
  silently dropped chips/history for returning visitors.
- **Round 2 of a function call passes ONLY `suggest_choices`** to prevent the
  model chaining another search after seeing the result.
- **Gemini: never pin model ids.** Google retires pinned ids (e.g.
  gemini-2.5-flash) for NEW API keys while the id still appears in GET /models
  — test-connection passes, generateContent 404s. Use the rolling aliases
  (`gemini-flash-lite-latest` default). Each model has its OWN free-tier daily
  quota bucket; Lite's is the biggest.
- **Gemini 3 function calling has two hard requirements** or round 2 400s:
  the replayed functionCall part must echo the part-level `thoughtSignature`
  AND the call `id`. Both are captured in the provider's `function_call`
  result (`thought_sig`, `gemini_id`) and threaded through
  `build_messages_with_tool_result()` — do not drop those keys.
- **Gemini 3 thinks by default** and reasoning tokens count against
  maxOutputTokens — under our tight caps the visible reply starves
  (finishReason MAX_TOKENS). We send `thinkingConfig.thinkingLevel: minimal`;
  the older `thinkingBudget: 0` form is REJECTED by Gemini 3 models.
- **Never mix embedding vector spaces.** `attendant_embedding_provider`
  stamps which provider built the index; queries/Q&A must embed with the
  stamped provider. Only a FULL re-index (with the new provider's key) may
  re-stamp — and it must blank `content_hash` first, or the embedder's
  skip-unchanged optimization strands old vectors under the new stamp.
- **Every model in the settings allowlist needs a PRICING row** in its
  provider class — `estimate_cost()` feeds the monthly-budget kill switch,
  and the fallback bills at the highest known rate (fail closed), never $0.
- **A `hidden` attribute loses to your own `display` rule.** The wizard modal
  shipped visible-on-load and un-closable because
  `.attendant-wiz-overlay { display: flex }` outranks the UA's
  `[hidden] { display: none }`. Any element you hide via the attribute needs an
  explicit `[hidden] { display: none !important }` companion rule.
- **Version admin CSS/JS by `filemtime()`, not `ATTENDANT_VERSION`** — same trap
  the widget already had. Between releases the version never changes, so an
  edited stylesheet keeps serving the old UI and you debug a fix that is
  already applied.
- **Slack: the chat session id is NOT a credential.** `wp_create_nonce` returns
  the SAME value for every logged-out visitor, so a nonce + guessed session id
  once let anyone read or post into another visitor's live handoff. Every
  handoff mints a 48-char secret (stored as a hash) that must be presented as
  `X-Attendant-Handoff` on poll / live send / end. Never gate a per-visitor
  resource on the nonce alone.
- **Slack setup is bring-your-own-app on purpose.** One shared app would need a
  hosted OAuth broker (customer domains can't be pre-registered) plus a router
  for inbound events — i.e. a SaaS backend. `apps.manifest.create` exists but
  needs an obscure 12-hour config token AND still cannot mint the bot token, so
  it makes setup harder, not easier. The pre-filled manifest URL is the
  simplest path Slack offers; don't "improve" it into OAuth without deciding to
  run a server.
- **Never send Slack arguments as a JSON body.** Slack's docs claim both
  content types are accepted, but in practice the read methods
  (`users.conversations`, `conversations.info`, `conversations.members`,
  `users.lookupByEmail`) drop a JSON body entirely: the call still returns 200
  with `ok:true` and *default* arguments. That is how a private channel the bot
  had just created vanished from the picker — `types` never arrived, so
  `users.conversations` fell back to `public_channel`. `conversations.info`
  400s with `invalid_arguments` on the same body, which is the tell. `api_call()`
  form-encodes everything (bools → `'true'`/`'false'`, arrays → JSON string).
- **A channel the bot created has exactly one member: the bot.** It is not a
  working setup — the owner cannot read it, and a *private* channel does not
  appear in Slack search, so there is no way back in without an invite by
  user id. `create_channel()` reports `alone` and the UI warns instead of
  showing a tick.
- **One Slack app carries exactly ONE event request URL**, baked in from
  `rest_url()` at manifest time. So a site running the plugin needs its OWN
  Slack app — reusing one across sites leaves outbound working everywhere while
  every inbound reply goes to whichever site owns the URL (which logs
  `skipped_wrong_channel` and drops it). Many apps in one workspace is fine and
  is the supported setup; `app_name()` names each after its site so they can be
  told apart, within Slack's 35-char cap.
- **The plugin can never remove a Slack channel** — there is no archive or
  delete call anywhere, and Slack gives bots no delete. Re-running the wizard
  therefore changes nothing inside Slack; picking "create a new channel" just
  makes a second one and re-points `slack_channel`. Only the channel NAME can
  collide (`name_taken`, and an archived channel still holds its name). Never
  write copy telling owners to delete a channel: it is permanent and those
  threads are their support history.
- **A bot token carries no identity.** Slack never tells an app which human
  pasted the token into wp-admin, so the plugin cannot know who the site owner
  is in Slack. The bridge is `users.list` + a pick-list (`guess_owner_id()`
  falls back to `is_primary_owner` when no email matches the WP account).
  Don't reintroduce "type your email" — Sungraiz rejected it, and adding
  people after setup belongs in Slack, not in our settings screen.
- **Private channels need `users.conversations`, not `conversations.list`** —
  the latter does not reliably surface them. The bot must also be a member,
  which is why the wizard creates the channel itself (creator = member, so no
  manual `/invite`). Scopes for that: `channels:manage`, `groups:write`,
  `users:read`, `users:read.email` — adding them means existing apps must be
  reinstalled.
- **Local-dev traps seen here:** macOS negative-caches a router DNS SERVFAIL
  (curl fails while nslookup works → `dscacheutil -flushcache`), and Local's
  PHP-FPM opcache can serve stale plugin code while WP-CLI runs fresh files —
  if CLI and browser disagree, flush opcache before debugging further.

## How to develop here (Local by Flywheel)

```sh
# Sungraiz's recipe for WP-CLI through Local's bundled PHP:
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
WP="/opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp"

# Run-IDs are dynamic — find by grepping the Local run config:
RUN="/Users/sungraizfaryad/Library/Application Support/Local/run"
SITE_RID() { grep -rl "$1" "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | sort -u | head -1; }
# media-usage-inspector at time of writing: H5cgHPi5B
# flp:                                       8-CWukao6

SOCK="$RUN/$(SITE_RID flp)/mysql/mysqld.sock"
$PHP -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
    $WP --path="/Users/sungraizfaryad/Local Sites/flp/app/public" plugin list
```

FLP auto-login (admin, no password — only over plain HTTP, https drops the param):
`http://flp.local/?localwp_auto_login=36`. wps-hide-login + WP Rocket installed there.

When testing leads or other email flows on FLP, drop in this mu-plugin first —
wp-mail-smtp is configured and WILL send real mail otherwise:

```php
// /wp-content/mu-plugins/aicm-test-mail-intercept.php (DELETE WHEN DONE)
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
    file_put_contents( '/tmp/aicm-intercepted-mail.json',
        wp_json_encode( $atts, JSON_PRETTY_PRINT ) . "\n---\n", FILE_APPEND );
    return true;
}, 10, 2 );
```

## Architecture map

- `includes/class-attendant-conversation-handler.php` — orchestrator. Builds system
  prompt, calls provider, handles function-call branches, owns prompt rules
  for brevity / chips / lead capture. `extract_text_choices()` is the safety
  net for the chips system.
- `includes/class-attendant-query-builder.php` — translates `search_posts` args into
  `WP_Query`. Owns the operator whitelist, numeric normaliser, NUMERIC-type
  inference, underscore-meta rejection, and `zero_results_help()`.
- `includes/class-attendant-index-manager.php` — manual + background indexing. Has
  `enqueue_full_reindex(bool $only_new)`, `ensure_cron()` self-repair,
  loopback chain via admin-ajax + hash_equals.
- `includes/class-attendant-leads.php` — opt-in callback capture. PHP owns
  `is_email`, session lock, daily cap, mail headers.
- `includes/class-attendant-chat-log.php` — JSONL daily logs in a protected uploads
  subdir, admin-only download.
- `includes/class-attendant-rest-api.php` — `/chat`, `/index/*`, `/settings`.
  `options` key in response carries quick-reply chips. Dual nonce
  (`X-WP-Nonce` + `X-Attendant-Nonce`).
- `public/js/attendant-widget.js` — vanilla. localStorage `attendant_chats_v1`
  (10 chats × 80 msgs), per-chat server `session_id`, chips render via
  `renderChips()`, init runs on `DOMContentLoaded` because markup prints at
  `wp_footer` priority 100.
- `includes/integrations/class-attendant-slack.php` — the whole Slack layer.
  `api_call()` (form-encoded, see mines), `list_channels()` / `list_members()`,
  `create_channel()` (reports `alone`), `invite_by_ids()`, `guess_owner_id()`,
  `has_human_member()`, `friendly_error()` (every Slack code → plain English +
  a fix link), `manifest()` / `manifest_url()` / `app_name()`. Session↔thread
  maps and the agent-message queue are 24h transients, no new tables.
  `slack_channel_name` is stored beside `slack_channel` purely as a label so the
  UI can say "#support" not "C0C07FE1NUQ" — the settings sanitizer BLANKS it
  whenever the id changes, so a stale name can never label the wrong channel.
- Slack REST routes live in `class-attendant-rest-api.php`: `/chat/handoff`,
  `/chat/poll`, `/slack/events`, `/slack/channels`, `/slack/members`,
  `/slack/create-channel`, `/slack/test`. `/slack/events` is public and gated by
  Slack's request-signature HMAC (5-min replay window, retries skipped, dedup by
  event_id); it logs WHY it skipped an event, which the Integrations tab shows.
- `admin/views/partials/slack-wizard.php` + `admin/js/attendant-slack-wizard.js`
  — the setup wizard. `attendant_wizard_shot()` PRINTS an inline blue link (base
  filename, no extension; probes webp/png/jpg) that opens
  `#attendant-wiz-shotview`, a full-screen scroller. Never wrap that output in
  `wp_kses_post` — it strips `<button>` and every `data-` attribute. Screenshots
  live in `admin/images/` at native resolution as WebP; do NOT downscale them,
  the panel is 1100px and they end up pixelated.
- `admin/views/settings.php` — six tabs, submenu is registered LAST.
- `uninstall.php` — multisite-safe; calls `ATTENDANT_Chat_Log::delete_all()` inside
  the per-site function.

## Tests

```sh
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
$PHP vendor/bin/phpunit --no-coverage
```

Brain Monkey + PHPUnit 11. `tests/bootstrap.php` stubs WP constants
(`MINUTE_IN_SECONDS`, `DAY_IN_SECONDS`, `ABSPATH`, `ATTENDANT_PLUGIN_DIR`). When
stubbing `sanitize_text_field` for a new test, use
`static fn( $v ) => trim( strip_tags( (string) $v ) )` — the pass-through stub
HID the operator bug.

128 tests / 329 assertions. Plugin Check on the zipped build must report **0 errors**.

## Build & ship

```sh
SRC="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public/wp-content/plugins/attendant"
BUILD=/tmp/attendant-build
ZIP="$HOME/Desktop/attendant-2.2.1.zip"
rm -f "$ZIP"                       # zip APPENDS to an existing archive — stale top dirs cause WP.org WRONGFORMAT
rm -rf "$BUILD" && mkdir -p "$BUILD/attendant"
cp -R "$SRC"/. "$BUILD/attendant"/
( cd "$BUILD/attendant" && rm -rf .git .github .gitignore .distignore \
    .svnignore .wordpress-org CLAUDE.md progress.md \
    composer.json composer.lock phpunit.xml.dist phpcs.xml phpcs.xml.dist \
    vendor tests node_modules .phpunit.result.cache docs )
find "$BUILD" \( -name '.DS_Store' -o -name '.playwright-mcp' \) -exec rm -rf {} +
( cd "$BUILD" && zip -rqX "$ZIP" attendant )
```

### Releasing to WP.org

Run Plugin Check on the **built copy**, never in place — in place it reports
~23 errors that are all dev files the build strips (`tests/`, `phpcs.xml.dist`,
`.DS_Store`). On the build it must be **0 errors**.

```sh
$WP plugin check /tmp/attendant-build/attendant --format=csv --fields=type,code
```

Then bump `Version:` in `attendant.php`, `ATTENDANT_VERSION`, and readme
`Stable tag:` (all three must match), add a `== Changelog ==` entry plus a
matching `== Upgrade Notice ==` (that one is the short line WordPress shows
inside wp-admin before someone updates), commit, and tag `X.Y.Z` — deploy.sh
refuses to run without a git tag matching the version.

Attendant's canonical repo is this folder, not `~/Local Sites/plugins/<slug>/`,
so deploy.sh needs its path passed at Q2:

```sh
rm -rf /tmp/attendant
cd ~/Local\ Sites/plugins
printf 'attendant\n<abs path to this folder>\n\n\n\n\n\ny\n' | ./deploy.sh
```

deploy.sh honours `.svnignore` (NOT `.distignore`) — it exports git HEAD, so
without `.svnignore` your tests and config ship publicly. It also prints
`svn: E125001: '/tmp/attendant/tags/X.Y.Z/trunk' does not exist` on the tag
step and commits the tag correctly anyway. Harmless, but never trust
`*** FIN ***` — verify:

```sh
svn ls https://plugins.svn.wordpress.org/attendant/tags/X.Y.Z/   # files at the ROOT, no nested trunk/
svn cat https://plugins.svn.wordpress.org/attendant/trunk/readme.txt | sed -n 6p
curl -s https://api.wordpress.org/plugins/info/1.0/attendant.json   # version goes live in a minute or two
```

**Any third party that receives user data must be declared in readme.txt** under
`== External services ==`, and echoed under `== Privacy ==` — what is sent, when,
and links to that service's terms and privacy policy. Missing it is a common
rejection. Currently declared: Google Gemini, OpenAI, Slack.

## When in doubt

- See `progress.md` (this folder) for current status.
- See cloud memory at `~/.claude/projects/-Users-sungraizfaryad-Local-Sites-media-usage-inspector/memory/`
  — entries prefixed `project_attendant_*` / `reference_ai_chatmate_*` belong to
  this plugin. Entries prefixed with other plugin names (unmam, curator_ai,
  simple_wp_slider, remove_taxonomy_url) are siblings — don't touch them.
- `wp-admin/` and `wp-includes/` are WordPress core. Never edit.
