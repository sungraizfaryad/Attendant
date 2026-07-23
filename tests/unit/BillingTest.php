<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-billing.php';

/**
 * Spend is tracked per provider: switching providers starts a fresh ledger
 * and the budget kill-switch only sees the active provider's numbers.
 */
final class BillingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Attendant_Plugin::$test_settings = array();
		$GLOBALS['__attendant_opt']      = array();
		Functions\when( 'get_option' )->alias(
			static function ( $k, $d = false ) {
				if ( 'attendant_settings' === $k ) {
					return Attendant_Plugin::$test_settings;
				}
				return $GLOBALS['__attendant_opt'][ $k ] ?? $d;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $k, $v ) {
				$GLOBALS['__attendant_opt'][ $k ] = $v;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Attendant_Plugin::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_over_budget_logic(): void {
		$this->assertFalse( ATTENDANT_Billing::over_budget( 5.0, 10.0 ) );
		$this->assertTrue( ATTENDANT_Billing::over_budget( 10.0, 10.0 ) );  // at the cap = reached
		$this->assertTrue( ATTENDANT_Billing::over_budget( 11.0, 10.0 ) );
		$this->assertFalse( ATTENDANT_Billing::over_budget( 999.0, 0.0 ) ); // 0 budget = unlimited
	}

	public function test_record_accumulates_per_day_and_today_spend_reads_it(): void {
		ATTENDANT_Billing::record( 'openai', 0.5, '2026-06-07', '2026-06' );
		ATTENDANT_Billing::record( 'openai', 0.25, '2026-06-07', '2026-06' );
		ATTENDANT_Billing::record( 'openai', 1.0, '2026-06-08', '2026-06' );

		$this->assertSame( 0.75, ATTENDANT_Billing::today_spend( 'openai', '2026-06-07' ) );
		$this->assertSame( 1.0, ATTENDANT_Billing::today_spend( 'openai', '2026-06-08' ) );
		$this->assertSame( 0.0, ATTENDANT_Billing::today_spend( 'openai', '2026-06-09' ) );

		$this->assertSame( 1.75, ATTENDANT_Billing::month_spend( 'openai', '2026-06' ) );
	}

	public function test_providers_keep_separate_ledgers(): void {
		ATTENDANT_Billing::record( 'openai', 3.0, '2026-07-01', '2026-07' );
		ATTENDANT_Billing::record( 'google', 0.002, '2026-07-01', '2026-07' );

		$this->assertSame( 3.0, ATTENDANT_Billing::month_spend( 'openai', '2026-07' ) );
		$this->assertSame( 0.002, ATTENDANT_Billing::month_spend( 'google', '2026-07' ) );
		$this->assertSame( array( '2026-07' => 0.002 ), ATTENDANT_Billing::monthly_usage( 'google' ) );
		// A provider with no history reads as empty, not as the other's spend.
		$this->assertSame( 0.0, ATTENDANT_Billing::today_spend( 'google', '2026-07-02' ) );
	}

	public function test_legacy_flat_map_reads_as_openai_history(): void {
		// Pre-2.1.0 shape: flat [ month => cost ] — all of it was OpenAI spend.
		$GLOBALS['__attendant_opt']['attendant_monthly_usage'] = array(
			'2026-06' => 1.0,
			'2026-07' => 3.0,
		);

		$this->assertSame( 3.0, ATTENDANT_Billing::month_spend( 'openai', '2026-07' ) );
		$this->assertSame( array(), ATTENDANT_Billing::monthly_usage( 'google' ) );
	}

	public function test_mixed_shape_map_keeps_both_sides(): void {
		// A stale PHP worker running old code can append a flat date key next
		// to already-nested buckets. Per-key normalization must keep both —
		// regardless of which shape happens to sit first in the array.
		$GLOBALS['__attendant_opt']['attendant_monthly_usage'] = array(
			'2024-05' => 12.3,
			'google'  => array( '2026-01' => 5.0 ),
		);
		$this->assertSame( 12.3, ATTENDANT_Billing::month_spend( 'openai', '2024-05' ) );
		$this->assertSame( 5.0, ATTENDANT_Billing::month_spend( 'google', '2026-01' ) );

		$GLOBALS['__attendant_opt']['attendant_monthly_usage'] = array(
			'google'  => array( '2026-01' => 5.0 ),
			'2024-05' => 12.3,
		);
		$this->assertSame( 12.3, ATTENDANT_Billing::month_spend( 'openai', '2024-05' ) );
		$this->assertSame( 5.0, ATTENDANT_Billing::month_spend( 'google', '2026-01' ) );
	}

	public function test_normalize_sums_flat_leaf_into_existing_bucket_month(): void {
		// Flat leaf and nested openai bucket for the SAME month are both real
		// spend increments — they add up instead of one shadowing the other.
		$normalized = ATTENDANT_Billing::normalize_map(
			array(
				'openai'  => array( '2026-07' => 1.0 ),
				'2026-07' => 0.5,
			)
		);
		$this->assertSame( 1.5, $normalized['openai']['2026-07'] );
	}

	public function test_normalize_drops_garbage(): void {
		$normalized = ATTENDANT_Billing::normalize_map(
			array(
				'2026-07'  => 'not-a-number',
				'weird'    => 'scalar-under-non-date-key',
				'google'   => array( 'not-a-date' => 3.0, '2026-07' => 2.0 ),
			)
		);
		$this->assertSame( array( 'google' => array( '2026-07' => 2.0 ) ), $normalized );
	}

	public function test_record_ignores_zero_or_negative(): void {
		ATTENDANT_Billing::record( 'openai', 0.0, '2026-06-07', '2026-06' );
		ATTENDANT_Billing::record( 'openai', -2.0, '2026-06-07', '2026-06' );
		$this->assertSame( 0.0, ATTENDANT_Billing::today_spend( 'openai', '2026-06-07' ) );
	}

	public function test_monthly_budget_watches_active_provider_only(): void {
		// Old OpenAI spend is over the cap, but Gemini is active with $0 —
		// the kill-switch must NOT fire on a provider no longer in use.
		Attendant_Plugin::$test_settings = array(
			'active_provider' => 'google',
			'monthly_budget'  => 1.0,
		);
		ATTENDANT_Billing::record( 'openai', 5.0, gmdate( 'Y-m-d' ), gmdate( 'Y-m' ) );

		$this->assertFalse( ATTENDANT_Billing::monthly_budget_reached() );

		ATTENDANT_Billing::record( 'google', 1.5, gmdate( 'Y-m-d' ), gmdate( 'Y-m' ) );
		$this->assertTrue( ATTENDANT_Billing::monthly_budget_reached() );
	}
}
