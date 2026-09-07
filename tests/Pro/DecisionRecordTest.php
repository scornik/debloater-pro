<?php
/**
 * One decision, one file.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Pro;

use PHPUnit\Framework\TestCase;

/**
 * The decision record is not duplicated, and the principles have not drifted.
 *
 * Both repositories carried a full copy of `docs/DECISIONS.md` from the split
 * until 0.2.0 — sixty-three decisions each, fifty-six of them identical.
 * Nothing noticed, because nothing looked. Amending one copy would have left
 * the other stating the opposite with equal authority, and `P1` cited
 * "D-0057" without saying which file it meant.
 *
 * This is the half of the check that can see both trees. Pro's CI checks the
 * free plugin out, so this test compares the two directly. The free plugin
 * cannot check Pro out — it is private and that repository is public — so its
 * half asserts the narrower thing it can know alone: that the numbers reserved
 * for Pro do not appear in its own file.
 *
 * Between them, a number cannot end up in both files without something going
 * red.
 */
final class DecisionRecordTest extends TestCase {

	/**
	 * The decisions that belong to Pro, and only to Pro.
	 *
	 * Named here as well as in the file, so that moving one without deciding to
	 * fails rather than drifts.
	 */
	private const PRO_ONLY = array(
		'D-0035',
		'D-0050',
		'D-0060',
		'D-0061',
		'D-0062',
		'D-0064',
		'D-0065',
	);

	/**
	 * Pro's decision file.
	 *
	 * @return string
	 */
	private function pro(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/DECISIONS.md' );
	}

	/**
	 * The free plugin's decision file, or null when it is not checked out.
	 *
	 * @return string|null
	 */
	private function free(): ?string {
		$named = getenv( 'DEBLOATER_FREE_PATH' );

		$candidates = array();

		if ( is_string( $named ) && '' !== $named ) {
			$candidates[] = $named;
		}

		$candidates[] = dirname( __DIR__, 3 ) . '/debloater';

		foreach ( $candidates as $candidate ) {
			$path = $candidate . '/docs/DECISIONS.md';

			if ( is_file( $path ) ) {
				return (string) file_get_contents( $path );
			}
		}

		return null;
	}

	/**
	 * Every `## D-NNNN` heading in a document.
	 *
	 * @param string $markdown The file.
	 * @return string[] Decision ids.
	 */
	private function numbers( string $markdown ): array {
		preg_match_all( '/^## (D-\d+)/m', $markdown, $found );

		return $found[1];
	}

	/**
	 * Pro's file holds exactly the decisions that are Pro's.
	 *
	 * @return void
	 */
	public function test_pro_holds_only_its_own_decisions(): void {
		$this->assertSame(
			self::PRO_ONLY,
			$this->numbers( $this->pro() ),
			'Pro\'s decision file should hold exactly the decisions that are Pro\'s, in order.'
		);
	}

	/**
	 * No decision number appears in both repositories.
	 *
	 * @return void
	 */
	public function test_no_decision_number_is_in_both_files(): void {
		$free = $this->free();

		if ( null === $free ) {
			$this->markTestSkipped(
				'The free plugin is not checked out beside this repository, so the two '
				. 'decision records could not be compared. Clone scornik/debloater as a '
				. 'sibling, or set DEBLOATER_FREE_PATH. CI always has it.'
			);
		}

		$both = array_intersect( $this->numbers( $this->pro() ), $this->numbers( $free ) );

		$this->assertSame(
			array(),
			array_values( $both ),
			"These decisions are recorded in both repositories:\n  "
				. implode( "\n  ", $both )
				. "\n\nOne file has to be authoritative, or amending either leaves the other "
				. 'stating the opposite with equal authority. Shared decisions belong in '
				. 'scornik/debloater.'
		);
	}

	/**
	 * The free plugin still holds the decisions this file says it does.
	 *
	 * A citation that leads nowhere is worse than no citation: the reader
	 * concludes the decision was never taken.
	 *
	 * @return void
	 */
	public function test_the_moved_decisions_are_findable(): void {
		$free = $this->free();

		if ( null === $free ) {
			$this->markTestSkipped( 'The free plugin is not checked out beside this repository.' );
		}

		// The whole section, down to the next rule. Matching only to the first
		// blank line captured the paragraph above the list, which names no
		// decisions -- so the assertion failed for a reason unrelated to the
		// thing being tested.
		preg_match( '/## What moved(.*?)\n---/s', $this->pro(), $section );

		$this->assertNotEmpty( $section, 'Pro\'s file should list what moved.' );

		preg_match_all( '/D-\d+/', $section[1], $listed );

		$this->assertNotEmpty( $listed[0], 'The moved list should name some decisions.' );

		$there = $this->numbers( $free );

		foreach ( $listed[0] as $moved ) {
			$this->assertContains(
				$moved,
				$there,
				sprintf( '%s is listed as moved to the free plugin, and is not there.', $moved )
			);
		}
	}

	/**
	 * The principles here are the principles there.
	 *
	 * Pro carries them by reference — one line each, with the reasoning left in
	 * one place. A reference is only useful while it is accurate, so this fails
	 * when the free plugin's list gains, loses or rewords an entry.
	 *
	 * @return void
	 */
	public function test_the_principles_have_not_drifted(): void {
		$free = $this->free();

		if ( null === $free ) {
			$this->markTestSkipped( 'The free plugin is not checked out beside this repository.' );
		}

		// `**P1.** Text.` here; `**P1. Text**` there — the same statement in two
		// layouts, so both are reduced to number and first sentence.
		$mine   = $this->principles( $this->pro(), '/^- \*\*(P\d)\.\*\* (.+)$/m' );
		$theirs = $this->principles( $free, '/^\*\*(P\d)\. (.+?)\*\*$/ms' );

		$this->assertNotEmpty( $theirs, 'The free plugin should have a Principles section.' );

		$this->assertSame(
			array_keys( $theirs ),
			array_keys( $mine ),
			'Pro lists a different set of principles from the free plugin.'
		);

		foreach ( $theirs as $number => $statement ) {
			$this->assertSame(
				$statement,
				$mine[ $number ],
				sprintf(
					'%s reads differently here than in the free plugin. Pro carries these '
						. 'by reference, so the words have to match.',
					$number
				)
			);
		}
	}

	/**
	 * Principles as number => statement, normalised.
	 *
	 * @param string $markdown The file.
	 * @param string $pattern  How they are written there.
	 * @return array<string,string>
	 */
	private function principles( string $markdown, string $pattern ): array {
		preg_match_all( $pattern, $markdown, $found, PREG_SET_ORDER );

		$principles = array();

		foreach ( $found as $one ) {
			$principles[ $one[1] ] = trim( preg_replace( '/\s+/', ' ', $one[2] ) );
		}

		return $principles;
	}
}
