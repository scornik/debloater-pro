<?php
/**
 * The display name, and the identifiers that must not move with it.
 *
 * @package DebloaterPro
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Pro;

use Debloater\Pro\Admin\Screen;
use Debloater\Pro\Pro;
use PHPUnit\Framework\TestCase;

/**
 * Pro 0.3.1 renamed the product "Hakeemify Debloater Pro" so the plugins list
 * reads as one vendor beside "Hakeemify Debloater". Only the name moved.
 *
 * `debloater-pro` is the folder, the main file, the text domain and the Freemius
 * slug, and it is bound to Freemius product 38409, the deployed archive and every
 * licence already issued. Change it and those licences point at a plugin that no
 * longer exists.
 *
 * The danger is the next bulk rename. A search-and-replace that makes the slug
 * "consistent" with the name would pass every other test here, because they
 * read the slug through the same code it changed. So everything below is a
 * **literal** (P4), and the last test states outright that the name and the
 * slug are different strings on purpose.
 */
final class DisplayNameTest extends TestCase {

	/**
	 * What people see.
	 */
	private const NAME = 'Hakeemify Debloater Pro';

	/**
	 * What WordPress, Freemius and every licence are keyed on.
	 */
	private const SLUG = 'debloater-pro';

	/**
	 * The display name is the same wherever it is shown.
	 *
	 * @return void
	 */
	public function test_the_display_name_is_hakeemify_debloater_pro(): void {
		$this->assertSame( self::NAME, $this->header( 'Plugin Name' ) );
		$this->assertSame( self::NAME, Pro::NAME );

		$this->assertStringStartsWith( '=== ' . self::NAME . " ===\n", $this->file( 'readme.txt' ) );
	}

	/**
	 * The identifiers are still `debloater-pro`, everywhere they appear.
	 *
	 * @return void
	 */
	public function test_the_slug_did_not_move_with_the_name(): void {
		$entry = $this->file( 'debloater-pro.php' );

		// The main file, by existing under this name, and the folder, which is
		// what the build names the archive's single top-level directory after.
		$this->assertFileExists( $this->root() . '/debloater-pro.php' );
		$this->assertStringContainsString( "slug: 'debloater-pro',", $this->file( 'scripts/plugin-zip.mjs' ) );

		$this->assertSame( self::SLUG, $this->header( 'Text Domain' ) );

		// Freemius. Both keys, because the SDK uses both to find this product.
		$this->assertStringContainsString( "'slug'             => 'debloater-pro',", $entry );
		$this->assertStringContainsString( "'premium_slug'     => 'debloater-pro',", $entry );
		$this->assertStringContainsString( "'id'         => '38409',", $this->file( 'config/freemius.php.dist' ) );

		// The admin page slug, which is in every bookmarked and linked URL.
		$this->assertSame( self::SLUG, Screen::SLUG );
	}

	/**
	 * The name and the slug are allowed to differ, and do.
	 *
	 * Written as an assertion so the reason is where somebody making them match
	 * will read it: the slug is not derived from the name and must not be
	 * brought into line with it.
	 *
	 * @return void
	 */
	public function test_the_name_and_the_slug_are_different_strings(): void {
		$slugified = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( self::NAME ) ), '-' );

		$this->assertSame( 'hakeemify-debloater-pro', $slugified );
		$this->assertNotSame(
			$slugified,
			self::SLUG,
			'The display name and the slug are different on purpose. debloater-pro is bound to Freemius product 38409 and every issued licence.'
		);
	}

	/**
	 * A plugin header field.
	 *
	 * @param string $field Header name.
	 * @return string
	 */
	private function header( string $field ): string {
		$this->assertSame(
			1,
			preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/m', $this->file( 'debloater-pro.php' ), $match ),
			$field . ' is missing from the plugin header.'
		);

		return trim( $match[1] );
	}

	/**
	 * A file from this repository.
	 *
	 * @param string $relative Path from the repository root.
	 * @return string
	 */
	private function file( string $relative ): string {
		$contents = file_get_contents( $this->root() . '/' . $relative );

		$this->assertIsString( $contents, $relative . ' could not be read.' );

		return str_replace( "\r\n", "\n", $contents );
	}

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}
}
