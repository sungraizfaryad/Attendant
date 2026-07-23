# Attendant — Progress

_Last updated: 2026-07-23 (v2.1.0 "Gemini free" complete + live-verified, NOT deployed)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (branch `gemini-only` @ 1f8e3de)

- Google Gemini added as second provider: FREE chat + FREE embeddings, one no-card key. OpenAI untouched for existing installs. Old 6-provider/OpenRouter/Slack/debug direction abandoned — parked on `backup/v2.2.0-full`.
- LIVE-VERIFIED with a real free key on mui: 113 chunks embedded via Gemini ($0, 0 failures), RAG retrieval + search_posts tool call + reply working in the widget.
- Embedding-provider stamp (`attendant_embedding_provider`) keeps chunk/query/Q&A vectors in one space; full re-index re-stamps + blanks content hashes + re-embeds Q&A pairs. FULLTEXT/fuzzy fallback in `includes/fallback/` when stamped key missing.
- Wizard defaults to Gemini (free, AI Studio link); settings has 2-provider rows. Title: "Attendant - Free AI Site Search & Chatbot". Version 2.1.0.
- Provider-switch re-index banner (admin_notices) on all 5 plugin admin screens until full re-index re-stamps; indexing page gets "run Index All Content below" wording. Condition: `ATTENDANT_Provider_Factory::needs_full_reindex()`. Live-verified on mui.
- Default model `gemini-flash-lite-latest` (rolling alias, biggest free daily quota). All selectable models priced; estimate_cost fails closed (unknown id → highest rate) so the monthly-budget kill switch can't be blinded.
- 84 tests / 194 assertions green. Plugin Check 0 production errors. Adversarial review (14 agents) + security review findings all fixed. Both installs (mui + FLP) run this build; FLP 9,284 chunks intact. Build: `~/Desktop/attendant-2.1.0-gemini.zip`.

## Decisions (durable)

- Two providers only (google + openai). OpenRouter etc.: future maybe.
- Gemini models: use rolling aliases (`gemini-flash-lite-latest` / `gemini-flash-latest`) — pinned ids get retired for NEW keys while still appearing in the models list (so test-connection passes but generateContent 404s).
- Gemini 3 API contract: `thinkingLevel: minimal` (else thinking starves the reply under our token caps; `thinkingBudget: 0` is rejected), and replayed functionCall parts MUST echo part-level `thoughtSignature` + `id` or round 2 400s.
- Per-model free-tier quotas are separate buckets — Flash-Lite has the biggest daily allowance, hence default.
- Old local tags 2.1.0/2.2.0 point into the backup branch — delete before tagging the release.

## Next steps

- Sungraiz: final look at mui (chat live on Gemini) → say "ship".
- Ship: merge `gemini-only` → main, push GitHub, deploy.sh to WP.org as 2.1.0 (delete stale tags first; re-check readme screenshots/captions).
- Post-ship: FLP has dead 2.2.0-era options (handoff_*, slack placeholder) — harmless; clean whenever.

## Key files

- `includes/providers/` — interface, factory (+embedding stamp), google + openai providers.
- `includes/providers/class-attendant-google-provider.php` — Gemini translation incl. thoughtSignature/id round-trip, thinkingLevel, batch embeddings @1536.
- `includes/fallback/` — keyword retriever + fuzzy Q&A matcher.
- `tests/unit/GoogleProviderTest.php` — handler-shape regression tests for the tool round-trip.
