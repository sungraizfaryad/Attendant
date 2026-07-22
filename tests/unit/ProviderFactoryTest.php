<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/class-attendant-provider-factory.php';

/**
 * Two-provider factory: Google (free) + OpenAI. Chat follows active_provider;
 * embeddings follow the index-time stamp so vector spaces never mix.
 */
final class ProviderFactoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Attendant_Plugin::$test_settings = array();
		Functions\when( 'wp_salt' )->alias( fn( $scheme = 'auth' ) => "salt-{$scheme}" );
	}
	protected function tearDown(): void {
		Attendant_Plugin::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Stub get_option: keys map + optional embedding stamp. */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = '' ) use ( $options ) {
				if ( 'attendant_settings' === $name ) {
					return Attendant_Plugin::$test_settings;
				}
				return $options[ $name ] ?? $default;
			}
		);
	}

	public function test_supported_is_google_then_openai(): void {
		$this->assertSame( array( 'google', 'openai' ), ATTENDANT_Provider_Factory::supported() );
	}

	public function test_active_provider_defaults_to_openai_for_legacy_installs(): void {
		$this->stub_options( array() );
		$this->assertSame( 'openai', ATTENDANT_Provider_Factory::active_provider() );
	}

	public function test_active_provider_reads_setting(): void {
		Attendant_Plugin::$test_settings = array( 'active_provider' => 'google' );
		$this->stub_options( array() );
		$this->assertSame( 'google', ATTENDANT_Provider_Factory::active_provider() );
	}

	public function test_unknown_active_provider_falls_back(): void {
		Attendant_Plugin::$test_settings = array( 'active_provider' => 'openrouter' );
		$this->stub_options( array() );
		$this->assertSame( 'openai', ATTENDANT_Provider_Factory::active_provider() );
	}

	public function test_create_null_without_key(): void {
		$this->stub_options( array() );
		$this->assertNull( ATTENDANT_Provider_Factory::create() );
	}

	public function test_create_google_when_active_and_keyed(): void {
		Attendant_Plugin::$test_settings = array( 'active_provider' => 'google' );
		$this->stub_options( array( 'attendant_api_key_google' => 'enc' ) );
		$provider = ATTENDANT_Provider_Factory::create();
		$this->assertSame( 'google', $provider->get_provider_name() );
	}

	public function test_create_ignores_other_provider_keys(): void {
		// Active openai (default) but only google key stored — no chat provider.
		$this->stub_options( array( 'attendant_api_key_google' => 'enc' ) );
		$this->assertNull( ATTENDANT_Provider_Factory::create() );
	}

	/** Install a wpdb double reporting the given chunk count. */
	private function stub_wpdb( int $chunk_count ): void {
		$GLOBALS['wpdb'] = new class( $chunk_count ) {
			public string $prefix = 'wp_';
			private int $count;
			public function __construct( int $count ) {
				$this->count = $count;
			}
			public function get_var( $sql ) {
				return $this->count;
			}
		};
	}

	public function test_unstamped_legacy_install_resolves_to_openai(): void {
		// Chunks exist but no stamp — vectors predate the option: OpenAI.
		$this->stub_options( array() );
		$this->stub_wpdb( 500 );
		Functions\when( 'update_option' )->justReturn( true );
		$this->assertSame( 'openai', ATTENDANT_Provider_Factory::embedding_provider() );
	}

	public function test_unstamped_fresh_install_follows_active_provider(): void {
		// Empty index — nothing to stay consistent with: follow active.
		Attendant_Plugin::$test_settings = array( 'active_provider' => 'google' );
		$this->stub_options( array() );
		$this->stub_wpdb( 0 );
		$persisted = array();
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) use ( &$persisted ) {
				$persisted[ $k ] = $v;
				return true;
			}
		);
		$this->assertSame( 'google', ATTENDANT_Provider_Factory::embedding_provider() );
		$this->assertSame( 'google', $persisted['attendant_embedding_provider'] );
	}

	public function test_embeddings_follow_stamp_not_active_provider(): void {
		// Chat switched to Google, but the index was built with OpenAI:
		// embedding work must keep using OpenAI.
		Attendant_Plugin::$test_settings = array( 'active_provider' => 'google' );
		$this->stub_options(
			array(
				'attendant_api_key_google'      => 'enc-g',
				'attendant_api_key_openai'      => 'enc-o',
				'attendant_embedding_provider'  => 'openai',
			)
		);
		$embedder = ATTENDANT_Provider_Factory::create_for_embeddings();
		$this->assertSame( 'openai', $embedder->get_provider_name() );
	}

	public function test_embeddings_null_when_stamped_key_missing(): void {
		// Index stamped openai, openai key deleted → fallback expected.
		Attendant_Plugin::$test_settings = array( 'active_provider' => 'google' );
		$this->stub_options(
			array(
				'attendant_api_key_google'     => 'enc-g',
				'attendant_embedding_provider' => 'openai',
			)
		);
		$this->assertNull( ATTENDANT_Provider_Factory::create_for_embeddings() );
	}

	public function test_stamp_validates_slug(): void {
		$updates = array();
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$updates ) {
				$updates[ $name ] = $value;
				return true;
			}
		);
		ATTENDANT_Provider_Factory::stamp_embedding_provider( 'google' );
		ATTENDANT_Provider_Factory::stamp_embedding_provider( 'openrouter' );

		$this->assertSame( array( 'attendant_embedding_provider' => 'google' ), $updates );
	}
}
