<?php
/**
 * Pro's own admin screen.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Admin;

use Debloater\Brand;
use Debloater\Pro\Features\BeforeAfterReport;
use Debloater\Pro\Features\BulkApply;
use Debloater\Pro\Features\ScheduledScans;
use Debloater\Pro\Pro;
use Debloater\Security\Capabilities;

/**
 * A submenu page under Debloater, and everything Pro can be told to do.
 *
 * Its own screen rather than tabs bolted onto the free plugin's. The free
 * plugin exposes a filter that accepts *text* for its dashboard and nothing
 * more (`docs/HOOKS.md`), which is exactly right: an extension should not be
 * able to put controls on somebody else's page. So Pro renders its own, and is
 * responsible for its own escaping and its own nonces.
 *
 * Plain PHP and a form post rather than React. The free plugin's screen is an
 * application — findings to browse, a plan to read, a run to watch. This is
 * four settings and two buttons, and a build step for four settings is a build
 * step somebody has to maintain forever.
 *
 * Everything here is behind the same capability as the free plugin, and every
 * post is nonce-checked (BUILD-SPEC §13 rules 1 and 2). Applying goes through
 * `BulkApply`, which goes through the free plugin's own preview and apply, so
 * the recovery point, the verification and the automatic rollback all happen
 * exactly as they do everywhere else.
 */
final class Screen {

	/**
	 * The submenu slug.
	 */
	public const SLUG = 'debloater-pro';

	/**
	 * The action name for this screen's form posts.
	 */
	private const ACTION = 'debloater_pro_save';

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
	 * Hook the menu and the post handler.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ), 11 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handlePost' ) );
	}

	/**
	 * Add the submenu.
	 *
	 * Priority 11 so the free plugin's menu exists first. If it does not — Pro
	 * active without Debloater, which `Requires Plugins` is meant to prevent —
	 * `add_submenu_page` returns false and nothing else happens.
	 *
	 * @return void
	 */
	public function registerMenu(): void {
		add_submenu_page(
			Brand::MENU_SLUG,
			__( 'Debloater Pro', 'debloater-pro' ),
			__( 'Pro', 'debloater-pro' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle a form post, then send the browser back to the screen.
	 *
	 * @return void
	 */
	public function handlePost(): void {
		if ( ! Capabilities::currentUserCanManage() ) {
			wp_die( esc_html__( 'You do not have permission to manage Debloater on this site.', 'debloater-pro' ) );
		}

		check_admin_referer( self::ACTION );

		$entitlement = $this->pro->entitlement()->entitlement();
		$notice      = 'saved';

		$frequency = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : '';

		if ( $entitlement->allows( ScheduledScans::FEATURE ) ) {
			$this->pro->scans()->setFrequency( $frequency );
		}

		if ( $entitlement->allows( BeforeAfterReport::FEATURE ) ) {
			$branding = isset( $_POST['branding'] ) ? sanitize_text_field( wp_unslash( $_POST['branding'] ) ) : '';

			$this->pro->report()->setBranding( $branding );
		}

		if ( $entitlement->allows( BulkApply::FEATURE ) ) {
			$profile = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';

			if ( ! $this->pro->bulk()->save( $profile ) ) {
				$notice = 'unknown-profile';
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					'notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! Capabilities::currentUserCanManage() ) {
			wp_die( esc_html__( 'You do not have permission to manage Debloater on this site.', 'debloater-pro' ) );
		}

		// A report is a whole document — its own <html>, its own print CSS —
		// so it is served instead of the screen rather than inside it. Printed
		// from the browser, which is why there is no PDF library
		// (docs/DECISIONS.md D-0049).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a run id to display; it changes nothing, and the capability check above is what guards it.
		$report = isset( $_GET['report'] ) ? absint( wp_unslash( $_GET['report'] ) ) : 0;

		if ( 0 !== $report ) {
			$html = $this->pro->renderReport( $report );

			if ( '' === $html ) {
				wp_die( esc_html__( 'There is no report for that change.', 'debloater-pro' ) );
			}

			// Built entirely by BeforeAfterReport, which escapes every value it
			// puts in. Echoed whole because it is a document rather than a
			// fragment of this page.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled and escaped field by field in BeforeAfterReport::render().

			exit;
		}

		$entitlement = $this->pro->entitlement()->entitlement();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Debloater Pro', 'debloater-pro' ) );

		$this->renderNotice();

		if ( $entitlement->isEmpty() ) {
			$this->renderUnentitled();

			echo '</div>';

			return;
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Workflow for people who manage several sites. Nothing here changes what Debloater does to a site — the recovery points, the verification and the automatic rollback are the same ones the free plugin uses.',
				'debloater-pro'
			)
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION ) );

		wp_nonce_field( self::ACTION );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->renderSchedule( $entitlement );
		$this->renderProfile( $entitlement );
		$this->renderBranding( $entitlement );

		echo '</tbody></table>';

		submit_button( __( 'Save', 'debloater-pro' ) );

		echo '</form>';

		$this->renderDrift( $entitlement );
		$this->renderReports( $entitlement );

		echo '</div>';
	}

	/**
	 * What to say when nothing is unlocked.
	 *
	 * Says which provider answered, because "no licence" and "the licensing
	 * platform is not installed" are different problems with the same symptom,
	 * and a support conversation that starts with the right one is shorter.
	 *
	 * @return void
	 */
	private function renderUnentitled(): void {
		printf(
			'<div class="notice notice-info inline"><p>%s</p><p><code>%s</code></p></div>',
			esc_html__(
				'No Pro features are unlocked on this site. Debloater itself is unaffected and keeps working exactly as it does without Pro.',
				'debloater-pro'
			),
			esc_html(
				sprintf(
					/* translators: %s: the name of the entitlement provider that answered. */
					__( 'Entitlement source: %s', 'debloater-pro' ),
					$this->pro->entitlement()->name()
				)
			)
		);
	}

	/**
	 * The scan schedule.
	 *
	 * @param \Debloater\Pro\Entitlement\Entitlement $entitlement What is unlocked.
	 * @return void
	 */
	private function renderSchedule( $entitlement ): void {
		if ( ! $entitlement->allows( ScheduledScans::FEATURE ) ) {
			return;
		}

		$current = $this->pro->scans()->frequency();
		$next    = wp_next_scheduled( ScheduledScans::HOOK );

		$options = array(
			''       => __( 'Off', 'debloater-pro' ),
			'daily'  => __( 'Every day', 'debloater-pro' ),
			'weekly' => __( 'Every week', 'debloater-pro' ),
		);

		echo '<tr><th scope="row"><label for="debloater-pro-schedule">';
		esc_html_e( 'Scan on a schedule', 'debloater-pro' );
		echo '</label></th><td>';

		echo '<select name="schedule" id="debloater-pro-schedule">';

		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'A scan reads and records; it changes nothing. Applying on a schedule is deliberately not offered — a change nobody is watching is the thing this plugin is arranged to avoid.',
				'debloater-pro'
			)
		);

		if ( false !== $next ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: a date and time. */
						__( 'Next scan: %s', 'debloater-pro' ),
						wp_date( 'j M Y, H:i', (int) $next )
					)
				)
			);
		}

		echo '</td></tr>';
	}

	/**
	 * The saved profile for bulk apply.
	 *
	 * @param \Debloater\Pro\Entitlement\Entitlement $entitlement What is unlocked.
	 * @return void
	 */
	private function renderProfile( $entitlement ): void {
		if ( ! $entitlement->allows( BulkApply::FEATURE ) ) {
			return;
		}

		$saved = $this->pro->bulk()->saved();

		echo '<tr><th scope="row"><label for="debloater-pro-profile">';
		esc_html_e( 'Saved profile', 'debloater-pro' );
		echo '</label></th><td>';

		echo '<select name="profile" id="debloater-pro-profile">';

		printf( '<option value="">%s</option>', esc_html__( 'None', 'debloater-pro' ) );

		foreach ( array_keys( $this->pro->profiles() ) as $profile ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $profile ),
				selected( $saved, $profile, false ),
				esc_html( ucfirst( (string) $profile ) )
			);
		}

		echo '</select>';

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Applying it still goes through the preview and the confirmation on the Debloater screen. This only remembers which one you meant.',
				'debloater-pro'
			)
		);

		echo '</td></tr>';
	}

	/**
	 * The name on the report.
	 *
	 * @param \Debloater\Pro\Entitlement\Entitlement $entitlement What is unlocked.
	 * @return void
	 */
	private function renderBranding( $entitlement ): void {
		if ( ! $entitlement->allows( BeforeAfterReport::FEATURE ) ) {
			return;
		}

		echo '<tr><th scope="row"><label for="debloater-pro-branding">';
		esc_html_e( 'Name on reports', 'debloater-pro' );
		echo '</label></th><td>';

		printf(
			'<input type="text" class="regular-text" name="branding" id="debloater-pro-branding" value="%s" />',
			esc_attr( $this->pro->report()->branding() )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'Your agency name, for the printable before/after report. It replaces ours on the page. It does not change any of the numbers.',
				'debloater-pro'
			)
		);

		echo '</td></tr>';
	}

	/**
	 * What changed since the last scan.
	 *
	 * @param \Debloater\Pro\Entitlement\Entitlement $entitlement What is unlocked.
	 * @return void
	 */
	private function renderDrift( $entitlement ): void {
		if ( ! $entitlement->allows( \Debloater\Pro\Features\DriftDetector::FEATURE ) ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'What changed since the last scan', 'debloater-pro' ) );

		$report = $this->pro->drift()->latest();

		if ( null === $report ) {
			printf(
				'<p>%s</p>',
				esc_html__(
					'There is only one scan to go on. Drift needs two, and saying "nothing has changed" on the strength of one would be an invented reassurance.',
					'debloater-pro'
				)
			);

			return;
		}

		printf( '<p><strong>%s</strong></p>', esc_html( $report->summary() ) );

		$rows = $report->rows();

		if ( array() === $rows ) {
			return;
		}

		echo '<table class="widefat striped"><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td style="width:12rem">%s</td><td>%s</td></tr>',
				esc_html( $row['label'] ),
				esc_html( $row['value'] )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * A printable report per apply.
	 *
	 * @param \Debloater\Pro\Entitlement\Entitlement $entitlement What is unlocked.
	 * @return void
	 */
	private function renderReports( $entitlement ): void {
		if ( ! $entitlement->allows( BeforeAfterReport::FEATURE ) ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Before and after', 'debloater-pro' ) );

		$runs = $this->pro->appliedRuns( 10 );

		if ( array() === $runs ) {
			printf(
				'<p>%s</p>',
				esc_html__(
					'Nothing has been applied yet, so there is nothing to compare.',
					'debloater-pro'
				)
			);

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Change', 'debloater-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'When', 'debloater-pro' ) );
		printf( '<th>%s</th>', esc_html__( 'Report', 'debloater-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( $runs as $run ) {
			$url = add_query_arg(
				array(
					'page'   => self::SLUG,
					'report' => (int) $run->id,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			printf( '<td>#%d</td>', (int) $run->id );
			printf( '<td>%s</td>', esc_html( $run->started_at ) );
			printf(
				'<td><a href="%s" target="_blank" rel="noopener">%s</a></td>',
				esc_url( $url ),
				esc_html__( 'Open', 'debloater-pro' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Whatever the last post left to say.
	 *
	 * @return void
	 */
	private function renderNotice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a status word this screen put in its own redirect; it decides nothing.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'saved'           => array( 'success', __( 'Saved.', 'debloater-pro' ) ),
			'unknown-profile' => array(
				'error',
				__( 'That profile is not one this site knows about, so it was not saved.', 'debloater-pro' ),
			),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}
}
