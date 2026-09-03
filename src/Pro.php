<?php
/**
 * Wiring for Debloater Pro.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro;

use Debloater\Plugin;
use Debloater\Pro\Cloud\CloudServiceClient;
use Debloater\Pro\Cloud\HakeemifyCloudClient;
use Debloater\Pro\Entitlement\CachedEntitlementProvider;
use Debloater\Pro\Entitlement\EntitlementProvider;
use Debloater\Pro\Entitlement\FixtureEntitlementProvider;
use Debloater\Pro\Entitlement\FreemiusEntitlementProvider;
use Debloater\Pro\Features\BeforeAfterReport;
use Debloater\Pro\Features\BulkApply;
use Debloater\Pro\Features\DriftDetector;
use Debloater\Pro\Features\RegistryChannel;
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
 * detection, a printable report, applying a saved profile in one step, and
 * getting registry updates sooner are all *workflow*: they save an agency time
 * on sites they already manage. The recovery points, the verification, the
 * automatic rollback, the risk rules and the refusal to delete without a
 * backup are in the free plugin and stay there, because safety is never
 * paywalled (BUILD-SPEC §13 rule 15).
 */
final class Pro {

	/**
	 * Version.
	 */
	public const VERSION = '0.1.0';

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
	 * Applying a saved profile.
	 *
	 * @var BulkApply
	 */
	private BulkApply $bulk;

	/**
	 * The priority registry channel.
	 *
	 * @var RegistryChannel
	 */
	private RegistryChannel $channel;

	/**
	 * Multisite groundwork.
	 *
	 * @var NetworkDefaults
	 */
	private NetworkDefaults $network;

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
		$this->entitlement = $entitlement ?? self::defaultProvider();
		$this->cloud       = $cloud ?? new HakeemifyCloudClient(
			defined( 'DEBLOATER_PRO_CLOUD' ) && constant( 'DEBLOATER_PRO_CLOUD' ),
			self::VERSION
		);

		$this->scans   = new ScheduledScans( $plugin, $this->entitlement );
		$this->drift   = new DriftDetector( $plugin );
		$this->report  = new BeforeAfterReport( $plugin, $this->entitlement );
		$this->bulk    = new BulkApply( $plugin, $this->entitlement );
		$this->channel = new RegistryChannel( $this->entitlement );
		$this->network = new NetworkDefaults( $this->entitlement );
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
		$this->channel->boot();

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
	 * Applying a saved profile.
	 *
	 * @return BulkApply
	 */
	public function bulk(): BulkApply {
		return $this->bulk;
	}

	/**
	 * Multisite groundwork.
	 *
	 * @return NetworkDefaults
	 */
	public function network(): NetworkDefaults {
		return $this->network;
	}
}
