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
