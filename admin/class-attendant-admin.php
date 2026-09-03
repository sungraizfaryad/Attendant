<?php
/**
 * Admin Controller
 *
 * Handles all WordPress admin area integration:
 *  - Registers the admin menu and submenu pages.
 *  - Enqueues admin scripts and styles ONLY on plugin pages.
 *  - Bootstraps the settings page.
 *
 * Performance note: scripts and styles are conditional. We check the
 * current admin screen before enqueueing — zero overhead on pages that
 * have nothing to do with this plugin.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ATTENDANT_Admin
 */
class ATTENDANT_Admin {

	/**
	 * Admin menu slug — used to identify our pages.
	 */
	private const MENU_SLUG = 'attendant';

	/**
	 * Single instance of this class.
	 *
	 * @var ATTENDANT_Admin|null
	 */
	private static ?ATTENDANT_Admin $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return ATTENDANT_Admin
	 */
	public static function instance(): ATTENDANT_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — private to enforce singleton.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_attendant_download_log', array( $this, 'download_chat_log' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_reindex_notice' ) );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level admin menu and all submenu pages.
	 */
	public function register_menus(): void {
		// Top-level menu — points to the Dashboard page.
		add_menu_page(
			__( 'Attendant', 'attendant' ),        // Page title (browser tab).
			__( 'Attendant', 'attendant' ),        // Menu label.
			'manage_options',                           // Capability required.
			self::MENU_SLUG,                            // Menu slug.
			array( $this, 'render_settings_page' ),    // Callback — Phase 1 shows settings.
			'dashicons-format-chat',                    // Icon.
			80                                          // Position (after Settings).
		);

		// Submenu: Content Indexing — fully implemented in Phase 3.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Content Indexing — Attendant', 'attendant' ),
			__( 'Content Indexing', 'attendant' ),
			'manage_options',
			self::MENU_SLUG . '-indexing',
			array( $this, 'render_indexing_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Schema — Attendant', 'attendant' ),
			__( 'Schema', 'attendant' ),
			'manage_options',
			self::MENU_SLUG . '-schema',
			array( $this, 'render_schema_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Q&A Manager — Attendant', 'attendant' ),
			__( 'Q&amp;A Manager', 'attendant' ),
			'manage_options',
			self::MENU_SLUG . '-qa',
			array( $this, 'render_qa_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Analytics — Attendant', 'attendant' ),
			__( 'Analytics', 'attendant' ),
			'manage_options',
			self::MENU_SLUG . '-analytics',
			array( $this, 'render_analytics_page' )
		);

		// Submenu: Settings — registered LAST so it sits at the bottom of the
		// submenu list. Uses the parent slug, so the top-level menu item still
		// lands on Settings (and the setup wizard on first run).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings — Attendant', 'attendant' ),
			__( 'Settings', 'attendant' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueueing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin CSS and JS — only on plugin admin pages.
	 *
	 * We check the current screen's base against our menu slug to avoid
	 * loading any assets on unrelated admin pages.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Only enqueue on our own admin pages.
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}

		// Pass the REST API base URL and a nonce to JavaScript.
		// The nonce uses 'wp_rest' action — this is what X-WP-Nonce expects.
		wp_add_inline_script(
			'wp-api',  // wp-api is always loaded on admin pages — safe to depend on it.
			sprintf(
				'var attendantAdmin = %s;',
				wp_json_encode(
					array(
						'restUrl' => esc_url_raw( rest_url( 'attendant/v1' ) ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
						'version' => ATTENDANT_VERSION,
						'i18n'    => array(
							'saving'    => __( 'Saving…', 'attendant' ),
							'saved'     => __( 'Settings saved.', 'attendant' ),
							'testing'   => __( 'Testing connection…', 'attendant' ),
							'connected' => __( 'Connected!', 'attendant' ),
							'error'     => __( 'Something went wrong. Please try again.', 'attendant' ),
						),
					)
				)
			),
			'before'
		);

		// Wizard assets — only on the top-level page (where the wizard renders).
		if ( str_contains( $hook_suffix, 'toplevel_page_' . self::MENU_SLUG ) ) {
			wp_enqueue_style(
				'attendant-wizard',
				ATTENDANT_PLUGIN_URL . 'admin/css/attendant-wizard.css',
				array(),
				ATTENDANT_VERSION
			);
			wp_enqueue_script(
				'attendant-wizard',
				ATTENDANT_PLUGIN_URL . 'admin/js/attendant-wizard.js',
				array( 'wp-api' ),
				ATTENDANT_VERSION,
				true
			);
			wp_add_inline_script(
				'wp-api',
				sprintf( 'attendantAdmin.settingsUrl = %s;', wp_json_encode( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) ),
				'after'
			);
		}

		// ── Settings page (top-level) ─────────────────────────────────────
		if ( str_contains( $hook_suffix, 'toplevel_page_' . self::MENU_SLUG ) ) {
			// Version by file mtime, not plugin version: admin CSS/JS changes
			// between releases and a cached stylesheet silently breaks the UI
			// (a stale sheet is what made the wizard modal appear stuck open).
			wp_enqueue_style(
				'attendant-settings',
				ATTENDANT_PLUGIN_URL . 'admin/css/attendant-settings.css',
				array(),
				self::asset_version( 'admin/css/attendant-settings.css' )
			);
			wp_enqueue_script(
				'attendant-settings',
				ATTENDANT_PLUGIN_URL . 'admin/js/attendant-settings.js',
				array( 'wp-api' ),
				self::asset_version( 'admin/js/attendant-settings.js' ),
				true
			);
			wp_enqueue_script(
				'attendant-slack-wizard',
				ATTENDANT_PLUGIN_URL . 'admin/js/attendant-slack-wizard.js',
				array( 'wp-api' ),
				self::asset_version( 'admin/js/attendant-slack-wizard.js' ),
				true
			);
		}

		// ── Content Indexing page ─────────────────────────────────────────
		if ( str_contains( $hook_suffix, self::MENU_SLUG . '-indexing' ) ) {
			wp_enqueue_style(
				'attendant-indexing',
				ATTENDANT_PLUGIN_URL . 'admin/css/attendant-indexing.css',
				array(),
				ATTENDANT_VERSION
			);
			wp_enqueue_script(
				'attendant-indexing',
				ATTENDANT_PLUGIN_URL . 'admin/js/attendant-indexing.js',
				array( 'wp-api' ),
				ATTENDANT_VERSION,
				true
			);
			$index_status = get_option( 'attendant_index_status', array() );
			wp_localize_script(
				'attendant-indexing',
				'attendantIndexing',
				array(
					'isRunning' => ! empty( $index_status['is_running'] ),
					'mode'      => (string) Attendant_Plugin::get_setting( 'indexing_mode', 'frontend' ),
					'i18n'      => array(
						'inProgress'        => __( 'Indexing in progress…', 'attendant' ),
						'complete'          => __( 'Indexing complete', 'attendant' ),
						'couldNotStart'     => __( 'Could not start indexing. Please try again.', 'attendant' ),
						'requestFailed'     => __( 'Request failed. Please check your connection and try again.', 'attendant' ),
						'stopped'           => __( 'Indexing stopped. Pending items have been cleared.', 'attendant' ),
						'couldNotStop'      => __( 'Could not stop indexing. Please try again.', 'attendant' ),
						'finished'          => __( 'Indexing finished. All queued content has been processed.', 'attendant' ),
						'startedBackground' => __( 'Indexing started in the background — you can close this page. Progress updates below while you stay.', 'attendant' ),
						'actIndexed'        => __( 'Indexed ✓', 'attendant' ),
						'actRemoved'        => __( 'Removed', 'attendant' ),
						'actFailed'         => __( 'Failed ✕', 'attendant' ),
						'stalledKicking'    => __( 'Indexing has not made progress for a while — it may have timed out. Resuming it automatically now…', 'attendant' ),
						'resumed'           => __( 'Indexing resumed and is making progress again.', 'attendant' ),
						'stalledFailed'     => __( 'Indexing appears stalled and the automatic resume failed. Click "Start Indexing" to resume, or switch the processing mode to "While the Indexing page is open" in Settings → Indexing.', 'attendant' ),
					),
				)
			);
		}

		// ── Schema page ───────────────────────────────────────────────────
		if ( str_contains( $hook_suffix, self::MENU_SLUG . '-schema' ) ) {
			wp_enqueue_style(
				'attendant-schema',
				ATTENDANT_PLUGIN_URL . 'admin/css/attendant-schema.css',
				array(),
				ATTENDANT_VERSION
			);
			wp_enqueue_script(
				'attendant-schema',
				ATTENDANT_PLUGIN_URL . 'admin/js/attendant-schema.js',
				array( 'wp-api' ),
				ATTENDANT_VERSION,
				true
			);
			wp_localize_script(
				'attendant-schema',
				'attendantSchema',
				array(
					'i18n' => array(
						'scanning'      => __( 'Scanning…', 'attendant' ),
						'doneReloading' => __( 'Done! Reloading…', 'attendant' ),
						'errorRetry'    => __( 'Error. Please try again.', 'attendant' ),
						'networkError'  => __( 'Network error. Please try again.', 'attendant' ),
					),
				)
			);
		}

		// ── Q&A Manager page ──────────────────────────────────────────────
		if ( str_contains( $hook_suffix, self::MENU_SLUG . '-qa' ) ) {
			wp_enqueue_style(
				'attendant-qa',
				ATTENDANT_PLUGIN_URL . 'admin/css/attendant-qa.css',
				array(),
				ATTENDANT_VERSION
			);
			wp_enqueue_script(
				'attendant-qa',
				ATTENDANT_PLUGIN_URL . 'admin/js/attendant-qa.js',
				array( 'wp-api' ),
				ATTENDANT_VERSION,
				true
			);
			wp_localize_script(
				'attendant-qa',
				'attendantQA',
				array(
					'i18n' => array(
						'editPair'      => __( 'Edit Q&A Pair', 'attendant' ),
						'addPair'       => __( 'Add New Q&A Pair', 'attendant' ),
						'active'        => __( 'Active', 'attendant' ),
						'inactive'      => __( 'Inactive', 'attendant' ),
						'edit'          => __( 'Edit', 'attendant' ),
						'delete'        => __( 'Delete', 'attendant' ),
						'confirmDelete' => __( 'Delete this Q&A pair? This cannot be undone.', 'attendant' ),
						'required'      => __( 'Question and answer are required.', 'attendant' ),
						'saving'        => __( 'Saving…', 'attendant' ),
						'saved'         => __( 'Saved.', 'attendant' ),
					),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Warn on every plugin admin screen when the AI provider was switched but
	 * the content index still holds the previous provider's vectors. The
	 * notice clears itself the moment a full re-index starts (re-stamping
	 * happens at enqueue time) or the provider is switched back.
	 */
	public function maybe_show_reindex_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! str_contains( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		// Not during the setup wizard — nothing is configured yet.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view toggle.
		if ( isset( $_GET['onboarding'] ) || ! ATTENDANT_Onboarding::is_complete() ) {
			return;
		}

		require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/class-attendant-provider-factory.php';

		if ( ! ATTENDANT_Provider_Factory::needs_full_reindex() ) {
			return;
		}

		$active      = ATTENDANT_Provider_Factory::active_provider();
		$stamp       = ATTENDANT_Provider_Factory::embedding_provider();
		$active_name = 'google' === $active ? __( 'Google Gemini', 'attendant' ) : __( 'OpenAI', 'attendant' );
		$stamp_name  = 'google' === $stamp ? __( 'Google Gemini', 'attendant' ) : __( 'OpenAI', 'attendant' );
		$on_indexing = str_contains( (string) $screen->id, self::MENU_SLUG . '-indexing' );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html__( 'Attendant: AI provider changed — re-index needed.', 'attendant' ); ?></strong>
				<?php
				printf(
					/* translators: 1: new provider name, 2: provider the index was built with */
					esc_html__( 'You switched the AI provider to %1$s, but your content index was built with %2$s. Search keeps using %2$s until you rebuild the index.', 'attendant' ),
					esc_html( $active_name ),
					esc_html( $stamp_name )
				);
				?>
			</p>
			<p>
				<?php if ( $on_indexing ) : ?>
					<?php
					printf(
						/* translators: %s: new provider name */
						esc_html__( 'Run "Index All Content" below to rebuild it with %s.', 'attendant' ),
						esc_html( $active_name )
					);
					?>
				<?php else : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-indexing' ) ); ?>" class="button button-primary">
						<?php echo esc_html__( 'Re-index now', 'attendant' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the Settings page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'attendant' ) );
		}

		// First-run (or ?onboarding=1): show the setup wizard instead of settings.
		// This is a read-only view toggle, no state change, so no nonce is needed.
		$force_wizard = isset( $_GET['onboarding'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view toggle.
		if ( $force_wizard || ! ATTENDANT_Onboarding::is_complete() ) {
			include ATTENDANT_PLUGIN_DIR . 'admin/views/onboarding.php';
			return;
		}

		include ATTENDANT_PLUGIN_DIR . 'admin/views/settings.php';
	}

	/**
	 * Render the Content Indexing page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_indexing_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'attendant' ) );
		}

		include ATTENDANT_PLUGIN_DIR . 'admin/views/indexing.php';
	}

	/**
	 * Render the Schema discovery page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_schema_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'attendant' ) );
		}

		include ATTENDANT_PLUGIN_DIR . 'admin/views/schema.php';
	}

	/**
	 * Render the Q&A Manager page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_qa_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'attendant' ) );
		}

		include ATTENDANT_PLUGIN_DIR . 'admin/views/qa.php';
	}

	/**
	 * Render the Analytics page.
	 *
	 * Capability is already enforced by WordPress via the menu registration.
	 * We double-check here for defence in depth.
	 */
	public function render_analytics_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'attendant' ) );
		}

		include ATTENDANT_PLUGIN_DIR . 'admin/views/analytics.php';
	}

	/**
	 * Stream a chat log file to an administrator (admin-post.php endpoint).
	 *
	 * Security: manage_options capability + nonce; the filename is validated
	 * by ATTENDANT_Chat_Log::resolve_download() against a strict whitelist pattern,
	 * so traversal or arbitrary-file reads are impossible.
	 */
	public function download_chat_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download logs.', 'attendant' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'attendant_download_log' );

		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( (string) $_GET['file'] ) ) : '';
		$path = ATTENDANT_Chat_Log::resolve_download( $file );

		if ( '' === $path ) {
			wp_die( esc_html__( 'Log file not found.', 'attendant' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="attendant-chat-' . basename( $path ) . '"' );
		// Content-Length lies (truncates the download) when zlib output
		// compression rewrites the body — only send it when that is off.
		if ( ! ini_get( 'zlib.output_compression' ) ) {
			header( 'Content-Length: ' . (string) filesize( $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current admin page belongs to this plugin.
	 *
	 * @param string $hook_suffix The hook suffix from admin_enqueue_scripts.
	 * @return bool
	 */
	/**
	 * Cache-busting version for a plugin-relative asset: its modification
	 * time, falling back to the plugin version when that is unavailable.
	 *
	 * @param string $relative_path Path inside the plugin folder.
	 * @return string
	 */
	private static function asset_version( string $relative_path ): string {
		$mtime = @filemtime( ATTENDANT_PLUGIN_DIR . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- version fallback handles failure.

		return (string) ( $mtime ?: ATTENDANT_VERSION );
	}

	private function is_plugin_page( string $hook_suffix ): bool {
		// WordPress generates hook suffixes like:
		// toplevel_page_attendant
		// attendant_page_attendant-indexing
		// Both contain our menu slug.
		return str_contains( $hook_suffix, self::MENU_SLUG );
	}
}
