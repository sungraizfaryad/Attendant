<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-encryption.php';
require_once ATTENDANT_PLUGIN_DIR . 'includes/integrations/class-attendant-slack.php';

/**
 * Slack live-agent handoff: request signatures, session↔thread mapping,
 * the agent-message queue, and the pre-filled app manifest.
 */
final class SlackTest extends TestCase {

	/** In-memory transient store. */
	private array $transients = array();

	/** In-memory option store. */
	private array $options = array();

	/** Captured wp_remote_post calls. */
	private array $posts = array();

	/** Fake Slack API response body for the next call(s). */
	private array $api_response = array( 'ok' => true, 'ts' => '111.222' );

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients   = array();
		$this->options      = array();
		$this->posts        = array();
		$this->api_response = array( 'ok' => true, 'ts' => '111.222' );

		Attendant_Plugin::$test_settings = array(
			'slack_enabled' => true,
			'slack_channel' => 'C0TESTCHAN',
		);

		Functions\when( 'wp_salt' )->alias( static fn( $scheme = 'auth' ) => "salt-{$scheme}" );
		Functions\when( 'wp_hash' )->alias( static fn( $v, $scheme = 'auth' ) => hash_hmac( 'sha256', (string) $v, "salt-{$scheme}" ) );
		Functions\when( 'wp_generate_password' )->alias(
			static function ( $len = 12 ) {
				// Deterministic-enough for tests: unique per call.
				static $n = 0;
				++$n;
				return substr( str_repeat( 'abcdefghijklmnop', 4 ), 0, (int) $len ) . $n;
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'current_time' )->justReturn( '2026-08-22 12:00:00' );
		Functions\when( 'sanitize_email' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'rest_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-json/' . $path );

		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $this->options[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias(
			function ( $k, $v ) {
				$this->options[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->transients[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $k ) {
				unset( $this->transients[ $k ] );
				return true;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array( 'url' => $url, 'args' => $args );
				return array( 'body' => json_encode( $this->api_response ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] ?? '' );
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Attendant_Plugin::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function store_credentials(): void {
		$this->options['attendant_slack_bot_token']      = ATTENDANT_Encryption::encrypt( 'xoxb-test-token' );
		$this->options['attendant_slack_signing_secret'] = ATTENDANT_Encryption::encrypt( 'shhh-secret' );
	}

	// ── Signature verification ───────────────────────────────────────────

	private function sign( string $body, int $ts, string $secret = 'shhh-secret' ): string {
		return 'v0=' . hash_hmac( 'sha256', "v0:{$ts}:{$body}", $secret );
	}

	public function test_valid_signature_passes(): void {
		$this->store_credentials();
		$now  = 1700000000;
		$body = '{"type":"event_callback"}';

		$this->assertTrue(
			ATTENDANT_Slack::verify_signature( (string) $now, $this->sign( $body, $now ), $body, $now )
		);
	}

	public function test_wrong_secret_fails(): void {
		$this->store_credentials();
		$now  = 1700000000;
		$body = '{}';

		$this->assertFalse(
			ATTENDANT_Slack::verify_signature( (string) $now, $this->sign( $body, $now, 'other' ), $body, $now )
		);
	}

	public function test_stale_timestamp_fails_replay(): void {
		$this->store_credentials();
		$then = 1700000000;
		$body = '{}';

		// Signed correctly but 10 minutes old — replay window is 5 minutes.
		$this->assertFalse(
			ATTENDANT_Slack::verify_signature( (string) $then, $this->sign( $body, $then ), $body, $then + 600 )
		);
	}

	public function test_no_secret_stored_rejects_everything(): void {
		$now  = 1700000000;
		$body = '{}';

		$this->assertFalse(
			ATTENDANT_Slack::verify_signature( (string) $now, $this->sign( $body, $now ), $body, $now )
		);
	}

	// ── Handoff lifecycle ────────────────────────────────────────────────

	public function test_start_handoff_maps_session_both_ways_and_issues_secret(): void {
		$this->store_credentials();

		$secret = ATTENDANT_Slack::start_handoff(
			'sess-1',
			array(
				array( 'role' => 'user', 'text' => 'I need help' ),
				array( 'role' => 'bot', 'text' => 'Let me get a human' ),
			)
		);

		$this->assertNotSame( '', $secret );
		$this->assertSame( '111.222', ATTENDANT_Slack::thread_for_session( 'sess-1' ) );
		$this->assertSame( 'sess-1', ATTENDANT_Slack::session_for_thread( '111.222' ) );

		// The issued secret owns the session; a wrong one does not.
		$this->assertTrue( ATTENDANT_Slack::verify_session_secret( 'sess-1', $secret ) );
		$this->assertFalse( ATTENDANT_Slack::verify_session_secret( 'sess-1', 'wrong' ) );
		$this->assertFalse( ATTENDANT_Slack::verify_session_secret( 'other-sess', $secret ) );

		// The parent message carries the transcript.
		$sent = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertStringContainsString( 'I need help', $sent['text'] );
		$this->assertSame( 'C0TESTCHAN', $sent['channel'] );
	}

	public function test_secret_is_a_bearer_credential_not_the_session_id(): void {
		$this->store_credentials();
		ATTENDANT_Slack::start_handoff( 'sess-1', array() );

		// Knowing the session id but not the secret grants nothing.
		$this->assertFalse( ATTENDANT_Slack::verify_session_secret( 'sess-1', '' ) );
		$this->assertFalse( ATTENDANT_Slack::verify_session_secret( 'sess-1', 'sess-1' ) );

		// end_handoff revokes the secret.
		$secret = ATTENDANT_Slack::issue_session_secret( 'sess-1' );
		$this->assertTrue( ATTENDANT_Slack::verify_session_secret( 'sess-1', $secret ) );
		ATTENDANT_Slack::end_handoff( 'sess-1' );
		$this->assertFalse( ATTENDANT_Slack::verify_session_secret( 'sess-1', $secret ) );
	}

	public function test_visitor_message_goes_into_the_thread(): void {
		$this->store_credentials();
		ATTENDANT_Slack::start_handoff( 'sess-1', array() );

		ATTENDANT_Slack::forward_visitor_message( 'sess-1', 'hello there' );

		$sent = json_decode( end( $this->posts )['args']['body'], true );
		$this->assertSame( '111.222', $sent['thread_ts'] );
		$this->assertStringContainsString( 'hello there', $sent['text'] );
	}

	public function test_end_handoff_clears_both_mappings(): void {
		$this->store_credentials();
		ATTENDANT_Slack::start_handoff( 'sess-1', array() );

		ATTENDANT_Slack::end_handoff( 'sess-1' );

		$this->assertSame( '', ATTENDANT_Slack::thread_for_session( 'sess-1' ) );
		$this->assertSame( '', ATTENDANT_Slack::session_for_thread( '111.222' ) );
	}

	public function test_handoff_fails_closed_when_slack_rejects(): void {
		$this->store_credentials();
		$this->api_response = array( 'ok' => false, 'error' => 'channel_not_found' );

		$this->assertSame( '', ATTENDANT_Slack::start_handoff( 'sess-1', array() ) );
		$this->assertSame( '', ATTENDANT_Slack::thread_for_session( 'sess-1' ) );
	}

	// ── Agent message queue ──────────────────────────────────────────────

	public function test_queue_and_drain_agent_messages(): void {
		ATTENDANT_Slack::queue_agent_message( 'sess-9', 'first' );
		ATTENDANT_Slack::queue_agent_message( 'sess-9', 'second' );

		$drained = ATTENDANT_Slack::drain_agent_messages( 'sess-9' );

		$this->assertCount( 2, $drained );
		$this->assertSame( 'first', $drained[0]['text'] );
		$this->assertSame( 'second', $drained[1]['text'] );

		// Drained means gone.
		$this->assertSame( array(), ATTENDANT_Slack::drain_agent_messages( 'sess-9' ) );
	}

	// ── Text hygiene ─────────────────────────────────────────────────────

	public function test_visitor_text_cannot_ping_slack(): void {
		$cleaned = ATTENDANT_Slack::clean_for_slack( 'hi <!channel> and <@U123>' );
		$this->assertStringNotContainsString( '<!channel>', $cleaned );
		$this->assertStringNotContainsString( '<@U123>', $cleaned );
	}

	public function test_slack_text_is_flattened_for_the_widget(): void {
		$this->assertSame(
			'see our pricing page',
			ATTENDANT_Slack::clean_from_slack( 'see <https://x.test/pricing|our pricing page>' )
		);
		$this->assertSame(
			'hello',
			ATTENDANT_Slack::clean_from_slack( '<@U0AGENT> hello' )
		);
	}

	// ── Manifest ─────────────────────────────────────────────────────────

	public function test_manifest_prefills_events_url_and_scopes(): void {
		$manifest = ATTENDANT_Slack::manifest();

		$this->assertSame(
			'https://example.test/wp-json/attendant/v1/slack/events',
			$manifest['settings']['event_subscriptions']['request_url']
		);
		$this->assertContains( 'chat:write', $manifest['oauth_config']['scopes']['bot'] );
		$this->assertContains( 'groups:history', $manifest['oauth_config']['scopes']['bot'] );
		$this->assertContains( 'message.groups', $manifest['settings']['event_subscriptions']['bot_events'] );
	}

	public function test_normalize_channel_name(): void {
		$this->assertSame( 'website-chat', ATTENDANT_Slack::normalize_channel_name( 'Website Chat' ) );
		$this->assertSame( 'sales-2026', ATTENDANT_Slack::normalize_channel_name( '  Sales 2026!!  ' ) );
		$this->assertSame( 'a-b', ATTENDANT_Slack::normalize_channel_name( '--a  b--' ) );
		$this->assertSame( '', ATTENDANT_Slack::normalize_channel_name( '@@@' ) );
	}

	public function test_create_channel_returns_id_and_marks_bot_member(): void {
		$this->store_credentials();
		$this->api_response = array( 'ok' => true, 'channel' => array( 'id' => 'C0NEW', 'name' => 'website-chat' ) );

		$r = ATTENDANT_Slack::create_channel( 'Website Chat', true );

		$this->assertTrue( $r['ok'] );
		$this->assertSame( 'C0NEW', $r['id'] );
		$sent = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertSame( 'website-chat', $sent['name'] );
		$this->assertTrue( $sent['is_private'] );
	}

	public function test_create_channel_reports_name_taken(): void {
		$this->store_credentials();
		$this->api_response = array( 'ok' => false, 'error' => 'name_taken' );

		$r = ATTENDANT_Slack::create_channel( 'general', false );

		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'name_taken', $r['error'] );
		$this->assertStringContainsString( 'already exists', ATTENDANT_Slack::friendly_error( 'name_taken' )['message'] );
	}

	public function test_invite_by_emails_splits_found_and_missing(): void {
		$this->store_credentials();
		// lookupByEmail then conversations.invite, per email; alternate found/not.
		$calls = 0;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$calls ) {
				$this->posts[] = array( 'url' => $url, 'args' => $args );
				++$calls;
				if ( false !== strpos( $url, 'users.lookupByEmail' ) ) {
					// First email resolves, second does not.
					$body = json_decode( $args['body'], true );
					if ( 'known@example.com' === ( $body['email'] ?? '' ) ) {
						return array( 'body' => json_encode( array( 'ok' => true, 'user' => array( 'id' => 'U1' ) ) ) );
					}
					return array( 'body' => json_encode( array( 'ok' => false, 'error' => 'users_not_found' ) ) );
				}
				return array( 'body' => json_encode( array( 'ok' => true ) ) );
			}
		);

		$r = ATTENDANT_Slack::invite_by_emails( 'C0NEW', array( 'known@example.com', 'ghost@example.com' ) );

		$this->assertSame( array( 'known@example.com' ), $r['invited'] );
		$this->assertSame( array( 'ghost@example.com' ), $r['failed'] );
	}

	public function test_friendly_error_maps_common_codes(): void {
		// Bad token → points at the apps page.
		$bad = ATTENDANT_Slack::friendly_error( 'invalid_auth' );
		$this->assertStringContainsString( 'Bot Token', $bad['message'] );
		$this->assertSame( 'https://api.slack.com/apps', $bad['link'] );

		// Missing scope → recreate-app link (manifest url).
		$scope = ATTENDANT_Slack::friendly_error( 'missing_scope' );
		$this->assertStringContainsString( 'permission', $scope['message'] );
		$this->assertStringContainsString( 'new_app=1', $scope['link'] );

		// Not invited → /invite instruction, no link needed.
		$invite = ATTENDANT_Slack::friendly_error( 'not_in_channel' );
		$this->assertStringContainsString( '/invite', $invite['message'] );
		$this->assertArrayNotHasKey( 'link', $invite );

		// Unknown code → still human, echoes the code, has a fallback link.
		$unknown = ATTENDANT_Slack::friendly_error( 'some_new_code' );
		$this->assertStringContainsString( 'some_new_code', $unknown['message'] );
		$this->assertArrayHasKey( 'link', $unknown );
	}

	public function test_post_message_records_error_for_diagnostics(): void {
		$this->store_credentials();
		$this->api_response = array( 'ok' => false, 'error' => 'not_in_channel' );

		$ts = ATTENDANT_Slack::post_message( 'hello' );

		$this->assertSame( '', $ts );
		$this->assertSame( 'not_in_channel', ATTENDANT_Slack::last_post_error() );
	}

	public function test_debug_log_roundtrips(): void {
		ATTENDANT_Slack::log_debug( 'forwarded_to_visitor', array( 'channel' => 'C0ABC' ) );
		$dbg = ATTENDANT_Slack::get_debug();

		$this->assertSame( 'forwarded_to_visitor', $dbg['outcome'] );
		$this->assertSame( 'C0ABC', $dbg['channel'] );
		$this->assertArrayHasKey( 'time', $dbg );
	}

	public function test_channel_list_paginates_and_flags_private(): void {
		// First page returns a cursor; second page finishes.
		$page = 0;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$page ) {
				$this->posts[] = array( 'url' => $url, 'args' => $args );
				++$page;
				if ( 1 === $page ) {
					return array(
						'body' => json_encode(
							array(
								'ok'                => true,
								'channels'          => array( array( 'id' => 'C1', 'name' => 'general', 'is_private' => false ) ),
								'response_metadata' => array( 'next_cursor' => 'CURSOR2' ),
							)
						),
					);
				}
				return array(
					'body' => json_encode(
						array(
							'ok'       => true,
							'channels' => array( array( 'id' => 'G2', 'name' => 'secret-room', 'is_private' => true ) ),
						)
					),
				);
			}
		);
		$this->store_credentials();

		$channels = ATTENDANT_Slack::list_channels();

		$this->assertCount( 2, $channels );
		$this->assertSame( 'G2', $channels[1]['id'] );
		$this->assertTrue( $channels[1]['private'] );
		// Second request carried the cursor.
		$second = json_decode( $this->posts[1]['args']['body'], true );
		$this->assertSame( 'CURSOR2', $second['cursor'] );
	}

	public function test_is_configured_requires_everything(): void {
		$this->assertFalse( ATTENDANT_Slack::is_configured() );

		$this->store_credentials();
		$this->assertTrue( ATTENDANT_Slack::is_configured() );

		Attendant_Plugin::$test_settings['slack_enabled'] = false;
		$this->assertFalse( ATTENDANT_Slack::is_configured() );
	}
}
