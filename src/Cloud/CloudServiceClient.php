<?php
/**
 * What a server-backed feature is allowed to ask for.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Cloud;

/**
 * BUILD-SPEC §13 rule 14: the cloud is optional.
 *
 * "Optional" is a property of the code, not a promise in a document, so this
 * interface is shaped to make the degraded path the easy one. Every call
 * returns a `CloudResponse`, never throws, and a response that did not arrive
 * is an ordinary value with `ok() === false`. A feature written against this
 * cannot fail to handle an outage, because there is no exception to forget to
 * catch.
 *
 * What comes back is **data**. There is no method that returns code, no method
 * that returns a URL to load a script from, and no method whose result is
 * executed or rendered as markup. That is the whole of the Phase 19 criterion
 * "no endpoint can cause PHP or JS from a remote to execute on the site": it
 * is enforced by there being nothing here that could.
 *
 * Licensing does not go through this interface. Entitlement is read through
 * `EntitlementProvider`, from a third-party platform, and a cloud endpoint
 * whose real purpose was licence validation is prohibited. The two are separate
 * concerns and neither is implemented in terms of the other.
 */
interface CloudServiceClient {

	/**
	 * Ask a service for something.
	 *
	 * Must not throw.
	 *
	 * @param string              $service Service name.
	 * @param string              $path    Path within the service.
	 * @param array<string,scalar> $query  Query parameters.
	 * @return CloudResponse
	 */
	public function get( string $service, string $path = '', array $query = array() ): CloudResponse;

	/**
	 * Whether the cloud is configured and worth trying.
	 *
	 * A feature may use this to decide whether to offer a cloud-backed option
	 * at all, rather than offering one that always fails.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;
}
