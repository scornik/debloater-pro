<?php
/**
 * Bootstrap for Pro's WordPress-free tests.
 *
 * These tests load Pro's classes and nothing else — no WordPress, no free
 * plugin, no database. That is the point of them: Pro extends Debloater through
 * hooks Debloater documents rather than by calling into its classes, so its own
 * units are testable with nothing installed, and a test that needs the whole
 * stack to check a value object is a test that will eventually be skipped.
 *
 * What they do need is the handful of constants WordPress defines globally and
 * that ordinary code uses without thinking about where they came from. When
 * this file lived in the plugin's repository the unit bootstrap there supplied
 * them; here they are supplied for the same reason and no other.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Durations. WordPress defines these in wp-includes/default-constants.php.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

/*
 * A single option, in memory.
 *
 * `CachedEntitlementProvider` remembers the last entitlement in an option so
 * that a licensing platform having a bad day does not switch off features
 * somebody has paid for. Testing that decision needs somewhere to put the
 * value; it does not need WordPress.
 *
 * Deliberately the smallest thing that is true: get, update, delete, one
 * autoload flag ignored. Anything more and this stops being a stand-in and
 * starts being a second implementation of the options API that can disagree
 * with the real one. The behaviour under test is the cache's, not WordPress's,
 * and the real options layer is exercised by the free plugin's integration
 * suite.
 */
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['debloater_pro_test_options'] = array();

	/**
	 * @param string $name    Option name.
	 * @param mixed  $fallback Value when unset.
	 * @return mixed
	 */
	function get_option( string $name, $fallback = false ) {
		return $GLOBALS['debloater_pro_test_options'][ $name ] ?? $fallback;
	}

	/**
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( string $name, $value ): bool {
		$GLOBALS['debloater_pro_test_options'][ $name ] = $value;

		return true;
	}

	/**
	 * @param string $name Option name.
	 * @return bool
	 */
	function delete_option( string $name ): bool {
		unset( $GLOBALS['debloater_pro_test_options'][ $name ] );

		return true;
	}
}
