<?php
/**
 * Billing / spend tracking + kill-switch.
 *
 * Records per-day and per-month API spend and answers "has today's budget been
 * reached?" so the public chat endpoint can shut itself off before running up
 * the site owner's bill. Daily history is pruned to recent days to stay small.
 *
 * Spend is tracked PER PROVIDER: switching from OpenAI to Gemini starts a
 * fresh ledger, and switching back shows the old OpenAI history untouched.
 * The budget kill-switch only looks at the active provider's spend — old
 * charges from a provider you no longer use can't pause the widget.
 *
 * Option shape: [ 'openai' => [ 'YYYY-MM-DD' => float, ... ], 'google' => ... ]
 * Installs upgraded from the flat single-provider shape are migrated by the
 * activator; provider_map() also self-heals on read as a safety net.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ATTENDANT_Billing
 */
class ATTENDANT_Billing {

	/** wp_options key for the per-provider, per-day spend map. */
	private const DAILY_OPTION = 'attendant_daily_usage';

	/** wp_options key for the per-provider, per-month spend map. */
	private const MONTHLY_OPTION = 'attendant_monthly_usage';

	/** Days of daily history to keep (per provider). */
	private const KEEP_DAYS = 40;

	/**
	 * Is a spend amount at or over a budget? A budget of 0 means unlimited.
	 *
	 * @param float $spent  Amount spent.
	 * @param float $budget Budget ceiling (0 = unlimited).
	 * @return bool
	 */
	public static function over_budget( float $spent, float $budget ): bool {
		return $budget > 0.0 && $spent >= $budget;
	}

	/**
	 * Record an API cost against a provider's daily and monthly spend maps.
	 *
	 * @param string $provider Provider slug ('openai' / 'google').
	 * @param float  $cost     Cost in USD (ignored when <= 0).
	 * @param string $day      Day key (YYYY-MM-DD); defaults to today (UTC).
	 * @param string $month    Month key (YYYY-MM); defaults to this month (UTC).
	 */
	public static function record( string $provider, float $cost, string $day = '', string $month = '' ): void {
		if ( $cost <= 0.0 || '' === $provider ) {
			return;
		}

		$day   = '' !== $day ? $day : gmdate( 'Y-m-d' );
		$month = '' !== $month ? $month : gmdate( 'Y-m' );

		$daily = self::provider_map( self::DAILY_OPTION );

		$bucket         = isset( $daily[ $provider ] ) && is_array( $daily[ $provider ] ) ? $daily[ $provider ] : array();
		$bucket[ $day ] = round( (float) ( $bucket[ $day ] ?? 0.0 ) + $cost, 6 );

		// Prune old days so the option never grows without bound.
		if ( count( $bucket ) > self::KEEP_DAYS ) {
			ksort( $bucket );
			$bucket = array_slice( $bucket, -self::KEEP_DAYS, null, true );
		}
		$daily[ $provider ] = $bucket;
		update_option( self::DAILY_OPTION, $daily, false );

		$monthly                        = self::provider_map( self::MONTHLY_OPTION );
		$months                         = isset( $monthly[ $provider ] ) && is_array( $monthly[ $provider ] ) ? $monthly[ $provider ] : array();
		$months[ $month ]               = round( (float) ( $months[ $month ] ?? 0.0 ) + $cost, 6 );
		$monthly[ $provider ]           = $months;
		update_option( self::MONTHLY_OPTION, $monthly, false );
	}

	/**
	 * Spend recorded for a provider on a given day (defaults to today, UTC).
	 *
	 * @param string $provider Provider slug.
	 * @param string $day      Day key (YYYY-MM-DD).
	 * @return float
	 */
	public static function today_spend( string $provider, string $day = '' ): float {
		$day   = '' !== $day ? $day : gmdate( 'Y-m-d' );
		$daily = self::provider_map( self::DAILY_OPTION );
		return (float) ( $daily[ $provider ][ $day ] ?? 0.0 );
	}

	/**
	 * Spend recorded for a provider in a given month (defaults to this month, UTC).
	 *
	 * @param string $provider Provider slug.
	 * @param string $month    Month key (YYYY-MM).
	 * @return float
	 */
	public static function month_spend( string $provider, string $month = '' ): float {
		$month   = '' !== $month ? $month : gmdate( 'Y-m' );
		$monthly = self::provider_map( self::MONTHLY_OPTION );
		return (float) ( $monthly[ $provider ][ $month ] ?? 0.0 );
	}

	/**
	 * A provider's full monthly history for the analytics page.
	 *
	 * @param string $provider Provider slug.
	 * @return array<string, float> [ 'YYYY-MM' => cost ]
	 */
	public static function monthly_usage( string $provider ): array {
		$monthly = self::provider_map( self::MONTHLY_OPTION );
		$months  = $monthly[ $provider ] ?? array();
		return is_array( $months ) ? array_map( 'floatval', $months ) : array();
	}

	/**
	 * Has the active provider's spend reached the configured daily budget?
	 *
	 * @return bool True when the kill-switch should fire.
	 */
	public static function daily_budget_reached(): bool {
		$budget = (float) Attendant_Plugin::get_setting( 'daily_budget', 0 );
		return self::over_budget( self::today_spend( self::active_provider() ), $budget );
	}

	/**
	 * Has the active provider's spend reached the configured monthly budget?
	 *
	 * @return bool True when the kill-switch should fire.
	 */
	public static function monthly_budget_reached(): bool {
		$budget = (float) Attendant_Plugin::get_setting( 'monthly_budget', 0 );
		return self::over_budget( self::month_spend( self::active_provider() ), $budget );
	}

	/**
	 * Active provider slug, via the factory.
	 *
	 * @return string
	 */
	private static function active_provider(): string {
		require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/class-attendant-provider-factory.php';
		return ATTENDANT_Provider_Factory::active_provider();
	}

	/**
	 * Load a spend option, healing the legacy flat single-provider shape.
	 *
	 * Pre-2.1.0 installs stored [ 'YYYY-MM' => cost ] directly; everything
	 * recorded back then came from OpenAI, so a flat map belongs to it.
	 *
	 * @param string $option Option name.
	 * @return array<string, array<string, float>>
	 */
	private static function provider_map( string $option ): array {
		$raw = get_option( $option, array() );
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		$first = reset( $raw );
		if ( ! is_array( $first ) ) {
			return array( 'openai' => $raw );
		}

		return $raw;
	}
}
