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
 * a link to Debloater's own preview, so the recovery point, the verification
 * and the automatic rollback all happen exactly as they do everywhere else.
 * Pro contains no apply path of its own -- see docs/DECISIONS.md D-0068.
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
	 * The action name for serving a report.
	 */
	private const REPORT = 'debloater_pro_report';

	/**
	 * Pro.
	 *
	 * @var Pro
	 */
	private Pro $pro;

	/**
	 * The profiles panel, which handles its own posts.
	 *
	 * @var ProfilesPanel
	 */
	private ProfilesPanel $profiles;

	/**
	 * Constructor.
	 *
	 * @param Pro $pro Pro.
	 */
	public function __construct( Pro $pro ) {
		$this->pro      = $pro;
		$this->profiles = new ProfilesPanel( $pro );
	}

	/**
	 * Hook the menu and the post handler.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ), 11 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handlePost' ) );
		add_action( 'admin_post_' . self::REPORT, array( $this, 'serveReport' ) );

		$this->profiles->boot();
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
			Pro::NAME,
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
		$frequency   = isset( $_POST['schedule'] ) ? sanitize_key( wp_unslash( $_POST['schedule'] ) ) : '';

		if ( $entitlement->allows( ScheduledScans::FEATURE ) ) {
			$this->pro->scans()->setFrequency( $frequency );
		}

		if ( $entitlement->allows( BeforeAfterReport::FEATURE ) ) {
			$branding = isset( $_POST['branding'] ) ? sanitize_text_field( wp_unslash( $_POST['branding'] ) ) : '';

			$this->pro->report()->setBranding( $branding );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::SLUG,
					'notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Serve one report as a document of its own.
	 *
	 * Through `admin-post.php` rather than the screen callback, and that is not
	 * a preference. A submenu callback runs *after* WordPress has already
	 * printed the admin header, the sidebar and any notices other plugins have
	 * queued — so echoing a complete `<!doctype html>` document from one puts a
	 * second document inside the first. The first version did exactly that, and
	 * the report came out wrapped in the admin chrome with somebody else's
	 * "activate your licence" notice above the heading.
	 *
	 * `admin-post.php` runs before any of that, which is what a page meant to
	 * be printed needs.
	 *
	 * @return void
	 */
	public function serveReport(): void {
		if ( ! Capabilities::currentUserCanManage() ) {
			wp_die( esc_html__( 'You do not have permission to manage Debloater on this site.', 'debloater-pro' ) );
		}

		check_admin_referer( self::REPORT );

		$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;
		$html   = 0 === $run_id ? '' : $this->pro->renderReport( $run_id );

		if ( '' === $html ) {
			wp_die( esc_html__( 'There is no report for that change.', 'debloater-pro' ) );
		}

		// Assembled and escaped field by field in BeforeAfterReport::render(),
		// and served as the whole response rather than part of a page.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at construction; see BeforeAfterReport::render().

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

		$entitlement = $this->pro->entitlement()->entitlement();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html( Pro::NAME ) );

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
		$this->renderBranding( $entitlement );

		echo '</tbody></table>';

		submit_button( __( 'Save', 'debloater-pro' ) );

		echo '</form>';

		// After the form, and not inside it. Each row of the panel is a form of
		// its own — a rename posts a name, a delete posts nothing else — and a
		// form nested in a form is markup no browser agrees about.
		if ( $entitlement->allows( ProfilesPanel::FEATURE ) ) {
			$this->profiles->render();
		}

		$this->renderDrift( $entitlement );
		$this->renderLicence();
		$this->renderReports( $entitlement );

		echo '</div>';
	}

	/**
	 * What this site's licence covers, on our own screen.
	 *
	 * Not a link to somewhere else. The SDK's Account submenu is always there —
	 * it is forced on, because licence activation and deactivation live on it —
	 * but on a white-labelled licence its contents are stripped: no owner
	 * email, no licence key, no prices, no billing address, no invoices.
	 *
	 * So an agency's client sees an Account item that tells them almost
	 * nothing. A Pro screen whose only answer to "what does this site have" was
	 * a link to that page would be pointing at the one page deliberately
	 * emptied of the answer. Plan, licence state and a way to release the site
	 * are therefore rendered here, and a test asserts this screen carries them
	 * itself rather than delegating.
	 *
	 * Display only. The quota shown is the platform's own count and nothing
	 * here decides anything on it: enforcing a site limit from inside the
	 * plugin would mean enforcing a rule it cannot see the whole of.
	 *
	 * @return void
	 */
	private function renderLicence(): void {
		$provider = $this->pro->entitlement();

		if ( ! $provider instanceof \Debloater\Pro\Entitlement\FreemiusEntitlementProvider
			&& ! method_exists( $provider, 'siteQuota' ) ) {
			return;
		}

		// Asked by name rather than by type. The provider is whatever the
		// installation wired up — the Freemius adapter on a real site, a
		// fixture in development — and only some of them can answer this.
		$quota = method_exists( $provider, 'siteQuota' ) ? $provider->siteQuota() : null;
		$urls  = method_exists( $provider, 'licenceUrls' ) ? $provider->licenceUrls() : array();

		echo '<h2>' . esc_html__( 'Licence', 'debloater-pro' ) . '</h2>';

		if ( ! is_array( $quota ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__(
					'This site could not read its licence just now. Pro keeps working on what it last knew, and nothing about your site changes while that is true.',
					'debloater-pro'
				)
			);

			return;
		}

		$limit = $quota['limit'] ?? null;
		$used  = $quota['used'] ?? null;

		printf(
			'<p>%s</p>',
			esc_html(
				null === $limit
					? sprintf(
						/* translators: %s: number of sites, or "not known". */
						__( 'Unlimited sites. In use on %s.', 'debloater-pro' ),
						null === $used ? __( 'a number this site cannot read', 'debloater-pro' ) : (string) $used
					)
					: sprintf(
						/* translators: 1: sites in use, 2: sites the licence covers. */
						__( 'In use on %1$s of %2$d sites.', 'debloater-pro' ),
						null === $used ? '?' : (string) $used,
						$limit
					)
			)
		);

		$deactivate = $urls['deactivate'] ?? null;

		if ( is_string( $deactivate ) && '' !== $deactivate ) {
			printf(
				'<p><a href="%1$s">%2$s</a> %3$s</p>',
				esc_url( $deactivate ),
				esc_html__( 'Release this site from the licence', 'debloater-pro' ),
				esc_html__( 'Pro stops here and frees the slot for another site. Nothing Debloater has applied is undone.', 'debloater-pro' )
			);
		}
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
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => self::REPORT,
						'run'    => (int) $run->id,
					),
					admin_url( 'admin-post.php' )
				),
				self::REPORT
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
			'saved'                  => array( 'success', __( 'Saved.', 'debloater-pro' ) ),

			// The profiles panel's, which posts to its own handler and comes
			// back here to be told how it went.
			'profile-renamed'        => array( 'success', __( 'Renamed.', 'debloater-pro' ) ),
			'profile-duplicated'     => array( 'success', __( 'Copied. The copy is yours to rename and edit; the original is untouched.', 'debloater-pro' ) ),
			'profile-deleted'        => array( 'success', __( 'Deleted. Nothing about this site changed — a profile is a list of changes, not the changes themselves.', 'debloater-pro' ) ),
			'profile-missing'        => array( 'error', __( 'That profile is not one this site has, so nothing was done.', 'debloater-pro' ) ),
			'profile-unnamed'        => array( 'error', __( 'A profile needs a name. Nothing was changed.', 'debloater-pro' ) ),
			'profile-refused'        => array( 'error', __( 'Debloater would not save that: either this site is already holding as many profiles as it keeps, or the profile is one that ships with the plugin and cannot be edited.', 'debloater-pro' ) ),
			'profile-unknown-action' => array( 'error', __( 'That is not something this screen does.', 'debloater-pro' ) ),
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
