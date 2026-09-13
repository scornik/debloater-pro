<?php
/**
 * Plugin Name:       Hakeemify Debloater Pro
 * Plugin URI:        https://github.com/scornik/debloater
 * Description:       Workflow for people who manage several sites: scans on a schedule, drift detection between them, a printable before/after report, and applying a saved profile in one step. Adds nothing to what Debloater does to a site.
 * Version:           0.3.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  hakeemify-debloater
 * Author:            Hakeemify
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       debloater-pro
 * Domain Path:       /languages
 *
 * A separate plugin, on purpose. Pro extends Debloater only through hooks
 * Debloater documents (docs/HOOKS.md), which means the free plugin can be read,
 * audited and released without reference to this one — and this one cannot
 * quietly become load-bearing for anything the free plugin promises.
 *
 * Everything to do with safety lives in the free plugin: recovery points,
 * verification, automatic rollback, risk rules, the refusal to delete without a
 * backup. None of it is here, and a test asserts that activating this adds no
 * tweaks and no safety features (BUILD-SPEC §13 rule 15, §17 Phase 19).
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

const DEBLOATER_PRO_VERSION = '0.1.1';
const DEBLOATER_PRO_FILE    = __FILE__;

/**
 * The Freemius product this build belongs to.
 *
 * `config/freemius.php` when a developer has one, `config/freemius.php.dist`
 * otherwise. The `.dist` ships, so a distributed build needs no copying; the
 * override is gitignored and excluded from packages, so a local experiment
 * cannot become a release.
 *
 * Neither holds a secret. The id and public key are what identify the product,
 * not what authorises anything, and both are handed to every browser that loads
 * the licensing UI. The secret key lives in the test site's `wp-config.php` and
 * nowhere else (README.md).
 *
 * @return array{id: string, public_key: string}|null Null when neither file is readable.
 */
function debloater_pro_freemius_config(): ?array {
	// The paths are written out at each `require` rather than held in a
	// variable first. Both are constants and neither is reachable from input,
	// but Pro's architecture test refuses any include whose path is a variable —
	// and the right answer to a rule like that is to satisfy it by
	// construction, not to add an exemption for the case that happens to be
	// safe today. A literal include path is checkable by reading it.
	$config = null;

	if ( is_file( __DIR__ . '/config/freemius.php' ) ) {
		$config = require __DIR__ . '/config/freemius.php';
	} elseif ( is_file( __DIR__ . '/config/freemius.php.dist' ) ) {
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingNonPHPFile.IncludingNonPHPFile -- The .dist extension is the convention for a committed template; the file itself is PHP that returns an array, so requiring it is right and file_get_contents() would hand back source to parse by hand.
		$config = require __DIR__ . '/config/freemius.php.dist';
	}

	if ( is_array( $config ) && isset( $config['id'], $config['public_key'] ) ) {
		return array(
			'id'         => (string) $config['id'],
			'public_key' => (string) $config['public_key'],
		);
	}

	return null;
}

if ( ! function_exists( 'dp_fs' ) ) {
	/**
	 * The Freemius instance, or null when the SDK is not installed.
	 *
	 * Initialised here, at the top of the entry point, because the SDK has to
	 * be running before `plugins_loaded` — it hooks activation, deactivation
	 * and the admin menu, and a licence check that starts later has already
	 * missed them.
	 *
	 * **It may return null, and every caller must cope.** The SDK is a
	 * Composer dependency, so it is absent in a checkout nobody has run
	 * `composer install` in, absent in the unit suite, and absent in CI. Those
	 * are not broken states to be asserted away: a plugin that fatals because a
	 * licensing platform is missing has made licensing load-bearing for the
	 * code, which is the thing BUILD-SPEC §13 rule 13 rules out.
	 *
	 * @return \Freemius|null
	 */
	function dp_fs() {
		global $dp_fs;

		if ( isset( $dp_fs ) ) {
			return $dp_fs;
		}

		$config = debloater_pro_freemius_config();

		if ( ! is_file( __DIR__ . '/vendor/freemius/wordpress-sdk/start.php' ) || null === $config ) {
			$dp_fs = null;

			return $dp_fs;
		}

		require_once __DIR__ . '/vendor/freemius/wordpress-sdk/start.php';

		if ( ! function_exists( 'fs_dynamic_init' ) ) {
			$dp_fs = null;

			return $dp_fs;
		}

		$dp_fs = fs_dynamic_init(
			array(
				'id'               => $config['id'],
				'slug'             => 'debloater-pro',
				'premium_slug'     => 'debloater-pro',
				'type'             => 'plugin',
				'public_key'       => $config['public_key'],
				'is_premium'       => true,
				'is_premium_only'  => true,
				'has_addons'       => false,
				'has_paid_plans'   => true,

				// Not distributed through wordpress.org, and saying so is what
				// stops the SDK applying that repository's rules to a plugin
				// that is not in it.
				'is_org_compliant' => false,
				'menu'             => array(
					'slug'    => 'debloater-pro',
					'support' => false,

					// Under Debloater's own menu, so licence and features are
					// in one place rather than two.
					'parent'  => array(
						'slug' => 'debloater',
					),
				),
			)
		);

		return $dp_fs;
	}

	dp_fs();

	if ( null !== dp_fs() ) {
		/*
		 * The licence notices, in this product's words.
		 *
		 * Only the notices that describe what a licence buys. The opt-in screen
		 * and every word of its data-collection copy are left exactly as the
		 * SDK wrote them: that text is a disclosure of what gets sent to a
		 * third party, and rewording somebody's privacy disclosure to suit your
		 * own tone is not a thing to do.
		 *
		 * The placeholders are kept in the same number and order. These strings
		 * reach `sprintf`, so an override that drops a `%s` does not read
		 * differently — it throws.
		 */
		dp_fs()->override_i18n(
			array(
				// " %s to access version %s security & feature updates, and support."
				'x-for-updates-and-support'    => ' %s to access version %s feature updates, version change reports and priority support.',

				// "You can still enjoy all %s features but you will not have
				//  access to %s security & feature updates, nor support."
				'after-downgrade-non-blocking' => 'You can still use every %s feature you have already set up, but %s feature updates, version change reports and priority support stop.',

				// "Once your license expires you can still use the Free version
				//  but you will NOT have access to the %s features."
				'after-downgrade-blocking'     => 'When the licence ends, Debloater keeps working and every change you have applied stays applied. What stops is the %s features.',
			)
		);
	}

	/**
	 * Fires once the licensing SDK has been initialised, or established absent.
	 *
	 * `dp_fs()` may be null when it fires. That is the point of firing either
	 * way: anything waiting on this is waiting to know, not waiting for a yes.
	 */
	do_action( 'dp_fs_loaded' );
}

/**
 * Pro's own autoloader.
 *
 * Its own rather than the free plugin's, and hand-written rather than
 * Composer's, for the same reason the free plugin ships zero runtime
 * dependencies: a plugin whose job is to remove work from a site should not
 * arrive carrying any.
 *
 * @param string $class_name Fully-qualified class name.
 * @return void
 */
function debloater_pro_autoload( string $class_name ): void {
	$prefix = 'Debloater\\Pro\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

	// realpath before is_file, so a class name that tried to walk out of src/
	// resolves to something outside it and is refused rather than required.
	$real = realpath( $path );
	$root = realpath( __DIR__ . '/src' );

	if ( false === $real || false === $root || 0 !== strpos( $real, $root ) ) {
		return;
	}

	require_once $real;
}

spl_autoload_register( 'debloater_pro_autoload' );

/**
 * Start Pro, once the free plugin says it is ready.
 *
 * `debloater_loaded` is the documented entry point and the only one used. If
 * the free plugin is not installed, not activated, or too old to fire it,
 * nothing here runs and nothing here errors — which is what "Requires Plugins"
 * asks for politely and this arranges for regardless.
 *
 * @param \Debloater\Plugin $plugin The booted free plugin.
 * @return void
 */
function debloater_pro_boot( $plugin ): void {
	if ( ! $plugin instanceof \Debloater\Plugin ) {
		return;
	}

	$GLOBALS['debloater_pro'] = new \Debloater\Pro\Pro( $plugin );

	$GLOBALS['debloater_pro']->boot();
}

add_action( 'debloater_loaded', 'debloater_pro_boot' );

/**
 * Leave nothing running behind.
 *
 * A scheduled scan whose plugin has been deactivated is an event WordPress
 * keeps firing into an empty hook forever. Removing it on deactivation is the
 * least a plugin that schedules anything owes the site.
 *
 * @return void
 */
function debloater_pro_deactivate(): void {
	$scheduled = wp_next_scheduled( \Debloater\Pro\Features\ScheduledScans::HOOK );

	if ( false !== $scheduled ) {
		wp_unschedule_event( (int) $scheduled, \Debloater\Pro\Features\ScheduledScans::HOOK );
	}
}

register_deactivation_hook( __FILE__, 'debloater_pro_deactivate' );
