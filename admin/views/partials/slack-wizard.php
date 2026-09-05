<?php
/**
 * Slack setup wizard (modal).
 *
 * Rendered hidden inside the Integrations tab and opened by the "Set up Slack"
 * button. Each step is a panel; admin/js/attendant-slack-wizard.js handles
 * navigation and the REST calls (save credentials, create/choose channel, test).
 *
 * Screenshots: drop images into admin/images/ using the names below and they
 * appear automatically in the matching step — no code change needed. Any of
 * .webp / .png / .jpg works; the shipped set is .webp.
 *   slack-step-signin
 *   slack-step-create-1       · slack-step-create-2
 *   slack-step-install-1      · slack-step-install-2
 *   slack-step-credentials-1  · slack-step-credentials-2
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The create-app step links to the pre-filled manifest URL, so this view
// needs the Slack class whether or not the caller already loaded it.
require_once ATTENDANT_PLUGIN_DIR . 'includes/integrations/class-attendant-slack.php';

// Re-run or first run? Computed here rather than trusting a caller's variable.
$attendant_wiz_settings = (array) Attendant_Plugin::get_setting();
$attendant_wiz_channel  = (string) ( $attendant_wiz_settings['slack_channel'] ?? '' );
$attendant_wiz_chan     = (string) ( $attendant_wiz_settings['slack_channel_name'] ?? '' );
$attendant_wiz_rerun    = '' !== $attendant_wiz_channel
	&& '' !== (string) get_option( 'attendant_slack_bot_token', '' )
	&& '' !== (string) get_option( 'attendant_slack_signing_secret', '' );
$attendant_wiz_chan     = '' !== $attendant_wiz_chan ? '#' . $attendant_wiz_chan : $attendant_wiz_channel;

/**
 * A link that opens the screenshot for a step. Prints nothing when the file
 * has not been added yet.
 *
 * Printed inline so it can sit inside a sentence or a list item — people skip
 * a row of buttons under the instructions, but they click a blue link sitting
 * on the step it belongs to. Every part is escaped here, so call sites echo it
 * directly (wp_kses_post would strip the button and its data attributes).
 *
 * @param string $name    Base filename inside admin/images/, no extension.
 * @param string $alt     Alt text.
 * @param string $caption Caption shown above the image in the viewer.
 * @param string $label   Link text.
 */
if ( ! function_exists( 'attendant_wizard_shot' ) ) :
function attendant_wizard_shot( string $name, string $alt, string $caption = '', string $label = '' ): void {
	$file = '';
	foreach ( array( 'webp', 'png', 'jpg', 'jpeg' ) as $ext ) {
		if ( file_exists( ATTENDANT_PLUGIN_DIR . 'admin/images/' . $name . '.' . $ext ) ) {
			$file = $name . '.' . $ext;
			break;
		}
	}
	if ( '' === $file ) {
		return;
	}
	if ( '' === $label ) {
		$label = __( 'click here to see the screenshot', 'attendant' );
	}

	printf(
		' <button type="button" class="attendant-wiz__shotlink" data-shot="%s" data-alt="%s" data-caption="%s">%s</button>',
		esc_url( ATTENDANT_PLUGIN_URL . 'admin/images/' . $file ),
		esc_attr( $alt ),
		esc_attr( '' === $caption ? $label : $caption ),
		esc_html( $label )
	);
}
endif;
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
				<?php if ( $attendant_wiz_rerun ) : ?>
				<div class="attendant-wiz__notice">
					<p class="attendant-wiz__notice-head">
						<?php
						printf(
							/* translators: %s: Slack channel name, e.g. #website-chat */
							esc_html__( 'You are already set up. Chats are going to %s.', 'attendant' ),
							'<strong>' . esc_html( $attendant_wiz_chan ) . '</strong>'
						);
						?>
					</p>
					<p>
						<?php echo esc_html__( 'Running this again never changes anything inside Slack. Your channel and every message in it stay exactly as they are.', 'attendant' ); ?>
					</p>
					<ul>
						<li>
							<strong><?php echo esc_html__( 'Just reconnecting?', 'attendant' ); ?></strong>
							<?php echo esc_html__( 'Click through and leave the two credential boxes empty to keep the ones you already have, then pick the same channel at the end.', 'attendant' ); ?>
						</li>
						<li>
							<strong><?php echo esc_html__( 'Moving to a channel that already exists?', 'attendant' ); ?></strong>
							<?php echo esc_html__( 'Choose "Use an existing channel" and pick it. Nothing is created.', 'attendant' ); ?>
						</li>
						<li>
							<strong><?php echo esc_html__( 'Want a brand new channel?', 'attendant' ); ?></strong>
							<?php echo esc_html__( 'Give it a name you are not already using — Slack refuses a name that is taken, and an archived channel still holds its name. Your old channel stays where it is; archive it in Slack if you are done with it, which keeps the history. Deleting is permanent and you rarely want it.', 'attendant' ); ?>
						</li>
					</ul>
					<p class="attendant-wiz__notice-warn">
						<?php echo esc_html__( 'One caution: if a visitor is talking to your team right now, switching channels cuts that conversation off. Finish it first.', 'attendant' ); ?>
					</p>
				</div>
				<?php endif; ?>
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
				<p class="attendant-wiz__note">
					<?php
					attendant_wizard_shot(
						'slack-step-signin',
						__( 'Slack sign-in screen', 'attendant' ),
						__( 'The Slack sign-in screen.', 'attendant' )
					);
					?>
				</p>
			</section>

			<!-- 2 — Create the app ------------------------------------------ -->
			<section class="attendant-wiz__step" data-step="2" hidden>
				<h3><?php echo esc_html__( 'Create the Attendant app', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'This link opens Slack with everything already filled in for you — the name, the permissions, and your website address.', 'attendant' ); ?>
				</p>
				<ol class="attendant-wiz__list">
					<li>
						<?php
						echo esc_html__( 'Choose your workspace', 'attendant' );
						attendant_wizard_shot(
							'slack-step-create-1',
							__( 'Slack "Create from a manifest" screen with the workspace picker', 'attendant' ),
							__( 'Pick your workspace, then Next.', 'attendant' )
						);
						?>
					</li>
					<li><?php echo esc_html__( 'Click Next', 'attendant' ); ?></li>
					<li>
						<?php
						echo esc_html__( 'Click Create and Install', 'attendant' );
						attendant_wizard_shot(
							'slack-step-create-2',
							__( 'Slack "Review your app" screen', 'attendant' ),
							__( 'Then Create and Install.', 'attendant' )
						);
						?>
					</li>
				</ol>
				<p>
					<a class="button button-hero button-primary" id="attendant-wiz-create-app" href="<?php echo esc_url( ATTENDANT_Slack::manifest_url() ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__( 'Create the app in Slack', 'attendant' ); ?> ↗
					</a>
				</p>
			</section>

			<!-- 3 — Install to workspace ------------------------------------ -->
			<section class="attendant-wiz__step" data-step="3" hidden>
				<h3><?php echo esc_html__( 'Give it permission', 'attendant' ); ?></h3>
				<p class="attendant-wiz__lead">
					<?php echo esc_html__( 'Slack now asks whether the app may act in your workspace.', 'attendant' ); ?>
				</p>
				<ol class="attendant-wiz__list attendant-wiz__list--big">
					<li>
						<strong><?php echo esc_html__( 'Click "Allow"', 'attendant' ); ?></strong>
						<?php
						attendant_wizard_shot(
							'slack-step-install-1',
							__( 'Slack permission screen for the Attendant app', 'attendant' ),
							__( 'Check the workspace is the right one, then Allow.', 'attendant' )
						);
						?>
					</li>
					<li>
						<strong><?php echo esc_html__( 'Click "Go to App Settings"', 'attendant' ); ?></strong>
						<?php
						attendant_wizard_shot(
							'slack-step-install-2',
							__( 'Slack "app is ready" screen', 'attendant' ),
							__( 'Then Go to App Settings.', 'attendant' )
						);
						?>
					</li>
				</ol>
				<p class="attendant-wiz__note">
					<?php echo esc_html__( 'Keep that Slack tab open — the next step needs two values from it. You can ignore the Slack CLI instructions on that screen; they are for people writing their own app.', 'attendant' ); ?>
				</p>
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
					attendant_wizard_shot(
						'slack-step-credentials-1',
						__( 'Slack Basic Information page showing the Signing Secret', 'attendant' ),
						__( 'Signing Secret: Basic Information → App Credentials → Show.', 'attendant' )
					);
					?>
				</p>
				<input type="password" id="attendant-wiz-secret" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr__( 'Paste the Signing Secret', 'attendant' ); ?>">

				<label class="attendant-wiz__label" for="attendant-wiz-token">
					<?php echo esc_html__( '2. Bot Token', 'attendant' ); ?>
				</label>
				<p class="attendant-wiz__hint">
					<?php
					echo esc_html__( 'Same app → OAuth & Permissions → copy the Bot User OAuth Token (it starts with xoxb-).', 'attendant' );
					attendant_wizard_shot(
						'slack-step-credentials-2',
						__( 'Slack OAuth and Permissions page showing the Bot User OAuth Token', 'attendant' ),
						__( 'Bot Token: OAuth & Permissions → Bot User OAuth Token → Copy.', 'attendant' )
					);
					?>
				</p>
				<input type="password" id="attendant-wiz-token" class="regular-text" autocomplete="off" placeholder="xoxb-…">

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
				<label class="attendant-wiz__label"><?php echo esc_html__( 'Who should be in this channel?', 'attendant' ); ?></label>
				<p class="attendant-wiz__hint">
					<?php echo esc_html__( 'You are ticked already. Tick anyone else who should answer visitor chats — but you do not have to do it here: once the channel exists, adding people is done in Slack like any other channel.', 'attendant' ); ?>
				</p>
				<p>
					<input type="search" id="attendant-wiz-people-filter" class="regular-text" placeholder="<?php echo esc_attr__( 'Search people', 'attendant' ); ?>">
				</p>
				<div id="attendant-wiz-people" class="attendant-wiz__people"></div>

				<div id="attendant-wiz-people-fallback" hidden>
					<label class="attendant-wiz__label" for="attendant-wiz-new-emails"><?php echo esc_html__( 'Email addresses instead', 'attendant' ); ?></label>
					<p class="attendant-wiz__hint"><?php echo esc_html__( 'One per line, as it appears on their Slack account. Include your own.', 'attendant' ); ?></p>
					<textarea id="attendant-wiz-new-emails" rows="3" class="large-text" placeholder="you@example.com"><?php echo esc_textarea( wp_get_current_user()->user_email ); ?></textarea>
				</div>
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
					<?php
					printf(
						/* translators: %s: the Slack app's name, e.g. Attendant Chat (My Site) */
						esc_html__( 'Missing a private channel? Open it in Slack, type /invite @%s, then Reload.', 'attendant' ),
						esc_html( ATTENDANT_Slack::app_name() )
					);
					?>
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

	<div class="attendant-wiz-shotview" id="attendant-wiz-shotview" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Screenshot', 'attendant' ); ?>" hidden>
		<button type="button" class="attendant-wiz-shotview__close" id="attendant-wiz-shotview-close" aria-label="<?php echo esc_attr__( 'Close screenshot', 'attendant' ); ?>" title="<?php echo esc_attr__( 'Close screenshot', 'attendant' ); ?>">
			<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
		</button>
		<div class="attendant-wiz-shotview__scroll" id="attendant-wiz-shotview-scroll" tabindex="0">
			<figure class="attendant-wiz-shotview__panel">
				<figcaption class="attendant-wiz-shotview__caption" id="attendant-wiz-shotview-caption"></figcaption>
				<img id="attendant-wiz-shotview-img" src="" alt="">
			</figure>
		</div>
	</div>
</div>
