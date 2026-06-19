# Attendant — Progress

_Last updated: 2026-06-19 (v2.0.0 live on WP.org)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (v2.0.0 — shipped)

- Slug `attendant` approved by WP.org reviewer team after rename round.
- Full prefix rename: `aicm_` → `attendant_`, `AICM_` → `ATTENDANT_`, `AI_ChatMate` → `Attendant_Plugin`, text-domain `attendant`, REST namespace `attendant/v1`, nonce `attendant_chat_nonce`, header `X-Attendant-Nonce`, localStorage `attendant_chats_v1`.
- Folder renamed `ai-chatmate/` → `attendant/` on both local installs; data-migrating `activate()` copies legacy `aicm_*` options + RENAME TABLE for all 4 DB tables + WP_Filesystem::move on log dir + clears old cron hooks.
- FLP install verified end-to-end after rename: 9,284 chunks preserved, OpenAI key intact, live chat tested with real property data, source chips work, history persists cross-page + reload, 0 console errors.
- WP.org SVN: trunk (r3578671), assets (r3578672, 9 files), tag `2.0.0/`. Public: https://wordpress.org/plugins/attendant.
- GitHub: https://github.com/sungraizfaryad/Attendant `main` @ `cc22b1b`, tag `2.0.0`.
- 48 tests / 126 assertions pass. Plugin Check 0 production errors.

## Decisions (durable)

- Activator migration runs first, before `create_tables()` — ensures legacy options/tables available when new tables are built.
- WP_Filesystem::move() for log dir rename (Plugin Check `WordPress.WP.AlternativeFunctions.rename_rename` blocker).
- `.svnignore` required — deploy.sh export drags in `tests/`, `docs/`, `CLAUDE.md`, `progress.md`, `phpcs.xml.dist`, etc. unless ignored. (`CLAUDE.md` contains FLP auto-login URL — must not ship.)
- Build script must `rm -f "$ZIP"` before `zip -rqX` — otherwise stale top-level dir from previous build remains in archive (= WP.org WRONGFORMAT).
- Banner + icon are textless: WP.org renders plugin title above banner, so wordmark would duplicate.

## Next steps

- Watch WP.org listing for asset cache to populate (~5–15 min after r3578672).
- Optional: add GitHub repo description + topics for discoverability.
- Address Plugin Check warnings (not errors) before v2.0.1: `WordPress.DB.DirectDatabaseQuery.DirectQuery/NoCaching` on the `RENAME TABLE` queries + transient deletes.
- Bump `Tested up to:` on next WP major.

## Key files

- `includes/class-attendant-conversation-handler.php` — prompt + function-call orchestrator, chips fallbacks.
- `includes/class-attendant-query-builder.php` — operator whitelist, numeric normaliser, zero-result help.
- `includes/class-attendant-index-manager.php` — manual + background indexing + self-repair.
- `includes/class-attendant-leads.php` — callback capture.
- `public/js/attendant-widget.js` — localStorage history, chips, dual-nonce REST.
- `includes/class-attendant-activator.php` — migration logic from `aicm_` to `attendant_`.
- `.svnignore` — controls what ships to WP.org SVN (mirrors `.distignore` + adds CLAUDE.md/progress.md).
- `tests/unit/` — QueryBuilderTest, LeadsTest, ChoicesTest, FrontendReadyTest, etc.
