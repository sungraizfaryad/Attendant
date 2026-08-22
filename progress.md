# Attendant — Progress

_Last updated: 2026-08-22 (v2.1.0 SHIPPED to WP.org + GitHub)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (main @ 47eae11 = tag 2.1.0, deployed)

- Google Gemini added as second provider: FREE chat + FREE embeddings, one no-card key. OpenAI untouched for existing installs. Old 6-provider/OpenRouter/Slack/debug direction abandoned — parked on `backup/v2.2.0-full`.
- LIVE-VERIFIED with a real free key on mui: 113 chunks embedded via Gemini ($0, 0 failures), RAG retrieval + search_posts tool call + reply working in the widget.
- Embedding-provider stamp (`attendant_embedding_provider`) keeps chunk/query/Q&A vectors in one space; full re-index re-stamps + blanks content hashes + re-embeds Q&A pairs. FULLTEXT/fuzzy fallback in `includes/fallback/` when stamped key missing.
- Wizard defaults to Gemini (free, AI Studio link); settings has 2-provider rows. Title: "Attendant - Free AI Site Search & Chatbot". Version 2.1.0.
- Provider-switch re-index banner (admin_notices) on all 5 plugin admin screens until full re-index re-stamps; indexing page gets "run Index All Content below" wording. Condition: `ATTENDANT_Provider_Factory::needs_full_reindex()`. Live-verified on mui.
- Default model `gemini-flash-lite-latest` (rolling alias, biggest free daily quota). All selectable models priced; estimate_cost fails closed (unknown id → highest rate) so the monthly-budget kill switch can't be blinded.
- Per-provider spend ledgers (`ATTENDANT_Billing`, options nested [slug => [date => usd]]): switch provider → analytics shows that provider's history only ($0 fresh for Gemini); budget kill-switch watches ACTIVE provider; monthly budget now actually gates /chat (was display-only). Legacy flat maps migrate to openai bucket; normalize_map() heals mixed shapes per key; migrate_from_aicm no longer clobbers existing attendant_* options. Costs labeled "estimated" + "$0 on free tier" notes for Gemini.
- Model dropdown: "fixed version" wording + layman bullets. Analytics logging notice deep-links to Settings #privacy (tabs follow hashchange now).
- Cross-tab chat sync in widget: storage event repaints other tabs live, saveStore() merges before write (append-only tail merge) so concurrent tabs can't lose messages. Live-verified 2 tabs on mui.
- `attendant_lead_captured` action fires after validated lead + send (email, name/phone/time/topic/session) — newsletter/CRM plugins hook it; readme FAQ documents. E2E-verified on mui (full chat flow → hook payload captured via mu-plugin).
- 92 tests / 212 assertions green. Plugin Check 0 production errors. Two adversarial review rounds, all confirmed findings fixed. Both installs (mui + FLP) run this build; FLP 9,284 chunks intact. Build: `~/Desktop/attendant-2.1.0-gemini.zip`.

## Decisions (durable)

- Two providers only (google + openai). OpenRouter etc.: future maybe.
- Gemini models: use rolling aliases (`gemini-flash-lite-latest` / `gemini-flash-latest`) — pinned ids get retired for NEW keys while still appearing in the models list (so test-connection passes but generateContent 404s).
- Gemini 3 API contract: `thinkingLevel: minimal` (else thinking starves the reply under our token caps; `thinkingBudget: 0` is rejected), and replayed functionCall parts MUST echo part-level `thoughtSignature` + `id` or round 2 400s.
- Per-model free-tier quotas are separate buckets — Flash-Lite has the biggest daily allowance, hence default.
- Old local tags 2.1.0/2.2.0 point into the backup branch — delete before tagging the release.

## Next steps (post-ship)

- SHIPPED 2026-08-22: WP.org SVN r3660619 (trunk+assets+tag 2.1.0), GitHub main 47eae11 + tag. `gemini-only` merged & deleted; `backup/v2.2.0-full` kept for parts.
- v2.2.0 plan: Slack handoff — port one-way notify from backup branch (half session), build two-way reply path (3-4 sessions: Slack app, events endpoint, widget polling, mode UX).
- Screenshots on WP.org still show 2.0.0-era UI — captions accurate; refresh via assets-only deploy whenever.
- FLP has dead 2.2.0-era options (handoff_*, slack placeholder) — harmless; clean whenever.

## Key files

- `includes/providers/` — interface, factory (+embedding stamp), google + openai providers.
- `includes/providers/class-attendant-google-provider.php` — Gemini translation incl. thoughtSignature/id round-trip, thinkingLevel, batch embeddings @1536.
- `includes/fallback/` — keyword retriever + fuzzy Q&A matcher.
- `tests/unit/GoogleProviderTest.php` — handler-shape regression tests for the tool round-trip.
