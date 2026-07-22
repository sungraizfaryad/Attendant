<?php
/**
 * Provider factory — maps provider slugs to instances.
 *
 * Two providers: Google Gemini (free tier — recommended) and OpenAI (paid).
 * Callers never instantiate provider classes directly.
 *
 * Chat and embeddings are resolved separately on purpose. The chat provider
 * is whatever `active_provider` says. Embeddings must stay consistent with
 * the vectors already stored in the chunks table, so they follow the
 * `attendant_embedding_provider` option stamped at index time — switching
 * the chat provider never silently mixes vector spaces. A full re-index is
 * what re-stamps the embedding provider.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/interface-attendant-llm-provider.php';

/**
 * Class ATTENDANT_Provider_Factory
 */
final class ATTENDANT_Provider_Factory {

	/**
	 * Provider slug → class file + class name. Order = settings UI order.
	 *
	 * @var array<string, array{file: string, class: string}>
	 */
	private const PROVIDERS = array(
		'google' => array(
			'file'  => 'class-attendant-google-provider.php',
			'class' => 'ATTENDANT_Google_Provider',
		),
		'openai' => array(
			'file'  => 'class-attendant-openai-provider.php',
			'class' => 'ATTENDANT_OpenAI_Provider',
		),
	);

	/**
	 * The chat provider selected in settings, validated against the map.
	 *
	 * Default 'openai' — existing installs predate the setting and have
	 * OpenAI keys; fresh installs get 'google' written by the wizard.
	 *
	 * @return string
	 */
	public static function active_provider(): string {
		$active = (string) Attendant_Plugin::get_setting( 'active_provider', 'openai' );
		return isset( self::PROVIDERS[ $active ] ) ? $active : 'openai';
	}

	/**
	 * All supported provider slugs, in display order.
	 *
	 * @return string[]
	 */
	public static function supported(): array {
		return array_keys( self::PROVIDERS );
	}

	/**
	 * Whether a non-empty API key is stored for the given provider.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function has_key( string $provider ): bool {
		return '' !== trim( (string) get_option( "attendant_api_key_{$provider}", '' ) );
	}

	/**
	 * Instantiate a provider, or null when unknown / key missing.
	 *
	 * @param string|null $provider Slug override; defaults to the active provider.
	 * @return ATTENDANT_LLM_Provider|null
	 */
	public static function create( ?string $provider = null ): ?ATTENDANT_LLM_Provider {
		$slug = $provider ?? self::active_provider();

		if ( ! isset( self::PROVIDERS[ $slug ] ) || ! self::has_key( $slug ) ) {
			return null;
		}

		$entry = self::PROVIDERS[ $slug ];
		require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/' . $entry['file'];

		return new $entry['class']();
	}

	/**
	 * Which provider's vectors the index currently holds.
	 *
	 * No stamp yet means one of two things: a legacy install whose vectors
	 * are OpenAI (indexed before this option existed), or a fresh site with
	 * an empty index — there the stamp simply follows the active provider,
	 * since there are no existing vectors to stay consistent with. The
	 * resolution is persisted so the chunk count is only checked once.
	 *
	 * @return string
	 */
	public static function embedding_provider(): string {
		$stamp = (string) get_option( 'attendant_embedding_provider', '' );

		if ( isset( self::PROVIDERS[ $stamp ] ) ) {
			return $stamp;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'attendant_chunks';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0;

		$resolved = $has_chunks ? 'openai' : self::active_provider();
		update_option( 'attendant_embedding_provider', $resolved );

		return $resolved;
	}

	/**
	 * Provider instance for embedding work (indexing, query embedding,
	 * Q&A matching). Null when the stamped provider has no key — callers
	 * fall back to FULLTEXT / fuzzy matching.
	 *
	 * @return ATTENDANT_LLM_Provider|null
	 */
	public static function create_for_embeddings(): ?ATTENDANT_LLM_Provider {
		return self::create( self::embedding_provider() );
	}

	/**
	 * Re-stamp the embedding provider. Called by the index manager when a
	 * FULL re-index starts — never on incremental runs, which must keep
	 * embedding with whatever the existing vectors used.
	 *
	 * @param string $provider Provider slug.
	 */
	public static function stamp_embedding_provider( string $provider ): void {
		if ( isset( self::PROVIDERS[ $provider ] ) ) {
			update_option( 'attendant_embedding_provider', $provider );
		}
	}
}
