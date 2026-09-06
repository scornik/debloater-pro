<?php
/**
 * Pro's profiles panel.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Config\Profile;
use Debloater\Config\ProfileStore;
use Debloater\Pro\Admin\ProfilesPanel;
use Debloater\Pro\Admin\Screen;
use Debloater\Pro\Entitlement\FixtureEntitlementProvider;
use Debloater\Pro\Pro;
use Debloater\Recommend\IntentProfile;

/**
 * BUILD-SPEC §13 rules 8 and 15, and §17 Phase 19c.
 *
 * The panel replaced a dropdown that listed three names and stored one of them.
 * Everything below is about the difference between those two things being
 * workflow rather than power: the panel can copy, rename, delete and export a
 * list of changes, and it cannot apply one.
 *
 * The assertion that matters most is the one about Apply, and it is worth
 * saying why it is phrased as it is. "Apply" here builds a URL to Debloater's
 * screen. If somebody later made it convenient by planning and applying on the
 * spot, every safety property the free plugin has — the preview, the token
 * issued for that exact plan, the recovery point, the verification, the
 * rollback — would still exist and would simply have been walked around. So the
 * test asks the panel to apply, and requires it to say it does not know how.
 */
final class ProProfilesPanelTest extends IntegrationTestCase {

	/**
	 * Pro, with everything unlocked.
	 *
	 * @var Pro
	 */
	private Pro $pro;

	/**
	 * The panel under test.
	 *
	 * @var ProfilesPanel
	 */
	private ProfilesPanel $panel;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		$this->pro   = new Pro( $this->plugin, FixtureEntitlementProvider::everything() );
		$this->panel = new ProfilesPanel( $this->pro );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( ProfileStore::OPTION );
		delete_option( 'debloater_pro_saved_profile' );

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * A site that has saved nothing still has profiles to look at.
	 *
	 * The state of every fresh install, and the one the dropdown handled by
	 * listing three names. A panel that was empty until somebody had already
	 * used it would teach them the feature was empty.
	 *
	 * @return void
	 */
	public function test_the_panel_is_never_empty(): void {
		$this->assertSame(
			0,
			$this->pro->profileStore()->count(),
			'this test is about a site with nothing saved'
		);

		$markup = $this->render();

		$this->assertStringContainsString( 'Profiles', $markup );
		$this->assertStringContainsString( 'built in', $markup );

		// Every profile the registry defines is on the page, by name.
		foreach ( $this->pro->profileStore()->builtins() as $profile ) {
			$this->assertStringContainsString( esc_html( $profile->name ), $markup );
		}
	}

	/**
	 * A saved profile is listed beside the built-ins, and is the editable one.
	 *
	 * @return void
	 */
	public function test_only_a_sites_own_profiles_offer_rename_and_delete(): void {
		$this->save( 'Client baseline' );

		$markup = $this->render();

		$this->assertStringContainsString( 'Client baseline', $markup );

		foreach ( array( 'Apply', 'Export', 'Duplicate', 'Rename', 'Delete' ) as $action ) {
			$this->assertStringContainsString( '>' . $action . '</button>', $markup );
		}

		// One Rename and one Delete on a site with one saved profile and three
		// built-in ones: the built-ins offer neither.
		$this->assertSame( 1, substr_count( $markup, '>Rename</button>' ) );
		$this->assertSame( 1, substr_count( $markup, '>Delete</button>' ) );

		$rows = count( $this->pro->profileStore()->all() );

		$this->assertGreaterThan( 1, $rows );
		$this->assertSame( $rows, substr_count( $markup, '>Apply</button>' ) );
	}

	/**
	 * Every form is nonced, and per operation rather than per screen.
	 *
	 * §13 rule 2. A single nonce covering the whole panel would mean the token
	 * that exports a profile is the token that deletes it.
	 *
	 * @return void
	 */
	public function test_each_action_carries_its_own_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->save( 'Client baseline' );

		$markup = $this->render();
		$seen   = array();

		foreach ( array( 'apply', 'export', 'duplicate', 'rename', 'delete' ) as $action ) {
			$nonce = wp_create_nonce( ProfilesPanel::ACTION . '_' . $action );

			$this->assertStringContainsString(
				'value="' . $nonce . '"',
				$markup,
				sprintf( 'the %s form should carry its own nonce', $action )
			);

			$seen[] = $nonce;
		}

		$this->assertSame( $seen, array_unique( $seen ), 'the five nonces must differ' );
	}

	/**
	 * Apply is a link to Debloater's preview, and the panel cannot apply.
	 *
	 * @return void
	 */
	public function test_apply_leads_to_the_preview_and_applies_nothing(): void {
		$this->save( 'Client baseline' );

		$url = $this->panel->previewUrl( 'client-baseline' );

		// The literal, not the constant. This name is a contract with the other
		// plugin: `admin-ui/src/components/Profiles.js` has the same string on
		// its side and Debloater's docs/HOOKS.md documents it. Written as
		// `ProfilesPanel::PRESELECT` this assertion would agree with whatever
		// the constant was renamed to, which is the one thing it must not do.
		$this->assertSame( 'debloater_profile', ProfilesPanel::PRESELECT );

		$this->assertStringContainsString( 'page=debloater', $url );
		$this->assertStringContainsString( 'debloater_profile=client-baseline', $url );

		// It goes to this site's own admin and nowhere else.
		$this->assertStringStartsWith( admin_url( 'admin.php' ), $url );

		// And the panel has no operation that applies. Asked to, it says it
		// does not know how — which is the property, not a limitation.
		$this->assertSame(
			'profile-unknown-action',
			$this->panel->perform( 'apply', 'client-baseline', '' )
		);

		$this->assertNoRunsWereStarted();
	}

	/**
	 * Duplicating copies, and leaves the original alone.
	 *
	 * @return void
	 */
	public function test_duplicate_copies_without_touching_the_original(): void {
		$this->save( 'Client baseline' );

		$this->assertSame(
			'profile-duplicated',
			$this->panel->perform( 'duplicate', 'client-baseline', 'Client baseline (copy)' )
		);

		$saved = $this->pro->profileStore()->saved();

		$this->assertCount( 2, $saved );
		$this->assertSame( 'Client baseline', $saved['client-baseline']->name );

		$names = array_map( static fn ( Profile $one ): string => $one->name, array_values( $saved ) );

		$this->assertContains( 'Client baseline (copy)', $names );

		// The copy holds the same changes; duplicating is a copy, not a new
		// empty profile with a similar name. Found by name rather than by id,
		// because the id a store derives from a name is the store's business
		// and asserting its exact shape here would tie this test to a rule it
		// is not about.
		$copy = null;

		foreach ( $saved as $id => $one ) {
			if ( 'client-baseline' !== $id ) {
				$copy = $one;
			}
		}

		$this->assertNotNull( $copy );
		$this->assertSame( 'Client baseline (copy)', $copy->name );
		$this->assertSame(
			array_keys( $saved['client-baseline']->selection ),
			array_keys( $copy->selection )
		);
	}

	/**
	 * A built-in can be copied, and the copy is the site's own.
	 *
	 * This is the whole reason Duplicate is offered on a row that cannot be
	 * renamed: it is how somebody starts from `safe` and makes it theirs.
	 *
	 * @return void
	 */
	public function test_a_builtin_can_be_copied_even_though_it_cannot_be_edited(): void {
		$this->assertSame( 'profile-duplicated', $this->panel->perform( 'duplicate', 'safe', 'Safe (copy)' ) );

		$saved = $this->pro->profileStore()->saved();

		$this->assertCount( 1, $saved );
		$this->assertFalse( $this->pro->profileStore()->isBuiltin( (string) array_key_first( $saved ) ) );
	}

	/**
	 * Renaming a saved profile renames it in place.
	 *
	 * @return void
	 */
	public function test_rename_keeps_the_profile_and_its_changes(): void {
		$this->save( 'Client baseline' );

		$before = $this->pro->profileStore()->find( 'client-baseline' );

		$this->assertNotNull( $before );

		$this->assertSame(
			'profile-renamed',
			$this->panel->perform( 'rename', 'client-baseline', 'Agency default' )
		);

		$after = $this->pro->profileStore()->find( 'client-baseline' );

		$this->assertNotNull( $after );
		$this->assertSame( 'Agency default', $after->name );
		$this->assertSame( array_keys( $before->selection ), array_keys( $after->selection ) );
		$this->assertCount( 1, $this->pro->profileStore()->saved() );
	}

	/**
	 * An empty name is refused rather than stored.
	 *
	 * @return void
	 */
	public function test_a_profile_cannot_be_renamed_to_nothing(): void {
		$this->save( 'Client baseline' );

		$this->assertSame( 'profile-unnamed', $this->panel->perform( 'rename', 'client-baseline', '' ) );

		$profile = $this->pro->profileStore()->find( 'client-baseline' );

		$this->assertNotNull( $profile );
		$this->assertSame( 'Client baseline', $profile->name );
	}

	/**
	 * Deleting removes the profile and changes nothing about the site.
	 *
	 * @return void
	 */
	public function test_delete_removes_the_profile_and_nothing_else(): void {
		$this->save( 'Client baseline' );

		$this->assertSame( 'profile-deleted', $this->panel->perform( 'delete', 'client-baseline', '' ) );

		$this->assertNull( $this->pro->profileStore()->find( 'client-baseline' ) );
		$this->assertSame( 0, $this->pro->profileStore()->count() );

		// A profile is a list of changes, not the changes. Deleting one applies
		// nothing and undoes nothing.
		$this->assertNoRunsWereStarted();
	}

	/**
	 * The profiles Debloater ships with cannot be renamed or deleted here.
	 *
	 * The buttons are not rendered for them, and this is the assertion that the
	 * missing buttons are a courtesy rather than the enforcement: posting the
	 * operation anyway is refused.
	 *
	 * @return void
	 */
	public function test_a_builtin_cannot_be_renamed_or_deleted(): void {
		foreach ( array( 'rename', 'delete' ) as $operation ) {
			$this->assertSame(
				'profile-refused',
				$this->panel->perform( $operation, 'safe', 'Mine now' ),
				sprintf( 'a built-in must refuse %s however it is asked', $operation )
			);
		}

		$safe = $this->pro->profileStore()->find( 'safe' );

		$this->assertNotNull( $safe );
		$this->assertNotSame( 'Mine now', $safe->name );
	}

	/**
	 * A profile that is not there is said to be not there.
	 *
	 * @return void
	 */
	public function test_an_unknown_profile_does_nothing(): void {
		foreach ( array( 'duplicate', 'rename', 'delete' ) as $operation ) {
			$this->assertSame(
				'profile-missing',
				$this->panel->perform( $operation, 'no-such-profile', 'Anything' )
			);
		}

		$this->assertSame( 0, $this->pro->profileStore()->count() );
	}

	/**
	 * A site that is full says so instead of failing.
	 *
	 * @return void
	 */
	public function test_a_full_site_is_told_rather_than_broken(): void {
		$store    = $this->pro->profileStore();
		$registry = $this->plugin->registry();

		for ( $n = 1; $n <= ProfileStore::MAX; $n++ ) {
			$store->save(
				new Profile(
					'Profile ' . $n,
					array( 'core.remove_rsd' => array() ),
					new IntentProfile(),
					$registry->hash(),
					'2026-01-01T00:00:00Z'
				)
			);
		}

		$this->assertSame( ProfileStore::MAX, $store->count() );

		$id = (string) array_key_first( $store->saved() );

		$this->assertSame( 'profile-refused', $this->panel->perform( 'duplicate', $id, 'One too many' ) );
		$this->assertSame( ProfileStore::MAX, $this->pro->profileStore()->count() );
	}

	/**
	 * The file a profile exports as is the same file everywhere.
	 *
	 * Pro's Export, Debloater's Export and `wp debloater profile export` all
	 * hand over `Profile::toJson()`. Three encoders would be three files that
	 * differ in whitespace and key order, and the drift would start the first
	 * time any of them changed.
	 *
	 * @return void
	 */
	public function test_the_exported_file_is_the_profiles_own_encoding(): void {
		$this->save( 'Client baseline' );

		$profile = $this->pro->profileStore()->find( 'client-baseline' );

		$this->assertNotNull( $profile );
		$this->assertSame( ProfileStore::export( $profile ), $profile->toJson() );
		$this->assertSame( 'client-baseline.json', ProfilesPanel::fileName( $profile ) );
	}

	/**
	 * A profile's name is somebody's text, and reaches the page escaped.
	 *
	 * @return void
	 */
	public function test_a_name_cannot_carry_markup_onto_the_screen(): void {
		$this->save( '<script>alert(1)</script>' );

		$markup = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $markup );
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
	}

	/**
	 * A name too long to copy is shortened rather than refused.
	 *
	 * @return void
	 */
	public function test_copying_a_long_name_still_produces_a_name(): void {
		$long = str_repeat( 'a', Profile::MAX_NAME );

		$this->assertSame( Profile::MAX_NAME, mb_strlen( ProfilesPanel::copyName( $long ) ) );

		$this->save( $long );

		$this->assertSame(
			'profile-duplicated',
			$this->panel->perform( 'duplicate', (string) array_key_first( $this->pro->profileStore()->saved() ), '' )
		);
	}

	/**
	 * Save a profile with one real change in it.
	 *
	 * @param string $name What to call it.
	 * @return string The id it was stored under.
	 */
	private function save( string $name ): string {
		return $this->pro->profileStore()->save(
			new Profile(
				$name,
				array( 'core.remove_rsd' => array() ),
				new IntentProfile(),
				$this->plugin->registry()->hash(),
				'2026-01-01T00:00:00Z'
			)
		);
	}

	/**
	 * Render the panel and capture it.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->panel->render();

		return (string) ob_get_clean();
	}

	/**
	 * Nothing was planned, applied or rolled back.
	 *
	 * @return void
	 */
	private function assertNoRunsWereStarted(): void {
		$this->assertSame(
			array(),
			$this->plugin->runs()->recent( 5 ),
			'the profiles panel must not start a run.'
		);
	}
}
