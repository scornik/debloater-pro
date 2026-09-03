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
			$changed
		);
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
