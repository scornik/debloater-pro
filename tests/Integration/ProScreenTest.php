<?php
/**
 * Pro's admin screen.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Brand;
use Debloater\Pro\Admin\Screen;
use Debloater\Pro\Entitlement\FixtureEntitlementProvider;
use Debloater\Pro\Features\ScheduledScans;
use Debloater\Pro\Pro;

/**
 * BUILD-SPEC §13 rules 1, 2 and 4, and §17 Phase 19.
 *
 * Reported from a live install: "I don't see any difference between free and
 * Pro." Pro was working — the drift panel was on the dashboard — but four of
 * its five features had no interface at all, so the only visible difference was
 * one panel somebody had to know to look for.
 *
 * This screen is the interface. What matters about it, and what is asserted
 * here, is that giving Pro a page of its own did not give it a way around
 * anything: the same capability, a nonce on every post, no markup from an
 * extension on the free plugin's screen, and applying still going through the
 * free plugin's own preview and confirmation.
 */
final class ProScreenTest extends IntegrationTestCase {

	/**
	 * Pro, with everything unlocked.
	 *
	 * @var Pro
	 */
	private Pro $pro;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		$this->pro = new Pro( $this->plugin, FixtureEntitlementProvider::everything() );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->pro->scans()->unschedule();

		delete_option( 'debloater_pro_saved_profile' );
		delete_option( 'debloater_pro_scan_schedule' );
		delete_option( 'debloater_pro_report_branding' );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The screen sits under Debloater rather than at the top level.
	 *
	 * @return void
	 */
	public function test_the_screen_is_a_submenu_of_debloater(): void {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Cleared so the assertion below is about this boot rather than about
		// whatever an earlier test left in the global.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The point of the test is what add_submenu_page() writes here.
		$submenu = array();

		$this->pro->boot();

		do_action( 'admin_menu' );

		$this->assertArrayHasKey( Brand::MENU_SLUG, $submenu );

		$slugs = array_column( $submenu[ Brand::MENU_SLUG ], 2 );

		$this->assertContains( Screen::SLUG, $slugs );
	}

	/**
	 * Somebody without the capability gets nothing.
	 *
	 * @return void
	 */
	public function test_a_subscriber_cannot_open_the_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$screen = new Screen( $this->pro );

		$this->expectException( \WPDieException::class );

		$screen->render();
	}

	/**
	 * The settings render, and carry a nonce.
	 *
	 * @return void
	 */
	public function test_the_screen_renders_its_settings_with_a_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$markup = $this->render();

		foreach ( array( 'Scan on a schedule', 'Name on reports', 'Profiles' ) as $control ) {
			$this->assertStringContainsString( $control, $markup );
		}

		// The dropdown this screen used to carry, and what replaced it. It
		// listed the three registry profiles and stored which one you meant;
		// the panel lists every profile the site has and can copy, rename,
		// delete and export them (§17 Phase 19c). Named here so that removing
		// the panel would have to remove this line too, rather than quietly
		// leaving the screen without either.
		$this->assertStringNotContainsString( 'Saved profile', $markup );
		$this->assertStringNotContainsString( 'debloater-pro-profile"', $markup );
		$this->assertStringContainsString( 'debloater-pro-profiles', $markup );

		// §13 rule 2: the form that changes settings carries a nonce.
		$this->assertStringContainsString( '_wpnonce', $markup );
		$this->assertStringContainsString( 'admin-post.php', $markup );
	}

	/**
	 * With no entitlement, the screen says so and offers no controls.
	 *
	 * @return void
	 */
	public function test_without_entitlement_there_is_nothing_to_change(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$unentitled = new Pro( $this->plugin, new FixtureEntitlementProvider() );
		$screen     = new Screen( $unentitled );

		ob_start();
		$screen->render();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'No Pro features are unlocked', $markup );
		$this->assertStringNotContainsString( 'Scan on a schedule', $markup );
		$this->assertStringNotContainsString( '<form', $markup );

		// And it names which provider answered, because "not paid" and "the
		// SDK is not installed" are different problems with one symptom.
		$this->assertStringContainsString( 'fixture', $markup );
	}

	/**
	 * The schedule is stored and the cron event follows it.
	 *
	 * @return void
	 */
	public function test_setting_a_schedule_schedules_the_scan(): void {
		$this->assertFalse( wp_next_scheduled( ScheduledScans::HOOK ) );

		$this->pro->scans()->setFrequency( 'weekly' );

		$this->assertSame( 'weekly', $this->pro->scans()->frequency() );
		$this->assertNotFalse( wp_next_scheduled( ScheduledScans::HOOK ) );

		// And turning it off removes the event rather than leaving one firing
		// into a hook nobody listens to.
		$this->pro->scans()->setFrequency( '' );

		$this->assertSame( '', $this->pro->scans()->frequency() );
		$this->assertFalse( wp_next_scheduled( ScheduledScans::HOOK ) );
	}

	/**
	 * A profile that does not exist is refused rather than stored.
	 *
	 * `BulkApply` keeps the option and the check; what changed in Phase 19c is
	 * who writes it. The dropdown wrote it on Save, and the profiles panel
	 * writes it when somebody presses Apply, which is a plainer statement of
	 * which profile they meant.
	 *
	 * @return void
	 */
	public function test_an_unknown_profile_is_refused(): void {
		$this->assertFalse( $this->pro->bulk()->save( 'not-a-profile' ) );
		$this->assertSame( '', $this->pro->bulk()->saved() );

		$this->assertTrue( $this->pro->bulk()->save( 'safe' ) );
		$this->assertSame( 'safe', $this->pro->bulk()->saved() );
	}

	/**
	 * The branding is stored as text, and cannot carry markup onto the report.
	 *
	 * @return void
	 */
	public function test_branding_is_stripped_of_markup(): void {
		$this->pro->report()->setBranding( '<script>alert(1)</script>Acme' );

		$stored = $this->pro->report()->branding();

		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringContainsString( 'Acme', $stored );
	}

	/**
	 * The report is a whole document, and every value in it is escaped.
	 *
	 * @return void
	 */
	public function test_the_report_escapes_what_it_prints(): void {
		$this->pro->report()->setBranding( 'Acme & Sons' );

		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$result = $this->plugin->apply( $preview->plan );
		$html   = $this->pro->renderReport( $result->run_id );

		$this->assertStringContainsString( '<!doctype html>', $html );
		$this->assertStringContainsString( 'Acme &amp; Sons', $html );
		$this->assertStringNotContainsString( 'Acme & Sons', $html );

		// §12 invariant 14: measured figures only, and never a speed claim.
		foreach ( array( 'faster', 'slower', 'speed up' ) as $claim ) {
			$this->assertStringNotContainsString( $claim, strtolower( $html ) );
		}

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * The report shows the measurements the apply actually recorded.
	 *
	 * The assertion that was missing. Every report on a live site said
	 * "Nothing was measured for this change" — including for applies that had
	 * measured plenty — because the report read
	 * `payload['measurements']` as `{ label: { before, after } }`, a shape
	 * nothing has ever written. What `ApplyManager` stores is
	 * `Comparison::toArray()`: `{ before, after, deltas, changed, unknown }`.
	 *
	 * The old test only ever asked whether the report escaped what it printed,
	 * which it did — of an empty table. Asserting on the *content* is what
	 * catches a reader pointed at the wrong shape.
	 *
	 * @return void
	 */
	public function test_the_report_shows_what_was_measured(): void {
		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$result = $this->plugin->apply( $preview->plan );
		$run    = $this->plugin->runs()->find( $result->run_id );

		$this->assertNotNull( $run );

		$measured = $run->payload['measurements'] ?? array();

		// The producer's shape, named here so a change to it fails this test
		// rather than quietly emptying the report again.
		$this->assertIsArray( $measured );
		$this->assertArrayHasKey( 'deltas', $measured, 'ApplyManager stores Comparison::toArray()' );
		$this->assertNotEmpty( $measured['deltas'], 'this apply should have measured something' );

		$html = $this->pro->renderReport( $result->run_id );

		$this->assertStringNotContainsString(
			'Nothing was measured',
			$html,
			'The report must show the measurements the run recorded.'
		);

		// Every metric it measured appears by name.
		foreach ( $measured['deltas'] as $delta ) {
			$this->assertStringContainsString( (string) $delta['metric'], $html );
		}

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * A run that applied nothing is not offered a report.
	 *
	 * An aborted run changed nothing and has nothing to compare. Listing one
	 * is how a client ends up reading a page about a change that never
	 * happened.
	 *
	 * @return void
	 */
	public function test_only_runs_that_changed_something_are_listed(): void {
		$this->plugin->scan();

		$preview = $this->plugin->previewTweaks( array( 'core.remove_generator' ) );

		$this->assertNotNull( $preview );

		$applied = $this->plugin->apply( $preview->plan );

		$listed = array_map(
			static fn ( $run ): int => (int) $run->id,
			$this->pro->appliedRuns( 20 )
		);

		$this->assertContains( $applied->run_id, $listed );

		foreach ( $this->pro->appliedRuns( 20 ) as $run ) {
			$this->assertNotSame(
				'ABORTED',
				$run->status,
				'An aborted run applied nothing and must not be offered a report.'
			);
			$this->assertNotSame( 'ROLLED_BACK', $run->status );
		}

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * A report for a run that does not exist is nothing, not a broken page.
	 *
	 * @return void
	 */
	public function test_a_report_for_an_unknown_run_is_empty(): void {
		$this->assertSame( '', $this->pro->renderReport( 999999 ) );
	}

	/**
	 * Pro's screen adds nothing to the free plugin's.
	 *
	 * The property that matters most: a Pro page is not a way around the rule
	 * that an extension may only put text on Debloater's own screen.
	 *
	 * @return void
	 */
	public function test_pro_puts_no_markup_on_the_free_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->pro->boot();

		$this->plugin->scan();
		$this->plugin->scan();

		$panels = apply_filters( 'debloater_dashboard_panels', array() );

		$this->assertNotEmpty( $panels, 'the drift panel should be there' );

		$encoded = (string) wp_json_encode( $panels );

		$this->assertStringNotContainsString( '<', $encoded );
	}

	/**
	 * Render the screen and capture it.
	 *
	 * @return string
	 */
	private function render(): string {
		$screen = new Screen( $this->pro );

		ob_start();
		$screen->render();

		return (string) ob_get_clean();
	}
}
