<?php
/**
 * The only place a cloud URL is built.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Cloud;

use InvalidArgumentException;

/**
 * Turns a service and a path into a URL, and refuses everything else.
 *
 * BUILD-SPEC §13 rule 14 and the Phase 19 exit criterion: **one host, and no
 * cloud host outside this resolver**. A test greps for the host everywhere else
 * and fails if it finds it.
 *
 * Everything is versioned and product-scoped:
 *
 *     https://cloud.hakeemify.com/v1/debloater/<service>/<path>
 *
 * The reason for one host rather than several is not tidiness. A site
 * administrator who wants to know what this plugin talks to should be able to
 * answer with a single line in a firewall rule, and a single name to allow or
 * block. Spreading the same traffic over `api.`, `license.` and `registry.`
 * subdomains makes that question take four answers, three of which somebody
 * will miss.
 *
 * Segments are checked rather than trusted. A path built from something a
 * response said would otherwise be a way to point this plugin at a different
 * host, and the traversal check is what stops `..` from walking out of the
 * product scope.
 */
final class EndpointResolver {

	/**
	 * The one host. There is no second one, and no setter.
	 */
	public const HOST = 'cloud.hakeemify.com';

	/**
	 * The API version, in the path rather than a header, so a URL in a log says
	 * which contract produced it.
	 */
	public const VERSION = 'v1';

	/**
	 * The product scope. One host serves several products; this is ours.
	 */
	public const PRODUCT = 'debloater';

	/**
	 * The canonical base, assembled from the parts above and nowhere written
	 * out as a literal.
	 *
	 * @return string
	 */
	public function base(): string {
		return 'https://' . self::HOST . '/' . self::VERSION . '/' . self::PRODUCT;
	}

	/**
	 * A URL for a service path.
	 *
	 * @param string             $service Service name, e.g. "reports".
	 * @param string             $path    Path within the service, may be empty.
	 * @param array<string,scalar> $query Query parameters.
	 * @return string
	 * @throws InvalidArgumentException When a segment is not acceptable.
	 */
	public function url( string $service, string $path = '', array $query = array() ): string {
		$segments = array( $this->segment( $service ) );

		foreach ( explode( '/', trim( $path, '/' ) ) as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$segments[] = $this->segment( $part );
		}

		$url = $this->base() . '/' . implode( '/', $segments );

		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query );
		}

		return $url;
	}

	/**
	 * Check one path segment.
	 *
	 * Lowercase letters, digits, hyphens and underscores. Not a blocklist: a
	 * blocklist of the ways a path can escape its prefix is a list somebody
	 * always finds one more entry for.
	 *
	 * @param string $segment Segment to check.
	 * @return string
	 * @throws InvalidArgumentException When the segment is not acceptable.
	 */
	private function segment( string $segment ): string {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $segment ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Refusing to build a cloud URL from the segment "%s".', $segment )
			);
		}

		return $segment;
	}
}
