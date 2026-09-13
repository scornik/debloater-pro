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
 *     wp-env run tests-cli --env-cwd=wp-content/plugins/hakeemify-debloater \
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
 * Named by `DEBLOATER_FREE_PATH`, or beside this checkout. "Beside" has two
 * names since the free plugin was renamed (its D-0071): a git checkout is still
 * called `debloater`, after the repository, while wp-env maps it into the
 * container as `hakeemify-debloater`, after the slug. Either is looked for;
 * what makes a directory the free plugin is its entry file, not its name.
 */
$debloater_free_dir = getenv( 'DEBLOATER_FREE_PATH' );

if ( ! is_string( $debloater_free_dir ) || '' === $debloater_free_dir ) {
	$debloater_free_dir = dirname( $debloater_pro_dir ) . '/hakeemify-debloater';

	if ( ! is_readable( $debloater_free_dir . '/hakeemify-debloater.php' ) ) {
		$debloater_free_dir = dirname( $debloater_pro_dir ) . '/debloater';
	}
}

if ( ! is_readable( $debloater_free_dir . '/hakeemify-debloater.php' ) ) {
	// Fails, and does not skip. Everything in this suite is a claim about what
	// Pro does to a site running Debloater; without Debloater there is nothing
	// to make the claim against, and a green tick earned by not looking is the
	// outcome this project treats as worse than a red one.
	fwrite(
		STDERR,
		"\nThe free plugin is not here.\n\n"
		. "Looked for hakeemify-debloater.php in:\n"
		. "  {$debloater_free_dir}\n\n"
		. "Pro's integration tests assert what Pro does to a site running Debloater,\n"
		. "so without it there is nothing to assert against. They fail rather than\n"
		. "skip, deliberately.\n\n"
		. "Inside wp-env this means the Pro checkout is not mapped into the container.\n"
		. "The mapping is not in the free plugin's .wp-env.json -- that repository is\n"
		. "public and has to start on a machine with no Pro beside it -- so it is a\n"
		. "template there instead:\n\n"
		. "  cp .wp-env.override.json.dist .wp-env.override.json   (in the free plugin)\n"
		. "  npm run env:start                                     (restart, to apply it)\n"
		. "  npm run test:integration:pro\n\n"
		. "Outside a container, check out scornik/debloater beside this repository or\n"
		. "set DEBLOATER_FREE_PATH to wherever it is.\n\n"
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
