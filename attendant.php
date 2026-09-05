<?php
/**
 * Plugin Name: Attendant - Free AI Site Search & Chatbot
 * Plugin URI:  https://wordpress.org/plugins/attendant/
 * Description: Free AI search chatbot for your site, powered by Google Gemini's free tier (no credit card). Visitors ask plain-language questions; Attendant safely searches your own posts, pages, products, and listings and answers in a chat widget. OpenAI supported too.
 * Version:     2.2.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      Sungraiz Faryad
 * Author URI:
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: attendant
 * Domain Path: /languages
 *
 * @package Attendant
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version and path constants.
define( 'ATTENDANT_VERSION', '2.2.1' );
define( 'ATTENDANT_PLUGIN_FILE', __FILE__ );
define( 'ATTENDANT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ATTENDANT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ATTENDANT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum requirements.
 *
 * We declare these as constants so they can be checked programmatically
 * and referenced in admin notices without hard-coded strings.
 */
define( 'ATTENDANT_REQUIRED_PHP', '8.0' );
define( 'ATTENDANT_REQUIRED_WP', '6.0' );

// -------------------------------------------------------------------------
// Activation / deactivation hooks — registered before any class is loaded.
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, 'attendant_activate_plugin' );
register_deactivation_hook( __FILE__, 'attendant_deactivate_plugin' );

/**
 * Plugin activation callback.
 *
 * Loads only the activator class (keeps the memory footprint small) and
 * delegates all setup work to it.
 */
function attendant_activate_plugin(): void {
	require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-activator.php';
	ATTENDANT_Activator::activate();
}

/**
 * Plugin deactivation callback.
 */
function attendant_deactivate_plugin(): void {
	require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-deactivator.php';
	ATTENDANT_Deactivator::deactivate();
}

// -------------------------------------------------------------------------
// Requirement check — show an admin notice and bail if requirements unmet.
// Runs as early as possible so we never load plugin code on an incompatible
// environment.
// -------------------------------------------------------------------------

/**
 * Check minimum PHP and WordPress version requirements.
 *
 * @return bool True if requirements are met.
 */
function attendant_requirements_met(): bool {
	return version_compare( PHP_VERSION, ATTENDANT_REQUIRED_PHP, '>=' )
		&& version_compare( get_bloginfo( 'version' ), ATTENDANT_REQUIRED_WP, '>=' );
}

if ( ! attendant_requirements_met() ) {
	add_action(
		'admin_notices',
		function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
						/* translators: 1: Required PHP version, 2: Required WP version */
						__(
							'<strong>Attendant</strong> requires PHP %1$s and WordPress %2$s or higher. Please upgrade your environment.',
							'attendant'
						),
						esc_html( ATTENDANT_REQUIRED_PHP ),
						esc_html( ATTENDANT_REQUIRED_WP )
					)
				)
			);
		}
	);
	return; // Do not load any further plugin code.
}

// -------------------------------------------------------------------------
// Main plugin class — singleton.
// Loaded only after requirements are confirmed.
// -------------------------------------------------------------------------

/**
 * Main plugin class.
 *
 * Responsible only for bootstrapping: loading dependencies and registering
 * top-level WordPress hooks. Business logic lives in dedicated classes.
 */
final class Attendant_Plugin {

	/**
	 * Single instance of this class.
	 *
	 * @var Attendant_Plugin|null
	 */
	private static ?Attendant_Plugin $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Attendant_Plugin
	 */
	public static function instance(): Attendant_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — private to enforce singleton.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Prevent cloning of the singleton instance.
	 */
	private function __clone() {}

	/**
	 * Load all required class files.
	 *
	 * Admin-only and CLI-only files are loaded conditionally to avoid
	 * loading dead code on the frontend or in non-CLI contexts.
	 */
	private function load_dependencies(): void {
		// Core utilities (always needed).
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-encryption.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-field-config.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-onboarding.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-billing.php';

		// Schema classes — needed by REST API and cron handlers.
		require_once ATTENDANT_PLUGIN_DIR . 'includes/schema/class-attendant-schema-cache.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/schema/class-attendant-field-detector.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/schema/class-attendant-schema-discovery.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/schema/class-attendant-schema-catalog.php';

		// Indexing pipeline — loaded on every request because:
		// a) WP-Cron fires via HTTP on any page load.
		// b) Auto-sync hooks (save_post, before_delete_post) fire everywhere.
		// c) REST /index/start calls ATTENDANT_Index_Manager from any origin.
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-content-fetcher.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-text-extractor.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-chunker.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-embedder.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-index-manager.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-auto-sync.php';

		// Chat engine — loaded on every request because REST is always active.
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-rag-retriever.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-query-builder.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-qa-manager.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-conversation-handler.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-chat-log.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-leads.php';
		require_once ATTENDANT_PLUGIN_DIR . 'includes/integrations/class-attendant-slack.php';

		// REST API (always needed — REST is active on all requests).
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-rest-api.php';

		// Admin-only classes.
		if ( is_admin() ) {
			require_once ATTENDANT_PLUGIN_DIR . 'admin/class-attendant-admin.php';
		}

		// Frontend-only class (not needed in admin or WP-Cron context).
		if ( ! is_admin() ) {
			require_once ATTENDANT_PLUGIN_DIR . 'public/class-attendant-frontend.php';
		}
	}

	/**
	 * Register all WordPress action and filter hooks.
	 *
	 * Hooks that drive admin UI, REST registration, and background tasks.
	 * No business logic here — just hookup.
	 */
	private function register_hooks(): void {
		// Register our custom 5-minute cron interval for the indexing queue.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// REST API registration runs on every request (REST is stateless).
		add_action( 'rest_api_init', array( $this, 'init_rest_api' ) );

		// Admin initialisation only in the dashboard.
		if ( is_admin() ) {
			add_action( 'init', array( $this, 'init_admin' ) );
		}

		// Frontend widget — only on frontend requests (not admin, not WP-Cron).
		if ( ! is_admin() ) {
			add_action( 'init', array( $this, 'init_frontend' ) );
		}

		// Cron action handlers — must be registered on all requests so WP-Cron
		// can call them via HTTP when they are scheduled to fire.
		add_action( 'attendant_weekly_schema_scan', array( $this, 'run_weekly_schema_scan' ) );
		add_action( 'attendant_process_index_queue', array( $this, 'process_index_queue' ) );

		// Background-mode indexing loopback endpoint. Both hooks are required:
		// loopback requests carry no cookies, so they always arrive
		// unauthenticated (nopriv); the secret-key check happens inside.
		add_action( 'wp_ajax_attendant_async_index', array( 'ATTENDANT_Index_Manager', 'handle_async_request' ) );
		add_action( 'wp_ajax_nopriv_attendant_async_index', array( 'ATTENDANT_Index_Manager', 'handle_async_request' ) );

		// Post lifecycle hooks — auto-sync must fire on every request type
		// (admin, frontend, REST, WP-Cron) since post saves can happen anywhere.
		ATTENDANT_Auto_Sync::init();
	}

	/**
	 * Register custom WP-Cron intervals used by this plugin.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['attendant_five_minutes'] ) ) {
			$schedules['attendant_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (Attendant)', 'attendant' ),
			);
		}
		return $schedules;
	}

	/**
	 * Initialise the REST API controller.
	 *
	 * Callback for the `rest_api_init` action.
	 */
	public function init_rest_api(): void {
		$controller = new ATTENDANT_REST_API();
		$controller->register_routes();
	}

	/**
	 * Initialise the admin area.
	 *
	 * Callback for the `init` action (admin-only).
	 */
	public function init_admin(): void {
		ATTENDANT_Admin::instance();
	}

	/**
	 * Initialise the frontend widget.
	 *
	 * Callback for the `init` action (frontend-only).
	 * Bootstraps ATTENDANT_Frontend which registers enqueue and footer hooks.
	 */
	public function init_frontend(): void {
		ATTENDANT_Frontend::instance();
	}

	/**
	 * Run the weekly schema re-scan.
	 *
	 * Callback for the `attendant_weekly_schema_scan` WP-Cron action.
	 * Discovers all post types, taxonomies, and custom fields; caches
	 * the result in wp_options so the AI always has an up-to-date picture
	 * of the site's content structure.
	 */
	public function run_weekly_schema_scan(): void {
		ATTENDANT_Schema_Discovery::run();
	}

	/**
	 * Process the next batch of the content indexing queue.
	 *
	 * Callback for the `attendant_process_index_queue` WP-Cron action.
	 * Fires every 5 minutes. If the queue is empty or a concurrency lock
	 * is held, the method exits immediately without doing any work.
	 */
	public function process_index_queue(): void {
		ATTENDANT_Index_Manager::process_queue_batch();
	}

	/**
	 * Retrieve a plugin setting.
	 *
	 * @param string|null $key     Setting key, or null to return all settings.
	 * @param mixed       $default Default value when the key is absent.
	 * @return mixed
	 */
	public static function get_setting( ?string $key = null, mixed $default = null ): mixed {
		$settings = get_option( 'attendant_settings', array() );

		if ( null === $key ) {
			return $settings;
		}

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Persist a single plugin setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 */
	public static function update_setting( string $key, mixed $value ): void {
		$settings         = self::get_setting();
		$settings[ $key ] = $value;
		update_option( 'attendant_settings', $settings );
	}
}

/**
 * Global helper — returns the single plugin instance.
 *
 * Allows other code to call attendant() without using statics directly.
 *
 * @return Attendant_Plugin
 */
function attendant(): Attendant_Plugin {
	return Attendant_Plugin::instance();
}

// Boot the plugin on `plugins_loaded` so that all plugins are available and
// WordPress is in a consistent state before we do anything.
add_action( 'plugins_loaded', 'attendant' );

/**
 * Run schema/option migrations when plugin files were updated without the
 * activation hook firing (normal WP update flow). Cheap check per request;
 * real work happens once, until attendant_db_version catches up.
 *
 * Admin/cron requests only — migrations include an ALTER TABLE that must not
 * land on a random visitor pageview, and every update flow involves wp-admin.
 * Priority 20: the bootstrap (priority 10) registers the custom cron
 * schedule that schedule_cron_events() relies on.
 */
function attendant_maybe_run_upgrade_migrations(): void {
	if ( ! is_admin() && ! wp_doing_cron() ) {
		return;
	}
	$stored = (string) get_option( 'attendant_db_version', '0.0.0' );
	if ( version_compare( $stored, ATTENDANT_VERSION, '>=' ) ) {
		return;
	}
	if ( ! class_exists( 'ATTENDANT_Activator' ) ) {
		require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-activator.php';
	}
	ATTENDANT_Activator::run_migrations();
}
add_action( 'plugins_loaded', 'attendant_maybe_run_upgrade_migrations', 20 );
