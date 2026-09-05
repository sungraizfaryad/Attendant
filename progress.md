# Attendant — Progress

_Last updated: 2026-09-05. v2.2.1 live on WP.org + GitHub. Nothing in flight._

## Done

- **v2.2.1** (SVN r3682719) — Slack app named after the site via `app_name()`.
- **v2.2.0** (SVN r3682498/9) — Slack live-agent handoff, verified end to end on
  a real workspace **including inbound**. Wizard, member pick-list, conditional
  "talk to a person" button, AI handover brief, connection-status panel. Slack
  declared under readme External services + Privacy.
- **v2.1.0** (r3660619) Gemini free tier · **v2.0.0** structured-search rebuild.
- 128 tests / 329 assertions. Plugin Check on the built zip: 0 errors.

## Decisions (do not re-open without a business reason)

- Bring-your-own Slack app; OAuth needs a hosted broker. One app **per site** —
  an app carries one event URL; many apps in one workspace is the multi-site setup.
- People join the channel from a pick-list at setup; after that it is a Slack job,
  not a plugin screen. Re-running the wizard never touches Slack, and never tells
  anyone to delete a channel (permanent, destroys support history).

## Next steps

- WhatsApp, reusing the same inbound/outbound machinery.
- Cosmetic: no `slack-step-signin` shot for step 1; `slack-step-create-1.webp`
  has the dev domain pixelated rather than re-shot from a public site.
- Stale: `phpcs.xml.dist` still lists `aicm`/`AICM`/`AI_ChatMate` prefixes, so
  every `attendant_*` name reports a false prefix/text-domain error.
- FLP test install still has the old `ai-chatmate` copy.

## Key files

`includes/integrations/class-attendant-slack.php` · `class-attendant-rest-api.php`
· `class-attendant-conversation-handler.php` · `public/js/attendant-widget.js` ·
`admin/views/partials/slack-wizard.php` + `admin/js/attendant-slack-wizard.js` ·
`tests/unit/SlackTest.php`, `tests/unit/OfferHumanTest.php`
