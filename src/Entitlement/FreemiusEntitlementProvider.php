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
	private const SDK_FUNCTION = 'dp_fs';

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
			'portable_profiles',
		),
		'agency' => array(
			'scheduled_scans',
			'drift_detection',
			'white_label_report',
			'portable_profiles',
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
			// `can_use_premium_code__premium_only()` rather than `is_paying()`.
			//
			// They answer different questions. `is_paying()` asks whether there
			// is a live paid subscription; the premium check asks whether this
			// build is entitled to run premium code, which is also true during
			// a trial and true for a licence that is paid up but not renewing.
			// Gating on the first would switch features off for somebody who
			// had cancelled a renewal and still had three months left.
			//
			// The method carries the `__premium_only` suffix because the SDK
			// strips such methods out of a free build. This is a premium-only
			// product, so it is always present — but it is checked for anyway,
			// because a missing method must read as "nothing unlocked" and not
			// as a fatal.
			$gate = 'can_use_premium_code__premium_only';

			if ( ! method_exists( $sdk, $gate ) ) {
				// An SDK that is present but does not answer the one question
				// asked of it. A version bump that renamed the method lands
				// here, and lands as "nothing unlocked" rather than as a fatal.
				return Entitlement::none( 'freemius-unrecognised' );
			}

			if ( true !== $sdk->$gate() ) {
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
			$instance = $entry();
		} catch ( \Throwable $error ) {
			unset( $error );

			return null;
		}

		return is_object( $instance ) ? $instance : null;
	}

	/**
	 * How many sites this licence covers, and how many are used.
	 *
	 * **For display only.** Nothing decides anything on this: the licensing
	 * platform enforces its own quota, and a plugin that switched features off
	 * because it had counted the sites itself would be enforcing a rule it
	 * cannot see the whole of — other installs, a site removed an hour ago, a
	 * quota the customer has since raised.
	 *
	 * Returned as plain scalars so nothing outside this file holds a Freemius
	 * object. `null` when there is no licence, when the SDK does not expose one,
	 * or when reading it throws — all three being "we cannot say", which the
	 * screen renders as "not known" rather than as a number that might be wrong.
	 *
	 * @return array{limit: int|null, used: int|null}|null
	 */
	public function siteQuota(): ?array {
		$sdk = $this->sdk();

		if ( null === $sdk || ! method_exists( $sdk, '_get_license' ) ) {
			return null;
		}

		try {
			$licence = $sdk->_get_license();

			if ( ! is_object( $licence ) ) {
				return null;
			}

			// `quota` is null on an unlimited licence, which is a meaningful
			// answer rather than a missing one, so it is passed through as null
			// and the screen says "unlimited".
			$limit = property_exists( $licence, 'quota' ) ? $licence->quota : null;
			$used  = property_exists( $licence, 'activated' ) ? $licence->activated : null;

			return array(
				'limit' => is_numeric( $limit ) ? (int) $limit : null,
				'used'  => is_numeric( $used ) ? (int) $used : null,
			);
		} catch ( \Throwable $error ) {
			unset( $error );

			return null;
		}
	}

	/**
	 * Where a customer manages this licence, as plain URLs.
	 *
	 * The reason this exists is white-label, and not the reason first written
	 * here. That said the SDK hid its Account menu on a white-labelled licence.
	 * It does not: the SDK forces that submenu on, because activating and
	 * deactivating a licence happen there. What white-label hides is the
	 * *content* — the owner's email, the licence key, prices, the billing
	 * address and invoices.
	 *
	 * So the page is present and, for the agency's client, close to empty. That
	 * is the actual problem: a Pro screen whose only answer to "what does this
	 * site have" was "go and look at Account" would be sending somebody to a
	 * page deliberately stripped of exactly that.
	 *
	 * The URLs are asked for here and rendered on our own screen. Strings out,
	 * no Freemius object, and null for anything the SDK will not give.
	 *
	 * @return array{account: string|null, deactivate: string|null}
	 */
	public function licenceUrls(): array {
		$sdk  = $this->sdk();
		$urls = array(
			'account'    => null,
			'deactivate' => null,
		);

		if ( null === $sdk || ! method_exists( $sdk, 'get_account_url' ) ) {
			return $urls;
		}

		try {
			$account = $sdk->get_account_url();
			$release = $sdk->get_account_url( 'deactivate_license' );

			$urls['account']    = is_string( $account ) ? $account : null;
			$urls['deactivate'] = is_string( $release ) ? $release : null;
		} catch ( \Throwable $error ) {
			unset( $error );
		}

		return $urls;
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
