<?php
/**
 * Pro, active, against a real WordPress.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Integration;

use Debloater\Pro\Cloud\CloudResponse;
use Debloater\Pro\Cloud\CloudServiceClient;
use Debloater\Pro\Cloud\HakeemifyCloudClient;
use Debloater\Pro\Admin\ProfilesPanel;
use Debloater\Pro\Entitlement\FixtureEntitlementProvider;
use Debloater\Pro\Features\ScheduledScans;
use Debloater\Pro\Pro;

/**
 * BUILD-SPEC §17 Phase 19 exit criteria, the ones that need a site.
 *
 * The architecture invariants are greps and live in `tests/Pro`. These are the
 * behavioural ones, and every one of them is about what happens when something
 * is *missing* — no entitlement, no cloud, no second scan — because that is the
 * state a real install spends almost all its time in.
 */
final class ProIntegrationTest extends IntegrationTestCase {

	/**
	 * Pro, wired to a fixture entitlement.
	 *
	 * @var Pro
	 */
	private Pro $pro;

	/**
	 * Set up with everything unlocked unless a test says otherwise.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->plugin->schema()->ensure();

		$this->pro = new Pro(
			$this->plugin,
			FixtureEntitlementProvider::everything(),
			$this->offlineCloud()
		);
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

		remove_all_filters( 'debloater_dashboard_panels' );
		remove_all_filters( 'debloater_registry_origin' );

		parent::tear_down();
	}

	/**
	 * Activating Pro adds no tweaks.
	 *
	 * The single most important assertion in this file. Pro is workflow; the
	 * set of things that can be done to a site is the free plugin's, and must
	 * be identical whether or not Pro is present.
	 *
	 * @return void
	 */
	public function test_pro_adds_no_tweaks(): void {
		$before = $this->plugin->registry()->ids();

		$this->pro->boot();

		$this->plugin->resetServices();

		$this->assertSame(
			$before,
			$this->plugin->registry()->ids(),
			'Pro must not add, remove or alter a single tweak.'
		);
	}

	/**
	 * Activating Pro registers no runtime hooks of its own.
	 *
	 * @return void
	 */
	public function test_pro_changes_no_runtime_behaviour(): void {
		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$without = (string) file_get_contents( $this->context()->runtimeFile() );

		$this->pro->boot();

		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$with = (string) file_get_contents( $this->context()->runtimeFile() );

		$this->assertSame(
			$without,
			$with,
			'The generated runtime must be byte-identical with Pro active.'
		);

		$this->assertStringNotContainsString( 'Pro', $with );

		$this->unregisterHandlers( array( 'core.remove_generator' ) );
	}

	/**
	 * With no entitlement, nothing Pro offers runs — and nothing breaks.
	 *
	 * @return void
	 */
	public function test_without_entitlement_pro_does_nothing(): void {
		$pro = new Pro( $this->plugin, new FixtureEntitlementProvider(), $this->offlineCloud() );

		$pro->boot();

		// No schedule.
		$pro->scans()->setFrequency( 'daily' );

		$this->assertFalse(
			wp_next_scheduled( ScheduledScans::HOOK ),
			'A site with no entitlement must not get a scheduled scan.'
		);

		// No panel.
		$this->assertSame( array(), $pro->panels( array() ) );

		// No report.
		$this->assertSame( '', $pro->report()->render( 1 ) );

		// And, the one that matters: no apply.
		$this->assertFalse(
			$pro->entitlement()->entitlement()->allows( ProfilesPanel::FEATURE ),
			'without an entitlement the profiles panel is not offered.'
		);

		// The free plugin is untouched by any of it.
		$this->assertNotNull( $this->plugin->scan() );
		$this->assertNotNull( $this->plugin->preview( 'safe' ) );
	}

	/**
	 * A cloud outage degrades Pro and changes nothing.
	 *
	 * @return void
	 */
	public function test_a_cloud_outage_changes_nothing(): void {
		$this->pro->boot();

		$before = $this->siteFingerprint();

		$response = $this->pro->cloud()->get( 'reports', 'anything' );

		$this->assertInstanceOf( CloudResponse::class, $response );
		$this->assertFalse( $response->ok() );
		$this->assertNotSame( '', $response->error );
		$this->assertSame( array(), $response->data );

		// Local features keep working with the cloud unreachable.
		$this->plugin->scan();
		$this->plugin->scan();

		$this->assertNotNull( $this->pro->drift()->latest() );

		$this->assertSame(
			$before,
			$this->siteFingerprint(),
			'A cloud outage must not change anything about the site.'
		);
	}

	/**
	 * The cloud is off unless a site opts in, so no request is made.
	 *
	 * @return void
	 */
	public function test_the_cloud_is_off_by_default(): void {
		$client = new HakeemifyCloudClient();

		$this->assertFalse( $client->isAvailable() );

		$requests = array();

		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$requests ) {
				unset( $args );

				$requests[] = $url;

				return new \WP_Error( 'offline', 'No network in this test.' );
			},
			1,
			3
		);

		$response = $client->get( 'reports' );

		remove_all_filters( 'pre_http_request' );

		$this->assertFalse( $response->ok() );
		$this->assertSame(
			array(),
			$requests,
			'A cloud that is switched off must not make a request to find out.'
		);
	}

	/**
	 * Drift reports what appeared and what was resolved.
	 *
	 * @return void
	 */
	public function test_drift_reports_added_and_resolved_findings(): void {
		$first = $this->plugin->scan();

		// Make the site different in a way a scanner will notice, then scan
		// again. Seeding expired transients is the cheapest real change: a
		// finding about them appears where there was none.
		for ( $index = 0; $index < 60; $index++ ) {
			set_transient( 'debloater_drift_' . $index, str_repeat( 'x', 512 ), 60 );
			update_option( '_transient_timeout_debloater_drift_' . $index, time() - 3600 );
		}

		$second = $this->plugin->scan();

		$report = $this->pro->drift()->compare( $first, $second );

		$this->assertNotNull( $report );
		$this->assertFalse( $report->isEmpty(), 'the site changed, so something should have moved' );
		$this->assertGreaterThan( 0, $report->count() );

		// The report reads as counts, and never as an opinion.
		$summary = $report->summary();

		$this->assertNotSame( '', $summary );

		foreach ( array( 'faster', 'slower', 'better', 'worse', 'should' ) as $opinion ) {
			$this->assertStringNotContainsString( $opinion, strtolower( $summary ) );
		}

		// Comparing the other way round turns appearances into resolutions,
		// which is the property that makes "resolved" mean anything.
		$reversed = $this->pro->drift()->compare( $second, $first );

		$this->assertCount( count( $report->appeared ), $reversed->resolved );
		$this->assertCount( count( $report->resolved ), $reversed->appeared );
	}

	/**
	 * One scan is not drift.
	 *
	 * @return void
	 */
	public function test_a_single_scan_produces_no_drift_report(): void {
		$this->plugin->scan();

		$this->assertNull(
			$this->pro->drift()->latest(),
			'Reporting "nothing changed" from one scan would be an invented reassurance.'
		);
	}

	/**
	 * Drift appears on the dashboard as text.
	 *
	 * @return void
	 */
	public function test_drift_reaches_the_dashboard_as_text(): void {
		$this->plugin->scan();
		$this->plugin->scan();

		$panels = $this->pro->panels( array() );

		$this->assertCount( 1, $panels );
		$this->assertArrayHasKey( 'title', $panels[0] );
		$this->assertArrayHasKey( 'rows', $panels[0] );

		$encoded = (string) wp_json_encode( $panels );

		$this->assertStringNotContainsString( '<', $encoded );
	}

	/**
	 * The priority channel is entitlement-gated, and still verified.
	 *
	 * @return void
	 */
	public function test_the_priority_channel_needs_an_entitlement(): void {
		$unentitled = new Pro( $this->plugin, new FixtureEntitlementProvider(), $this->offlineCloud() );

		$unentitled->boot();

		$this->plugin->resetServices();

		$this->assertSame(
			\Debloater\Update\RegistryOrigin::DEFAULT_BASE,
			$this->plugin->registryUpdater()->originBase(),
			'Without the entitlement, updates come from the public channel.'
		);

		remove_all_filters( 'debloater_registry_origin' );

		$this->pro->boot();

		$this->plugin->resetServices();

		$this->assertNotSame(
			\Debloater\Update\RegistryOrigin::DEFAULT_BASE,
			$this->plugin->registryUpdater()->originBase(),
			'With the entitlement, updates come from the priority channel.'
		);

		// Either way the fetch is still opt-in, and the signature check is
		// still the same one.
		$this->assertFalse( $this->plugin->registryUpdater()->enabled() );
	}

	/**
	 * A cloud client that is switched on but unreachable.
	 *
	 * @return CloudServiceClient
	 */
	private function offlineCloud(): CloudServiceClient {
		return new class() implements CloudServiceClient {

			/**
			 * Always fails, the way an outage does.
			 *
			 * @param string              $service Service.
			 * @param string              $path    Path.
			 * @param array<string,scalar> $query  Query.
			 * @return CloudResponse
			 */
			public function get( string $service, string $path = '', array $query = array() ): CloudResponse {
				unset( $service, $path, $query );

				return CloudResponse::unavailable( 'The cloud could not be reached.' );
			}

			/**
			 * Configured, but down.
			 *
			 * @return bool
			 */
			public function isAvailable(): bool {
				return true;
			}
		};
	}

	/**
	 * Enough of the site's state to notice a change to it.
	 *
	 * @return array<string,mixed>
	 */
	private function siteFingerprint(): array {
		return array(
			'runtime'   => is_readable( $this->context()->runtimeFile() )
				? md5( (string) file_get_contents( $this->context()->runtimeFile() ) )
				: '',
			'selection' => $this->plugin->state()->get( 'selection', array() ),
			'snapshots' => $this->plugin->snapshots()->count(),
		);
	}
}
