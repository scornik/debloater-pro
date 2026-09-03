<?php
/**
 * The Freemius adapter, and the only file that knows Freemius exists.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Entitlement;

/**
 * Reads entitlement from Freemius, if Freemius is there.
 *
 * BUILD-SPEC §13 rule 13 and the Phase 19 exit criterion: **no Freemius symbol
 * appears outside this file**, and a test asserts it. Everything Freemius knows
 * about — plans, licences, trials, the SDK's global function — stops here and
 * leaves as an `Entitlement`, which knows only which features are on.
 *
 * The SDK is not a dependency. It is discovered at runtime and its absence is
 * an ordinary answer rather than an error: development and CI run with no
 * licensing platform present at all, which is exactly the configuration this
 * has to survive, because it is also what a free install looks like.
 *
 * Nothing here is a security boundary. A determined person can edit PHP on
 * their own server, and pretending otherwise leads to the things the
 * architecture brief rules out — encryption, ionCube, anti-debugging traps,
 * destructive anti-tamper. What this is for is telling an honest site what it
 * has paid for.
 */
final class FreemiusEntitlementProvider implements EntitlementProvider {

	/**
	 * The SDK's entry function, named once.
	 *
	 * A string rather than a call, so that no part of this plugin references a
	 * Freemius symbol that a static analyser or a grep would have to know
	 * about — including the invariant test, which greps for exactly this.
	 */
	private const SDK_FUNCTION = 'debloater_fs';

	/**
	 * Plan-to-feature mapping.
	 *
	 * Kept here rather than in the features themselves. A feature asks
	 * `allows( 'drift_detection' )`; what a plan is called, and which plan
	 * includes it, is a fact about the storefront and belongs next to the
	 * storefront adapter.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const PLANS = array(
		'pro'    => array(
			'scheduled_scans',
			'drift_detection',
			'white_label_report',
			'bulk_apply',
			'priority_registry',
		),
		'agency' => array(
			'scheduled_scans',
			'drift_detection',
			'white_label_report',
			'bulk_apply',
			'priority_registry',
			'multisite',
		),
	);

	/**
	 * How long an answer is good for.
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * What this site is entitled to.
	 *
	 * @return Entitlement
	 */
	public function entitlement(): Entitlement {
		$sdk = $this->sdk();

		if ( null === $sdk ) {
			// No SDK: a free install, or a development one. Not an error.
			return Entitlement::none( 'freemius-absent' );
		}

		try {
			if ( ! method_exists( $sdk, 'is_paying' ) ) {
				// An SDK that is present but does not answer the one question
				// asked of it. A version bump that renamed the method lands
				// here, and lands as "nothing unlocked" rather than as a fatal.
				return Entitlement::none( 'freemius-unrecognised' );
			}

			if ( true !== $sdk->is_paying() ) {
				return Entitlement::none( 'freemius-unpaid' );
			}

			return new Entitlement(
				$this->featuresFor( $this->planName( $sdk ) ),
				time() + self::TTL,
				'freemius'
			);
		} catch ( \Throwable $error ) {
			// A licensing platform having a bad day must not be a site having a
			// bad day. Pro degrades to its free behaviour and the site is
			// untouched.
			unset( $error );

			return Entitlement::none( 'freemius-error' );
		}
	}

	/**
	 * A short name for diagnostics.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'freemius';
	}

	/**
	 * Whether the SDK is present at all.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool {
		return null !== $this->sdk();
	}

	/**
	 * The SDK instance, or null.
	 *
	 * @return object|null
	 */
	private function sdk(): ?object {
		if ( ! function_exists( self::SDK_FUNCTION ) ) {
			return null;
		}

		$entry = self::SDK_FUNCTION;

		try {
			// @phpstan-ignore callable.nonCallable (The entry function is defined by the licensing SDK, which is a separate plugin and deliberately not a dependency of this one — so it is not in the analysed set and never will be. The function_exists() check above is the one that applies at runtime.)
			$instance = $entry();
		} catch ( \Throwable $error ) {
			unset( $error );

			return null;
		}

		return is_object( $instance ) ? $instance : null;
	}

	/**
	 * The plan this site is on, lowercased.
	 *
	 * @param object $sdk The SDK instance.
	 * @return string
	 */
	private function planName( object $sdk ): string {
		if ( ! method_exists( $sdk, 'get_plan_name' ) ) {
			return '';
		}

		$plan = $sdk->get_plan_name();

		return is_string( $plan ) ? strtolower( trim( $plan ) ) : '';
	}

	/**
	 * Features for a plan name, or none for a plan we do not know.
	 *
	 * An unrecognised plan unlocks nothing. The alternative — treat unknown as
	 * "probably the top plan" — would mean a typo on the storefront silently
	 * giving everything away, and a renamed plan silently doing the same.
	 *
	 * @param string $plan Plan name.
	 * @return array<int,string>
	 */
	private function featuresFor( string $plan ): array {
		return self::PLANS[ $plan ] ?? array();
	}
}
