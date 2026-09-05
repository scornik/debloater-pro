<?php
/**
 * Constants static analysis needs to know exist.
 *
 * WordPress defines some of these at runtime and `wp-config.php` defines the
 * rest, so nothing here is a value Pro relies on — the values are placeholders
 * and `phpstan.neon` lists the ones whose value must stay unknown, or every
 * guard against them would be reported as dead code.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

// The free plugin's version constant, defined by its entry point. Pro reads it
// when reporting what it is running beside.
defined( 'DEBLOATER_VERSION' ) || define( 'DEBLOATER_VERSION', '0.1.1' );

// Set in wp-config.php while integrating the licensing SDK, and never in a
// distributed package.
defined( 'WP_FS__DEV_MODE' ) || define( 'WP_FS__DEV_MODE', false );

// Set by a site that wants Pro's features unlocked without a licence, for
// development. The provider treats it as a fixture, not as an entitlement.
defined( 'DEBLOATER_PRO_FIXTURE' ) || define( 'DEBLOATER_PRO_FIXTURE', false );

// Durations, as in tests/bootstrap-pro.php. WordPress defines these in
// wp-includes/default-constants.php.
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
