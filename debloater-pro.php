<?php
/**
 * Plugin Name:       Debloater Pro
 * Plugin URI:        https://github.com/scornik/debloater
 * Description:       Workflow for people who manage several sites: scans on a schedule, drift detection between them, a printable before/after report, and applying a saved profile in one step. Adds nothing to what Debloater does to a site.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  debloater
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

const DEBLOATER_PRO_VERSION = '0.1.0';
const DEBLOATER_PRO_FILE    = __FILE__;

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
