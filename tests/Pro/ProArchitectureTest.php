<?php
/**
 * The boundaries Pro is not allowed to cross.
 *
 * @package Debloater
 */

declare( strict_types = 1 );

namespace Debloater\Tests\Pro;

use Debloater\Pro\Cloud\EndpointResolver;
use PHPUnit\Framework\TestCase;

/**
 * BUILD-SPEC §13 rules 13, 14 and 15, and the Phase 19 exit criteria.
 *
 * Four claims about where things live, each of which is easy to state, easy to
 * believe, and impossible to keep true by intention alone across a codebase
 * somebody else will edit next year:
 *
 * 1. No Freemius symbol outside its adapter.
 * 2. No cloud host outside the endpoint resolver.
 * 3. Pro registers no tweaks and ships no runtime handlers.
 * 4. Nothing in Pro executes anything that came off the network.
 *
 * These are greps, and greps are a blunt instrument. They are used here anyway,
 * because the thing being defended is a *location* rather than a behaviour, and
 * a location is exactly what a grep can check. The alternative — reviewing every
 * new file for whether it happens to mention Freemius — is the kind of vigilance
 * that works until the week somebody is busy.
 */
final class ProArchitectureTest extends TestCase {

	/**
	 * Rule 13: Freemius appears in one file, and that file is the adapter.
	 *
	 * @return void
	 */
	public function test_no_freemius_symbol_outside_the_adapter(): void {
		$adapter   = 'pro/src/Entitlement/FreemiusEntitlementProvider.php';
		$offenders = array();

		foreach ( $this->sources() as $path => $source ) {
			if ( $adapter === $path ) {
				continue;
			}

			// The adapter's own class name is allowed to appear: something has
			// to construct it, and Pro::defaultProvider() is that something.
			// What must not appear anywhere else is the SDK — its entry
			// function, its class, or a method only it answers. Removing the
			// adapter's name first is what separates "wires up the adapter"
			// from "talks to Freemius".
			$code = str_replace(
				'freemiusentitlementprovider',
				'',
				strtolower( $this->withoutComments( $source ) )
			);

			foreach ( array( 'freemius', 'fs_dynamic_init', 'debloater_fs', 'is_paying' ) as $needle ) {
				if ( str_contains( $code, $needle ) ) {
					$offenders[] = $path . ' names ' . $needle . ' in code';
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"§13 rule 13: the licensing platform must not be named outside its adapter.\n"
				. implode( "\n", $offenders )
		);

		// And the adapter really is where it lives, so this test cannot pass by
		// the adapter having been deleted.
		$this->assertStringContainsString(
			'freemius',
			strtolower( (string) file_get_contents( $this->path( $adapter ) ) )
		);
	}

	/**
	 * Rule 13: nothing outside the adapter asks whether a licence is valid.
	 *
	 * The subtler half of provider-agnosticism. Keeping the SDK in one file is
	 * worth little if forty call sites ask questions only that SDK can answer.
	 * Features ask `allows( 'some_feature' )` and nothing else.
	 *
	 * @return void
	 */
	public function test_features_ask_only_about_features(): void {
		$forbidden = array( 'isValid', 'is_valid', 'licenseKey', 'license_key', 'getPlan', 'is_paying' );
		$offenders = array();

		foreach ( $this->sources() as $path => $source ) {
			// Pro only. The free plugin has an unrelated SchemaValidator::isValid(),
			// and a check that flagged it would be measuring the wrong thing.
			if ( ! str_starts_with( $path, 'pro/' ) ) {
				continue;
			}

			if ( str_contains( $path, 'FreemiusEntitlementProvider' ) ) {
				continue;
			}

			$code = $this->withoutComments( $source );

			foreach ( $forbidden as $needle ) {
				if ( str_contains( $code, $needle ) ) {
					$offenders[] = $path . ' asks ' . $needle;
				}
			}
		}

		$this->assertSame( array(), $offenders, implode( "\n", $offenders ) );
	}

	/**
	 * Rule 14: one host, named in one place.
	 *
	 * @return void
	 */
	public function test_no_cloud_host_outside_the_resolver(): void {
		$resolver  = 'pro/src/Cloud/EndpointResolver.php';
		$offenders = array();

		foreach ( $this->sources() as $path => $source ) {
			if ( $resolver === $path ) {
				continue;
			}

			if ( str_contains( $source, 'hakeemify.com' ) ) {
				$offenders[] = $path;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'§13 rule 14: the cloud host must be named only in the resolver — found in '
				. implode( ', ', $offenders )
		);
	}

	/**
	 * Rule 14: every path is versioned and product-scoped.
	 *
	 * @return void
	 */
	public function test_every_cloud_path_is_versioned_and_scoped(): void {
		$resolver = new EndpointResolver();

		$this->assertSame( 'https://cloud.hakeemify.com/v1/debloater', $resolver->base() );

		$url = $resolver->url( 'reports', 'templates/default' );

		$this->assertSame(
			'https://cloud.hakeemify.com/v1/debloater/reports/templates/default',
			$url
		);

		// Nothing can walk out of the product scope.
		foreach ( array( '..', '../other', 'UPPER', 'has space', '', 'a/b', 'x?y' ) as $bad ) {
			$refused = false;

			try {
				$resolver->url( $bad );
			} catch ( \InvalidArgumentException $error ) {
				unset( $error );

				$refused = true;
			}

			$this->assertTrue(
				$refused,
				sprintf( 'The resolver should refuse the segment "%s".', $bad )
			);
		}
	}

	/**
	 * Rule 15: Pro adds no tweaks and no runtime handlers.
	 *
	 * @return void
	 */
	public function test_pro_adds_no_tweaks_and_no_handlers(): void {
		$this->assertDirectoryDoesNotExist(
			$this->path( 'pro/runtime-handlers' ),
			'Pro must not ship runtime handlers: what runs on a site is the free plugin.'
		);

		$this->assertDirectoryDoesNotExist(
			$this->path( 'pro/registry' ),
			'Pro must not ship a registry: the change list is the free plugin.'
		);

		$offenders = array();

		foreach ( $this->sources() as $path => $source ) {
			if ( ! str_starts_with( $path, 'pro/' ) ) {
				continue;
			}

			// The names of the things that make a tweak exist. Pro may read a
			// tweak id; it may not define, register or compile one.
			foreach ( array( 'TweakDefinition', 'runtime-handlers/', 'RuntimeWriter', 'Compiler', 'SnapshotManager' ) as $needle ) {
				if ( str_contains( $source, $needle ) ) {
					$offenders[] = $path . ' reaches ' . $needle;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"§13 rule 15: Pro is workflow around the engine, never part of it.\n"
				. implode( "\n", $offenders )
		);
	}

	/**
	 * Nothing off the network is executed.
	 *
	 * @return void
	 */
	public function test_nothing_remote_is_executed(): void {
		$forbidden = array( 'eval(', 'create_function', 'assert(', 'wp_enqueue_script( \'http', 'call_user_func( $response' );
		$offenders = array();

		foreach ( $this->sources() as $path => $source ) {
			if ( ! str_starts_with( $path, 'pro/' ) ) {
				continue;
			}

			foreach ( $forbidden as $needle ) {
				if ( str_contains( $source, $needle ) ) {
					$offenders[] = $path . ' contains ' . $needle;
				}
			}

			// Nor may anything be required or included from a variable path.
			$this->assertSame(
				0,
				preg_match( '/\b(require|include)(_once)?\s+\$(?!real\b)/', $source ),
				$path . ' includes a file from a variable path.'
			);
		}

		$this->assertSame( array(), $offenders, implode( "\n", $offenders ) );
	}

	/**
	 * Pro carries no secret, exactly as the free plugin carries none.
	 *
	 * @return void
	 */
	public function test_pro_ships_nothing_secret(): void {
		foreach ( $this->sources() as $path => $source ) {
			if ( ! str_starts_with( $path, 'pro/' ) ) {
				continue;
			}

			$this->assertSame(
				0,
				preg_match( '/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $source ),
				$path . ' looks like it holds a private key.'
			);

			$this->assertSame(
				0,
				preg_match( '/\b(sk_live|sk_test|pk_live|ghp_)[A-Za-z0-9]{8}/', $source ),
				$path . ' looks like it holds an API secret.'
			);
		}
	}

	/**
	 * Source with its comments removed.
	 *
	 * These checks are about where a *symbol* lives, and a docblock explaining
	 * why Freemius is confined to one file is documentation rather than a
	 * second place it lives. Grepping raw source would make writing down the
	 * reason for a rule a violation of it, which is a good way to end up with
	 * rules nobody explains.
	 *
	 * @param string $source PHP source.
	 * @return string
	 */
	private function withoutComments( string $source ): string {
		$code = '';

		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$code .= is_array( $token ) ? $token[1] : $token;
		}

		return $code;
	}

	/**
	 * Every PHP file in the free plugin and in Pro, keyed by relative path.
	 *
	 * @return array<string,string>
	 */
	private function sources(): array {
		$files = array();
		$root  = str_replace( '\\', '/', (string) realpath( $this->path( '' ) ) );

		foreach ( array( 'src', 'runtime-handlers', 'mu-loader', 'pro' ) as $directory ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $this->path( $directory ), \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
					continue;
				}

				$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 );

				$files[ $relative ] = (string) file_get_contents( $file->getPathname() );
			}
		}

		return $files;
	}

	/**
	 * A path inside the repository.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}
}
