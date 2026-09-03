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
 * What changed, in a form the dashboard and an email can both read.
 *
 * Holds findings rather than rendered text, so the same report can become a
 * dashboard panel, an email body and a printable page without any of them
 * re-deriving the comparison — and without the three drifting apart, which is
 * how a report ends up saying two different numbers in two different places.
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
	 * Constructor.
	 *
	 * @param Run                $before   Earlier scan.
	 * @param Run                $after    Later scan.
	 * @param array<int,Finding> $appeared New findings.
	 * @param array<int,Finding> $resolved Findings that have gone.
	 * @param array<int,array{finding:Finding,difference:array<string,array{from:string,to:string}>}> $changed Findings that moved.
	 */
	public function __construct(
		Run $before,
		Run $after,
		array $appeared,
		array $resolved,
		array $changed
	) {
		$this->before   = $before;
		$this->after    = $after;
		$this->appeared = array_values( $appeared );
		$this->resolved = array_values( $resolved );
		$this->changed  = array_values( $changed );
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
			return __( 'Nothing changed between these two scans.', 'debloater-pro' );
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
