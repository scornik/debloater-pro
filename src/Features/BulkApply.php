<?php
/**
 * Applying a saved profile to a site in one step.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Features;

use Debloater\Contracts\ApplyResult;
use Debloater\Plugin;
use Debloater\Pro\Entitlement\EntitlementProvider;

/**
 * Saves a profile choice and applies it, through the same path as everything
 * else.
 *
 * What this removes is the clicking, not the safety. It calls
 * `Plugin::preview()` and `Plugin::apply()` — the same two methods the
 * dashboard and WP-CLI call — so the recovery point, the risk rules, the
 * conflict resolution, the verification and the automatic rollback all happen
 * exactly as they do for a single change, because they are the same code.
 *
 * Three things it deliberately does not do:
 *
 * - **Skip confirmation.** The caller supplies a confirmation token issued for
 *   the exact plan, and `ApplyRoute` refuses a stale one (§13 rule 8). A "bulk"
 *   feature that quietly bypassed the confirmation would be a different feature
 *   with the same name.
 * - **Include destructive operations.** A profile never contains one, and this
 *   applies a profile. Deleting rows stays a separate, explicit decision, one
 *   operation at a time — which is the whole reason profiles exclude them.
 * - **Run unattended.** There is no cron hook here. Somebody presses a button
 *   and waits for the result, because verification failing needs somebody to be
 *   there when it does.
 */
final class BulkApply {

	/**
	 * The feature key this needs.
	 */
	public const FEATURE = 'bulk_apply';

	/**
	 * Where the saved profile is kept.
	 */
	private const OPTION = 'debloater_pro_saved_profile';

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
	 * The saved profile id, or '' when none is saved.
	 *
	 * @return string
	 */
	public function saved(): string {
		$stored = get_option( self::OPTION, '' );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return '';
		}

		// Checked against the registry every time rather than trusted from
		// storage: a profile can be removed by a registry update, and a saved
		// id that no longer names anything must read as "none saved" rather
		// than reaching the planner.
		return array_key_exists( $stored, $this->plugin->registry()->profiles() ) ? $stored : '';
	}

	/**
	 * Save a profile to apply later.
	 *
	 * @param string $profile Profile id, or '' to clear.
	 * @return bool Whether it was saved.
	 */
	public function save( string $profile ): bool {
		if ( '' === $profile ) {
			delete_option( self::OPTION );

			return true;
		}

		if ( ! array_key_exists( $profile, $this->plugin->registry()->profiles() ) ) {
			return false;
		}

		update_option( self::OPTION, $profile, false );

		return true;
	}

	/**
	 * Apply the saved profile.
	 *
	 * @param string $confirmation The confirmation token for this exact plan.
	 * @return ApplyResult|null Null when it did not run, and nothing changed.
	 */
	public function apply( string $confirmation ): ?ApplyResult {
		if ( ! $this->entitlement->entitlement()->allows( self::FEATURE ) ) {
			return null;
		}

		$profile = $this->saved();

		if ( '' === $profile ) {
			return null;
		}

		$result = $this->plugin->preview( $profile );

		if ( null === $result || $result->plan->isEmpty() ) {
			return null;
		}

		// The same token check the REST route makes. Duplicated deliberately:
		// this is a second entry point to applying, and an entry point that
		// trusted its caller to have checked would be the one somebody calls
		// from somewhere that has not.
		if ( ! \Debloater\Rest\ConfirmationToken::matchesPlan( $result->plan, $confirmation ) ) {
			return null;
		}

		return $this->plugin->apply( $result->plan );
	}
}
