<?php
/**
 * What changed between two scans.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Features;

use Debloater\Contracts\Finding;
use Debloater\Contracts\Run;
use Debloater\Contracts\RunType;
use Debloater\Plugin;

/**
 * Compares the findings of two scans and reports the difference.
 *
 * This is the feature scheduled scans exist to serve. A single scan tells you
 * what a site looks like; two scans a week apart tell you what somebody
 * changed, which is usually the more interesting question and almost never one
 * anybody goes looking for by hand.
 *
 * Three kinds of difference, and the third is the one that matters:
 *
 * - **Appeared.** A finding that was not there before. A plugin was installed,
 *   revisions accumulated, somebody switched a theme.
 * - **Resolved.** A finding that has gone. Reported because a report that only
 *   ever grows is one people stop reading, and because a finding resolving is
 *   how you tell that something worked.
 * - **Changed.** The same finding, with a different severity or a different
 *   decision. This is drift proper: nothing appeared or vanished, but the
 *   site moved underneath a conclusion that was already drawn — a `dont_touch`
 *   that is no longer warranted, or a low-severity finding that has become a
 *   high one.
 *
 * The comparison is on finding **id**, which is stable across scans by
 * construction. Comparing on title would make a copy-edit look like drift.
 *
 * ## And what changed on the site itself
 *
 * Separately, and kept separate: WordPress's version, and the version of every
 * active plugin. Those are facts, recorded on every run in its payload, and
 * until 0.4.0 nothing read them — so a site could take a major WordPress update
 * between two scans and this reported whatever that did to the findings, never
 * the update.
 *
 * The two are different questions and are not merged. "WooCommerce 9.1.4 →
 * 9.2.0" is what changed on the site; "1 new finding" is what changed in what
 * Debloater concluded about it. Mixing them into one list produces a report
 * where a plugin update and a new finding look like the same kind of event,
 * and where the cause sits next to the effect with nothing saying which is
 * which.
 *
 * These rows state the change and nothing else. No "should update", no "out of
 * date": the scanner reports facts and the analyzer draws conclusions, and a
 * version row that editorialised would be Pro doing the analyzer's job from
 * outside it (§13, and the free plugin's invariants 1 and 2).
 *
 * The theme is absent, deliberately. `theme.active` is a stylesheet slug and
 * `theme.parent` its template; there is no theme **version** fact to compare,
 * and adding one is a change to the free plugin's scanner rather than
 * something to invent here.
 */
final class DriftDetector {

	/**
	 * The feature key this needs.
	 */
	public const FEATURE = 'drift_detection';

	/**
	 * The plugin, for reading runs and findings.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin The free plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Compare the two most recent scans.
	 *
	 * @return DriftReport|null Null when there are not two scans to compare.
	 */
	public function latest(): ?DriftReport {
		$runs = array();

		foreach ( $this->plugin->runs()->recent( 10, RunType::SCAN ) as $run ) {
			$runs[] = $run;

			if ( count( $runs ) === 2 ) {
				break;
			}
		}

		if ( count( $runs ) < 2 ) {
			// One scan is not drift. Saying "nothing has changed" on the
			// strength of a single data point would be an invented reassurance.
			return null;
		}

		return $this->compare( $runs[1], $runs[0] );
	}

	/**
	 * Compare two specific runs.
	 *
	 * @param Run $before The earlier scan.
	 * @param Run $after  The later scan.
	 * @return DriftReport
	 */
	public function compare( Run $before, Run $after ): DriftReport {
		$was = $this->index( $before );
		$is  = $this->index( $after );

		$appeared = array();
		$resolved = array();
		$changed  = array();

		foreach ( $is as $id => $finding ) {
			if ( ! isset( $was[ $id ] ) ) {
				$appeared[] = $finding;

				continue;
			}

			$difference = $this->difference( $was[ $id ], $finding );

			if ( array() !== $difference ) {
				$changed[] = array(
					'finding'    => $finding,
					'difference' => $difference,
				);
			}
		}

		foreach ( $was as $id => $finding ) {
			if ( ! isset( $is[ $id ] ) ) {
				$resolved[] = $finding;
			}
		}

		return new DriftReport(
			$before,
			$after,
			$appeared,
			$resolved,
			$changed,
			$this->versions( $before, $after )
		);
	}

	/**
	 * What changed on the site itself between two scans.
	 *
	 * WordPress's version, and each active plugin's, keyed by plugin file —
	 * which is the identifier that survives a rename of the plugin's display
	 * name, and the one `plugins.meta` is keyed by.
	 *
	 * A plugin present in one scan's `plugins.active` and not the other's is
	 * reported as activated or deactivated. This cannot tell deactivated from
	 * deleted: `plugins.active` says what was running, and a plugin that was
	 * removed and one that was switched off look identical from here. The row
	 * says "is no longer active", which is true of both.
	 *
	 * @param Run $before The earlier scan.
	 * @param Run $after  The later scan.
	 * @return array<int,array{kind:string,name:string,from:string,to:string}>
	 */
	private function versions( Run $before, Run $after ): array {
		$was = $before->facts();
		$is  = $after->facts();

		$rows = array();

		$core_before = (string) $was->value( 'env.wp_version', '' );
		$core_after  = (string) $is->value( 'env.wp_version', '' );

		if ( '' !== $core_before && '' !== $core_after && $core_before !== $core_after ) {
			$rows[] = array(
				'kind' => 'core',
				'name' => 'WordPress',
				'from' => $core_before,
				'to'   => $core_after,
			);
		}

		$before_active = $this->activePlugins( $was );
		$after_active  = $this->activePlugins( $is );
		$before_meta   = $this->pluginMeta( $was );
		$after_meta    = $this->pluginMeta( $is );

		foreach ( $after_active as $file ) {
			$name = $this->pluginName( $file, $after_meta );

			if ( ! in_array( $file, $before_active, true ) ) {
				$rows[] = array(
					'kind' => 'activated',
					'name' => $name,
					'from' => '',
					'to'   => $this->pluginVersion( $file, $after_meta ),
				);

				continue;
			}

			$from = $this->pluginVersion( $file, $before_meta );
			$to   = $this->pluginVersion( $file, $after_meta );

			if ( '' !== $from && '' !== $to && $from !== $to ) {
				$rows[] = array(
					'kind' => 'plugin',
					'name' => $name,
					'from' => $from,
					'to'   => $to,
				);
			}
		}

		foreach ( $before_active as $file ) {
			if ( ! in_array( $file, $after_active, true ) ) {
				$rows[] = array(
					'kind' => 'deactivated',
					'name' => $this->pluginName( $file, $before_meta ),
					'from' => $this->pluginVersion( $file, $before_meta ),
					'to'   => '',
				);
			}
		}

		return $rows;
	}

	/**
	 * The active plugin files of a run.
	 *
	 * @param \Debloater\Contracts\FactSet $facts The run's facts.
	 * @return array<int,string>
	 */
	private function activePlugins( \Debloater\Contracts\FactSet $facts ): array {
		$active = $facts->value( 'plugins.active', array() );

		if ( ! is_array( $active ) ) {
			return array();
		}

		$files = array();

		foreach ( $active as $file ) {
			if ( is_string( $file ) && '' !== $file ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Per-plugin metadata of a run, keyed by plugin file.
	 *
	 * @param \Debloater\Contracts\FactSet $facts The run's facts.
	 * @return array<string,array<string,mixed>>
	 */
	private function pluginMeta( \Debloater\Contracts\FactSet $facts ): array {
		$meta = $facts->value( 'plugins.meta', array() );

		if ( ! is_array( $meta ) ) {
			return array();
		}

		$clean = array();

		foreach ( $meta as $file => $entry ) {
			if ( is_string( $file ) && is_array( $entry ) ) {
				$clean[ $file ] = $entry;
			}
		}

		return $clean;
	}

	/**
	 * A plugin's name, or its file when the scan recorded no name.
	 *
	 * @param string                            $file Plugin file.
	 * @param array<string,array<string,mixed>> $meta Metadata from that scan.
	 * @return string
	 */
	private function pluginName( string $file, array $meta ): string {
		$name = $meta[ $file ]['name'] ?? '';

		return is_string( $name ) && '' !== $name ? $name : $file;
	}

	/**
	 * A plugin's version, or '' when the scan recorded none.
	 *
	 * @param string                            $file Plugin file.
	 * @param array<string,array<string,mixed>> $meta Metadata from that scan.
	 * @return string
	 */
	private function pluginVersion( string $file, array $meta ): string {
		$version = $meta[ $file ]['version'] ?? '';

		return is_string( $version ) ? $version : '';
	}

	/**
	 * Findings of a run, keyed by id.
	 *
	 * @param Run $run The run.
	 * @return array<string,Finding>
	 */
	private function index( Run $run ): array {
		$indexed = array();

		foreach ( $this->plugin->findingsOf( $run ) as $finding ) {
			$indexed[ $finding->id ] = $finding;
		}

		return $indexed;
	}

	/**
	 * What differs between two versions of the same finding.
	 *
	 * Severity and decision only. Confidence moves by fractions between scans
	 * for reasons that are not drift — a page sample that caught a different
	 * set of pages — and reporting that as a change would bury the two fields
	 * that actually mean something happened.
	 *
	 * @param Finding $was Earlier.
	 * @param Finding $is  Later.
	 * @return array<string,array{from:string,to:string}>
	 */
	private function difference( Finding $was, Finding $is ): array {
		$difference = array();

		if ( $was->severity !== $is->severity ) {
			$difference['severity'] = array(
				'from' => $was->severity->value,
				'to'   => $is->severity->value,
			);
		}

		if ( $was->decision !== $is->decision ) {
			$difference['decision'] = array(
				'from' => $was->decision->value,
				'to'   => $is->decision->value,
			);
		}

		return $difference;
	}
}
