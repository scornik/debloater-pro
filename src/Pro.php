<?php
/**
 * Wiring for Hakeemify Debloater Pro.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro;

use Debloater\Config\ProfileStore;
use Debloater\Contracts\RunState;
use Debloater\Contracts\RunType;
use Debloater\Plugin;
use Debloater\Pro\Admin\Screen;
use Debloater\Pro\Cloud\CloudServiceClient;
use Debloater\Pro\Cloud\HakeemifyCloudClient;
use Debloater\Pro\Entitlement\CachedEntitlementProvider;
use Debloater\Pro\Entitlement\EntitlementProvider;
use Debloater\Pro\Entitlement\FixtureEntitlementProvider;
use Debloater\Pro\Entitlement\FreemiusEntitlementProvider;
use Debloater\Pro\Features\BeforeAfterReport;
use Debloater\Pro\Features\DriftDetector;
use Debloater\Pro\Features\ScheduledScans;
use Debloater\Pro\Multisite\NetworkDefaults;

/**
 * Everything Pro adds, and the seams it adds it through.
 *
 * Pro reaches the free plugin only through hooks the free plugin documents
 * (`docs/HOOKS.md`). It constructs no engine class, replaces no service, and
 * registers no tweak. That is not a stylistic preference: it is the property
 * the Phase 19 exit criteria name — "Pro adds no tweaks and no safety
 * features" — and it is checked by a test rather than left to discipline.
 *
 * Read that list of features and notice what is not in it. Nothing here makes
 * the site safer, and nothing here makes it less safe. Scheduled scans, drift
 * detection, a printable report, portable profiles, and getting registry
 * updates sooner are all *workflow*: they save an agency time
 * on sites they already manage. The recovery points, the verification, the
 * automatic rollback, the risk rules and the refusal to delete without a
 * backup are in the free plugin and stay there, because safety is never
 * paywalled (BUILD-SPEC §13 rule 15).
 */
final class Pro {

	/**
	 * Version.
	 */
	/**
	 * The product name shown to people. Not translated: it is a proper noun,
	 * as the free plugin's `Brand::NAME` is.
	 *
	 * "Hakeemify Debloater Pro" beside "Hakeemify Debloater", so the plugins list
	 * reads as one vendor's two products. It was "Debloater Pro" until 0.3.1.
	 *
	 * **The name is not the slug and must not follow it.** `debloater-pro` is the
	 * folder, the main file, the text domain and the Freemius slug, and it is
	 * bound to Freemius product 38409, the deployed archive and every licence
	 * issued. A bulk rename that moved it with the name would orphan live
	 * licences. `DisplayNameTest` pins both, by literal.
	 */
	public const NAME = 'Hakeemify Debloater Pro';

	public const VERSION = '0.3.0';

	/**
	 * The free plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Where entitlement comes from.
	 *
	 * @var EntitlementProvider
	 */
	private EntitlementProvider $entitlement;

	/**
	 * The cloud, which may be switched off.
	 *
	 * @var CloudServiceClient
	 */
	private CloudServiceClient $cloud;

	/**
	 * Scheduled scans.
	 *
	 * @var ScheduledScans
	 */
	private ScheduledScans $scans;

	/**
	 * Drift detection.
	 *
	 * @var DriftDetector
	 */
	private DriftDetector $drift;

	/**
	 * The printable report.
	 *
	 * @var BeforeAfterReport
	 */
	private BeforeAfterReport $report;

	/**
	 * Multisite groundwork.
	 *
	 * @var NetworkDefaults
	 */
	private NetworkDefaults $network;

	/**
	 * Pro's own admin screen.
	 *
	 * @var Screen
	 */
	private Screen $screen;

	/**
	 * Constructor.
	 *
	 * @param Plugin                   $plugin      The free plugin.
	 * @param EntitlementProvider|null $entitlement Entitlement source.
	 * @param CloudServiceClient|null  $cloud       Cloud client.
	 */
	public function __construct(
		Plugin $plugin,
		?EntitlementProvider $entitlement = null,
		?CloudServiceClient $cloud = null
	) {
		$this->plugin      = $plugin;
		$this->entitlement = $entitlement ?? self::defaultProvider();
		$this->cloud       = $cloud ?? new HakeemifyCloudClient(
			defined( 'DEBLOATER_PRO_CLOUD' ) && constant( 'DEBLOATER_PRO_CLOUD' ),
			self::VERSION
		);

		$this->scans   = new ScheduledScans( $plugin, $this->entitlement );
		$this->drift   = new DriftDetector( $plugin );
		$this->report  = new BeforeAfterReport( $plugin, $this->entitlement );
		$this->network = new NetworkDefaults( $this->entitlement );
		$this->screen  = new Screen( $this );
	}

	/**
	 * Which provider a site uses.
	 *
	 * The fixture provider only when a wp-config constant asks for it, which is
	 * a developer's machine and nowhere else. Everywhere else it is Freemius,
	 * wrapped so an outage is survivable — and Freemius being absent is an
	 * ordinary answer meaning "nothing unlocked", not an error.
	 *
	 * @return EntitlementProvider
	 */
	private static function defaultProvider(): EntitlementProvider {
		if ( defined( 'DEBLOATER_PRO_FIXTURE' ) && constant( 'DEBLOATER_PRO_FIXTURE' ) ) {
			return FixtureEntitlementProvider::everything();
		}

		return new CachedEntitlementProvider( new FreemiusEntitlementProvider() );
	}

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->scans->boot();
		$this->screen->boot();

		add_action( 'admin_init', array( $this->scans, 'sync' ) );
		add_filter( 'debloater_dashboard_panels', array( $this, 'panels' ) );
	}

	/**
	 * What Pro contributes to the dashboard.
	 *
	 * Text rows, which is all the free plugin's filter accepts. Pro does not
	 * get to put markup on somebody else's screen, and the screen strips it if
	 * it tries.
	 *
	 * @param array<int,array{title:string,rows:array<int,array{label:string,value:string}>}> $panels Panels so far.
	 * @return array<int,array{title:string,rows:array<int,array{label:string,value:string}>}>
	 */
	public function panels( $panels ): array {
		$panels = is_array( $panels ) ? $panels : array();

		if ( ! $this->entitlement->entitlement()->allows( DriftDetector::FEATURE ) ) {
			return $panels;
		}

		$report = $this->drift->latest();

		if ( null === $report ) {
			return $panels;
		}

		$panels[] = array(
			'title' => __( 'What changed since the last scan', 'debloater-pro' ),
			'rows'  => array_merge(
				array(
					array(
						'label' => __( 'Summary', 'debloater-pro' ),
						'value' => $report->summary(),
					),
				),
				$report->rows()
			),
		);

		return $panels;
	}

	/**
	 * Entitlement, for features and tests.
	 *
	 * @return EntitlementProvider
	 */
	public function entitlement(): EntitlementProvider {
		return $this->entitlement;
	}

	/**
	 * The cloud client.
	 *
	 * @return CloudServiceClient
	 */
	public function cloud(): CloudServiceClient {
		return $this->cloud;
	}

	/**
	 * Scheduled scans.
	 *
	 * @return ScheduledScans
	 */
	public function scans(): ScheduledScans {
		return $this->scans;
	}

	/**
	 * Drift detection.
	 *
	 * @return DriftDetector
	 */
	public function drift(): DriftDetector {
		return $this->drift;
	}

	/**
	 * The printable report.
	 *
	 * @return BeforeAfterReport
	 */
	public function report(): BeforeAfterReport {
		return $this->report;
	}

	/**
	 * Multisite groundwork.
	 *
	 * @return NetworkDefaults
	 */
	public function network(): NetworkDefaults {
		return $this->network;
	}

	/**
	 * The profiles this site knows about.
	 *
	 * Read from the free plugin's registry rather than listed here, so a
	 * registry update that adds or removes one is reflected without Pro
	 * knowing anything about it.
	 *
	 * @return array<string,mixed>
	 */
	public function profiles(): array {
		return $this->plugin->registry()->profiles();
	}

	/**
	 * Saved and built-in profiles, from the free plugin.
	 *
	 * Pro keeps no profile store of its own, and this is what stops it growing
	 * one. A profile saved on Debloater's screen, one imported from a file and
	 * one renamed in Pro's panel are the same row in the same option, because
	 * there is one place they are kept and this is a handle on it rather than
	 * a copy of it.
	 *
	 * @return ProfileStore
	 */
	public function profileStore(): ProfileStore {
		return new ProfileStore( $this->plugin->registry() );
	}

	/**
	 * Recent applies, newest first.
	 *
	 * @param int $limit How many.
	 * @return array<int,\Debloater\Contracts\Run>
	 */
	public function appliedRuns( int $limit = 10 ): array {
		$runs = array();

		// Only runs that actually changed something. An aborted run applied
		// nothing and has nothing to compare, and offering a report for one is
		// how a client ends up looking at a page about a change that never
		// happened. Rolled-back runs are excluded for the same reason: the site
		// ended where it started.
		$reportable = array(
			RunState::COMMITTED->value,
			RunState::VERIFIED->value,
			RunState::VERIFIED_WITH_WARNINGS->value,
		);

		foreach ( $this->plugin->runs()->recent( $limit * 4, RunType::APPLY ) as $run ) {
			if ( ! in_array( $run->status, $reportable, true ) ) {
				continue;
			}

			$runs[] = $run;

			if ( count( $runs ) >= $limit ) {
				break;
			}
		}

		return $runs;
	}

	/**
	 * One apply's before/after report, as HTML.
	 *
	 * @param int $run_id Run to report on.
	 * @return string
	 */
	public function renderReport( int $run_id ): string {
		return $this->report->render( $run_id );
	}
}
