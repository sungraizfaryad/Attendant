<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-encryption.php';
require_once ATTENDANT_PLUGIN_DIR . 'includes/integrations/class-attendant-slack.php';
require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-conversation-handler.php';

/**
 * When the widget offers a live person.
 *
 * The button must stay out of the way while the assistant is doing its job,
 * and appear the moment it cannot help (or the chat drags on).
 */
final class OfferHumanTest extends TestCase {

	private array $transients = array();
	private array $options    = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();
		$this->options    = array();

		Attendant_Plugin::$test_settings = array(
			'slack_enabled' => true,
			'slack_channel' => 'C0TEST',
		);

		Functions\when( 'wp_salt' )->alias( static fn( $s = 'auth' ) => "salt-{$s}" );
		Functions\when( '__' )->returnArg();
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

		// Slack fully configured so the offer is even possible.
		$this->options['attendant_slack_bot_token']      = ATTENDANT_Encryption::encrypt( 'xoxb-x' );
		$this->options['attendant_slack_signing_secret'] = ATTENDANT_Encryption::encrypt( 'sec' );
	}

	protected function tearDown(): void {
		Attendant_Plugin::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Call the private decision method. */
	private function offer( string $session, array $history, array $sources, string $reply ): bool {
		$m = new ReflectionMethod( 'ATTENDANT_Conversation_Handler', 'should_offer_human' );
		$m->setAccessible( true );

		return (bool) $m->invoke( null, $session, $history, $sources, $reply );
	}

	private function turns( int $n ): array {
		$h = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$h[] = array( 'role' => 'user', 'content' => 'q' );
			$h[] = array( 'role' => 'assistant', 'content' => 'a' );
		}
		return $h;
	}

	public function test_no_offer_when_answer_is_sourced(): void {
		$this->assertFalse(
			$this->offer( 's1', $this->turns( 1 ), array( 12, 34 ), 'Here are 2 posts about pricing.' )
		);
	}

	public function test_offers_when_assistant_admits_it_cannot_find_anything(): void {
		$this->assertTrue(
			$this->offer( 's1', $this->turns( 1 ), array(), "I couldn't find anything matching your search." )
		);
	}

	public function test_offers_when_assistant_fails_to_generate(): void {
		$this->assertTrue(
			$this->offer( 's1', $this->turns( 1 ), array(), "I'm sorry, I couldn't generate a response. Please try again." )
		);
	}

	public function test_offers_after_a_long_back_and_forth_even_while_answering(): void {
		$this->assertTrue(
			$this->offer( 's1', $this->turns( 6 ), array( 5 ), 'Here is another answer.' )
		);
	}

	public function test_never_offers_when_slack_is_not_set_up(): void {
		Attendant_Plugin::$test_settings['slack_enabled'] = false;
		$this->assertFalse(
			$this->offer( 's1', $this->turns( 1 ), array(), "I couldn't find anything." )
		);
	}

	public function test_never_offers_while_a_human_is_already_on_the_chat(): void {
		$this->transients[ 'attendant_slack_thread_' . md5( 's1' ) ] = '111.222';
		$this->assertFalse(
			$this->offer( 's1', $this->turns( 1 ), array(), "I couldn't find anything." )
		);
	}

	public function test_a_good_answer_resets_the_miss_streak(): void {
		// One miss recorded...
		$this->offer( 's1', $this->turns( 1 ), array(), "I couldn't find anything." );
		$this->assertSame( 1, $this->transients[ 'attendant_miss_' . md5( 's1' ) ] );

		// ...then a sourced answer clears it.
		$this->offer( 's1', $this->turns( 2 ), array( 7 ), 'Found it: the pricing page.' );
		$this->assertArrayNotHasKey( 'attendant_miss_' . md5( 's1' ), $this->transients );
	}
}
