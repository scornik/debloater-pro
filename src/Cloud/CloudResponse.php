<?php
/**
 * What a cloud call gives back, whether or not it worked.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Cloud;

/**
 * A response, or the absence of one, as the same kind of thing.
 *
 * There is no exception path. A feature that calls the cloud gets one of these
 * whatever happened — a 200, a 500, a DNS failure, a body that would not
 * parse — and the only question it has to ask is `ok()`. That is what makes
 * "the cloud is optional" true in the code rather than in a comment: there is
 * no way to write a feature that works when the cloud is up and fatals when it
 * is not, because there is nothing to fail to catch.
 *
 * `data` is always an array and never anything else. A response that decoded to
 * a string, a number or null arrives here as a failure, not as a value some
 * caller then has to type-check.
 */
final class CloudResponse {

	/**
	 * HTTP status, or 0 when the request never completed.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Decoded body.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $data;

	/**
	 * Why it failed, or '' when it did not.
	 *
	 * @var string
	 */
	public readonly string $error;

	/**
	 * Constructor.
	 *
	 * @param int                 $status HTTP status, 0 if none.
	 * @param array<string,mixed> $data   Decoded body.
	 * @param string              $error  Failure reason.
	 */
	public function __construct( int $status, array $data = array(), string $error = '' ) {
		$this->status = $status;
		$this->data   = $data;
		$this->error  = $error;
	}

	/**
	 * A response that never arrived.
	 *
	 * @param string $reason Why.
	 * @return self
	 */
	public static function unavailable( string $reason ): self {
		return new self( 0, array(), $reason );
	}

	/**
	 * Whether this is a usable answer.
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return '' === $this->error && $this->status >= 200 && $this->status < 300;
	}
}
