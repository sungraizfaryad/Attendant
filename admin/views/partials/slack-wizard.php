<?php
/**
 * Slack setup wizard (modal).
 *
 * Rendered hidden inside the Integrations tab and opened by the "Set up Slack"
 * button. Each step is a panel; admin/js/attendant-slack-wizard.js handles
 * navigation and the REST calls (save credentials, create/choose channel, test).
 *
 * Screenshots: drop PNGs into admin/images/ using the names below and they
 * appear automatically in the matching step — no code change needed.
 *   slack-step-signin.png · slack-step-create.png · slack-step-install.png
 *   slack-step-credentials.png
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The create-app step links to the pre-filled manifest URL, so this view
// needs the Slack class whether or not the caller already loaded it.
require_once ATTENDANT_PLUGIN_DIR . 'includes/integrations/class-attendant-slack.php';

/**
 * Print a wizard screenshot when the file has been added, otherwise nothing.
 *
 * @param string $file Filename inside admin/images/.
 * @param string $alt  Alt text.
 */
function attendant_wizard_shot( string $file, string $alt ): void {
	if ( ! file_exists( ATTENDANT_PLUGIN_DIR . 'admin/images/' . $file ) ) {
		return;
	}
	printf(
		'<figure class="attendant-wiz__shot"><img src="%s" alt="%s"></figure>',
		esc_url( ATTENDANT_PLUGIN_URL . 'admin/images/' . $file ),
		esc_attr( $alt )
	);
}
?>
<div class="attendant-wiz-overlay" id="attendant-wiz" hidden>
	<div class="attendant-wiz" role="dialog" aria-modal="true" aria-labelledby="attendant-wiz-title">

		<div class="attendant-wiz__head">
			<h2 id="attendant-wiz-title"><?php echo esc_html__( 'Connect Slack', 'attendant' ); ?></h2>
			<button type="button" class="attendant-wiz__close" id="attendant-wiz-close" aria-label="<?php esc_attr_e( 'Close', 'attendant' ); ?>">&times;</button>
		</div>

		<div class="attendant-wiz__progress">
			<span class="attendant-wiz__count" id="attendant-wiz-count"></span>
			<div class="attendant-wiz__bar"><div class="attendant-wiz__bar-fill" id="attendant-wiz-bar"></div></div>
		</div>

		<div class="attendant-wiz__body">

			<!-- 1 — Sign in ------------------------------------------------- -->
			<section class="attendant-wiz__step" data-step="1">
				<h3><?php echo esc_html__( 'Sign in to Slack first', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'Open Slack in a new tab and sign in. If you do not have a workspace yet, you can create one there — it is free.', 'attendant' ); ?>
				</p>
				<p class="attendant-wiz__note">
					<?php echo esc_html__( 'This matters: if you are not signed in, the next step cannot pre-fill your app and you will have to set it up by hand.', 'attendant' ); ?>
				</p>
				<p>
					<a class="button button-hero button-primary" href="https://slack.com/signin" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'Open Slack and sign in', 'attendant' ); ?> ↗
					</a>
				</p>
				<?php attendant_wizard_shot( 'slack-step-signin.png', __( 'Slack sign-in screen', 'attendant' ) ); ?>
			</section>

			<!-- 2 — Create the app ------------------------------------------ -->
			<section class="attendant-wiz__step" data-step="2" hidden>
				<h3><?php echo esc_html__( 'Create the Attendant app', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'This link opens Slack with everything already filled in for you — the name, the permissions, and your website address.', 'attendant' ); ?>
				</p>
				<ol class="attendant-wiz__list">
					<li><?php echo esc_html__( 'Choose your workspace', 'attendant' ); ?></li>
					<li><?php echo esc_html__( 'Click Next', 'attendant' ); ?></li>
					<li><?php echo esc_html__( 'Click Create', 'attendant' ); ?></li>
				</ol>
				<p>
					<a class="button button-hero button-primary" id="attendant-wiz-create-app" href="<?php echo esc_url( ATTENDANT_Slack::manifest_url() ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'Create the app in Slack', 'attendant' ); ?> ↗
					</a>
				</p>
				<?php attendant_wizard_shot( 'slack-step-create.png', __( 'Slack create-app screen', 'attendant' ) ); ?>
			</section>

			<!-- 3 — Install to workspace ------------------------------------ -->
			<section class="attendant-wiz__step" data-step="3" hidden>
				<h3><?php echo esc_html__( 'Install it to your workspace', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'On the app page that just opened, install the app so it can post into Slack.', 'attendant' ); ?>
				</p>
				<ol class="attendant-wiz__list attendant-wiz__list--big">
					<li><strong><?php echo esc_html__( 'Click "Install to Workspace"', 'attendant' ); ?></strong></li>
					<li><strong><?php echo esc_html__( 'Click "Allow"', 'attendant' ); ?></strong></li>
				</ol>
				<p class="attendant-wiz__note">
					<?php echo esc_html__( 'Keep that Slack tab open — the next step needs two values from it.', 'attendant' ); ?>
				</p>
				<?php attendant_wizard_shot( 'slack-step-install.png', __( 'Slack install-to-workspace screen', 'attendant' ) ); ?>
			</section>

			<!-- 4 — Credentials --------------------------------------------- -->
			<section class="attendant-wiz__step" data-step="4" hidden>
				<h3><?php echo esc_html__( 'Copy two values from Slack', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'Both are on your app pages in Slack. Paste them here and we will store them encrypted.', 'attendant' ); ?>
				</p>

				<label class="attendant-wiz__label" for="attendant-wiz-secret">
					<?php echo esc_html__( '1. Signing Secret', 'attendant' ); ?>
				</label>
				<p class="attendant-wiz__hint">
					<?php
					printf(
						/* translators: %s: link to the Slack apps list */
						esc_html__( 'Open %s, click your app, then Basic Information → App Credentials → Show next to Signing Secret.', 'attendant' ),
						'<a href="https://api.slack.com/apps" target="_blank" rel="noopener noreferrer">api.slack.com/apps ↗</a>'
					);
					?>
				</p>
				<input type="password" id="attendant-wiz-secret" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr__( 'Paste the Signing Secret', 'attendant' ); ?>">

				<label class="attendant-wiz__label" for="attendant-wiz-token">
					<?php echo esc_html__( '2. Bot Token', 'attendant' ); ?>
				</label>
				<p class="attendant-wiz__hint">
					<?php echo esc_html__( 'Same app → OAuth & Permissions → copy the Bot User OAuth Token (it starts with xoxb-).', 'attendant' ); ?>
				</p>
				<input type="password" id="attendant-wiz-token" class="regular-text" autocomplete="off" placeholder="xoxb-…">

				<?php attendant_wizard_shot( 'slack-step-credentials.png', __( 'Where to find the Slack credentials', 'attendant' ) ); ?>
			</section>

			<!-- 5 — Channel choice ------------------------------------------ -->
			<section class="attendant-wiz__step" data-step="5" hidden>
				<h3><?php echo esc_html__( 'Where should chats arrive?', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'Each visitor conversation becomes one thread in this channel.', 'attendant' ); ?>
				</p>
				<div class="attendant-wiz__choices">
					<button type="button" class="attendant-wiz__choice" id="attendant-wiz-pick-new">
						<strong><?php echo esc_html__( 'Create a new channel', 'attendant' ); ?></strong>
						<span><?php echo esc_html__( 'Recommended — we make it and the bot joins automatically.', 'attendant' ); ?></span>
					</button>
					<button type="button" class="attendant-wiz__choice" id="attendant-wiz-pick-existing">
						<strong><?php echo esc_html__( 'Use an existing channel', 'attendant' ); ?></strong>
						<span><?php echo esc_html__( 'Pick one the app has already been added to.', 'attendant' ); ?></span>
					</button>
				</div>
			</section>

			<!-- 6 — Create a channel ---------------------------------------- -->
			<section class="attendant-wiz__step" data-step="6" hidden>
				<h3><?php echo esc_html__( 'Name your channel', 'attendant' ); ?></h3>
				<label class="attendant-wiz__label" for="attendant-wiz-new-name"><?php echo esc_html__( 'Channel name', 'attendant' ); ?></label>
				<input type="text" id="attendant-wiz-new-name" class="regular-text" placeholder="website-chat">
				<p style="margin-top:10px;">
					<label><input type="checkbox" id="attendant-wiz-new-private" checked> <?php echo esc_html__( 'Make it private (recommended)', 'attendant' ); ?></label>
				</p>
				<label class="attendant-wiz__label" for="attendant-wiz-new-emails"><?php echo esc_html__( 'Invite teammates (optional)', 'attendant' ); ?></label>
				<p class="attendant-wiz__hint"><?php echo esc_html__( 'One email address per line.', 'attendant' ); ?></p>
				<textarea id="attendant-wiz-new-emails" rows="3" class="large-text" placeholder="teammate@example.com"></textarea>
			</section>

			<!-- 7 — Choose existing ----------------------------------------- -->
			<section class="attendant-wiz__step" data-step="7" hidden>
				<h3><?php echo esc_html__( 'Choose a channel', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'These are the channels the app has been added to.', 'attendant' ); ?>
				</p>
				<div id="attendant-wiz-existing"></div>
				<p>
					<button type="button" class="button" id="attendant-wiz-reload"><?php echo esc_html__( 'Reload channels', 'attendant' ); ?></button>
				</p>
				<p class="attendant-wiz__note">
					<?php echo esc_html__( 'Missing a private channel? Open it in Slack, type /invite @Attendant Chat, then Reload.', 'attendant' ); ?>
				</p>
			</section>

			<!-- 8 — Test + finish ------------------------------------------- -->
			<section class="attendant-wiz__step" data-step="8" hidden>
				<h3><?php echo esc_html__( 'Send a test message', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead" id="attendant-wiz-summary"></p>
				<p>
					<button type="button" class="button button-hero button-primary" id="attendant-wiz-test">
						<?php echo esc_html__( 'Send test message', 'attendant' ); ?>
					</button>
				</p>
				<p class="attendant-wiz__note">
					<?php echo esc_html__( 'It should appear in your Slack channel within a second or two.', 'attendant' ); ?>
				</p>
			</section>

		</div>

		<div class="attendant-wiz__status" id="attendant-wiz-status" aria-live="polite"></div>

		<div class="attendant-wiz__foot">
			<button type="button" class="button" id="attendant-wiz-back"><?php echo esc_html__( 'Back', 'attendant' ); ?></button>
			<button type="button" class="button button-primary" id="attendant-wiz-next"><?php echo esc_html__( 'Next', 'attendant' ); ?></button>
		</div>

	</div>
</div>
