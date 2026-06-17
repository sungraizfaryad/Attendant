# Attendant — Progress

_Last updated: 2026-06-17 (rename complete)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (v2.0.0)

- Full rename: `aicm_` → `attendant_`, `AICM_` → `ATTENDANT_`, `AI_ChatMate` → `Attendant_Plugin`, text-domain `attendant`, REST namespace `attendant/v1`, nonce action `attendant_chat_nonce`, header `X-Attendant-Nonce`, localStorage key `attendant_chats_v1`.
- Migration on activate: copies all `aicm_*` options → `attendant_*`, RENAME TABLE for all 4 DB tables, renames log dir (via WP_Filesystem::move), clears old cron hooks.
- Data verified: 9,284 chunks preserved on FLP after table rename. Zero data loss.
- Browser-tested on both sites: settings save, chat flow, history cross-page/refresh, source chips, lead capture.
- FLP full test passed: real property data, 0 console errors, chat persists on `/property/` archive page.
- Plugin folder renamed `ai-chatmate/` → `attendant/` on both sites. Plugin re-activated via WP-CLI.
- 48 tests / 126 assertions pass. Build `~/Desktop/attendant-2.0.0.zip` (328K, Plugin Check 0 production errors).

## Decisions

- Migration runs first in `activate()`, before `create_tables()` — ensures old data available when tables checked.
- WP_Filesystem::move() used for log dir rename (Plugin Check requirement). WP_Filesystem() init is inline since this runs in activation hook.
- GitHub repo still named AI-ChatMate — rename on GitHub then update remote (see Next steps).

## Next steps

1. Rename GitHub repo AI-ChatMate → attendant, then: `git remote set-url origin https://github.com/sungraizfaryad/attendant.git && git push`.
2. Submit `~/Desktop/attendant-2.0.0.zip` to WP.org — reply to reviewer email confirming slug = `attendant`.

## Key files

- `includes/class-attendant-conversation-handler.php` — prompt + function-call orchestrator, chips fallbacks.
- `includes/class-attendant-query-builder.php` — operator whitelist, numeric normaliser, zero-result help.
- `includes/class-attendant-index-manager.php` — manual + background indexing + self-repair.
- `includes/class-attendant-leads.php` — callback capture.
- `public/js/attendant-widget.js` — localStorage history, chips, dual-nonce REST.
- `includes/class-attendant-activator.php` — migration logic from aicm_ to attendant_.
- `tests/unit/` — QueryBuilderTest, LeadsTest, ChoicesTest, FrontendReadyTest.
