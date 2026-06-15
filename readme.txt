=== Attendant - AI Site Search & Content Finder ===
Contributors:      sungraizfaryad
Tags:              ai, site-search, chatbot, openai, search
Requires at least: 6.0
Tested up to:      7.0
Stable tag:        2.0.0
Requires PHP:      8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

AI search chatbot that helps visitors find content on your website. Turns questions into a safe search of your own posts, pages, and products.

== Description ==

Attendant is an AI-powered site search chatbot that helps your visitors find content on your website. When someone asks a question in plain language, Attendant searches your own posts, pages, products, and listings, then answers right in the chat — so visitors reach the right page without digging through menus. It combines **conversational search** with **knowledge Q&A** in one chat widget.

Replies stay short and scannable: the assistant links to matching pages as clickable buttons, offers tappable choices when the next step is a selection, and guides the visitor with one question at a time when a search comes up empty. The conversation is remembered in the visitor's own browser so it survives page changes and refreshes. When the assistant genuinely cannot help, you can optionally let it offer a callback and email the request to your team.

**Mode 1 — Smart Search (for sites with listings)**
A visitor says: "Find me apartments in Lisbon under €500K with 3 bedrooms" → Attendant extracts the parameters, searches your WordPress database, and answers in the chat with clickable buttons to the matching listings.

**Mode 2 — Knowledge Q&A (for all sites)**
A visitor says: "What is your return policy?" → Attendant searches your indexed content and answers the question with source citations.

**The AI automatically picks the right mode** based on what the visitor is asking.

= Key Features =

* **Auto-discovers your site** — scans post types, taxonomies, and custom fields (ACF, MetaBox, WooCommerce) automatically
* **Function calling** — uses OpenAI's tools API for guaranteed structured search parameters, not fragile text guessing
* **Direct database access** — queries WordPress directly (no HTTP crawling = faster, more accurate)
* **Clickable source buttons** — every answer links to the matching pages as buttons, so replies stay short and scannable
* **Tap-to-answer chips** — when the next step is a choice (refine the search, pick a time), the visitor taps an option instead of typing
* **Guided help on no results** — when nothing matches, the assistant offers real alternatives drawn from your own content and asks one question at a time to narrow things down
* **Conversation history** — the chat is remembered in the visitor's own browser, so it survives page changes and refreshes; a "New chat" button and a "Previous chats" list are built in
* **Callback / lead capture (optional, off by default)** — when the assistant cannot help, it can offer a callback, collect the email the visitor provides (and optionally a phone number and preferred time), and email the request to you
* **RAG Q&A** — embeds your content, searches by cosine similarity, answers with source links
* **Custom Q&A pairs** — admins can add specific answers that always take priority
* **About-this-site context** — a short description you write that helps the assistant understand what your site is for
* **Manual or background indexing** — index on demand with a live activity log, or let it run in the background; the widget stays hidden until the first index completes so visitors never see half-indexed answers
* **Bring Your Own Key** — works with your own OpenAI API key, no SaaS subscription required
* **Privacy-first** — conversation logging and lead capture are both OFF by default; IP addresses are never stored

= Who It's For =

* Real estate directories (find properties by price, bedrooms, location)
* Job boards (find jobs by type, salary, location, remote)
* WooCommerce stores (find products by price, category, attributes)
* Event sites (find events by date, location, category)
* Doctor/medical directories
* Any WordPress site with custom post types

= Requirements =

* PHP 8.0 or higher
* WordPress 6.0 or higher
* OpenAI API key

== Installation ==

1. Upload the `ai-chatmate` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Go to **Attendant → Settings**
4. Enter your OpenAI API key and click **Save Settings**
5. Click **Test Connection** to confirm your key is working
6. Go to **Attendant → Content Indexing** and click **Start Indexing**
7. Add the chat widget with the shortcode `[ai_chatmate]` or enable the floating widget (appears automatically in the footer on every page)

== Frequently Asked Questions ==

= Do I need an OpenAI account? =

Yes. You need an OpenAI API key from platform.openai.com. The plugin uses your key directly — we never see it.

= How much will it cost to run? =

With gpt-4o-mini (our recommended model), each conversation turn costs approximately $0.005 USD. 1,000 conversations per month ≈ $5–10. Content indexing for a 500-page site costs approximately $0.025 (one-time).

= Is my data sent to OpenAI? =

Only the user's chat message and relevant content excerpts (for Q&A mode) are sent to OpenAI. Your full database is never sent. API keys are encrypted before storage and never leave your server.

= Will it slow down my site? =

No. The chat widget JS is less than 10KB gzipped and loads asynchronously. Admin scripts only load on plugin admin pages. All AI processing happens via REST API calls triggered by the visitor — never on page load.

= Does it work with ACF, MetaBox, WooCommerce? =

Yes. The schema discovery engine automatically detects custom fields from ACF, MetaBox, and WooCommerce product attributes.

= Is the chat history saved on my server? =

No. The conversation is kept in the visitor's own browser (localStorage) so it survives page changes and refreshes. It is not stored on your server or in your database. The visitor can clear it with the "New chat" button at any time.

= Can the assistant collect leads or callback requests? =

Yes, but it is off by default. When you enable lead capture in Settings and a visitor agrees to be contacted, the assistant collects the email they provide (and optionally a phone number and preferred time) and emails the request to the address you configure. It is rate-limited to one request per conversation and a daily maximum, and nothing is stored in a database. See the Privacy section for details.

= Can I keep a record of the conversations? =

Yes, optionally. File logging is off by default. When enabled, each exchange is written to a dated file in a protected folder in your uploads directory, kept for 30 days, and downloadable only by an administrator. IP addresses are never stored and session identifiers are one-way hashed. See the Privacy section.

== External services ==

This plugin connects to the OpenAI API. This is required for the AI chat features: OpenAI turns a visitor's natural-language question into a structured search of your own content and writes the answer.

What is sent, and when: only when a visitor sends a chat message, the plugin sends that message text plus the titles and short excerpts of the matching content from your own site to OpenAI. If you enable the optional Semantic Q&A mode, the text of your selected content is also sent to OpenAI during indexing to generate embeddings. Your API key, your full database, and IP addresses are never sent. Nothing is sent until you add your own OpenAI API key and turn the chat widget on — both are off by default.

The service is provided by OpenAI, L.L.C. Please review their policies:

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

1. Settings page — configure your API key and AI provider
2. Chat widget — visitor view with search results and preview cards
3. Content indexing — select post types and monitor indexing progress
4. Schema review — inspect auto-discovered post types and fields
5. Q&A Manager — add custom question-answer pairs

== Changelog ==

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
* Settings page — encrypted API key storage, model selection, widget configuration, privacy/GDPR controls.
* OpenAI provider — chat completion (gpt-4o-mini / gpt-4o) and embeddings (text-embedding-3-small).
* Schema discovery — auto-detects post types, taxonomies, and custom fields (ACF, MetaBox, WooCommerce).
* Content indexing — chunker, embedder, background queue (WP-Cron), auto-sync on post save/delete.
* Chat engine — RAG retrieval (cosine similarity), OpenAI function-calling (search_posts), conversation handler with session history and token budgeting.
* Frontend chat widget — floating launcher, accessible dialog, brand colour + position overrides, [ai_chatmate] shortcode.
* Analytics page — monthly API cost history, index health stats, conversation stats (when logging enabled).
* Q&A Manager — admin-configured question/answer pairs matched semantically before RAG (threshold 0.92); REST CRUD API.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
