<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-billing.php';

final class BillingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['__attendant_opt'] = array();
		Functions\when( 'get_option' )->alias( static fn( $k, $d = false ) => $GLOBALS['__attendant_opt'][ $k ] ?? $d );
		Functions\when( 'update_option' )->alias(
			static function ( $k, $v ) {
				$GLOBALS['__attendant_opt'][ $k ] = $v;
				return true;
			}
		);
	}

	protected function tearDown(): void {
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
		ATTENDANT_Billing::record( 0.5, '2026-06-07', '2026-06' );
		ATTENDANT_Billing::record( 0.25, '2026-06-07', '2026-06' );
		ATTENDANT_Billing::record( 1.0, '2026-06-08', '2026-06' );

		$this->assertSame( 0.75, ATTENDANT_Billing::today_spend( '2026-06-07' ) );
		$this->assertSame( 1.0, ATTENDANT_Billing::today_spend( '2026-06-08' ) );
		$this->assertSame( 0.0, ATTENDANT_Billing::today_spend( '2026-06-09' ) );

		// Monthly total is also accumulated for the analytics page.
		$monthly = $GLOBALS['__attendant_opt']['attendant_monthly_usage'];
		$this->assertSame( 1.75, $monthly['2026-06'] );
	}

	public function test_record_ignores_zero_or_negative(): void {
		ATTENDANT_Billing::record( 0.0, '2026-06-07', '2026-06' );
		ATTENDANT_Billing::record( -2.0, '2026-06-07', '2026-06' );
		$this->assertSame( 0.0, ATTENDANT_Billing::today_spend( '2026-06-07' ) );
	}
}
