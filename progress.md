# Attendant — Progress

_Last updated: 2026-07-22 (v2.1.0 "Gemini free" built on branch gemini-only, NOT deployed)._
_Rolling status only. Detail lives in `CLAUDE.md` and the cloud memory entries._

## Done (v2.1.0 — branch gemini-only, local)

- Direction reset: reverted to v2.0.0 baseline (= GitHub main). The old local 2.1.0 (Smart Search) + 2.2.0 (6 providers/OpenRouter/Slack/debug) work is PARKED on `backup/v2.2.0-full` — not shipping, kept for parts.
- Google Gemini added as second provider: free chat (gemini-2.5-flash/-lite) + free embeddings (gemini-embedding-001 @1536 dims) — one free key, no card. OpenAI untouched for existing installs (still the default there).
- Embedding-provider stamp: chunk/query/Q&A vectors always share one space; full re-index re-stamps + blanks content hashes + re-embeds Q&A pairs. Legacy installs resolve to openai, fresh installs follow active provider.
- FULLTEXT/fuzzy fallback (includes/fallback/) when the stamped embedder's key is missing; FULLTEXT index added via version-drift migration runner (admin/cron only, priority 20).
- Settings: 2-provider selector (Gemini first, FREE labeled), per-provider key+model rows, re-index mismatch notice, reload-after-save. Wizard step 4: Gemini radio default + AI Studio link. readme/title lead with "Free (Google Gemini)".
- Adversarial review (14 agents) caught + fixed: empty functionResponse.name (Gemini 400 on every tool round-trip), gemini_id not threaded, stale-vector re-index skip, drift-runner cron-schedule race + ALTER-on-frontend.
- 81 tests / 191 assertions green. Plugin Check 0 production errors. Playwright: settings/wizard/chat verified, 0 console errors. Both installs (mui + FLP) run this build; FLP 9,284 chunks intact, db_version reset to 2.1.0.
- Build: ~/Desktop/attendant-2.1.0-gemini.zip.

## Decisions (durable)

- Two providers only (google + openai). OpenRouter/others: future maybe.
- Embeddings never mix vector spaces — stamp option `attendant_embedding_provider`, re-stamp only on FULL re-index with the new provider's key present.
- Gemini free tier confirmed: key without card (Google billing docs), embeddings "Free of charge" (pricing page), chat ~250–1,500 req/day by model/region.
- Gemini quirks encoded: always v1beta (v1 drops tools), merge consecutive same-role turns, functionResponse.name REQUIRED, echo functionCall id when present.
- Old tags 2.1.0/2.2.0 point into backup branch — delete before tagging the release.

## Next steps

- DONE 2026-07-22: live Gemini E2E verified on mui — free embeddings (113 chunks), RAG + tool call + reply in widget. Model default: gemini-flash-lite-latest (rolling alias, biggest free quota). Gotchas learned: pinned ids retired for new keys; thinkingLevel minimal required; thoughtSignature must round-trip; watch macOS DNS SERVFAIL caching + FPM opcache during dev.
- Title finalized: "Attendant - Free AI Site Search & Chatbot" (brackets dropped per Sungraiz).
- Ship decision: merge gemini-only → main, push GitHub, deploy.sh to WP.org as 2.1.0 (delete stale local tags first).
- FLP settings still contain dead 2.2.0-era options (handoff_*, slack webhook placeholder) — harmless, ignore or clean at ship.

## Key files

- `includes/providers/` — interface, factory (+embedding stamp), google + openai providers.
- `includes/fallback/` — keyword retriever + fuzzy Q&A matcher.
- `includes/class-attendant-conversation-handler.php` — round-2 tool messages carry name + gemini_id (Gemini requirement).
- `admin/views/settings.php` + `onboarding.php` — provider rows / wizard radio.
- `tests/unit/` — GoogleProviderTest (incl. handler-shape regression), ProviderFactoryTest, KeywordRetrieverTest, QAFuzzyMatcherTest.
