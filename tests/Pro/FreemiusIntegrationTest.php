<?php
/**
 * The Freemius wiring, checked without Freemius answering.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Pro;

use Debloater\Pro\Entitlement\CachedEntitlementProvider;
use Debloater\Pro\Entitlement\Entitlement;
use Debloater\Pro\Entitlement\EntitlementProvider;
use PHPUnit\Framework\TestCase;

/**
 * BUILD-SPEC §13 rule 13, and Phase 19b part 2.
 *
 * What can be checked here is the wiring: where the SDK is initialised, what it
 * is initialised with, and what happens when it is not there or will not answer.
 * What cannot be checked here is Freemius itself — it is a live service, and a
 * test that needed it would be a test that failed whenever somebody else's
 * server was slow.
 *
 * So this reads the bootstrap as text for the facts that must be true of it,
 * and exercises the fallback path with a provider that fails on demand.
 */
final class FreemiusIntegrationTest extends TestCase {

	/**
	 * The bootstrap runs at the top of the entry point, not on a hook.
	 *
	 * The SDK hooks activation, deactivation and the admin menu. A licence
	 * check that starts on `plugins_loaded` has already missed them, and the
	 * symptom is subtle: everything works except the things that happened
	 * before you looked.
	 *
	 * @return void
	 */
	public function test_the_sdk_initialises_before_plugins_loaded(): void {
		$entry = $this->entryPoint();

		$init = strpos( $entry, 'dp_fs();' );

		$this->assertNotFalse( $init, 'the entry point must call dp_fs()' );

		// Nothing defers it.
		foreach ( array( "add_action( 'plugins_loaded'", "add_action( 'init'", "add_action( 'admin_init'" ) as $deferral ) {
			$position = strpos( $entry, $deferral );

			if ( false === $position ) {
				continue;
			}

			$this->assertGreaterThan(
				$init,
				$position,
				'dp_fs() must be called before anything is hooked, not from a hook.'
			);
		}

		// And it fires its own signal either way, so anything waiting is
		// waiting to know rather than waiting for a yes.
		$this->assertStringContainsString( "do_action( 'dp_fs_loaded' )", $entry );
	}

	/**
	 * The product is premium-only and not wordpress.org compliant.
	 *
	 * `is_org_compliant => false` is what tells the SDK this plugin is not
	 * distributed through wordpress.org, so it stops applying that repository's
	 * rules to it. Pro is not there and will not be: it is the paid half.
	 *
	 * @return void
	 */
	public function test_the_product_is_premium_only_and_not_org_compliant(): void {
		$entry = $this->entryPoint();

		// Matched with the spacing left loose. The alignment of a PHP array is
		// the code formatter's business — `phpcbf` rewrote it once already —
		// and a test that fails when somebody runs the formatter is a test
		// about whitespace wearing the costume of a test about licensing.
		$expected = array(
			'is_premium'       => 'true',
			'is_premium_only'  => 'true',
			'has_paid_plans'   => 'true',
			'has_addons'       => 'false',
			'is_org_compliant' => 'false',
			'slug'             => "'debloater-pro'",
			'premium_slug'     => "'debloater-pro'",
			'type'             => "'plugin'",
		);

		foreach ( $expected as $key => $value ) {
			$this->assertMatchesRegularExpression(
				'/' . preg_quote( "'" . $key . "'", '/' ) . '\s*=>\s*' . preg_quote( $value, '/' ) . '/',
				$entry,
				sprintf( 'the bootstrap must declare %s => %s', $key, $value )
			);
		}

		// Under Debloater's menu rather than its own top-level entry.
		$this->assertMatchesRegularExpression( "/'slug'\s*=>\s*'debloater'/", $entry );
		$this->assertMatchesRegularExpression( "/'support'\s*=>\s*false/", $entry );

		// wordpress.org's gatekeeper is for plugins that are on wordpress.org.
		$this->assertStringNotContainsString( 'wp_org_gatekeeper', $entry );
	}

	/**
	 * The product id and public key come from config, and the template ships.
	 *
	 * @return void
	 */
	public function test_the_product_is_configured_from_the_committed_template(): void {
		$template = dirname( __DIR__, 2 ) . '/config/freemius.php.dist';

		$this->assertFileExists( $template, 'the template must ship, so a build needs no copying' );

		$config = require $template;

		$this->assertIsArray( $config );
		$this->assertSame( '38409', $config['id'] );
		$this->assertSame( 'pk_9255b5bdb75deb17f57a40707ed87', $config['public_key'] );

		// The override is not committed. Neither file holds a secret, but the
		// one somebody edits locally must not become a release.
		$this->assertFileDoesNotExist(
			dirname( __DIR__, 2 ) . '/config/freemius.php',
			'config/freemius.php is gitignored; a committed one would ship a local experiment'
		);

		// And nothing anywhere holds the secret key, which is a different value
		// and belongs in wp-config.php.
		$this->assertStringNotContainsString( 'WP_FS__debloater-pro_SECRET_KEY', $this->entryPoint() );
	}

	/**
	 * A licensing platform that will not answer leaves the site working.
	 *
	 * The property that matters more than any feature: Pro degrades to its free
	 * behaviour, and nothing the free plugin does is blocked or altered while
	 * the platform is unreachable.
	 *
	 * @return void
	 */
	public function test_entitlement_falls_back_to_the_last_known_state(): void {
		$inner = new class() implements EntitlementProvider {

			/**
			 * Whether the platform is answering.
			 *
			 * @var bool
			 */
			public bool $reachable = true;

			/**
			 * How many times it was asked.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * What this site is entitled to.
			 *
			 * @return Entitlement
			 */
			public function entitlement(): Entitlement {
				++$this->calls;

				if ( ! $this->reachable ) {
					// Exactly what the real adapter returns when Freemius
					// throws or is absent: an answer, never an exception.
					return Entitlement::none( 'freemius-error' );
				}

				return new Entitlement( array( 'scheduled_scans', 'drift' ), time() + 3600, 'freemius' );
			}

			/**
			 * A short name.
			 *
			 * @return string
			 */
			public function name(): string {
				return 'fake';
			}
		};

		$cached = new CachedEntitlementProvider( $inner );

		$first = $cached->entitlement();

		$this->assertFalse( $first->isEmpty() );
		$this->assertTrue( $first->allows( 'drift' ) );

		// The platform goes away.
		$inner->reachable = false;

		$second = $cached->entitlement();

		$this->assertFalse(
			$second->isEmpty(),
			'An unreachable platform must not switch off features somebody has paid for.'
		);
		$this->assertTrue( $second->allows( 'drift' ) );

		$cached->forget();
	}

	/**
	 * A provider that throws is still an answer.
	 *
	 * @return void
	 */
	public function test_a_provider_that_throws_does_not_reach_the_caller(): void {
		$throwing = new class() implements EntitlementProvider {

			/**
			 * Always throws.
			 *
			 * @return Entitlement
			 * @throws \RuntimeException Always.
			 */
			public function entitlement(): Entitlement {
				throw new \RuntimeException( 'the platform is on fire' );
			}

			/**
			 * A short name.
			 *
			 * @return string
			 */
			public function name(): string {
				return 'throwing';
			}
		};

		$cached = new CachedEntitlementProvider( $throwing );

		$entitlement = $cached->entitlement();

		$this->assertTrue( $entitlement->isEmpty() );

		$cached->forget();
	}

	/**
	 * The Pro screen carries licence state itself, not a link to Account.
	 *
	 * The assertion is unchanged from when this was written; the reason for it
	 * was wrong. It said white-label removes the SDK's Account submenu. It does
	 * not — the SDK forces that submenu on, because licence activation and
	 * deactivation live there.
	 *
	 * What white-label removes is the content: the owner's email, the licence
	 * key, prices, the billing address, invoices. An agency's client therefore
	 * sees an Account item that answers almost nothing, which is a better
	 * argument for this test rather than a weaker one. A Pro screen whose only
	 * answer to "what does this site have" was "open Account" would be pointing
	 * at the page most deliberately emptied of it.
	 *
	 * Asserted against the source rather than by rendering, because rendering
	 * needs WordPress and this suite deliberately runs without it — and because
	 * what is being defended is *where* the information lives, which is a
	 * property of the file.
	 *
	 * @return void
	 */
	public function test_the_pro_screen_carries_licence_state_itself(): void {
		$screen = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Screen.php' );

		// It exists, and the main screen actually calls it.
		$this->assertStringContainsString( 'private function renderLicence(): void', $screen );
		$this->assertStringContainsString( '$this->renderLicence();', $screen );

		// Both things are here: what the licence covers, and how to release it.
		$this->assertStringContainsString( 'siteQuota', $screen );
		$this->assertStringContainsString( 'Release this site from the licence', $screen );

		// And it never answers by sending somebody to the Account page, which
		// on a white-labelled licence is present and stripped of the answer.
		foreach ( array( 'get_account_url', 'fs_account', 'account.php?page=debloater-pro-account' ) as $forbidden ) {
			$this->assertStringNotContainsString(
				$forbidden,
				$screen,
				'The Pro screen must answer for itself: on a white-labelled licence the Account '
					. 'page still exists but shows no key, no prices and no invoices.'
			);
		}

		// The URL it does use comes through the adapter, so the screen holds no
		// Freemius symbol of its own.
		$this->assertStringContainsString( 'licenceUrls', $screen );
	}

	/**
	 * The entry point's source.
	 *
	 * @return string
	 */
	private function entryPoint(): string {
		$path = dirname( __DIR__, 2 ) . '/debloater-pro.php';

		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}
}
