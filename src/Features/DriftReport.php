<?php
/**
 * The difference between two scans, as a value.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Features;

use Debloater\Contracts\Finding;
use Debloater\Contracts\Run;

/**
 * What changed between two scans, as a value the screen and the dashboard read.
 *
 * Nothing in Pro sends this anywhere, by email or otherwise; it is read when
 * somebody opens the screen or the dashboard. This line used to say
 * "a form the dashboard and an email can both read", describing a delivery
 * channel that was never built (P8).
 *
 * Holds findings and version rows rather than rendered text, so the screen and
 * the dashboard panel can both read the same comparison instead of each
 * deriving its own — which is how a report ends up saying two different numbers
 * in two different places.
 *
 * Two lists, kept apart on purpose. `appeared`, `resolved` and `changed` are
 * what moved in Debloater's findings; `versions` is what moved on the site —
 * WordPress's version, and the plugins'. One is usually the cause of the other,
 * and a single merged list would put them side by side with nothing saying
 * which was which.
 */
final class DriftReport {

	/**
	 * The earlier scan.
	 *
	 * @var Run
	 */
	public readonly Run $before;

	/**
	 * The later scan.
	 *
	 * @var Run
	 */
	public readonly Run $after;

	/**
	 * Findings that were not there before.
	 *
	 * @var array<int,Finding>
	 */
	public readonly array $appeared;

	/**
	 * Findings that have gone.
	 *
	 * @var array<int,Finding>
	 */
	public readonly array $resolved;

	/**
	 * Findings whose severity or decision moved.
	 *
	 * @var array<int,array{finding:Finding,difference:array<string,array{from:string,to:string}>}>
	 */
	public readonly array $changed;

	/**
	 * What changed on the site itself: WordPress's version, and the plugins'.
	 *
	 * @var array<int,array{kind:string,name:string,from:string,to:string}>
	 */
	public readonly array $versions;

	/**
	 * Constructor.
	 *
	 * @param Run                $before   Earlier scan.
	 * @param Run                $after    Later scan.
	 * @param array<int,Finding> $appeared New findings.
	 * @param array<int,Finding> $resolved Findings that have gone.
	 * @param array<int,array{finding:Finding,difference:array<string,array{from:string,to:string}>}> $changed Findings that moved.
	 * @param array<int,array{kind:string,name:string,from:string,to:string}>                         $versions Site changes.
	 */
	public function __construct(
		Run $before,
		Run $after,
		array $appeared,
		array $resolved,
		array $changed,
		array $versions = array()
	) {
		$this->before   = $before;
		$this->after    = $after;
		$this->appeared = array_values( $appeared );
		$this->resolved = array_values( $resolved );
		$this->changed  = array_values( $changed );
		$this->versions = array_values( $versions );
	}

	/**
	 * Whether anything moved at all.
	 *
	 * @return bool
	 */
	public function isEmpty(): bool {
		return array() === $this->appeared
			&& array() === $this->resolved
			&& array() === $this->changed;
	}

	/**
	 * Whether anything at all moved, findings or site.
	 *
	 * `isEmpty()` answers for the findings only, which is what it has always
	 * meant and what its callers ask it. A version change with no finding
	 * behind it is still a change, so the screen asks this.
	 *
	 * @return bool
	 */
	public function hasChanges(): bool {
		return ! $this->isEmpty() || array() !== $this->versions;
	}

	/**
	 * What changed on the site, as label and value rows.
	 *
	 * Each row states the change and stops there. "WooCommerce 9.1.4 → 9.2.0",
	 * not "WooCommerce is out of date": what a version means is a conclusion,
	 * and conclusions are the analyzer's (free invariants 1 and 2).
	 *
	 * @return array<int,array{label:string,value:string}>
	 */
	public function versionRows(): array {
		$rows = array();

		foreach ( $this->versions as $change ) {
			switch ( $change['kind'] ) {
				case 'activated':
					$rows[] = array(
						'label' => $change['name'],
						'value' => '' === $change['to']
							? __( 'was activated', 'debloater-pro' )
							: sprintf(
								/* translators: %s: the version that is now active. */
								__( 'was activated, at %s', 'debloater-pro' ),
								$change['to']
							),
					);
					break;

				case 'deactivated':
					$rows[] = array(
						'label' => $change['name'],
						'value' => __( 'is no longer active', 'debloater-pro' ),
					);
					break;

				default:
					$rows[] = array(
						'label' => $change['name'],
						'value' => sprintf( '%s → %s', $change['from'], $change['to'] ),
					);
					break;
			}
		}

		return $rows;
	}

	/**
	 * What changed on the site, in one line.
	 *
	 * @return string
	 */
	public function versionSummary(): string {
		if ( array() === $this->versions ) {
			return '';
		}

		return sprintf(
			/* translators: %d: how many version or activation changes. */
			_n( '%d change on this site', '%d changes on this site', count( $this->versions ), 'debloater-pro' ),
			count( $this->versions )
		);
	}

	/**
	 * How many differences there are, of all kinds.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->appeared ) + count( $this->resolved ) + count( $this->changed );
	}

	/**
	 * One line describing the report, in plain words.
	 *
	 * Counts and nothing else. No adjective, no assessment of whether the drift
	 * is good or bad — the free plugin does not tell people what their site
	 * should look like, and a Pro feature reporting on it does not get to
	 * either.
	 *
	 * @return string
	 */
	public function summary(): string {
		if ( $this->isEmpty() ) {
			// About the findings, and only those. The site itself may well have
			// changed; `versionSummary()` answers for that, separately.
			return __( 'Nothing changed in what Debloater found.', 'debloater-pro' );
		}

		$parts = array();

		if ( array() !== $this->appeared ) {
			$parts[] = sprintf(
				/* translators: %d: how many findings appeared. */
				_n( '%d new finding', '%d new findings', count( $this->appeared ), 'debloater-pro' ),
				count( $this->appeared )
			);
		}

		if ( array() !== $this->resolved ) {
			$parts[] = sprintf(
				/* translators: %d: how many findings are gone. */
				_n( '%d resolved', '%d resolved', count( $this->resolved ), 'debloater-pro' ),
				count( $this->resolved )
			);
		}

		if ( array() !== $this->changed ) {
			$parts[] = sprintf(
				/* translators: %d: how many findings changed severity or decision. */
				_n( '%d changed', '%d changed', count( $this->changed ), 'debloater-pro' ),
				count( $this->changed )
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * Rows for a dashboard panel.
	 *
	 * @return array<int,array{label:string,value:string}>
	 */
	public function rows(): array {
		$rows = array();

		foreach ( $this->appeared as $finding ) {
			$rows[] = array(
				'label' => __( 'New', 'debloater-pro' ),
				'value' => $finding->title,
			);
		}

		foreach ( $this->resolved as $finding ) {
			$rows[] = array(
				'label' => __( 'Resolved', 'debloater-pro' ),
				'value' => $finding->title,
			);
		}

		foreach ( $this->changed as $entry ) {
			foreach ( $entry['difference'] as $field => $move ) {
				$rows[] = array(
					'label' => $entry['finding']->title,
					'value' => sprintf(
						/* translators: 1: field name, 2: previous value, 3: new value. */
						__( '%1$s went from %2$s to %3$s', 'debloater-pro' ),
						$field,
						$move['from'],
						$move['to']
					),
				);
			}
		}

		return $rows;
	}
}
