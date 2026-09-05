# Attendant — Progress

_Last updated: 2026-09-05. **v2.2.0 SHIPPED** to WP.org and GitHub. Slack
live-agent handoff is live, verified end to end on a real workspace including
the inbound direction._

## Shipped

- **v2.2.0 "Slack handoff"** live on WP.org (SVN r3682498 trunk + assets,
  r3682499 tag; tags 2.0.0 / 2.1.0 / 2.2.0) and GitHub (`main` @ e40439a,
  tag 2.2.0). 126 tests / 306 assertions, Plugin Check on the built zip 0
  errors. Build: `~/Desktop/attendant-2.2.0.zip` (644 KB).
- **v2.1.0 "Gemini free"** (SVN r3660619, GitHub tag 2.1.0 @ 47eae11).

## What shipped in 2.2.0 — the Slack layer

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

## Bugs found and fixed during real-workspace testing

Wizard reported success but the channel was unreachable. Two causes, both
reproduced live against Sungraiz's workspace via WP-CLI:

1. `api_call()` sent a JSON body. Slack's read methods ignore it and answer
   with defaults, so `users.conversations` fell back to `types=public_channel`
   and the just-created *private* channel never appeared ("No channels found").
   `users.lookupByEmail` was broken the same way, so invites always failed.
   Now form-encoded. Verified: `list_channels()` 0 → 1.
2. Nothing ever put a human in the channel. The bot creates it, so the bot is
   the only member; a private channel is not searchable, so the owner had no
   route in and the test message landed in an empty room.

Who-goes-in-the-channel is now a pick-list, not typing (Sungraiz's call —
typing an email was "not good"):

- `GET /slack/members` → `list_members()` (`users.list`, bots/deleted/Slackbot
  filtered, sorted by name). The handler marks one person `you`: an email
  matching the WP account, else `is_primary_owner`. `matched:false` means the
  tick is a guess and the wizard says so.
- Wizard step 6 renders that as a searchable checkbox list with `you`
  pre-ticked; create is blocked if nothing is ticked. If `users.list` fails
  (scope/transport) the step falls back to the old email textarea.
- Invites go out by user id (`invite_by_ids` — one `conversations.invite` with
  every id, partial failures read out of `errors`).
- The settings tab has NO people UI at all: no "Add people" row, no email box
  on Create channel. Adding people after setup is a Slack job. When that row
  creates a channel the server resolves the owner itself via
  `guess_owner_id()`.
- `create_channel()` still returns `alone`; wizard and settings warn instead of
  showing a tick.

**Reset done 2026-09-03 on media-usage-inspector only** (not FLP), so the
wizard starts clean: deleted `attendant_slack_bot_token`,
`attendant_slack_signing_secret`, `attendant_slack_debug` and 2 Slack
transients; blanked `slack_channel`, `slack_enabled` in `attendant_settings`.
All 42 other settings keys, the API keys and the index were left alone.
Backup of the deleted values:
`<session scratchpad>/slack-reset-backup.json`. Sungraiz is deleting the old
Slack app and re-creating it from the manifest URL (scopes unchanged).

## Next steps

- Screenshots open in a viewer, and the trigger is an underlined blue link
  ("click here to see the screenshot") printed INSIDE the step it explains —
  a list item or a hint paragraph. A row of buttons under the instructions
  read as decoration and got skipped. `attendant_wizard_shot()` prints (does
  not return) the markup and escapes each part itself: `wp_kses_post` strips
  `<button>` and every `data-` attribute, so call sites must echo it raw.
- `#attendant-wiz-shotview` is a full-screen scroller: the whole overlay is
  `overflow-y: auto`, the image is `width: 100%; height: auto`, and the close
  button is `position: fixed` in the screen's top-right corner (48px, dashicon
  at 30px) so it stays reachable however far down you scroll. Do NOT go back to
  fitting the image with `object-fit: contain` and `max-height: 100%` — a
  percentage height inside a flex column has no definite height to resolve
  against, so it silently falls back to the natural size, overflows, and gets
  clipped with no way to reach the rest. Opening resets `scrollTop` and focuses
  the scroller so arrow keys page through immediately. Escape closes the viewer
  first and the wizard only when no shot is open.
- Screenshots: six in `admin/images/` (`slack-step-create-1/2`,
  `slack-step-install-1/2`, `slack-step-credentials-1/2`), NATIVE resolution
  WebP q82, page background auto-cropped off the edges — 452 KB for the set.
  The first pass downscaled to 1200px and quantised to 256 colours: half the
  size but visibly pixelated against a 1100px panel on a retina screen. Do not
  downscale these again; the natural size is already at or under 2× the panel.
  `attendant_wizard_shot()` takes a base name with NO extension and probes
  webp/png/jpg, so a replacement can be dropped in any format.
- Step 3's copy was rewritten to match what the manifest flow actually does
  (Allow → Go to App Settings, not "Install to Workspace").
- Re-running the wizard shows a notice at the top of step 1 (gated on
  token + secret + channel all being present). It says plainly that re-running
  never touches Slack, then splits into three paths: reconnect (leave the
  credential boxes empty, they are kept), move to an existing channel, or make
  a new one. It does NOT tell people to delete the old channel — deleting is
  permanent, destroys support history, and is not required; only the NAME can
  collide, and `name_taken` already has a friendly error. Archive is the
  suggested tidy-up. Also warns that switching cuts off a live handoff.
- `slack_channel_name` now rides beside `slack_channel` so the UI can say
  "#website-chat" instead of "C0C07FE1NUQ" — set by create-channel, by the
  wizard's existing-channel path, and by a hidden field on the settings form.
  The sanitizer blanks a stale name whenever the id changes, so the label can
  never name one channel while messages go to another. Everything falls back to
  the raw id when the name is unknown.
- Still missing: `slack-step-signin` for step 1. And `slack-step-create-1`
  shows a `.local` request_url — worth re-shooting from the public site before
  release.
- Inbound Slack→site is CONFIRMED (2026-09-05, Sungraiz's workspace, fresh app
  from the manifest URL). The whole wizard runs clean start to finish. The
  status panel stays as the diagnostic for when it does not.
- readme.txt declares Slack under BOTH `== External services ==` and
  `== Privacy ==` — required by WP.org for a third party that receives visitor
  messages, and a common rejection reason if missed.
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
