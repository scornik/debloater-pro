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

		$without = $this->storedRuntime();

		// Not empty, or the comparison below is two absences agreeing. The
		// option is named by its literal (P4): if the free plugin renames it,
		// this has to fail rather than compare nothing with nothing.
		$this->assertStringContainsString( 'core-remove-generator.php', $without );

		$this->pro->boot();

		$this->selectAndGenerate( array( 'core.remove_generator' => array() ) );

		$with = $this->storedRuntime();

		$this->assertSame(
			$without,
			$with,
			'What the runtime loads must be identical with Pro active.'
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
	 * A WordPress or plugin version change is reported, and named.
	 *
	 * The feature row promises exactly this, and until 0.4.0 nothing read the
	 * version facts: `env.wp_version` and `plugins.meta` sat in both runs'
	 * payloads, and drift compared findings only. A site could take a major
	 * WordPress update between two scans and this reported whatever that did to
	 * the findings, never the update.
	 *
	 * The two scans are real. Their recorded facts are then rewritten to the
	 * versions a later scan would have found, because the alternative is
	 * updating WordPress inside a test.
	 *
	 * @return void
	 */
	public function test_a_version_change_is_reported(): void {
		[ $before, $after ] = $this->twoScans();

		$after = $this->withVersions(
			$after,
			'9.9.9',
			array(
				'acme/acme.php' => array(
					'name'    => 'Acme',
					'version' => '2.0.0',
				),
			)
		);

		$before = $this->withVersions(
			$before,
			'6.5.2',
			array(
				'acme/acme.php' => array(
					'name'    => 'Acme',
					'version' => '1.4.0',
				),
			)
		);

		$report = $this->pro->drift()->compare( $before, $after );

		$rows = $this->rowsByLabel( $report->versionRows() );

		$this->assertSame( '6.5.2 → 9.9.9', $rows['WordPress'] ?? '', 'the core version change belongs on the report' );
		$this->assertSame( '1.4.0 → 2.0.0', $rows['Acme'] ?? '', 'the plugin version change belongs on the report, by name' );

		// Stating the change, and nothing about it.
		foreach ( $report->versionRows() as $row ) {
			foreach ( array( 'out of date', 'should', 'must', 'insecure', 'old' ) as $opinion ) {
				$this->assertStringNotContainsString( $opinion, strtolower( $row['value'] ) );
			}
		}

		// And the findings diff is untouched by any of it.
		$this->assertTrue( $report->isEmpty(), 'no finding moved, so the findings half stays empty' );
		$this->assertTrue( $report->hasChanges(), 'but something did change' );
	}

	/**
	 * A plugin that appears, and one that goes, each get a row.
	 *
	 * @return void
	 */
	public function test_a_plugin_appearing_and_disappearing_are_reported(): void {
		[ $before, $after ] = $this->twoScans();

		$meta = array(
			'acme/acme.php' => array(
				'name'    => 'Acme',
				'version' => '1.0.0',
			),
		);

		$activated = $this->pro->drift()->compare(
			$this->withVersions( $before, '6.5.2', array(), array() ),
			$this->withVersions( $after, '6.5.2', $meta, array( 'acme/acme.php' ) )
		);

		$this->assertSame(
			array( 'Acme' => 'was activated, at 1.0.0' ),
			$this->rowsByLabel( $activated->versionRows() )
		);

		$deactivated = $this->pro->drift()->compare(
			$this->withVersions( $before, '6.5.2', $meta, array( 'acme/acme.php' ) ),
			$this->withVersions( $after, '6.5.2', array(), array() )
		);

		$this->assertSame(
			array( 'Acme' => 'is no longer active' ),
			$this->rowsByLabel( $deactivated->versionRows() )
		);
	}

	/**
	 * Two scans of a site nobody touched report no version changes.
	 *
	 * The half of this that matters: a report that finds something in every
	 * comparison is a report nobody reads twice.
	 *
	 * @return void
	 */
	public function test_identical_runs_report_no_version_changes(): void {
		[ $before, $after ] = $this->twoScans();

		$report = $this->pro->drift()->compare( $before, $after );

		$this->assertSame( array(), $report->versions );
		$this->assertSame( array(), $report->versionRows() );
		$this->assertSame( '', $report->versionSummary() );
	}

	/**
	 * A finding whose severity moves between scans is reported as changed.
	 *
	 * The category the class docblock calls "drift proper", and until now the
	 * only one with no test: nothing appeared, nothing resolved, the site moved
	 * underneath a conclusion already drawn.
	 *
	 * A real move, not a rewritten payload. The expired-transients rule is low
	 * severity from 50 and medium from 1,000, so the site gains enough expired
	 * transients to cross that line between one scan and the next.
	 *
	 * @return void
	 */
	public function test_a_severity_move_is_reported_as_changed(): void {
		$this->seedExpiredTransients( 'debloater_low_', 60 );

		$before = $this->plugin->scan();

		$this->seedExpiredTransients( 'debloater_medium_', 1000 );

		$after = $this->plugin->scan();

		$report = $this->pro->drift()->compare( $before, $after );

		$moved = array();

		foreach ( $report->changed as $entry ) {
			$moved[ $entry['finding']->id ] = $entry['difference'];
		}

		$this->assertArrayHasKey( 'db.transients.expired', $moved, 'the finding stayed and its severity moved, so it is changed' );
		$this->assertSame(
			array(
				'from' => 'low',
				'to'   => 'medium',
			),
			$moved['db.transients.expired']['severity'] ?? null
		);

		// Neither appeared nor resolved: it is the same finding.
		foreach ( array_merge( $report->appeared, $report->resolved ) as $finding ) {
			$this->assertNotSame( 'db.transients.expired', $finding->id );
		}

		// And it reaches the rows as a statement of the move.
		$this->assertContains( 'severity went from low to medium', array_column( $report->rows(), 'value' ) );
	}

	/**
	 * Firing the scheduled scan's hook records a scan.
	 *
	 * Nothing fired it before. The schedule was tested — set, cleared,
	 * `wp_next_scheduled()` agreeing — but never what happens when WP-Cron
	 * runs it, which is the only part a customer experiences.
	 *
	 * The hook is named by its literal (P4): WP-Cron stores it in the `cron`
	 * option, so a site already scheduled holds this string whatever the
	 * constant becomes.
	 *
	 * @return void
	 */
	public function test_the_scheduled_hook_records_a_scan(): void {
		remove_all_actions( 'debloater_pro_scheduled_scan' );

		$this->pro->boot();

		$before = $this->scanCount();

		do_action( 'debloater_pro_scheduled_scan' );

		$this->assertSame( $before + 1, $this->scanCount(), 'one scan run, recorded by the scheduled event' );

		remove_all_actions( 'debloater_pro_scheduled_scan' );
	}

	/**
	 * The entitlement is checked when the event fires, not only when it is set.
	 *
	 * A subscription that lapses between scheduling and the next tick must stop
	 * the scan at that tick. Scheduled while entitled, fired while not.
	 *
	 * @return void
	 */
	public function test_the_scheduled_hook_refuses_without_the_entitlement(): void {
		remove_all_actions( 'debloater_pro_scheduled_scan' );

		// Scheduled by a site that could.
		$this->pro->scans()->setFrequency( 'daily' );
		$this->assertNotFalse( wp_next_scheduled( 'debloater_pro_scheduled_scan' ) );

		// Fired on a site that no longer can.
		$lapsed = new Pro( $this->plugin, new FixtureEntitlementProvider(), $this->offlineCloud() );
		$lapsed->boot();

		$before = $this->scanCount();

		do_action( 'debloater_pro_scheduled_scan' );

		$this->assertSame( $before, $this->scanCount(), 'a lapsed entitlement must stop the scan at the tick' );

		remove_all_actions( 'debloater_pro_scheduled_scan' );
	}

	/**
	 * How many scan runs are recorded.
	 *
	 * @return int
	 */
	private function scanCount(): int {
		return count( $this->plugin->runs()->recent( 1000, \Debloater\Contracts\RunType::SCAN ) );
	}

	/**
	 * Store expired transients, cheaply.
	 *
	 * Two statements rather than a thousand `set_transient()` calls. The rows
	 * are the shape WordPress writes: a value row and a timeout row per
	 * transient, the timeout in the past.
	 *
	 * @param string $prefix Name prefix, unique per call.
	 * @param int    $count  How many.
	 * @return void
	 */
	private function seedExpiredTransients( string $prefix, int $count ): void {
		global $wpdb;

		$expired  = time() - HOUR_IN_SECONDS;
		$values   = array();
		$timeouts = array();

		for ( $index = 0; $index < $count; $index++ ) {
			$name       = $prefix . $index;
			$values[]   = $wpdb->prepare( '(%s, %s, %s)', '_transient_' . $name, 'x', 'off' );
			$timeouts[] = $wpdb->prepare( '(%s, %s, %s)', '_transient_timeout_' . $name, (string) $expired, 'off' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Each row above is prepared; this joins prepared fragments into one statement, in a disposable test database.
		$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $values ) );
		$wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES " . implode( ',', $timeouts ) );
		// phpcs:enable

		wp_cache_flush();
	}

	/**
	 * Two real scans, in order.
	 *
	 * @return array{0:\Debloater\Contracts\Run,1:\Debloater\Contracts\Run}
	 */
	private function twoScans(): array {
		$before = $this->plugin->scan();
		$after  = $this->plugin->scan();

		return array( $before, $after );
	}

	/**
	 * The same run, with the version facts a later scan would have recorded.
	 *
	 * Written through the run's own payload, in the shape `Run::facts()` reads,
	 * so this exercises the path a scan writes rather than a shape invented
	 * here.
	 *
	 * @param \Debloater\Contracts\Run          $run     The run.
	 * @param string                            $core    WordPress version.
	 * @param array<string,array<string,string>> $meta   plugins.meta entries.
	 * @param array<int,string>|null            $active  plugins.active, or null to derive from $meta.
	 * @return \Debloater\Contracts\Run
	 */
	private function withVersions( \Debloater\Contracts\Run $run, string $core, array $meta, ?array $active = null ): \Debloater\Contracts\Run {
		$facts = $run->facts()->toArray();

		$facts['env.wp_version'] = $core;
		$facts['plugins.meta']   = $meta;
		$facts['plugins.active'] = null === $active ? array_keys( $meta ) : $active;

		$payload          = $run->payload;
		$payload['facts'] = $facts;

		return $run->withPayload( $payload );
	}

	/**
	 * Version rows as label => value, which is how they are read.
	 *
	 * @param array<int,array{label:string,value:string}> $rows The rows.
	 * @return array<string,string>
	 */
	private function rowsByLabel( array $rows ): array {
		$by_label = array();

		foreach ( $rows as $row ) {
			$by_label[ $row['label'] ] = $row['value'];
		}

		return $by_label;
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
	 * With every entitlement, Pro hooks no registry origin.
	 *
	 * This was the test that the priority registry channel was
	 * entitlement-gated. The free plugin removed the filter and the fetch it
	 * fed in 0.4.0, and Pro withdrew the channel rather than rebuilding it
	 * (D-0078). What remains worth asserting is that a fully entitled Pro
	 * reaches for none of it.
	 *
	 * @return void
	 */
	public function test_pro_offers_no_registry_channel(): void {
		$this->pro->boot();

		$this->assertTrue(
			$this->pro->entitlement()->entitlement()->allows( 'drift_detection' ),
			'this Pro should be fully entitled, or the next assertion says nothing'
		);

		$this->assertFalse( has_filter( 'debloater_registry_origin' ) );
		$this->assertFalse( $this->pro->entitlement()->entitlement()->allows( 'priority_registry' ) );
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
	 * What the free plugin's runtime loads, as a comparable string.
	 *
	 * The runtime was a generated PHP file until free 0.3.0 and is an option
	 * now: the selected handler file names and their validated parameters
	 * (free D-0070). These tests used to compare the file's bytes; they compare
	 * the option's contents, which is the same claim about the thing that runs.
	 *
	 * `debloater_runtime` is written as a literal on purpose (P4). It is the free
	 * plugin's contract, and Pro reading it through the free constant would
	 * follow a rename silently instead of noticing one.
	 *
	 * @return string
	 */
	private function storedRuntime(): string {
		return (string) wp_json_encode( get_option( 'debloater_runtime', null ) );
	}

	/**
	 * Enough of the site's state to notice a change to it.
	 *
	 * @return array<string,mixed>
	 */
	private function siteFingerprint(): array {
		return array(
			'runtime'   => md5( $this->storedRuntime() ),
			'selection' => $this->plugin->state()->get( 'selection', array() ),
			'snapshots' => $this->plugin->snapshots()->count(),
		);
	}
}
