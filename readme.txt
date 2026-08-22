=== Attendant - Free AI Site Search & Chatbot ===
Contributors:      sungraizfaryad
Tags:              ai, site-search, chatbot, gemini, free
Requires at least: 6.0
Tested up to:      7.1
Stable tag:        2.1.0
Requires PHP:      8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Free AI chatbot and smart site search for WordPress. Runs on Google Gemini's free plan. No credit card, no subscription, no monthly fees.

== Description ==

Attendant adds a free AI chatbot to your WordPress site. Visitors ask questions in the chat, and it answers using your own posts, pages, and products, with buttons that take them straight to the right page. It is a chatbot, an AI assistant, and a smart site search in one chat widget.

It runs on Google Gemini's free plan. One free key, no credit card, ever. Prefer OpenAI, the company behind ChatGPT? That works too.

= What it does for your visitors =

* Answers questions instantly, day and night, in plain language
* Finds the right post, page, or product and shows it as a clickable button
* Understands detailed requests like "3 bedroom apartments in Lisbon under 500K"
* Answers questions about your business, like "what is your return policy?"
* Shows tap-to-answer buttons so visitors can choose instead of typing
* Suggests real alternatives from your site when nothing matches
* Remembers the conversation while they browse, even across open tabs

= What it does for you =

* Free to run with a Google Gemini key. No credit card, no monthly fee
* Answers the repeat customer support questions so you do not have to
* Sets itself up: a short wizard, then it learns your content on its own
* Works with WooCommerce products, custom fields (ACF, MetaBox), and any post type
* Collects leads: it can offer a callback, take the visitor's email, and send it to you. Off by default
* Passes captured leads to your newsletter or CRM plugin if you use one
* Lets you add your own questions and answers that the chatbot always uses first
* Spending limits you control, so the chat can never run up a surprise bill
* Private by design: chats stay in the visitor's browser and IP addresses are never stored

= Great for =

* Online stores: help shoppers find products by price, category, or feature
* Real estate sites: find properties by price, bedrooms, and location
* Job boards, event sites, directories, and listing sites
* Business and service sites that get the same questions every day
* Blogs and content sites with a big archive to search

= Requirements =

* WordPress 6.0 or higher and PHP 8.0 or higher
* A free Google Gemini key (no credit card), or an OpenAI key if you prefer

== Installation ==

1. Install and activate the plugin
2. Open **Attendant** in your dashboard. A short setup wizard starts
3. Paste your free Google Gemini key. The wizard links you to the page where you create one in two clicks
4. Go to **Attendant → Content Indexing** and click **Start Indexing** so the chatbot learns your site
5. Done. The chat button appears on your site by itself. You can also place it anywhere with the shortcode `[attendant]`

== Frequently Asked Questions ==

= Is it really free? =

Yes, with Google Gemini. Sign in to aistudio.google.com with any Google account and create a free key in two clicks. No credit card is ever asked. Paste the key into the setup wizard and everything runs at no cost, both the chat and the learning of your content. Because there is no payment method on the account, you can never be charged by accident. On a very busy day the chat simply pauses until the free daily allowance resets.

= Do I need an OpenAI account? =

No. OpenAI is an optional alternative. If you use it, a typical conversation costs well under one cent, so around $5 to $10 per month for 1,000 conversations, billed by OpenAI to your account.

= Are there limits on the free tier? =

Google's free plan allows hundreds of chat messages per day, plenty for a typical site. Teaching the chatbot your content is also free, so a large site simply takes a little longer to index the first time.

= Is my data sent to OpenAI? =

Only the user's chat message and relevant content excerpts (for Q&A mode) are sent to OpenAI. Your full database is never sent. API keys are encrypted before storage and never leave your server.

= Will it slow down my site? =

No. The chat widget is tiny and loads in the background without holding up your pages. All AI work happens only when a visitor actually sends a message, never while your pages load.

= Does it work with ACF, MetaBox, WooCommerce? =

Yes. The plugin automatically finds your custom fields from ACF, MetaBox, and WooCommerce product attributes. Nothing to configure.

= Is the chat history saved on my server? =

No. The conversation is kept in the visitor's own browser (localStorage) so it survives page changes and refreshes. It is not stored on your server or in your database. The visitor can clear it with the "New chat" button at any time.

= Can the assistant collect leads or callback requests? =

Yes, but it is off by default. When you enable lead capture in Settings and a visitor agrees to be contacted, the assistant collects the email they provide (and optionally a phone number and preferred time) and emails the request to the address you configure. It is rate-limited to one request per conversation and a daily maximum, and nothing is stored in a database. See the Privacy section for details.

= Can captured leads go to my newsletter or CRM tool? =

Yes. Every accepted callback request also fires a WordPress action, `attendant_lead_captured`, with the visitor's validated email and the optional details (name, phone, preferred time, topic). Any developer or newsletter plugin can hook it:

`add_action( 'attendant_lead_captured', function ( $email, $lead ) { my_newsletter_subscribe( $email, $lead['name'] ); }, 10, 2 );`

The hook fires only after validation and rate-limit checks pass, so you only ever receive real, consented requests.

= Can I keep a record of the conversations? =

Yes, optionally. File logging is off by default. When enabled, each exchange is written to a dated file in a protected folder in your uploads directory, kept for 30 days, and downloadable only by an administrator. IP addresses are never stored and session identifiers are one-way hashed. See the Privacy section.

== External services ==

This plugin connects to the AI provider you choose in Settings: Google Gemini (recommended, free tier) or OpenAI. This is required for the AI chat features: the provider turns a visitor's natural-language question into a structured search of your own content and writes the answer.

What is sent, and when: only when a visitor sends a chat message, the plugin sends that message text plus the titles and short excerpts of the matching content from your own site to your chosen provider. If you enable the optional Semantic Q&A mode, the text of your selected content is also sent to that provider during indexing to generate embeddings. Your API key, your full database, and IP addresses are never sent. Nothing is sent until you add your own API key and turn the chat widget on. Both are off by default. The plugin only ever talks to the one provider you configure.

Note on Google's free tier: Google's terms allow content submitted on the free tier to be used to improve their services. Attendant only sends your public website content and visitor chat messages. Review Google's terms if that matters for your site.

Google Gemini API, provided by Google LLC:

* Terms: https://ai.google.dev/gemini-api/terms
* Privacy Policy: https://policies.google.com/privacy

OpenAI API, provided by OpenAI, L.L.C.:

* Terms of Use: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy
* API data usage policies: https://openai.com/policies/api-data-usage-policies

== Privacy ==

Three features can handle personal data. All are OFF by default and only do anything once you, the site owner, turn them on.

**Conversation history (in the visitor's browser).** The chat keeps a copy of the current conversation in the visitor's own browser using localStorage so it survives page changes and refreshes. This data never leaves the visitor's device except as the normal chat messages already described above. It is not stored on your server or in your database. The visitor can clear it at any time with the "New chat" button or by clearing their browser storage.

**Conversation logging to files (optional, off by default).** When you enable file logging in Settings, the plugin writes each exchange (the visitor's message and the assistant's reply) to a dated log file in a protected folder inside your uploads directory, so you can review how the assistant is used. IP addresses are never stored. Session identifiers are stored only as short one-way hashes used to group a single conversation. Logs are kept for 30 days and then deleted automatically, and only a logged-in administrator can download them. No conversation data is sent anywhere by this feature; the files stay on your server.

**Callback / lead capture (optional, off by default).** When you enable lead capture and a visitor explicitly agrees to be contacted, the plugin collects the email address the visitor provides (and, if they choose, a phone number and a preferred time) and emails that request to the address you configure, using your site's normal email. It is rate-limited to one request per conversation and a daily maximum. This information is handled by whatever email service your WordPress site already uses; the plugin does not store it in a database or send it to any third party of its own.

You are responsible for disclosing these features in your own privacy policy if you enable them.

== Screenshots ==

1. Settings page: choose your AI provider and add your key
2. Chat widget: what visitors see, with answers and page buttons
3. Content indexing: pick what the chatbot learns and watch progress
4. Schema review: see what the plugin found on your site
5. Q&A Manager: add your own questions and answers

== Changelog ==

= 2.1.0 =
* New: Google Gemini support. Run the whole plugin on Google's free plan with one free key, no credit card.
* New: setup wizard recommends the free Gemini key with a direct link. OpenAI keeps working exactly as before, nothing changes on update.
* New: if a key is removed after switching providers, search falls back to fast keyword matching instead of going silent.
* Improved: switching providers is safe. Search keeps using your existing index until you choose to re-index (free with Gemini).
* New: `attendant_lead_captured` action to connect captured callback requests to your newsletter or CRM plugin.
* New: chat stays in sync across browser tabs. A conversation started in one tab appears live in the others.
* Improved: API costs are tracked per provider and clearly labeled as estimates; on the Gemini free tier the dashboard reminds you Google bills $0.

= 2.0.0 =
* Rebuilt as a structured-search-first site assistant.
* Natural-language queries are translated into a safe WP_Query over your post types, taxonomies, and custom fields.
* Embeddings / semantic Q&A is now optional and off by default.
* Short, scannable replies with clickable source buttons instead of links pasted into the text.
* Tap-to-answer quick-reply chips for refining a search, choosing a callback time, and other selections.
* Guided help when a search returns nothing: real alternatives from your own content, asked one question at a time.
* Conversation history kept in the visitor's browser (survives page changes and refreshes), with "New chat" and "Previous chats".
* Optional callback / lead capture: collects the email a visitor provides and emails the request to you, off by default, rate-limited.
* Optional file-based conversation logging in a protected uploads folder, admin-only download, 30-day rotation, off by default.
* "About this site" context setting to tune the assistant to your site.
* Manual and background content indexing with a live activity log; the chat widget stays hidden until the first index completes.
* Honest provider scope: OpenAI only in this release.

= 1.0.0 =
* Initial release.
* Settings page: encrypted API key storage, model selection, widget configuration, privacy/GDPR controls.
* OpenAI provider: chat completion (gpt-4o-mini / gpt-4o) and embeddings (text-embedding-3-small).
* Schema discovery: auto-detects post types, taxonomies, and custom fields (ACF, MetaBox, WooCommerce).
* Content indexing: chunker, embedder, background queue (WP-Cron), auto-sync on post save/delete.
* Chat engine: RAG retrieval (cosine similarity), OpenAI function-calling (search_posts), conversation handler with session history and token budgeting.
* Frontend chat widget: floating launcher, accessible dialog, brand colour + position overrides, [attendant] shortcode.
* Analytics page: monthly API cost history, index health stats, conversation stats (when logging enabled).
* Q&A Manager: admin-configured question/answer pairs matched semantically before RAG (threshold 0.92); REST CRUD API.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
