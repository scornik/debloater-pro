<?php
/**
 * The priority registry channel.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Features;

use Debloater\Pro\Entitlement\EntitlementProvider;

/**
 * Points registry updates at a channel that gets them sooner.
 *
 * The whole feature is one filter returning a different HTTPS base. It is worth
 * saying what that does and does not buy, because "priority channel" could
 * easily be read as something more dangerous than it is.
 *
 * It does **not** relax any check. The manifest fetched from the priority
 * channel is verified against the same Ed25519 public key, every path in it is
 * checked for traversal by the same code, and the same size and count ceilings
 * apply. A signature that does not verify fails closed on this channel exactly
 * as it does on the default one. What a subscriber gets is the same content
 * earlier, not different content with fewer questions asked.
 *
 * It does **not** turn updates on. The registry fetch is opt-in in the free
 * plugin (§13 rule 9) and stays opt-in here: a site that has not asked for
 * registry updates does not start making them because Pro was activated.
 *
 * And it is entitlement-gated at the point of use rather than at setup, so a
 * lapsed subscription falls back to the public channel rather than to no
 * updates at all. Losing a subscription should cost somebody the head start,
 * not the safety fixes.
 */
final class RegistryChannel {

	/**
	 * The feature key this needs.
	 */
	public const FEATURE = 'priority_registry';

	/**
	 * Where the priority channel lives.
	 *
	 * A repository, not a service. The same shape as the public one, so the
	 * verification path does not have a second case in it.
	 */
	private const BASE = 'https://raw.githubusercontent.com/scornik/debloater-registry-priority';

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
	 * Hook the filter.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'debloater_registry_origin', array( $this, 'origin' ) );
	}

	/**
	 * The base to fetch the registry from.
	 *
	 * @param string $shipped The free plugin's own base.
	 * @return string
	 */
	public function origin( $shipped ): string {
		if ( ! $this->entitlement->entitlement()->allows( self::FEATURE ) ) {
			return is_string( $shipped ) ? $shipped : '';
		}

		return self::BASE;
	}
}
