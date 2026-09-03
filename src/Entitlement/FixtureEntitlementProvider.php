<?php
/**
 * A provider that answers from a fixture.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Entitlement;

/**
 * What development and CI use instead of a licensing platform.
 *
 * BUILD-SPEC §17 Phase 19: "Development and CI use a fixture provider; live
 * credentials are never required to build or test."
 *
 * This ships. It is not test-only scaffolding hidden behind a constant, because
 * a provider that only exists in the test suite is a provider whose behaviour
 * nobody has to keep working — and the interface's real value is that a second
 * implementation is cheap, which is a claim best backed by having one.
 *
 * It is inert unless something constructs it with features. `Pro` only does so
 * when `DEBLOATER_PRO_FIXTURE` is defined, which is a wp-config constant on a
 * developer's machine and absent everywhere else.
 */
final class FixtureEntitlementProvider implements EntitlementProvider {

	/**
	 * The entitlement to hand back.
	 *
	 * @var Entitlement
	 */
	private Entitlement $entitlement;

	/**
	 * Constructor.
	 *
	 * @param array<int,string> $features Features to unlock.
	 * @param int|null          $expires  Expiry timestamp, or null for an hour from now.
	 */
	public function __construct( array $features = array(), ?int $expires = null ) {
		$this->entitlement = new Entitlement(
			$features,
			$expires ?? time() + HOUR_IN_SECONDS,
			'fixture'
		);
	}

	/**
	 * Everything Pro offers, for a development machine.
	 *
	 * @return self
	 */
	public static function everything(): self {
		return new self(
			array(
				'scheduled_scans',
				'drift_detection',
				'white_label_report',
				'bulk_apply',
				'priority_registry',
				'multisite',
			)
		);
	}

	/**
	 * What this site is entitled to.
	 *
	 * @return Entitlement
	 */
	public function entitlement(): Entitlement {
		return $this->entitlement;
	}

	/**
	 * A short name for diagnostics.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'fixture';
	}
}
