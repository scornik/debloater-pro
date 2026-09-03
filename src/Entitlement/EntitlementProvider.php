<?php
/**
 * Where an entitlement comes from.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Entitlement;

/**
 * The one question Pro is allowed to ask about licensing.
 *
 * BUILD-SPEC §13 rule 13: licensing is provider-agnostic. Debloater operates no
 * licence server of its own, and no Hakeemify host is a prerequisite for Pro.
 *
 * The interface is one method returning a value object, and that is the whole
 * design. A richer interface — `isValid()`, `getPlan()`, `getLicenseKey()` —
 * would leak the shape of whichever platform was implemented first into every
 * feature that consulted it, and replacing the platform would then mean
 * touching feature code. As written, a second implementation is a new class and
 * one line of wiring.
 *
 * An implementation must never throw. Every failure — no network, no SDK, a
 * malformed answer, a platform outage — is `Entitlement::none()`, because a
 * feature asking what it may do needs an answer, and an exception raised from a
 * licence check is how a licensing outage becomes a site outage.
 */
interface EntitlementProvider {

	/**
	 * What this site is entitled to.
	 *
	 * Must not throw, and must not block for long: this may be called during an
	 * admin page load.
	 *
	 * @return Entitlement
	 */
	public function entitlement(): Entitlement;

	/**
	 * A short name for this provider, for diagnostics.
	 *
	 * @return string
	 */
	public function name(): string;
}
