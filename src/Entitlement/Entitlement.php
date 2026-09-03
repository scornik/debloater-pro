<?php
/**
 * What a site is entitled to, as a value.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Entitlement;

/**
 * The answer to "what may this site use", separated from who was asked.
 *
 * Immutable, and deliberately small. It says which features are unlocked and
 * when the answer stops being trustworthy — nothing about accounts, payments,
 * or the platform that produced it. That is what makes the provider
 * replaceable: nothing downstream can accidentally depend on a Freemius shape
 * if the Freemius shape never gets this far.
 *
 * There is no `isValid()`. A caller asks whether a *named feature* is
 * available, never whether a licence is good, because those are different
 * questions and conflating them is how safety features end up behind a paywall
 * (BUILD-SPEC §13 rule 15).
 */
final class Entitlement {

	/**
	 * Feature keys this site may use.
	 *
	 * @var array<int,string>
	 */
	public readonly array $features;

	/**
	 * When this answer should be asked for again, as a Unix timestamp.
	 *
	 * @var int
	 */
	public readonly int $expires_at;

	/**
	 * A short, non-identifying word for where this came from.
	 *
	 * @var string
	 */
	public readonly string $source;

	/**
	 * Constructor.
	 *
	 * @param array<int,string> $features   Feature keys.
	 * @param int               $expires_at Unix timestamp.
	 * @param string            $source     Provider name, e.g. "freemius", "fixture", "cache".
	 */
	public function __construct( array $features, int $expires_at, string $source ) {
		$clean = array();

		foreach ( $features as $feature ) {
			if ( is_string( $feature ) && '' !== $feature ) {
				$clean[] = $feature;
			}
		}

		sort( $clean, SORT_STRING );

		$this->features   = array_values( array_unique( $clean ) );
		$this->expires_at = $expires_at;
		$this->source     = $source;
	}

	/**
	 * Nothing is unlocked.
	 *
	 * The value every failure produces. A provider that cannot answer, a
	 * network that is down, a response that will not parse: all of them end
	 * here, and none of them ends anywhere else. Failing to a known-empty value
	 * rather than to null is what stops "we could not tell" from being read as
	 * "yes" three call sites later.
	 *
	 * @param string $source Where the failure came from.
	 * @return self
	 */
	public static function none( string $source = 'none' ): self {
		return new self( array(), 0, $source );
	}

	/**
	 * Whether a named feature is available.
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	public function allows( string $feature ): bool {
		return in_array( $feature, $this->features, true );
	}

	/**
	 * Whether anything at all is unlocked.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->features;
	}

	/**
	 * As an array, for caching.
	 *
	 * @return array{features:array<int,string>,expires_at:int,source:string}
	 */
	public function toArray(): array {
		return array(
			'features'   => $this->features,
			'expires_at' => $this->expires_at,
			'source'     => $this->source,
		);
	}

	/**
	 * From a cached array, refusing anything that is not one.
	 *
	 * @param mixed $data Cached value.
	 * @return self|null Null when the value cannot be trusted.
	 */
	public static function fromArray( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! isset( $data['features'], $data['expires_at'] ) ) {
			return null;
		}

		if ( ! is_array( $data['features'] ) || ! is_int( $data['expires_at'] ) ) {
			return null;
		}

		return new self(
			$data['features'],
			$data['expires_at'],
			isset( $data['source'] ) && is_string( $data['source'] ) ? $data['source'] : 'cache'
		);
	}
}
