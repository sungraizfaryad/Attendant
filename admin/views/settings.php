<?php
/**
 * Admin Settings Page View
 *
 * Rendered by ATTENDANT_Admin::render_settings_page().
 *
 * This is a pure HTML/PHP view file — no business logic.
 * All dynamic data is escaped at the point of output.
 *
 * The form does NOT use wp_options directly on submit. Settings are saved
 * via the REST API (POST attendant/v1/settings) called by admin JS. This keeps
 * validation in one place and allows inline save feedback without a page reload.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Capability check — defence in depth (already checked by ATTENDANT_Admin).
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Access denied.', 'attendant' ) );
}

// Load current settings for pre-filling the form.
$settings = Attendant_Plugin::get_setting();

// Determine whether each API key is stored (without decrypting it).
require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/class-attendant-provider-factory.php';
$has_openai = ATTENDANT_Provider_Factory::has_key( 'openai' );
$has_google = ATTENDANT_Provider_Factory::has_key( 'google' );

$active_provider   = esc_attr( $settings['active_provider'] ?? 'openai' );
$chat_model        = esc_attr( $settings['chat_model'] ?? 'gpt-4o-mini' );
$chat_model_google = esc_attr( $settings['chat_model_google'] ?? 'gemini-flash-lite-latest' );
$embed_model     = esc_attr( $settings['embedding_model'] ?? 'text-embedding-3-small' );
$personality     = esc_attr( $settings['ai_personality'] ?? 'friendly' );
$welcome_msg     = (string) ( $settings['welcome_message'] ?? '' );
$widget_color    = (string) ( $settings['widget_color'] ?? '#0073aa' );
$widget_pos      = esc_attr( $settings['widget_position'] ?? 'bottom-right' );
$rate_limit      = (int) ( $settings['rate_limit_msgs'] ?? 20 );
$token_cap       = (int) ( $settings['session_token_cap'] ?? 5000 );
$budget          = (float) ( $settings['monthly_budget'] ?? 0 );
$logging         = ! empty( $settings['logging_enabled'] );
?>
<div class="wrap" id="attendant-settings-page">

	<h1><?php echo esc_html__( 'Attendant — Settings', 'attendant' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=attendant&onboarding=1' ) ); ?>" class="button">
			<?php echo esc_html__( 'Re-run setup wizard', 'attendant' ); ?>
		</a>
	</p>

	<?php
	// Widget visibility status — the admin must always know whether the chat
	// widget is actually showing on the frontend, and if not, exactly why.
	require_once ATTENDANT_PLUGIN_DIR . 'public/class-attendant-frontend.php';
	$attendant_widget_status = ATTENDANT_Frontend::status();

	if ( $attendant_widget_status['enabled'] && ! $attendant_widget_status['ready'] ) :
		?>
		<div class="notice notice-warning inline" style="margin:0 0 16px;">
			<p>
				<strong><?php echo esc_html__( 'The chat widget will NOT display on your site yet.', 'attendant' ); ?></strong>
				<?php if ( 'indexing' === $attendant_widget_status['reason'] ) : ?>
					<?php echo esc_html__( 'The first content indexing run has not finished — the widget stays hidden until it completes, so visitors never get answers from a half-built index.', 'attendant' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=attendant-indexing' ) ); ?>">
						<?php echo esc_html__( 'Check indexing progress', 'attendant' ); ?>
					</a>
				<?php elseif ( 'index_empty' === $attendant_widget_status['reason'] ) : ?>
					<?php echo esc_html__( 'Semantic Q&A is enabled and the content index is still empty. The widget appears automatically once indexing is complete.', 'attendant' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=attendant-indexing' ) ); ?>">
						<?php echo esc_html__( 'Run Content Indexing now', 'attendant' ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html__( 'No API key is saved for the active AI provider. Add your API key below — the widget appears automatically once it is saved.', 'attendant' ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php elseif ( $attendant_widget_status['enabled'] && $attendant_widget_status['ready'] ) : ?>
		<div class="notice notice-success inline" style="margin:0 0 16px;">
			<p><?php echo esc_html__( 'The chat widget is live on your site.', 'attendant' ); ?></p>
		</div>
	<?php endif; ?>

	<div id="attendant-notice" class="notice" style="display:none;"></div>

	<!-- ── Settings tabs ─────────────────────────────────────────────────── -->
	<h2 class="nav-tab-wrapper attendant-tabs">
		<a href="#provider" class="nav-tab nav-tab-active" data-tab="provider"><?php echo esc_html__( 'AI Provider', 'attendant' ); ?></a>
		<a href="#behaviour" class="nav-tab" data-tab="behaviour"><?php echo esc_html__( 'AI Behaviour', 'attendant' ); ?></a>
		<a href="#widget" class="nav-tab" data-tab="widget"><?php echo esc_html__( 'Chat Widget', 'attendant' ); ?></a>
		<a href="#limits" class="nav-tab" data-tab="limits"><?php echo esc_html__( 'Limits & Budget', 'attendant' ); ?></a>
		<a href="#indexing" class="nav-tab" data-tab="indexing"><?php echo esc_html__( 'Indexing', 'attendant' ); ?></a>
		<a href="#privacy" class="nav-tab" data-tab="privacy"><?php echo esc_html__( 'Privacy', 'attendant' ); ?></a>
	</h2>

	<form id="attendant-settings-form" novalidate>

		<?php
		// WordPress best practice: include a nonce field even though this form
		// submits via REST + JS. The nonce is read from attendantAdmin.nonce in JS.
		wp_nonce_field( 'wp_rest', '_wpnonce_display', false );
		?>

		<!-- ================================================================ -->
		<!-- Section 1: AI Provider & API Keys                                -->
		<!-- ================================================================ -->
		<div class="attendant-tab-panel is-active" data-tab="provider">
		<h2 class="title"><?php echo esc_html__( 'AI Provider & API Keys', 'attendant' ); ?></h2>
		<p class="description">
			<?php
			echo esc_html__(
				'Your API key is encrypted before being saved and is never exposed to the browser or included in API responses.',
				'attendant'
			);
			?>
		</p>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="attendant-active-provider">
						<?php echo esc_html__( 'Active Provider', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<select id="attendant-active-provider" name="active_provider">
						<option value="google" <?php selected( $active_provider, 'google' ); ?>>
							<?php echo esc_html__( 'Google Gemini — FREE, no card needed (recommended)', 'attendant' ); ?>
						</option>
						<option value="openai" <?php selected( $active_provider, 'openai' ); ?>>
							<?php echo esc_html__( 'OpenAI — paid (~$5–10/month typical)', 'attendant' ); ?>
						</option>
					</select>
					<p class="description">
						<?php echo esc_html__( 'One Google Gemini key powers both the chat and the site training (embeddings), completely free of charge.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr class="attendant-provider-config" data-provider="google"
				<?php echo 'google' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<th scope="row">
					<label for="attendant-api-key-google">
						<?php echo esc_html__( 'Google Gemini API Key', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="password"
						id="attendant-api-key-google"
						name="api_key_google"
						class="regular-text"
						autocomplete="new-password"
						placeholder="<?php echo $has_google ? esc_attr__( '••••••••  (key stored)', 'attendant' ) : esc_attr__( 'AIza…', 'attendant' ); ?>"
						value=""
					>
					<?php if ( $has_google ) : ?>
						<button type="button" class="button attendant-test-btn" data-provider="google">
							<?php echo esc_html__( 'Test Connection', 'attendant' ); ?>
						</button>
						<span id="attendant-test-google-result" class="attendant-test-result"></span>
					<?php endif; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to Google AI Studio */
							esc_html__( 'Free key from %s — sign in with any Google account, two clicks, no payment details ever asked.', 'attendant' ),
							'<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">aistudio.google.com/apikey</a>'
						);
						?>
					</p>

					<p style="margin-top:12px;">
						<label for="attendant-chat-model-google">
							<strong><?php echo esc_html__( 'Chat Model', 'attendant' ); ?></strong>
						</label><br>
						<select id="attendant-chat-model-google" name="chat_model_google">
							<option value="gemini-flash-lite-latest" <?php selected( $chat_model_google, 'gemini-flash-lite-latest' ); ?>>
								<?php echo esc_html__( 'Gemini Flash-Lite (always newest) — Recommended: fast, biggest free daily limit', 'attendant' ); ?>
							</option>
							<option value="gemini-flash-latest" <?php selected( $chat_model_google, 'gemini-flash-latest' ); ?>>
								<?php echo esc_html__( 'Gemini Flash (always newest) — smarter, smaller free daily limit', 'attendant' ); ?>
							</option>
							<option value="gemini-3.5-flash" <?php selected( $chat_model_google, 'gemini-3.5-flash' ); ?>>
								<?php echo esc_html__( 'Gemini 3.5 Flash — pinned version', 'attendant' ); ?>
							</option>
							<option value="gemini-3.5-flash-lite" <?php selected( $chat_model_google, 'gemini-3.5-flash-lite' ); ?>>
								<?php echo esc_html__( 'Gemini 3.5 Flash-Lite — pinned version', 'attendant' ); ?>
							</option>
						</select>
					</p>
				</td>
			</tr>

			<tr class="attendant-provider-config" data-provider="openai"
				<?php echo 'openai' === $active_provider ? '' : 'style="display:none;"'; ?>>
				<th scope="row">
					<label for="attendant-api-key-openai">
						<?php echo esc_html__( 'OpenAI API Key', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="password"
						id="attendant-api-key-openai"
						name="api_key_openai"
						class="regular-text"
						autocomplete="new-password"
						placeholder="<?php echo $has_openai ? esc_attr__( '••••••••  (key stored)', 'attendant' ) : esc_attr__( 'sk-...', 'attendant' ); ?>"
						value=""
					>
					<?php if ( $has_openai ) : ?>
						<button type="button" class="button attendant-test-btn" data-provider="openai">
							<?php echo esc_html__( 'Test Connection', 'attendant' ); ?>
						</button>
						<span id="attendant-test-openai-result" class="attendant-test-result"></span>
					<?php endif; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to OpenAI API keys page */
							esc_html__( 'Get your API key from %s — OpenAI usage is billed to your OpenAI account.', 'attendant' ),
							'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>'
						);
						?>
					</p>

					<p style="margin-top:12px;">
						<label for="attendant-chat-model">
							<strong><?php echo esc_html__( 'Chat Model', 'attendant' ); ?></strong>
						</label><br>
						<select id="attendant-chat-model" name="chat_model">
							<option value="gpt-4o-mini" <?php selected( $chat_model, 'gpt-4o-mini' ); ?>>
								<?php echo esc_html__( 'gpt-4o-mini — Recommended ($0.15 / 1M input tokens)', 'attendant' ); ?>
							</option>
							<option value="gpt-4o" <?php selected( $chat_model, 'gpt-4o' ); ?>>
								<?php echo esc_html__( 'gpt-4o — More capable ($2.50 / 1M input tokens)', 'attendant' ); ?>
							</option>
						</select>
					</p>

					<p style="margin-top:12px;">
						<label for="attendant-embed-model">
							<strong><?php echo esc_html__( 'Embedding Model', 'attendant' ); ?></strong>
						</label><br>
						<select id="attendant-embed-model" name="embedding_model">
							<option value="text-embedding-3-small" <?php selected( $embed_model, 'text-embedding-3-small' ); ?>>
								<?php echo esc_html__( 'text-embedding-3-small — Recommended ($0.02 / 1M tokens)', 'attendant' ); ?>
							</option>
							<option value="text-embedding-3-large" <?php selected( $embed_model, 'text-embedding-3-large' ); ?>>
								<?php echo esc_html__( 'text-embedding-3-large — Higher accuracy ($0.13 / 1M tokens)', 'attendant' ); ?>
							</option>
						</select>
					</p>
				</td>
			</tr>

		</table>

		<hr>

		</div><!-- /provider panel -->

		<!-- ================================================================ -->
		<!-- Section 2: AI Behaviour                                          -->
		<!-- ================================================================ -->
		<div class="attendant-tab-panel" data-tab="behaviour">
		<h2 class="title"><?php echo esc_html__( 'AI Behaviour', 'attendant' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="attendant-personality">
						<?php echo esc_html__( 'Personality', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<select id="attendant-personality" name="ai_personality">
						<option value="friendly"     <?php selected( $personality, 'friendly' ); ?>>
							<?php echo esc_html__( 'Friendly — Warm and conversational', 'attendant' ); ?>
						</option>
						<option value="professional" <?php selected( $personality, 'professional' ); ?>>
							<?php echo esc_html__( 'Professional — Formal and concise', 'attendant' ); ?>
						</option>
						<option value="casual"       <?php selected( $personality, 'casual' ); ?>>
							<?php echo esc_html__( 'Casual — Relaxed and informal', 'attendant' ); ?>
						</option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-site-context">
						<?php echo esc_html__( 'About this website', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<textarea
						id="attendant-site-context"
						name="site_context"
						class="large-text"
						rows="5"
						maxlength="2000"
						placeholder="<?php echo esc_attr__( 'e.g. We are a luxury real-estate agency selling villas and apartments in Portugal and Spain. Visitors usually search by location, budget, bedrooms, and property type.', 'attendant' ); ?>"
					><?php echo esc_textarea( (string) ( $settings['site_context'] ?? '' ) ); ?></textarea>
					<p class="description">
						<?php echo esc_html__( 'Optional but highly recommended. Tell the assistant what this website is about, what you offer, and what visitors usually look for. This is given to the AI as background context, so its answers and follow-up questions match your business.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

		</table>

		<hr>

		</div><!-- /behaviour panel -->

		<!-- ================================================================ -->
		<!-- Section 3: Chat Widget                                           -->
		<!-- ================================================================ -->
		<div class="attendant-tab-panel" data-tab="widget">
		<h2 class="title"><?php echo esc_html__( 'Chat Widget', 'attendant' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="attendant-welcome-msg">
						<?php echo esc_html__( 'Welcome Message', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="text"
						id="attendant-welcome-msg"
						name="welcome_message"
						class="large-text"
						value="<?php echo esc_attr( $welcome_msg ); ?>"
						maxlength="200"
					>
					<p class="description">
						<?php echo esc_html__( 'The first message shown when a visitor opens the chat.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<?php echo esc_html__( 'Lead Capture', 'attendant' ); ?>
				</th>
				<td>
					<label for="attendant-lead-capture">
						<input
							type="checkbox"
							id="attendant-lead-capture"
							name="lead_capture"
							value="1"
							<?php checked( ! empty( $settings['lead_capture'] ?? false ) ); ?>
						>
						<?php echo esc_html__( 'Offer a callback when the assistant cannot help', 'attendant' ); ?>
					</label>
					<p class="description">
						<?php echo esc_html__( 'When the assistant cannot find what a visitor needs, it politely offers to arrange a callback: it collects their email (required), phone and preferred time, then emails the request to the address below. Limits: one request per conversation, 20 per day.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-lead-email">
						<?php echo esc_html__( 'Send Callback Requests To', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="email"
						id="attendant-lead-email"
						name="lead_email"
						class="regular-text"
						value="<?php echo esc_attr( (string) ( $settings['lead_email'] ?? '' ) ); ?>"
						placeholder="<?php echo esc_attr( (string) get_option( 'admin_email', '' ) ); ?>"
					>
					<p class="description">
						<?php echo esc_html__( 'Leave empty to use the site admin email. Replying to a callback email goes directly to the visitor.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-widget-color">
						<?php echo esc_html__( 'Brand Colour', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="color"
						id="attendant-widget-color"
						name="widget_color"
						value="<?php echo esc_attr( $widget_color ); ?>"
					>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-widget-pos">
						<?php echo esc_html__( 'Widget Position', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<select id="attendant-widget-pos" name="widget_position">
						<option value="bottom-right" <?php selected( $widget_pos, 'bottom-right' ); ?>>
							<?php echo esc_html__( 'Bottom Right', 'attendant' ); ?>
						</option>
						<option value="bottom-left"  <?php selected( $widget_pos, 'bottom-left' ); ?>>
							<?php echo esc_html__( 'Bottom Left', 'attendant' ); ?>
						</option>
					</select>
				</td>
			</tr>

		</table>

		<hr>

		</div><!-- /widget panel -->

		<!-- ================================================================ -->
		<!-- Section 4: Rate Limiting & Budget                                -->
		<!-- ================================================================ -->
		<div class="attendant-tab-panel" data-tab="limits">
		<h2 class="title"><?php echo esc_html__( 'Rate Limiting & Budget', 'attendant' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="attendant-rate-limit">
						<?php echo esc_html__( 'Max Messages / Minute (per visitor)', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="attendant-rate-limit"
						name="rate_limit_msgs"
						class="small-text"
						value="<?php echo esc_attr( $rate_limit ); ?>"
						min="1"
						max="100"
					>
					<p class="description">
						<?php echo esc_html__( 'Set to 0 to disable rate limiting. Default: 20.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-token-cap">
						<?php echo esc_html__( 'Max Tokens per Session', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="attendant-token-cap"
						name="session_token_cap"
						class="small-text"
						value="<?php echo esc_attr( $token_cap ); ?>"
						min="500"
						max="32000"
					>
					<p class="description">
						<?php echo esc_html__( 'Conversation history is trimmed when this limit is reached. Default: 5000.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-budget">
						<?php echo esc_html__( 'Monthly Budget (USD)', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="attendant-budget"
						name="monthly_budget"
						class="small-text"
						value="<?php echo esc_attr( $budget ); ?>"
						min="0"
						step="0.01"
					>
					<p class="description">
						<?php echo esc_html__( 'Long-term monthly spend tracking shown on the Analytics page. Costs are estimates — on the Gemini free tier Google bills you $0, so leave this at 0 unless you enabled billing.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-daily-budget">
						<?php echo esc_html__( 'Daily Budget (USD)', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="attendant-daily-budget"
						name="daily_budget"
						class="small-text"
						value="<?php echo esc_attr( (float) ( $settings['daily_budget'] ?? 0 ) ); ?>"
						min="0"
						step="0.01"
					>
					<p class="description">
						<?php echo esc_html__( 'Hard kill-switch: when today\'s API spend reaches this amount, the public chat pauses until tomorrow. Set to 0 for unlimited.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="attendant-daily-cap">
						<?php echo esc_html__( 'Max Messages / Day (per visitor)', 'attendant' ); ?>
					</label>
				</th>
				<td>
					<input
						type="number"
						id="attendant-daily-cap"
						name="daily_msg_cap"
						class="small-text"
						value="<?php echo esc_attr( (int) ( $settings['daily_msg_cap'] ?? 0 ) ); ?>"
						min="0"
					>
					<p class="description">
						<?php echo esc_html__( 'Hard per-visitor daily ceiling. Set to 0 for unlimited.', 'attendant' ); ?>
					</p>
				</td>
			</tr>

		</table>

		<hr>

		</div><!-- /limits panel -->

		<!-- ================================================================ -->
		<!-- Section 5: Indexing                                              -->
		<!-- ================================================================ -->
		<div class="attendant-tab-panel" data-tab="indexing">
		<h2 class="title"><?php echo esc_html__( 'Content Indexing', 'attendant' ); ?></h2>
		<p class="description">
			<?php echo esc_html__( 'These options control how the content indexing queue is processed. Choose them here, then start a run from the Content Indexing page — options are locked while a run is in progress.', 'attendant' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Processing mode', 'attendant' ); ?></th>
				<td>
					<?php $attendant_idx_mode = (string) ( $settings['indexing_mode'] ?? 'frontend' ); ?>
					<label style="display:block;margin:4px 0;">
						<input type="radio" name="indexing_mode" value="frontend" <?php checked( $attendant_idx_mode, 'frontend' ); ?>>
						<?php echo esc_html__( 'While the Indexing page is open (recommended)', 'attendant' ); ?>
					</label>
					<p class="description" style="margin:2px 0 10px 24px;">
						<?php echo esc_html__( 'The Content Indexing page drives the queue itself, batch after batch. Works on every server — keep that tab open until it finishes.', 'attendant' ); ?>
					</p>
					<label style="display:block;margin:4px 0;">
						<input type="radio" name="indexing_mode" value="background" <?php checked( $attendant_idx_mode, 'background' ); ?>>
						<?php echo esc_html__( 'In the background (page can be closed)', 'attendant' ); ?>
					</label>
					<p class="description" style="margin:2px 0 0 24px;">
						<?php echo esc_html__( 'The server keeps processing on its own using loopback requests, with WP-Cron as a safety net. If your host blocks loopback requests, progress may pause until the site gets a visit — switch back to the first option if it stalls.', 'attendant' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="attendant-batch-size"><?php echo esc_html__( 'Batch size', 'attendant' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="attendant-batch-size"
						name="batch_size"
						min="10"
						max="200"
						value="<?php echo esc_attr( (string) (int) ( $settings['batch_size'] ?? 50 ) ); ?>"
						class="small-text"
					>
					<p class="description">
						<?php echo esc_html__( 'Posts processed per batch (10–200). Higher is faster but uses more memory and longer requests per batch.', 'attendant' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Auto-sync', 'attendant' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="auto_sync"
							value="1"
							<?php checked( ! empty( $settings['auto_sync'] ?? true ) ); ?>
						>
						<?php echo esc_html__( 'Automatically re-index posts when they are published, updated, or deleted', 'attendant' ); ?>
					</label>
					<p class="description">
						<?php echo esc_html__( 'Keeps the index current without manual scans. Recommended: on.', 'attendant' ); ?>
					</p>
				</td>
			</tr>
		</table>
		</div><!-- /indexing panel -->

		<!-- ================================================================ -->
		<!-- Section 6: Privacy / GDPR                                        -->
		<!-- ================================================================ -->
		<div class="attendant-tab-panel" data-tab="privacy">
		<h2 class="title"><?php echo esc_html__( 'Privacy & GDPR', 'attendant' ); ?></h2>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<?php echo esc_html__( 'Conversation Logging', 'attendant' ); ?>
				</th>
				<td>
					<label for="attendant-logging">
						<input
							type="checkbox"
							id="attendant-logging"
							name="logging_enabled"
							value="1"
							<?php checked( $logging ); ?>
						>
						<?php echo esc_html__( 'Enable conversation logging', 'attendant' ); ?>
					</label>
					<p class="description">
						<?php
						echo esc_html__(
							'Disabled by default. When enabled, conversation turns are saved to the database to power the Analytics dashboard. IP addresses are never logged — only a one-way hash.',
							'attendant'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<?php echo esc_html__( 'Downloadable Chat Logs', 'attendant' ); ?>
				</th>
				<td>
					<label for="attendant-file-logging">
						<input
							type="checkbox"
							id="attendant-file-logging"
							name="file_logging"
							value="1"
							<?php checked( ! empty( $settings['file_logging'] ?? false ) ); ?>
						>
						<?php echo esc_html__( 'Write each chat exchange to a downloadable daily log file', 'attendant' ); ?>
					</label>
					<p class="description">
						<?php
						echo esc_html__(
							'Disabled by default. Logs are stored as one file per day in a protected, non-public folder inside wp-content/uploads (never in the plugin folder, so they survive plugin updates). Files older than 30 days are deleted automatically. Download them from the Analytics page. IP addresses are never logged.',
							'attendant'
						);
						?>
					</p>
				</td>
			</tr>

		</table>

		</div><!-- /privacy panel -->

		<p class="submit">
			<button type="submit" id="attendant-save-settings" class="button button-primary">
				<?php echo esc_html__( 'Save Settings', 'attendant' ); ?>
			</button>
			<span id="attendant-save-status" class="attendant-save-status" aria-live="polite"></span>
		</p>

	</form><!-- #attendant-settings-form -->

</div><!-- .wrap -->
