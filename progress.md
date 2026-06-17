# Attendant — Progress

_Last updated: 2026-06-17 (full prefix rename session)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (v2.0.0)

- Full rename: `aicm_` → `attendant_`, `AICM_` → `ATTENDANT_`, `AI_ChatMate` → `Attendant_Plugin`, text-domain `attendant`, REST namespace `attendant/v1`, nonce action `attendant_chat_nonce`, header `X-Attendant-Nonce`, localStorage key `attendant_chats_v1`.
- Migration on activate: copies all `aicm_*` options → `attendant_*`, RENAME TABLE for all 4 DB tables, renames log dir, clears old cron hooks.
- Data verified: 9,284 chunks preserved on FLP after table rename. Zero data loss.
- Browser-tested on both sites: settings save, chat flow, history cross-page/refresh, source chips, lead capture.
- FLP full test passed: real property data, 0 console errors, chat persists on `/property/` archive page.
- 48 tests / 126 assertions pass. Build `~/Desktop/attendant-2.0.0.zip` (Plugin Check 0 errors).

## Decisions

- Migration runs first in `activate()`, before `create_tables()` — ensures old data available when tables checked.
- Folder on disk still named `ai-chatmate` — pending rename (requires deactivate/rename/reactivate on both sites, needs user approval per CLAUDE.md).
- GitHub repo URL update to `https://github.com/sungraizfaryad/attendant` needed after folder rename.

## Next steps

1. **Task 13**: Rename plugin folder `ai-chatmate` → `attendant` on both sites (needs user approval — deactivate, rename, reactivate).
2. **Task 14**: Rebuild zip from renamed folder, run Plugin Check 0 errors, push to GitHub, submit to WP.org.
3. Update build script in CLAUDE.md once folder is renamed.

## Key files

- `includes/class-attendant-conversation-handler.php` — prompt + function-call orchestrator, chips fallbacks.
- `includes/class-attendant-query-builder.php` — operator whitelist, numeric normaliser, zero-result help.
- `includes/class-attendant-index-manager.php` — manual + background indexing + self-repair.
- `includes/class-attendant-leads.php` — callback capture.
- `public/js/attendant-widget.js` — localStorage history, chips, dual-nonce REST.
- `includes/class-attendant-activator.php` — migration logic from aicm_ to attendant_.
- `tests/unit/` — QueryBuilderTest, LeadsTest, ChoicesTest, FrontendReadyTest.
