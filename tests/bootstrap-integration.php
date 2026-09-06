<?php
/**
 * Bootstrap for Pro's integration suite.
 *
 * Pro's integration tests need three things that are in three different places:
 * a real WordPress (the wp-env container), the free plugin (a separate
 * repository, loaded the way WordPress loads it), and Pro's own classes (this
 * repository's autoloader). Before the split all three were one checkout and
 * this file did not need to exist.
 *
 * They run from the free plugin's wp-env, with this directory mapped as a
 * second plugin:
 *
 *     wp-env run tests-cli --env-cwd=wp-content/plugins/debloater \
 *         php tools/phpunit-9.phar -c ../debloater-pro/phpunit-wp.xml.dist
 *
 * The mapping is in the free plugin's `.wp-env.override.json`, which is
 * untracked. It is there rather than in its `.wp-env.json` on purpose: that
 * repository is public and must not require a private sibling to start. See
 * README.md.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

$debloater_pro_dir = dirname( __DIR__ );

/**
 * Where the free plugin is, inside this container or on this machine.
 *
 * Named by `DEBLOATER_FREE_PATH`, or beside this checkout, which is the layout
 * README.md describes and the one wp-env's mapping produces.
 */
$debloater_free_dir = getenv( 'DEBLOATER_FREE_PATH' );

if ( ! is_string( $debloater_free_dir ) || '' === $debloater_free_dir ) {
	$debloater_free_dir = dirname( $debloater_pro_dir ) . '/debloater';
}

if ( ! is_readable( $debloater_free_dir . '/debloater.php' ) ) {
	fwrite(
		STDERR,
		"The free plugin was not found at {$debloater_free_dir}.\n\n"
		. "Pro's integration tests assert what Pro does to a site running Debloater,\n"
		. "so without it there is nothing to assert against. Check out scornik/debloater\n"
		. "beside this repository, or set DEBLOATER_FREE_PATH.\n\n"
	);
	exit( 1 );
}

// Pro's own classes. Loaded before the free plugin's bootstrap runs WordPress,
// because a test naming a Pro class during collection would otherwise not find
// it.
require_once $debloater_pro_dir . '/vendor/autoload.php';

/*
 * The free plugin's bootstrap, verbatim rather than reimplemented.
 *
 * It finds the WordPress test suite, loads the plugin at `muplugins_loaded` the
 * way WordPress does, defines DEBLOATER_TESTS_ROOT and requires
 * IntegrationTestCase — which Pro's tests extend. Copying any of that here
 * would be a second copy to keep in step with the first, and the first is the
 * one that has to be right.
 *
 * It reads `dirname( __DIR__ )` to locate the plugin, so it must be required
 * from its own tree. That is exactly where this points.
 */
require_once $debloater_free_dir . '/tests/bootstrap-integration.php';
