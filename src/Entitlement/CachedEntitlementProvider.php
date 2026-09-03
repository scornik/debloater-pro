<?php
/**
 * Caching, and the offline grace that comes with it.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Entitlement;

/**
 * Wraps a provider so the platform is asked rarely and an outage is survivable.
 *
 * Two jobs, and they are the same job seen from either side.
 *
 * **Speed.** An admin page load must not wait on somebody else's API. A fresh
 * answer is cached and reused until it expires.
 *
 * **Grace.** When the wrapped provider comes back empty, the last good answer
 * is used for a while longer. This is the part worth being careful about, so it
 * is worth stating exactly what it does and does not do.
 *
 * It does *not* mean a cancelled subscription keeps working indefinitely: the
 * grace window is finite, and once it passes the answer is empty like any
 * other. What it means is that somebody who has paid does not lose the features
 * they paid for because a third party had an outage, or because their host
 * blocked outbound HTTP for an afternoon. Between "a payer briefly keeps what
 * they bought" and "a payer is locked out by somebody else's downtime", the
 * first is the smaller wrong.
 *
 * The grace is applied only to a *previously good* answer. There is no path
 * from "never had an entitlement" to "has one", however many times a provider
 * fails.
 */
final class CachedEntitlementProvider implements EntitlementProvider {

	/**
	 * Where the last good answer is kept.
	 */
	private const OPTION = 'debloater_pro_entitlement';

	/**
	 * How long a good answer survives the provider being unable to confirm it.
	 */
	private const GRACE = 14 * DAY_IN_SECONDS;

	/**
	 * The provider being wrapped.
	 *
	 * @var EntitlementProvider
	 */
	private EntitlementProvider $inner;

	/**
	 * Resolved once per request.
	 *
	 * @var Entitlement|null
	 */
	private ?Entitlement $resolved = null;

	/**
	 * Constructor.
	 *
	 * @param EntitlementProvider $inner Provider to wrap.
	 */
	public function __construct( EntitlementProvider $inner ) {
		$this->inner = $inner;
	}

	/**
	 * What this site is entitled to.
	 *
	 * @return Entitlement
	 */
	public function entitlement(): Entitlement {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$cached = $this->cached();
		$now    = time();

		// A cached answer that has not expired is used without asking again.
		if ( null !== $cached && $cached->expires_at > $now ) {
			$this->resolved = $cached;

			return $this->resolved;
		}

		$fresh = $this->inner->entitlement();

		if ( ! $fresh->isEmpty() ) {
			$this->store( $fresh );

			$this->resolved = $fresh;

			return $this->resolved;
		}

		// Empty. Either this site is genuinely not entitled, or the provider
		// could not say. Those look identical from here, which is why the grace
		// is bounded and why it only applies to something that was good before.
		if ( null !== $cached && ! $cached->isEmpty() && $cached->expires_at + self::GRACE > $now ) {
			$this->resolved = new Entitlement(
				$cached->features,
				$cached->expires_at,
				'grace'
			);

			return $this->resolved;
		}

		$this->forget();

		$this->resolved = $fresh;

		return $this->resolved;
	}

	/**
	 * A short name for diagnostics.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'cached:' . $this->inner->name();
	}

	/**
	 * Drop the cache, so the next call asks the provider.
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->resolved = null;

		delete_option( self::OPTION );
	}

	/**
	 * The stored answer, if there is a usable one.
	 *
	 * @return Entitlement|null
	 */
	private function cached(): ?Entitlement {
		return Entitlement::fromArray( get_option( self::OPTION, null ) );
	}

	/**
	 * Store an answer.
	 *
	 * Not autoloaded: this is read on admin requests and by cron, never on a
	 * front-end page view, and the free plugin holds itself to the same rule.
	 *
	 * @param Entitlement $entitlement Answer to keep.
	 * @return void
	 */
	private function store( Entitlement $entitlement ): void {
		update_option( self::OPTION, $entitlement->toArray(), false );
	}
}
