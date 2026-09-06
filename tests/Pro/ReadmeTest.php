<?php
/**
 * readme.txt, and the header it must not drift from.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Pro;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19b part 2, after Freemius reported the file missing.
 *
 * Freemius parses `Requires at least`, `Tested up to` and `Stable tag` from
 * this file on deployment. Two places now state the same three facts, so the
 * only interesting question is whether they still agree — a stable tag left
 * behind at the previous version is the kind of thing nobody notices until a
 * customer is offered an update that does not exist.
 *
 * This file is for Freemius and for nothing else. Pro is not on wordpress.org
 * and will not be, so the readme carries no `Contributors`, no `Donate link`,
 * no `Tags` and no link to that repository: those lines would imply a listing
 * that does not exist, to anybody who opened the file.
 */
final class ReadmeTest extends TestCase {

	/**
	 * The stable tag is the version the plugin header declares.
	 *
	 * @return void
	 */
	public function test_the_stable_tag_matches_the_plugin_version(): void {
		$this->assertSame(
			$this->header( 'Version' ),
			$this->readme( 'Stable tag' ),
			'readme.txt Stable tag and the plugin header Version must agree, or Freemius '
				. 'offers a version that was never released.'
		);

		// Three numbers, because that is what a version is compared as.
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $this->readme( 'Stable tag' ) );
	}

	/**
	 * The requirements are the same in both files.
	 *
	 * @return void
	 */
	public function test_the_requirements_match_the_plugin_header(): void {
		foreach ( array( 'Requires at least', 'Requires PHP' ) as $field ) {
			$this->assertSame(
				$this->header( $field ),
				$this->readme( $field ),
				sprintf( '"%s" differs between readme.txt and the plugin header.', $field )
			);
		}

		$this->assertSame( '6.5', $this->readme( 'Requires at least' ) );
		$this->assertSame( '8.1', $this->readme( 'Requires PHP' ) );
		$this->assertSame( 'GPL-2.0-or-later', $this->readme( 'License' ) );
	}

	/**
	 * Tested up to is a released version, and the one the free plugin names.
	 *
	 * The two plugins are installed together, so a Pro readme claiming a
	 * different ceiling than Debloater's would be describing a combination
	 * nobody has tested.
	 *
	 * @return void
	 */
	public function test_tested_up_to_matches_the_free_plugin(): void {
		$tested = $this->readme( 'Tested up to' );

		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $tested );

		// The same two places ProArchitectureTest looks, and in the same order.
		// This test looked only at the sibling, so it skipped on every machine
		// and every CI job where the free plugin is somewhere else and named by
		// DEBLOATER_FREE_PATH -- which is the arrangement README.md documents.
		// A skip is a legitimate outcome when the plugin is genuinely absent
		// and a lie when it is present under another name.
		$named = getenv( 'DEBLOATER_FREE_PATH' );
		$free  = '';

		foreach ( array( is_string( $named ) ? $named : '', dirname( __DIR__, 3 ) . '/debloater' ) as $candidate ) {
			if ( '' !== $candidate && is_file( $candidate . '/readme.txt' ) ) {
				$free = $candidate . '/readme.txt';

				break;
			}
		}

		if ( '' === $free ) {
			$this->markTestSkipped(
				'The free plugin is not checked out beside this one, so the two "Tested up to" '
					. 'values could not be compared. Clone scornik/debloater as a sibling, or '
					. 'set DEBLOATER_FREE_PATH.'
			);
		}

		$matched = preg_match( '/^Tested up to:\s*(\S+)\s*$/m', (string) file_get_contents( $free ), $value );

		$this->assertSame( 1, $matched );
		$this->assertSame(
			$value[1],
			$tested,
			'Debloater and Debloater Pro are installed together; one may not claim a ceiling the other has not tested.'
		);
	}

	/**
	 * Nothing here implies a wordpress.org listing.
	 *
	 * @return void
	 */
	public function test_the_readme_claims_no_wordpress_org_listing(): void {
		$readme = $this->file();

		foreach ( array( 'Contributors:', 'Donate link:', 'Tags:' ) as $field ) {
			$this->assertStringNotContainsString(
				$field,
				$readme,
				sprintf( '"%s" belongs to a wordpress.org listing, and Pro does not have one.', $field )
			);
		}

		// The GPL's own URL is gnu.org, so a licence link is not a claim about
		// a listing. A wordpress.org link would be.
		$this->assertStringNotContainsString( 'wordpress.org', $readme );
	}

	/**
	 * The changelog names the version being shipped.
	 *
	 * @return void
	 */
	public function test_the_changelog_covers_this_version(): void {
		$readme = $this->file();

		$this->assertStringContainsString( '== Changelog ==', $readme );
		$this->assertStringContainsString( '= ' . $this->header( 'Version' ) . ' =', $readme );
	}

	/**
	 * It is packaged, or Freemius will report it missing again.
	 *
	 * @return void
	 */
	public function test_the_readme_is_in_the_ship_list(): void {
		$builder = (string) file_get_contents( dirname( __DIR__, 2 ) . '/scripts/plugin-zip.mjs' );

		$this->assertStringContainsString(
			"{ from: 'readme.txt' }",
			$builder,
			'readme.txt must be in the zip, which is the only place Freemius reads it from.'
		);
	}

	/**
	 * One field from readme.txt's header block.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private function readme( string $field ): string {
		$matched = preg_match(
			'/^' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m',
			$this->file(),
			$value
		);

		$this->assertSame( 1, $matched, sprintf( 'readme.txt has no "%s".', $field ) );

		return $value[1];
	}

	/**
	 * One field from the plugin header.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private function header( string $field ): string {
		$path = dirname( __DIR__, 2 ) . '/debloater-pro.php';

		$this->assertFileExists( $path );

		$matched = preg_match(
			'/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+?)\s*$/m',
			(string) file_get_contents( $path ),
			$value
		);

		$this->assertSame( 1, $matched, sprintf( 'the plugin header has no "%s".', $field ) );

		return $value[1];
	}

	/**
	 * readme.txt's contents.
	 *
	 * @return string
	 */
	private function file(): string {
		$path = dirname( __DIR__, 2 ) . '/readme.txt';

		$this->assertFileExists( $path, 'Freemius reads this file on deployment and reports it missing without one.' );

		return (string) file_get_contents( $path );
	}
}
