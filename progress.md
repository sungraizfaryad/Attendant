# Attendant — Progress

_Last updated: 2026-09-03. v2.1.0 SHIPPED. Slack live-agent handoff built on
branch `slack-handoff`, NOT merged, NOT shipped — awaiting Sungraiz's testing._

## Shipped

- **v2.1.0 "Gemini free"** live on WP.org (SVN r3660619, tags 2.0.0 + 2.1.0) and
  GitHub (`main` @ 1b3e0e2, tag 2.1.0 @ 47eae11). Title: "Attendant - Free AI
  Site Search & Chatbot". Readme rewritten in plain language (no dashes, no
  jargon); screenshots refreshed for the Gemini UI.

## In progress — branch `slack-handoff` (@ 6d8f4c3, 121 tests / 287 assertions)

Slack live-agent handoff. Each visitor conversation becomes ONE THREAD in the
owner's Slack channel; team replies land back in the chat widget.

- **Architecture**: `includes/integrations/class-attendant-slack.php` +
  REST routes `/chat/handoff`, `/chat/poll`, `/slack/events`,
  `/slack/channels`, `/slack/create-channel`, `/slack/test`. Session↔thread
  maps and the agent-message queue are transients (24h), no new tables.
- **Security**: Slack request-signature HMAC (5-min replay window, retries
  skipped, dedup by event_id). Every handoff mints a 48-char per-session
  secret (stored hashed) sent as `X-Attendant-Handoff` — the session id alone
  is NOT a credential (an earlier review found it was; see CLAUDE.md mines).
- **Setup wizard** (modal, `admin/views/partials/slack-wizard.php` +
  `admin/js/attendant-slack-wizard.js`): sign in → create pre-filled app →
  install → paste secret+token → create or choose channel → test → finish.
  Integrations tab shows ONLY the wizard CTA until setup completes, then the
  saved settings + "Re-run Slack setup".
- **Channel provisioning**: the wizard creates the channel (bot owns it, so no
  manual /invite) and invites teammates by email.
- **Conditional human button**: hidden until the AI actually falls short
  (unsourced + admits defeat, 2 misses in a row, or >6 visitor turns). On
  handoff the AI writes a handover brief that leads the Slack thread.
- **Diagnostics**: "Slack connection status" panel shows the last inbound event
  and why it was skipped (wrong channel, bad signature, …). All Slack error
  codes map to plain-language messages with fix links.

## Next steps

- **Sungraiz is testing the wizard** on a real workspace. Two prerequisites:
  re-create/reinstall the Slack app (new scopes: channels:manage, groups:write,
  users:read, users:read.email) and note Slack must reach the site (no local).
- Screenshots pending from Sungraiz: drop PNGs into `admin/images/` named
  `slack-step-signin.png`, `slack-step-create.png`, `slack-step-install.png`,
  `slack-step-credentials.png` — they auto-render in the matching wizard steps.
- Known-unverified: inbound Slack→site direction never confirmed on a real
  workspace (outbound works). The status panel exists to diagnose exactly that.
- Not done, deliberately: full OAuth "Add to Slack" (needs a hosted broker or
  swaps which two secrets get copied — see the Slack notes in CLAUDE.md).
- After Slack: WhatsApp reuses the same inbound/outbound machinery.

## Key files

- `includes/integrations/class-attendant-slack.php` — the whole Slack layer.
- `includes/class-attendant-conversation-handler.php` — `should_offer_human()`,
  `summarize_for_handoff()`.
- `public/js/attendant-widget.js` — handoff, polling, cross-tab sync, dividers.
- `admin/views/partials/slack-wizard.php` + `admin/js/attendant-slack-wizard.js`.
- `tests/unit/SlackTest.php`, `tests/unit/OfferHumanTest.php`.
