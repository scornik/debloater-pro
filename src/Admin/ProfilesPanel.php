<?php
/**
 * Profiles, on Pro's own screen.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Admin;

use Debloater\Brand;
use Debloater\Config\Profile;
use Debloater\Config\ProfileStore;
use Debloater\Contracts\ContractViolation;
use Debloater\Pro\Pro;
use Debloater\Security\Capabilities;

/**
 * The panel that replaced the "Saved profile" dropdown (BUILD-SPEC §17 Phase 19c).
 *
 * The dropdown listed three built-in names and remembered which one you meant.
 * It was a placeholder and the phase said so. This lists every profile the site
 * has, built-in and saved, and does the five things somebody running a dozen
 * sites actually wants: apply one, export it, copy it, rename it, delete it.
 *
 * ## Apply does not apply
 *
 * It sends the browser to Debloater's own screen with the profile named in the
 * URL, and that screen opens its ordinary preview with those changes ticked.
 * Everything after that is the free plugin's usual path: the plan, the
 * confirmation token issued for that exact plan, the recovery point, the
 * verification and the rollback (§13 rule 8, docs/DECISIONS.md D-0063).
 *
 * So there is no apply code here, no token, and no call into the engine. That
 * is worth saying plainly, because it is the reason a Pro feature cannot become
 * a way around a free safety mechanism: there is nothing here to go around one
 * with. The URL this builds is a request to *show* something, and a preview
 * somebody has to read and confirm is not a shortcut past a preview.
 *
 * ## Never empty
 *
 * Built-ins are listed on a site that has saved nothing, which is the state
 * everyone sees first. A panel that stayed blank until you had already used it
 * teaches you the feature is blank.
 *
 * ## Built-ins are not editable here
 *
 * Rename and Delete appear only for a site's own profiles. `ProfileStore`
 * refuses both for a built-in regardless of what is posted, which is what makes
 * the missing buttons a courtesy rather than the enforcement.
 */
final class ProfilesPanel {

	/**
	 * The admin-post action this panel handles.
	 */
	public const ACTION = 'debloater_pro_profiles';

	/**
	 * The query argument Debloater's screen reads to preselect a profile.
	 *
	 * A contract with the other plugin, documented on its side in
	 * `docs/HOOKS.md`. It carries an id and nothing else: the free screen looks
	 * that id up in its own store, so a URL somebody edited can ask for a
	 * profile that does not exist and get nothing — never a selection of its
	 * own choosing.
	 */
	public const PRESELECT = 'debloater_profile';

	/**
	 * The entitlement this panel needs.
	 *
	 * Was `bulk_apply`, when Pro had a class that could apply a profile itself.
	 * That class is gone (D-0068) and the capability it was named for was never
	 * the point: what Pro sells here is a profile that travels between sites,
	 * and the applying belongs to Debloater.
	 */
	public const FEATURE = 'portable_profiles';

	/**
	 * Pro.
	 *
	 * @var Pro
	 */
	private Pro $pro;

	/**
	 * Constructor.
	 *
	 * @param Pro $pro Pro.
	 */
	public function __construct( Pro $pro ) {
		$this->pro = $pro;
	}

	/**
	 * Hook the handler.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Draw the panel.
	 *
	 * @return void
	 */
	public function render(): void {
		$store = $this->pro->profileStore();

		printf( '<h2>%s</h2>', esc_html__( 'Profiles', 'debloater-pro' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'A named set of changes. Applying one opens Debloater\'s preview with those changes ticked — the recovery point, the checks afterwards and the way back are the same ones every other change gets.',
				'debloater-pro'
			)
		);

		echo '<table class="widefat striped debloater-pro-profiles"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Profile', 'debloater-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Type', 'debloater-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'What it applies', 'debloater-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Actions', 'debloater-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $store->all() as $entry ) {
			$this->renderRow( $entry['id'], $entry['profile'], $entry['builtin'] );
		}

		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: profiles this site has saved, 2: the most it keeps. */
					__( '%1$d of %2$d saved profiles used. Built-in ones do not count towards that.', 'debloater-pro' ),
					$store->count(),
					ProfileStore::MAX
				)
			)
		);
	}

	/**
	 * One row.
	 *
	 * @param string  $id      Profile id.
	 * @param Profile $profile The profile.
	 * @param bool    $builtin Whether it came from the registry.
	 * @return void
	 */
	private function renderRow( string $id, Profile $profile, bool $builtin ): void {
		echo '<tr><td>';
		echo esc_html( $profile->name );
		echo '</td><td>';

		// Its own cell, not a badge appended to the name.
		//
		// It was `<span class="description">built in</span>` after the name,
		// with a space in the markup -- and it read as "Maximumbuilt in" on the
		// screen, because WordPress gives `.description` block display inside a
		// table and the line break between them is not a space. The markup was
		// right and the rendering was wrong, which is the sort of thing only
		// looking at the page catches.
		echo esc_html(
			$builtin
				? __( 'Built in', 'debloater-pro' )
				: __( 'Saved here', 'debloater-pro' )
		);

		echo '</td><td>';
		echo esc_html( $this->applies( $id, $profile, $builtin ) );
		echo '</td><td>';

		$this->button( $id, 'apply', __( 'Apply', 'debloater-pro' ) );
		$this->button( $id, 'export', __( 'Export', 'debloater-pro' ) );
		$this->duplicateForm( $id, $profile->name );

		if ( ! $builtin ) {
			$this->renameForm( $id, $profile->name );
			$this->button(
				$id,
				'delete',
				__( 'Delete', 'debloater-pro' ),
				__( 'Delete this profile? Any changes it names stay applied to the site; only the saved list loses it.', 'debloater-pro' )
			);
		}

		echo '</td></tr>';
	}

	/**
	 * What a profile actually applies, in words.
	 *
	 * A saved profile is a fixed list, so the number of changes is the whole
	 * answer.
	 *
	 * A built-in one is not. `safe`, `performance` and `maximum` are defined by
	 * the risk bands they admit rather than by naming tweaks -- every one of
	 * them carries `tweaks: []` -- so what they apply depends on what the scan
	 * found on *that* site. Their count is genuinely zero, and printing `0`
	 * under a column headed "Changes" said they did nothing.
	 *
	 * So the column says what it applies, and for a built-in that is the bands.
	 *
	 * @param string  $id      Profile id.
	 * @param Profile $profile The profile.
	 * @param bool    $builtin Whether it came from the registry.
	 * @return string
	 */
	private function applies( string $id, Profile $profile, bool $builtin ): string {
		if ( ! $builtin ) {
			return sprintf(
				/* translators: %d: how many changes the profile names. */
				_n( '%d change', '%d changes', $profile->count(), 'debloater-pro' ),
				$profile->count()
			);
		}

		$definition = $this->pro->profiles()[ $id ] ?? null;
		$bands      = array();

		if ( $definition && property_exists( $definition, 'include_risk' ) ) {
			foreach ( $definition->include_risk as $risk ) {
				$bands[] = is_object( $risk ) && property_exists( $risk, 'value' )
					? (string) $risk->value
					: (string) $risk;
			}
		}

		if ( array() === $bands ) {
			// A registry profile this cannot read the bands of. Saying so beats
			// printing a zero that reads as "does nothing".
			return __( 'whatever the scan finds', 'debloater-pro' );
		}

		return sprintf(
			/* translators: %s: a comma-separated list of risk levels, such as "safe, low". */
			__( 'whatever the scan finds at risk: %s', 'debloater-pro' ),
			implode( ', ', $bands )
		);
	}

	/**
	 * A form whose only control is its button.
	 *
	 * @param string $id      Profile id.
	 * @param string $action  What to do.
	 * @param string $label   Button text.
	 * @param string $confirm Question to ask before submitting, or ''.
	 * @return void
	 */
	private function button( string $id, string $action, string $label, string $confirm = '' ): void {
		$this->open( $confirm );

		$this->fields( $id, $action );

		printf( '<button type="submit" class="button-link">%s</button>', esc_html( $label ) );

		echo '</form>';
	}

	/**
	 * Rename, with the name editable in the row.
	 *
	 * @param string $id   Profile id.
	 * @param string $name Current name.
	 * @return void
	 */
	private function renameForm( string $id, string $name ): void {
		$this->open( '' );

		$this->fields( $id, 'rename' );

		printf(
			'<input type="text" name="name" value="%1$s" maxlength="%2$d" size="16" aria-label="%3$s" />',
			esc_attr( $name ),
			(int) Profile::MAX_NAME,
			esc_attr__( 'New name for this profile', 'debloater-pro' )
		);

		printf( '<button type="submit" class="button-link">%s</button>', esc_html__( 'Rename', 'debloater-pro' ) );

		echo '</form>';
	}

	/**
	 * Duplicate, under a name derived from this one.
	 *
	 * The copy's name is proposed rather than asked for. Duplicating is what
	 * somebody does *before* editing a built-in, and stopping to name the copy
	 * first asks a question at the least useful moment — Rename is right there
	 * on the new row.
	 *
	 * @param string $id   Profile id.
	 * @param string $name Current name.
	 * @return void
	 */
	private function duplicateForm( string $id, string $name ): void {
		$this->open( '' );

		$this->fields( $id, 'duplicate' );

		printf(
			'<input type="hidden" name="name" value="%s" />',
			esc_attr( self::copyName( $name ) )
		);

		printf( '<button type="submit" class="button-link">%s</button>', esc_html__( 'Duplicate', 'debloater-pro' ) );

		echo '</form>';
	}

	/**
	 * What a copy of a profile is called.
	 *
	 * Trimmed to the length a name may be, because "(copy)" on an eighty
	 * character name is eighty-seven characters and `Profile` refuses it. A
	 * button that threw for long names would be a button that worked in
	 * testing.
	 *
	 * @param string $name The original name.
	 * @return string
	 */
	public static function copyName( string $name ): string {
		$copy = sprintf(
			/* translators: %s: the name of the profile being copied. */
			__( '%s (copy)', 'debloater-pro' ),
			$name
		);

		if ( mb_strlen( $copy ) <= Profile::MAX_NAME ) {
			return $copy;
		}

		return mb_substr( $copy, 0, Profile::MAX_NAME );
	}

	/**
	 * Open one of the row's little forms.
	 *
	 * @param string $confirm Question to ask first, or '' to just submit.
	 * @return void
	 */
	private function open( string $confirm ): void {
		printf(
			'<form method="post" action="%1$s" class="debloater-pro-profiles__action" style="display:inline"%2$s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			'' === $confirm
				? ''
				// A browser confirm, and a courtesy rather than a safety
				// mechanism — the safety is that deleting a profile changes
				// nothing about the site, which is what the question says.
				: sprintf( ' onsubmit="return confirm( \'%s\' );"', esc_js( $confirm ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js(), which is the escaping a JavaScript string literal takes.
		);
	}

	/**
	 * The hidden fields every one of those forms needs.
	 *
	 * The nonce names the action *and* the operation, so a nonce minted for
	 * "export this profile" is not one that deletes it (§13 rule 2).
	 *
	 * @param string $id     Profile id.
	 * @param string $action What to do.
	 * @return void
	 */
	private function fields( string $id, string $action ): void {
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION ) );
		printf( '<input type="hidden" name="profile" value="%s" />', esc_attr( $id ) );
		printf( '<input type="hidden" name="do" value="%s" />', esc_attr( $action ) );

		wp_nonce_field( self::ACTION . '_' . $action );
	}

	/**
	 * Do what the form asked, then send the browser somewhere.
	 *
	 * The deciding is in `perform()` and the leaving is here, which is what
	 * makes any of this testable: a method that ends in `exit` can be asserted
	 * about only by a test willing to run a whole HTTP request.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! Capabilities::currentUserCanManage() ) {
			wp_die( esc_html__( 'You do not have permission to manage Debloater on this site.', 'debloater-pro' ) );
		}

		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		// Before anything is read, and against a nonce that names the
		// operation, so a request that arrived without one never reaches the
		// store at all.
		check_admin_referer( self::ACTION . '_' . $do );

		$id   = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		$profile = $this->pro->profileStore()->find( $id );

		if ( 'export' === $do && null !== $profile ) {
			$this->send( $profile );
		}

		if ( 'apply' === $do && null !== $profile ) {
			$this->leave( $this->previewUrl( $id ) );
		}

		$this->leave( $this->noticeUrl( $this->perform( $do, $id, $name ) ) );
	}

	/**
	 * Carry out one operation, and say in a word what happened.
	 *
	 * Public because it is the part worth testing: everything else in the round
	 * trip is a nonce, a header and a redirect.
	 *
	 * @param string $operation Which operation.
	 * @param string $id        Profile id.
	 * @param string $name      A name, for the two operations that take one.
	 * @return string A word for `Screen`'s notice.
	 */
	public function perform( string $operation, string $id, string $name ): string {
		$store   = $this->pro->profileStore();
		$profile = $store->find( $id );

		if ( null === $profile ) {
			return 'profile-missing';
		}

		try {
			switch ( $operation ) {
				case 'duplicate':
					$store->save( $profile->renamed( '' === $name ? self::copyName( $profile->name ) : $name ) );

					return 'profile-duplicated';

				case 'rename':
					if ( '' === $name ) {
						return 'profile-unnamed';
					}

					$store->save( $profile->renamed( $name ), $id );

					return 'profile-renamed';

				case 'delete':
					$store->delete( $id );

					return 'profile-deleted';
			}
		} catch ( ContractViolation $error ) {
			// The store throws when a site is already full and when a built-in
			// is being edited. Both are ordinary answers to an ordinary
			// request, and letting either out as a PHP error would turn "you
			// already have fifty" into a white screen.
			unset( $error );

			return 'profile-refused';
		}

		// 'apply' and 'export' are handled before this and never arrive here;
		// anything else is a request nobody's browser sent.
		return 'profile-unknown-action';
	}

	/**
	 * Where Apply sends the browser.
	 *
	 * @param string $id Profile id.
	 * @return string
	 */
	public function previewUrl( string $id ): string {
		return add_query_arg(
			array(
				'page'          => Brand::MENU_SLUG,
				self::PRESELECT => rawurlencode( $id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Where everything else sends it.
	 *
	 * @param string $result What happened.
	 * @return string
	 */
	private function noticeUrl( string $result ): string {
		return add_query_arg(
			array(
				'page'   => Screen::SLUG,
				'notice' => $result,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Send a profile as a file.
	 *
	 * The bytes are the profile's own encoding, so the file this writes, the
	 * one Debloater's screen downloads and the one `wp debloater profile
	 * export` produces are the same file.
	 *
	 * @param Profile $profile The profile.
	 * @return void
	 */
	private function send( Profile $profile ): void {
		nocache_headers();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::fileName( $profile ) . '"' );

		// JSON, encoded by the class that owns the format. Escaping it here
		// would corrupt the document being sent.
		echo ProfileStore::export( $profile ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON file download, encoded by Profile::toJson().

		exit;
	}

	/**
	 * What an exported profile is called on disk.
	 *
	 * @param Profile $profile The profile.
	 * @return string
	 */
	public static function fileName( Profile $profile ): string {
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $profile->name ) ), '-' );

		return ( '' === $slug ? 'profile' : $slug ) . '.json';
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Where to.
	 * @return void
	 */
	private function leave( string $url ): void {
		wp_safe_redirect( $url );

		exit;
	}
}
