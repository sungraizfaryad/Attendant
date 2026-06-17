# Attendant — Progress

_Last updated: 2026-06-15 (Opus 4.8 session)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (v2.0.0, on GitHub `main`)

- Display name Conciera → **Attendant** (trademark: "Conciera" was used by a same-field doc-finder). Picked via 4 rounds of live-search vetting: no plugin-slug clash, no live software TM, only Cisco "Attendant Console" (telephony, different field). Folder/slug/text-domain/`aicm_` prefix unchanged. Log dir made brand-neutral `aicm-logs-`.
- Search correctness: `<=` operator bug, number normaliser, underscore-meta rejection, zero-result enrichment.
- Indexing: manual + background loopback, 45s stall watchdog, cron self-repair, activity feed, incremental vs full.
- Widget gated until first index. Assets versioned by `filemtime`.
- Chat history: localStorage (10×80), per-chat server session id, New / Previous UI.
- Quick-reply chips: `suggest_choices` fn, forced `tool_choice` on zero-results, bullet→chips fallback, + phone-step Skip-chip net.
- Lead capture: opt-in, `is_email`, 1/session + 20/day, Reply-To = visitor.
- File chat logs: protected `uploads/aicm-logs-<key>/`, 30-day rotation, admin download.
- readme.txt rewritten to current features + a Privacy section (history / logging / lead-capture disclosure).
- 48 tests / 126 assertions. Plugin Check 0 errors. Build `~/Desktop/attendant-2.0.0.zip` (159K).
- Full real-time FLP browser test PASSED: search + source buttons, zero-result + lead capture (email fired), history cross-page/refresh, admin pages, 0 console errors.

## Decisions

- Folder / slug / text-domain / `aicm_` prefix stay despite display rename — preserves slug + stored API keys.
- Chat history in localStorage, not server. File logs in uploads (random key), not plugin dir.
- Log dir name brand-neutral (`aicm-logs-`) so a future rename never churns it.
- Round 2 after a search passes only `suggest_choices` (no search chaining).

## Next steps

1. Send the drafted reviewer reply email (new name = Attendant) to plugins@wordpress.org, then resubmit the zip.
2. (Deferred) `aicm_` / `ai_` PHP prefix rename. Owner postponed.

## Key files

- `includes/class-aicm-conversation-handler.php` — prompt + function-call orchestrator, chips fallbacks.
- `includes/class-aicm-query-builder.php` — operator whitelist, numeric normaliser, zero-result help.
- `includes/class-aicm-index-manager.php` — manual + background indexing + self-repair.
- `includes/class-aicm-leads.php` — callback capture.
- `public/js/aicm-widget.js` — localStorage history, chips, dual-nonce REST.
- `tests/unit/` — QueryBuilderTest, LeadsTest, ChoicesTest, FrontendReadyTest.
