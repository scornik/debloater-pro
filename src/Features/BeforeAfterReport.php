<?php
/**
 * The white-label before/after report.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Pro\Features;

use Debloater\Plugin;
use Debloater\Pro\Entitlement\EntitlementProvider;

/**
 * A printable page an agency can hand to a client.
 *
 * HTML with print CSS, and no PDF library. `BUILD-SPEC` §17 Phase 19 allows a
 * server-side PDF "only if the bundled lib size is acceptable", and the answer
 * is that it is not: the smallest usable PHP PDF library is several megabytes
 * of vendored code for something every browser already does with Ctrl-P, on a
 * plugin whose entire zip is half a megabyte. Recorded in
 * `docs/DECISIONS.md` D-0049.
 *
 * White-label means the agency's name replaces Hakeemify's. It does not mean
 * the numbers change. Every figure here is a measured delta the free plugin
 * recorded — §12 invariant 14 holds just as firmly in a document meant to
 * impress a client as it does on the dashboard, and arguably more so, because
 * this is the artefact somebody might be paid on the strength of.
 *
 * There is no "faster". There is no score presented as a performance
 * benchmark. There are counts, before and after, and where nothing was measured
 * the report says nothing rather than estimating.
 */
final class BeforeAfterReport {

	/**
	 * The feature key this needs.
	 */
	public const FEATURE = 'white_label_report';

	/**
	 * Where the agency's name is kept.
	 */
	private const OPTION = 'debloater_pro_report_branding';

	/**
	 * The free plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * Entitlement.
	 *
	 * @var EntitlementProvider
	 */
	private EntitlementProvider $entitlement;

	/**
	 * Constructor.
	 *
	 * @param Plugin              $plugin      The free plugin.
	 * @param EntitlementProvider $entitlement Entitlement source.
	 */
	public function __construct( Plugin $plugin, EntitlementProvider $entitlement ) {
		$this->plugin      = $plugin;
		$this->entitlement = $entitlement;
	}

	/**
	 * The name to put on the report.
	 *
	 * @return string
	 */
	public function branding(): string {
		$stored = get_option( self::OPTION, '' );

		return is_string( $stored ) ? wp_strip_all_tags( $stored ) : '';
	}

	/**
	 * Set the name to put on the report.
	 *
	 * @param string $name Agency name, or '' to use none.
	 * @return void
	 */
	public function setBranding( string $name ): void {
		update_option( self::OPTION, wp_strip_all_tags( $name ), false );
	}

	/**
	 * The report, as HTML.
	 *
	 * @param int $run_id The apply run to report on.
	 * @return string HTML, or '' when there is nothing to report.
	 */
	public function render( int $run_id ): string {
		if ( ! $this->entitlement->entitlement()->allows( self::FEATURE ) ) {
			return '';
		}

		$run = $this->plugin->runs()->find( $run_id );

		if ( null === $run ) {
			return '';
		}

		$deltas   = $this->deltas( $run );
		$branding = $this->branding();

		$html  = '<!doctype html><html><head><meta charset="utf-8">';
		$html .= '<title>' . esc_html( $this->title( $branding ) ) . '</title>';
		$html .= '<style>' . $this->css() . '</style></head><body>';

		$html .= '<h1>' . esc_html( $this->title( $branding ) ) . '</h1>';
		$html .= '<p class="when">' . esc_html( $run->started_at ) . '</p>';

		if ( array() === $deltas ) {
			// Nothing was measured. Saying so is the report; inventing a figure
			// to fill the space is the one thing this must never do.
			$html .= '<p class="none">' . esc_html__(
				'Nothing was measured for this change, so there is nothing to compare. This report shows measured differences only.',
				'debloater-pro'
			) . '</p>';
		} else {
			$html .= '<table><thead><tr>';
			$html .= '<th>' . esc_html__( 'Measure', 'debloater-pro' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Before', 'debloater-pro' ) . '</th>';
			$html .= '<th>' . esc_html__( 'After', 'debloater-pro' ) . '</th>';
			$html .= '<th>' . esc_html__( 'Difference', 'debloater-pro' ) . '</th>';
			$html .= '</tr></thead><tbody>';

			foreach ( $deltas as $delta ) {
				$html .= '<tr>';
				$html .= '<td>' . esc_html( $this->label( $delta ) ) . '</td>';
				$html .= '<td>' . esc_html( $this->figure( $delta['before'] ) ) . '</td>';
				$html .= '<td>' . esc_html( $this->figure( $delta['after'] ) ) . '</td>';
				$html .= '<td>' . esc_html( $this->difference( $delta ) ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</tbody></table>';
		}

		$html .= '<p class="footnote">' . esc_html__(
			'These are counts measured on this site before and after the change. They are not a speed measurement, and this report does not make one.',
			'debloater-pro'
		) . '</p>';

		return $html . '</body></html>';
	}

	/**
	 * One row's name, with its unit when it has one.
	 *
	 * @param array<string,mixed> $delta One entry from the comparison.
	 * @return string
	 */
	private function label( array $delta ): string {
		$metric = is_string( $delta['metric'] ?? null ) ? $delta['metric'] : '';
		$unit   = is_string( $delta['unit'] ?? null ) ? $delta['unit'] : '';

		return '' === $unit ? $metric : sprintf( '%s (%s)', $metric, $unit );
	}

	/**
	 * A figure, or a dash where there is not one.
	 *
	 * @param mixed $value Measured value.
	 * @return string
	 */
	private function figure( mixed $value ): string {
		if ( ! is_numeric( $value ) ) {
			// A measurement that could not be taken. An em dash, not a zero:
			// "we did not measure this" and "this was zero" are different
			// statements and only one of them is true here.
			return '—';
		}

		return (string) ( $value + 0 );
	}

	/**
	 * The difference, signed, or why there is not one.
	 *
	 * @param array<string,mixed> $delta One entry from the comparison.
	 * @return string
	 */
	private function difference( array $delta ): string {
		if ( ! is_numeric( $delta['delta'] ?? null ) ) {
			$reason = is_string( $delta['reason'] ?? null ) ? $delta['reason'] : '';

			return '' === $reason ? __( 'not measured', 'debloater-pro' ) : $reason;
		}

		$change = $delta['delta'] + 0;

		if ( 0 === $change || 0.0 === $change ) {
			return __( 'no change', 'debloater-pro' );
		}

		$figure = ( $change > 0 ? '+' : '' ) . (string) $change;

		// The percentage only when the comparison worked one out. It refuses to
		// produce one from a "before" of zero, and passing that refusal through
		// rather than computing something here is the point.
		if ( is_numeric( $delta['percent'] ?? null ) ) {
			return sprintf( '%s (%s%%)', $figure, (string) ( $delta['percent'] + 0 ) );
		}

		return $figure;
	}

	/**
	 * The measured differences stored on a run.
	 *
	 * `ApplyManager` writes `Comparison::toArray()` into
	 * `$run->payload['measurements']`, which is
	 * `{ before, after, deltas, changed, unknown }` — and `deltas` is the list
	 * this report wants: one entry per metric with its unit, its before and
	 * after, the change, a percentage where an honest one exists, and a reason
	 * when the measurement could not be taken.
	 *
	 * The first version of this read the payload as
	 * `{ label: { before, after } }`, a shape nothing has ever written. It
	 * matched nothing, so every report said "nothing was measured" — including
	 * for applies that had measured plenty. Reading the producer rather than
	 * guessing at the consumer is the whole fix.
	 *
	 * @param \Debloater\Contracts\Run $run The run.
	 * @return array<int,array<string,mixed>>
	 */
	private function deltas( \Debloater\Contracts\Run $run ): array {
		$measured = $run->payload['measurements'] ?? array();

		if ( ! is_array( $measured ) || ! is_array( $measured['deltas'] ?? null ) ) {
			return array();
		}

		$deltas = array();

		foreach ( $measured['deltas'] as $delta ) {
			if ( is_array( $delta ) && isset( $delta['metric'] ) ) {
				$deltas[] = $delta;
			}
		}

		return $deltas;
	}

	/**
	 * The report title.
	 *
	 * @param string $branding Agency name, or ''.
	 * @return string
	 */
	private function title( string $branding ): string {
		if ( '' === $branding ) {
			return __( 'Site changes: before and after', 'debloater-pro' );
		}

		return sprintf(
			/* translators: %s: the agency name on the report. */
			__( '%s — site changes: before and after', 'debloater-pro' ),
			$branding
		);
	}

	/**
	 * Print CSS.
	 *
	 * Inline and tiny, because this page is opened, printed and closed. A
	 * stylesheet request would be one more thing to go wrong in the two seconds
	 * the page exists.
	 *
	 * @return string
	 */
	private function css(): string {
		return 'body{font:14px/1.5 system-ui,sans-serif;margin:2rem;color:#111}'
			. 'h1{font-size:1.5rem;margin:0 0 .25rem}'
			. '.when{color:#555;margin:0 0 1.5rem}'
			. 'table{border-collapse:collapse;width:100%}'
			. 'th,td{text-align:left;padding:.5rem .75rem;border-bottom:1px solid #ddd}'
			. 'th{font-weight:600;border-bottom-width:2px}'
			. '.footnote,.none{color:#555;margin-top:1.5rem}'
			. '@media print{body{margin:0}}';
	}
}
