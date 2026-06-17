<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'public/class-attendant-frontend.php';

/**
 * Readiness gate for the public chat widget.
 *
 * The widget (and the /chat endpoint) must not be available until the
 * assistant can actually answer: an API key is saved, and — when the optional
 * Semantic Q&A mode is on — the content index holds at least one chunk.
 */
final class FrontendReadyTest extends TestCase {

	/** Options returned by the stubbed get_option(). */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Attendant_Plugin::$test_settings = array();
		$this->options              = array();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => $this->options[ $name ] ?? $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_not_ready_without_api_key(): void {
		Attendant_Plugin::$test_settings = array( 'active_provider' => 'openai' );

		$this->assertFalse( ATTENDANT_Frontend::is_ready() );
	}

	public function test_ready_with_key_when_semantic_mode_off(): void {
		Attendant_Plugin::$test_settings        = array(
			'active_provider' => 'openai',
			'semantic_mode'   => false,
		);
		$this->options['attendant_api_key_openai'] = 'encrypted-blob';

		// Structured search needs no index — ready immediately.
		$this->assertTrue( ATTENDANT_Frontend::is_ready() );
	}

	public function test_not_ready_when_semantic_mode_on_and_index_empty(): void {
		Attendant_Plugin::$test_settings        = array(
			'active_provider' => 'openai',
			'semantic_mode'   => true,
		);
		$this->options['attendant_api_key_openai'] = 'encrypted-blob';
		$this->options['attendant_index_status']   = array( 'total_chunks' => 0 );

		$this->assertFalse( ATTENDANT_Frontend::is_ready() );
		$this->assertSame( 'index_empty', ATTENDANT_Frontend::status()['reason'] );
	}

	public function test_ready_when_semantic_mode_on_and_index_complete(): void {
		Attendant_Plugin::$test_settings        = array(
			'active_provider' => 'openai',
			'semantic_mode'   => true,
		);
		$this->options['attendant_api_key_openai'] = 'encrypted-blob';
		$this->options['attendant_index_status']   = array(
			'total_chunks'     => 42,
			'initial_complete' => true,
		);

		$this->assertTrue( ATTENDANT_Frontend::is_ready() );
	}

	public function test_not_ready_while_first_indexing_run_incomplete(): void {
		// The user's product rule: a half-built first index must never serve
		// visitors. Widget stays hidden until the initial run completes.
		Attendant_Plugin::$test_settings        = array(
			'active_provider' => 'openai',
			'semantic_mode'   => false,
		);
		$this->options['attendant_api_key_openai'] = 'encrypted-blob';
		$this->options['attendant_index_status']   = array(
			'total_chunks' => 500,
			'pending'      => 1648,
			'is_running'   => true,
		);

		$status = ATTENDANT_Frontend::status();
		$this->assertFalse( $status['ready'] );
		$this->assertSame( 'indexing', $status['reason'] );
	}

	public function test_ready_during_reindex_after_initial_complete(): void {
		// Re-indexing later must NOT re-hide the widget — only the first
		// build gates visibility.
		Attendant_Plugin::$test_settings        = array(
			'active_provider' => 'openai',
			'semantic_mode'   => false,
		);
		$this->options['attendant_api_key_openai'] = 'encrypted-blob';
		$this->options['attendant_index_status']   = array(
			'total_chunks'     => 5000,
			'pending'          => 300,
			'is_running'       => true,
			'initial_complete' => true,
		);

		$this->assertTrue( ATTENDANT_Frontend::is_ready() );
	}

	public function test_ready_when_indexing_never_started(): void {
		// Structured-search-only sites that never run indexing get the
		// widget as soon as a key is saved.
		Attendant_Plugin::$test_settings        = array(
			'active_provider' => 'openai',
			'semantic_mode'   => false,
		);
		$this->options['attendant_api_key_openai'] = 'encrypted-blob';
		$this->options['attendant_index_status']   = array(
			'total_chunks' => 0,
			'pending'      => 0,
			'is_running'   => false,
		);

		$this->assertTrue( ATTENDANT_Frontend::is_ready() );
	}
}
