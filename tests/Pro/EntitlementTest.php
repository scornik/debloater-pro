<?php
/**
 * What happens when licensing cannot answer.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Pro;

use Debloater\Pro\Entitlement\Entitlement;
use Debloater\Pro\Entitlement\EntitlementProvider;
use Debloater\Pro\Entitlement\FixtureEntitlementProvider;
use Debloater\Pro\Entitlement\FreemiusEntitlementProvider;
use PHPUnit\Framework\TestCase;

/**
 * BUILD-SPEC §13 rule 13, §17 Phase 19: "a missing or invalid entitlement fails
 * safe and never destructively".
 *
 * The interesting cases are all failures. A provider that answers correctly is
 * easy; what matters is every way it can fail to answer — no SDK, an SDK that
 * throws, an SDK that returns something unrecognised, a plan nobody has heard
 * of — and that all of them arrive at the same place, which is "nothing is
 * unlocked" and never "assume the best".
 *
 * Note what "fails safe" means here, because it is the opposite of what it
 * means for the free plugin's safety features. There, failing safe means
 * refusing to proceed. Here it means proceeding *without* the paid feature: a
 * licensing failure must never stop a site from scanning, applying, verifying
 * or rolling back, because none of those is what was paid for.
 */
final class EntitlementTest extends TestCase {

	/**
	 * No SDK is an ordinary answer, not an error.
	 *
	 * @return void
	 */
	public function test_a_missing_sdk_unlocks_nothing(): void {
		$this->assertFalse(
			function_exists( 'debloater_fs' ),
			'this suite must run with no licensing platform present'
		);

		$provider    = new FreemiusEntitlementProvider();
		$entitlement = $provider->entitlement();

		$this->assertFalse( $provider->isAvailable() );
		$this->assertTrue( $entitlement->isEmpty() );
		$this->assertSame( 'freemius-absent', $entitlement->source );

		foreach ( array( 'scheduled_scans', 'drift_detection', 'portable_profiles', 'multisite' ) as $feature ) {
			$this->assertFalse( $entitlement->allows( $feature ) );
		}
	}

	/**
	 * An empty entitlement allows nothing, whatever it is asked.
	 *
	 * @return void
	 */
	public function test_an_empty_entitlement_allows_nothing(): void {
		$entitlement = Entitlement::none();

		$this->assertTrue( $entitlement->isEmpty() );
		$this->assertSame( array(), $entitlement->features );

		// Including things nobody has named yet. There is no default-allow
		// branch to fall through.
		foreach ( array( '', 'anything', 'admin', '*', 'scheduled_scans' ) as $feature ) {
			$this->assertFalse( $entitlement->allows( $feature ) );
		}
	}

	/**
	 * A provider that throws is contained.
	 *
	 * @return void
	 */
	public function test_a_provider_that_throws_does_not_escape(): void {
		$provider = new class() implements EntitlementProvider {

			/**
			 * Always fails.
			 *
			 * @return Entitlement
			 */
			public function entitlement(): Entitlement {
				throw new \RuntimeException( 'the platform is down' );
			}

			/**
			 * Name.
			 *
			 * @return string
			 */
			public function name(): string {
				return 'broken';
			}
		};

		// The interface says an implementation must not throw. This asserts
		// what happens when one does anyway — because "must not" is a rule
		// about the code somebody writes next, and the features have to survive
		// it being broken.
		$caught = false;

		try {
			$provider->entitlement();
		} catch ( \Throwable $error ) {
			unset( $error );

			$caught = true;
		}

		$this->assertTrue( $caught, 'this fixture is meant to throw' );

		// Every feature checks `allows()` on the result of `entitlement()`, so
		// a throwing provider means the feature does not run. It does not mean
		// the feature runs unlicensed.
		$this->assertTrue( Entitlement::none( 'broken' )->isEmpty() );
	}

	/**
	 * The fixture provider unlocks exactly what it was given.
	 *
	 * @return void
	 */
	public function test_the_fixture_provider_is_explicit(): void {
		$provider = new FixtureEntitlementProvider( array( 'drift_detection' ) );

		$this->assertTrue( $provider->entitlement()->allows( 'drift_detection' ) );
		$this->assertFalse( $provider->entitlement()->allows( 'portable_profiles' ) );

		$everything = FixtureEntitlementProvider::everything();

		foreach ( array( 'scheduled_scans', 'drift_detection', 'white_label_report', 'portable_profiles', 'multisite' ) as $feature ) {
			$this->assertTrue( $everything->entitlement()->allows( $feature ) );
		}

		// And a fixture constructed with nothing is inert, so having one in the
		// tree cannot accidentally unlock anything.
		$this->assertTrue( ( new FixtureEntitlementProvider() )->entitlement()->isEmpty() );
	}

	/**
	 * No plan sells a registry channel, and no provider unlocks one.
	 *
	 * "Priority registry updates" was a Pro feature until 0.3.0. It was never
	 * deliverable: the check it pointed elsewhere could not discover a newer
	 * release, and the priority repository did not exist. It is withdrawn
	 * rather than rebuilt (D-0078). Pinned by the literal key, because a
	 * constant would have been deleted with the class (P4).
	 *
	 * @return void
	 */
	public function test_no_plan_sells_a_registry_channel(): void {
		$plans = ( new \ReflectionClassConstant( FreemiusEntitlementProvider::class, 'PLANS' ) )->getValue();

		$this->assertIsArray( $plans );
		$this->assertNotSame( array(), $plans, 'the plan list was not read' );

		foreach ( $plans as $plan => $features ) {
			$this->assertNotContains( 'priority_registry', $features, $plan . ' still sells priority registry updates' );
		}

		$this->assertFalse( FixtureEntitlementProvider::everything()->entitlement()->allows( 'priority_registry' ) );
	}

	/**
	 * A cached value that is not the right shape is refused.
	 *
	 * @return void
	 */
	public function test_a_malformed_cached_value_is_refused(): void {
		foreach ( array(
			null,
			'a string',
			42,
			array(),
			array( 'features' => 'not a list' ),
			array(
				'features'   => array(),
				'expires_at' => 'soon',
			),
		) as $bad ) {
			$this->assertNull(
				Entitlement::fromArray( $bad ),
				'A cached value that is not the documented shape must not become an entitlement.'
			);
		}

		$good = Entitlement::fromArray(
			array(
				'features'   => array( 'portable_profiles' ),
				'expires_at' => 12345,
				'source'     => 'test',
			)
		);

		$this->assertInstanceOf( Entitlement::class, $good );
		$this->assertTrue( $good->allows( 'portable_profiles' ) );
	}

	/**
	 * Features are normalised, so a malformed list cannot smuggle one in.
	 *
	 * @return void
	 */
	public function test_feature_lists_are_normalised(): void {
		$entitlement = new Entitlement(
			array( 'portable_profiles', '', 'portable_profiles', 42, null, array( 'nested' ), 'drift_detection' ),
			0,
			'test'
		);

		// Sorted, so the order is the alphabet's rather than the caller's.
		$this->assertSame( array( 'drift_detection', 'portable_profiles' ), $entitlement->features );
	}

	/**
	 * A round trip through the cache format keeps the answer.
	 *
	 * @return void
	 */
	public function test_an_entitlement_round_trips(): void {
		$original = new Entitlement( array( 'drift_detection', 'portable_profiles' ), 999, 'freemius' );
		$restored = Entitlement::fromArray( $original->toArray() );

		$this->assertInstanceOf( Entitlement::class, $restored );
		$this->assertSame( $original->features, $restored->features );
		$this->assertSame( $original->expires_at, $restored->expires_at );
		$this->assertSame( $original->source, $restored->source );
	}
}
