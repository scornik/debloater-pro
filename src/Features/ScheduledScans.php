<?php
/**
 * Scans that happen without anybody asking.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Features;

use Debloater\Plugin;
use Debloater\Pro\Entitlement\EntitlementProvider;

/**
 * Runs a scan on a schedule so drift has something to be measured against.
 *
 * A scan reads. It writes a run row and nothing else — no runtime file, no
 * option a tweak depends on, no change to the site. That is what makes it safe
 * to do unattended, and it is the only thing in Pro that runs unattended.
 * Applying on a schedule is deliberately not offered: an unattended change to
 * somebody's site, with nobody watching the verification, is the one thing this
 * whole plugin is arranged to avoid.
 *
 * The entitlement is checked when the event fires, not only when it is
 * scheduled. A subscription that lapses between one week and the next stops the
 * scan at the next tick rather than continuing until somebody notices — and
 * because the check is at the top, a lapse can only ever mean *less* happens,
 * never something different.
 */
final class ScheduledScans {

	/**
	 * The cron hook.
	 */
	public const HOOK = 'debloater_pro_scheduled_scan';

	/**
	 * The feature key this needs.
	 */
	public const FEATURE = 'scheduled_scans';

	/**
	 * Where the chosen frequency is kept.
	 */
	private const OPTION = 'debloater_pro_scan_schedule';

	/**
	 * The free plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Entitlement.
	 *
	 * @var EntitlementProvider
	 */
	private EntitlementProvider $entitlement;

	/**
	 * Constructor.
	 *
	 * @param Plugin              $plugin      The free plugin.
	 * @param EntitlementProvider $entitlement Entitlement source.
	 */
	public function __construct( Plugin $plugin, EntitlementProvider $entitlement ) {
		$this->plugin      = $plugin;
		$this->entitlement = $entitlement;
	}

	/**
	 * Hook the event.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Make sure the event is scheduled, or is not.
	 *
	 * @return void
	 */
	public function sync(): void {
		$wanted    = $this->frequency();
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( '' === $wanted || ! $this->entitlement->entitlement()->allows( self::FEATURE ) ) {
			if ( false !== $scheduled ) {
				wp_unschedule_event( (int) $scheduled, self::HOOK );
			}

			return;
		}

		if ( false === $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $wanted, self::HOOK );
		}
	}

	/**
	 * Run the scan, if this site may.
	 *
	 * @return void
	 */
	public function run(): void {
		if ( ! $this->entitlement->entitlement()->allows( self::FEATURE ) ) {
			return;
		}

		try {
			// Without the wordpress.org lookup. A scheduled scan that phoned a
			// third party every week, unattended and unasked, is not something
			// somebody opted into when they opted into scanning on a schedule.
			$this->plugin->scan( false );
		} catch ( \Throwable $error ) {
			// A failed scheduled scan leaves no run and changes nothing. It
			// must not become a fatal on a cron request, which on many hosts is
			// a front-end request wearing a hat.
			unset( $error );
		}
	}

	/**
	 * The chosen frequency, or '' for off.
	 *
	 * @return string
	 */
	public function frequency(): string {
		$stored = get_option( self::OPTION, '' );

		return in_array( $stored, array( 'daily', 'weekly' ), true ) ? $stored : '';
	}

	/**
	 * Choose a frequency.
	 *
	 * @param string $frequency 'daily', 'weekly', or '' for off.
	 * @return void
	 */
	public function setFrequency( string $frequency ): void {
		update_option(
			self::OPTION,
			in_array( $frequency, array( 'daily', 'weekly' ), true ) ? $frequency : '',
			false
		);

		$this->sync();
	}

	/**
	 * Remove the event, for deactivation.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( false !== $scheduled ) {
			wp_unschedule_event( (int) $scheduled, self::HOOK );
		}
	}
}
