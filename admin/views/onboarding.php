<?php
/**
 * Onboarding Wizard View
 *
 * Rendered by ATTENDANT_Admin for the top-level page when onboarding is incomplete
 * (or when ?onboarding=1). Pure markup; attendant-wizard.js drives the steps and
 * fills detected data. attendantAdmin (restUrl/nonce/i18n) is localized by ATTENDANT_Admin.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Access denied.', 'attendant' ) );
}

$attendant_steps = array(
	__( 'Welcome', 'attendant' ),
	__( 'Detect', 'attendant' ),
	__( 'Content types', 'attendant' ),
	__( 'Fields', 'attendant' ),
	__( 'Connect AI', 'attendant' ),
	__( 'Widget', 'attendant' ),
);
?>
<div class="wrap" id="attendant-wizard">
	<h1><?php echo esc_html__( 'Attendant — Setup', 'attendant' ); ?></h1>

	<ol class="attendant-wizard-steps" aria-hidden="true">
		<?php foreach ( $attendant_steps as $attendant_i => $attendant_label ) : ?>
			<li class="attendant-wizard-step<?php echo 0 === $attendant_i ? ' is-active' : ''; ?>" data-step="<?php echo (int) $attendant_i; ?>">
				<span class="attendant-wizard-num"><?php echo (int) $attendant_i + 1; ?></span>
				<span class="attendant-wizard-label"><?php echo esc_html( $attendant_label ); ?></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div id="attendant-wizard-notice" class="notice" style="display:none;"></div>

	<!-- 0: Welcome -->
	<section class="attendant-panel is-active" data-panel="0">
		<h2><?php echo esc_html__( 'Welcome', 'attendant' ); ?></h2>
		<p><?php echo esc_html__( "This assistant searches your own content and answers visitor questions. Nothing leaves your site except the visitor's question and the matched titles. Let's set it up in a few steps.", 'attendant' ); ?></p>
		<p><button type="button" class="button button-primary" data-attendant-next><?php echo esc_html__( 'Get started', 'attendant' ); ?></button></p>
	</section>

	<!-- 1: Detect -->
	<section class="attendant-panel" data-panel="1">
		<h2><?php echo esc_html__( 'Detect your site', 'attendant' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'We scan your post types, taxonomies, and custom fields. Nothing is saved yet.', 'attendant' ); ?></p>
		<div id="attendant-detect-result" class="attendant-detect-result"></div>
		<p>
			<button type="button" class="button" data-attendant-prev><?php echo esc_html__( 'Back', 'attendant' ); ?></button>
			<button type="button" class="button button-primary" data-attendant-next disabled id="attendant-detect-next"><?php echo esc_html__( 'Next', 'attendant' ); ?></button>
		</p>
	</section>

	<!-- 2: Choose types -->
	<section class="attendant-panel" data-panel="2">
		<h2><?php echo esc_html__( 'What should be searchable?', 'attendant' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Pick the content types visitors should be able to search.', 'attendant' ); ?></p>
		<div id="attendant-types-list"></div>
		<p>
			<button type="button" class="button" data-attendant-prev><?php echo esc_html__( 'Back', 'attendant' ); ?></button>
			<button type="button" class="button button-primary" data-attendant-next><?php echo esc_html__( 'Next', 'attendant' ); ?></button>
		</p>
	</section>

	<!-- 3: Confirm fields -->
	<section class="attendant-panel" data-panel="3">
		<h2><?php echo esc_html__( 'Confirm fields and labels', 'attendant' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'Turn fields off if you do not want them searched, and rename any for clarity.', 'attendant' ); ?></p>
		<div id="attendant-fields-list"></div>
		<p>
			<button type="button" class="button" data-attendant-prev><?php echo esc_html__( 'Back', 'attendant' ); ?></button>
			<button type="button" class="button button-primary" data-attendant-next><?php echo esc_html__( 'Next', 'attendant' ); ?></button>
		</p>
	</section>

	<!-- 4: Connect AI -->
	<section class="attendant-panel" data-panel="4">
		<h2><?php echo esc_html__( 'Connect the AI', 'attendant' ); ?></h2>
		<p class="description"><?php echo esc_html__( 'One API key powers the chat and trains the assistant on your site. Google Gemini is completely free — no card, no charges, ever.', 'attendant' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'AI Provider', 'attendant' ); ?></th>
				<td>
					<label style="display:block;margin-bottom:6px;">
						<input type="radio" name="attendant-wiz-provider" value="google" checked>
						<strong><?php echo esc_html__( 'Google Gemini', 'attendant' ); ?></strong>
						— <?php echo esc_html__( 'FREE. Sign in with any Google account, no payment details asked.', 'attendant' ); ?>
					</label>
					<label style="display:block;">
						<input type="radio" name="attendant-wiz-provider" value="openai">
						<strong><?php echo esc_html__( 'OpenAI', 'attendant' ); ?></strong>
						— <?php echo esc_html__( 'Paid (roughly $5–10/month for a typical site).', 'attendant' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="attendant-wiz-key" id="attendant-wiz-key-label"><?php echo esc_html__( 'Google Gemini API Key', 'attendant' ); ?></label></th>
				<td>
					<input type="password" id="attendant-wiz-key" class="regular-text" autocomplete="new-password" placeholder="AIza…">
					<button type="button" class="button" id="attendant-wiz-test"><?php echo esc_html__( 'Test', 'attendant' ); ?></button>
					<span id="attendant-wiz-test-result" class="attendant-test-result"></span>
					<p class="description" id="attendant-wiz-key-hint">
						<?php
						printf(
							/* translators: %s: link to Google AI Studio */
							esc_html__( 'Get your free key at %s — two clicks, copy, paste here.', 'attendant' ),
							'<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer" id="attendant-wiz-key-link">aistudio.google.com/apikey</a>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Semantic Q&A', 'attendant' ); ?></th>
				<td>
					<label><input type="checkbox" id="attendant-wiz-semantic"> <?php echo esc_html__( 'Enable meaning-based answers (free with Gemini; best for blogs and docs)', 'attendant' ); ?></label>
				</td>
			</tr>
		</table>
		<p>
			<button type="button" class="button" data-attendant-prev><?php echo esc_html__( 'Back', 'attendant' ); ?></button>
			<button type="button" class="button button-primary" data-attendant-next><?php echo esc_html__( 'Next', 'attendant' ); ?></button>
		</p>
	</section>

	<!-- 5: Widget + Finish -->
	<section class="attendant-panel" data-panel="5">
		<h2><?php echo esc_html__( 'Place the chat widget', 'attendant' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="attendant-wiz-context"><?php echo esc_html__( 'About this website (optional)', 'attendant' ); ?></label></th>
				<td>
					<textarea id="attendant-wiz-context" class="large-text" rows="4" maxlength="2000" placeholder="<?php echo esc_attr__( 'e.g. We are a luxury real-estate agency selling villas and apartments in Portugal and Spain. Visitors usually search by location, budget, bedrooms, and property type.', 'attendant' ); ?>"></textarea>
					<p class="description"><?php echo esc_html__( 'Tell the assistant what this website is about and what visitors usually look for — it uses this to give better answers and ask smarter follow-up questions. You can edit it later in Settings → AI Behaviour.', 'attendant' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Show widget', 'attendant' ); ?></th>
				<td><label><input type="checkbox" id="attendant-wiz-enable"> <?php echo esc_html__( 'Show the floating chat button on the site', 'attendant' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="attendant-wiz-color"><?php echo esc_html__( 'Brand colour', 'attendant' ); ?></label></th>
				<td><input type="color" id="attendant-wiz-color" value="#0073aa"></td>
			</tr>
		</table>
		<p>
			<button type="button" class="button" data-attendant-prev><?php echo esc_html__( 'Back', 'attendant' ); ?></button>
			<button type="button" class="button button-primary" id="attendant-wiz-finish"><?php echo esc_html__( 'Finish setup', 'attendant' ); ?></button>
			<span id="attendant-wiz-finish-status" class="attendant-save-status" aria-live="polite"></span>
		</p>
	</section>
</div>
