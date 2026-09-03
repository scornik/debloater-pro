<?php
/**
 * Network defaults, behind a flag.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Multisite;

use Debloater\Pro\Entitlement\EntitlementProvider;

/**
 * Groundwork only: a network default that a site may override.
 *
 * BUILD-SPEC §1 locked decision 8 says single-site first, and §17 Phase 19 asks
 * for groundwork behind a feature flag — "network defaults and per-site
 * overrides for **selection and intent profile only**". That "only" is the
 * whole specification of this class, and it is worth saying why it is drawn
 * where it is.
 *
 * A network default for *selection* means "these are the changes we start
 * from". A site can override it, and until a site applies something, nothing
 * has happened to that site. It is a suggestion with a shape.
 *
 * A network default for anything else would not be. A network-wide *apply*
 * would be one administrator changing sites they may never look at, with the
 * verification failing somewhere nobody is watching — which is the unattended
 * change this plugin exists to avoid, multiplied by the number of sites on the
 * network. So there is no method here that applies anything, and no method that
 * reaches another site's tables. Reading and writing a default is all this
 * does.
 *
 * The flag is `DEBLOATER_PRO_MULTISITE`, a wp-config constant. It is off unless
 * somebody turns it on, and on single-site WordPress it does nothing whatever
 * its value is.
 */
final class NetworkDefaults {

	/**
	 * The feature key this needs.
	 */
	public const FEATURE = 'multisite';

	/**
	 * The constant that turns this on.
	 */
	public const FLAG = 'DEBLOATER_PRO_MULTISITE';

	/**
	 * Where the network default is kept.
	 */
	private const OPTION = 'debloater_pro_network_defaults';

	/**
	 * Entitlement.
	 *
	 * @var EntitlementProvider
	 */
	private EntitlementProvider $entitlement;

	/**
	 * Constructor.
	 *
	 * @param EntitlementProvider $entitlement Entitlement source.
	 */
	public function __construct( EntitlementProvider $entitlement ) {
		$this->entitlement = $entitlement;
	}

	/**
	 * Whether this is switched on and usable here.
	 *
	 * Three conditions, all required: a multisite install, the flag set, and an
	 * entitlement that includes it.
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! defined( self::FLAG ) || ! constant( self::FLAG ) ) {
			return false;
		}

		return $this->entitlement->entitlement()->allows( self::FEATURE );
	}

	/**
	 * The network default selection and intent profile.
	 *
	 * @return array{selection:array<string,array<string,mixed>>,intent_profile:string}
	 */
	public function defaults(): array {
		$empty = array(
			'selection'      => array(),
			'intent_profile' => '',
		);

		if ( ! $this->isEnabled() ) {
			return $empty;
		}

		$stored = get_site_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return $empty;
		}

		return array(
			'selection'      => is_array( $stored['selection'] ?? null ) ? $stored['selection'] : array(),
			'intent_profile' => is_string( $stored['intent_profile'] ?? null ) ? $stored['intent_profile'] : '',
		);
	}

	/**
	 * Set the network default.
	 *
	 * Writes a default. Applies nothing, to this site or any other: a site
	 * picks this up the next time somebody looks at its dashboard, and it is
	 * still that site's administrator who decides to apply it.
	 *
	 * @param array<string,array<string,mixed>> $selection      Tweak ids to parameters.
	 * @param string                            $intent_profile Intent profile id.
	 * @return bool Whether it was stored.
	 */
	public function setDefaults( array $selection, string $intent_profile ): bool {
		if ( ! $this->isEnabled() ) {
			return false;
		}

		return (bool) update_site_option(
			self::OPTION,
			array(
				'selection'      => $selection,
				'intent_profile' => $intent_profile,
			)
		);
	}
}
