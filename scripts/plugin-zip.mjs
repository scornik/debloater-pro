#!/usr/bin/env node
/**
 * Build a distributable zip, identically on every platform.
 *
 * `npm run plugin-zip` (free), `npm run plugin-zip:pro`, `npm run
 * plugin-zip:all`. Output goes to `dist/<slug>-<version>.zip`, with the version
 * read from the plugin header.
 *
 * BUILD-SPEC §17 Phase 18, and Phase 18b after this shipped a zip that could
 * not be installed.
 *
 * ## The bug this exists to prevent
 *
 * The first version shelled out: `Compress-Archive` on Windows, `zip`
 * everywhere else. `Compress-Archive` under Windows PowerShell 5.1 writes
 * entry names with **backslash** separators — `debloater\debloater.php`. That
 * is legal in the container format and wrong for every consumer: the zip
 * specification says path separators are forward slashes, and a Windows tool
 * ignoring that produces an archive only Windows can read.
 *
 * On a Linux host WordPress extracts `debloater\debloater.php` as one flat file
 * whose *name* contains a backslash. The plugin directory is then empty, and
 * activation fails with "Plugin file does not exist."
 *
 * It is worth recording how this passed a review that thought it had checked.
 * Python's `zipfile.namelist()` — and most zip readers — normalise backslashes
 * to forward slashes on read, so the obvious verification reported zero
 * offending entries on an archive where all 302 were wrong. The check has to
 * read the central directory bytes, which is what `tests/packaging/` does.
 *
 * ## Rules
 *
 * - **Never shell out.** No PowerShell, no `zip`, no OS tool. `archiver` writes
 *   the bytes in-process, so the output does not depend on the machine.
 * - **Forward slashes, always.** Entry names are joined with `/` literally,
 *   never with `path.join`, which is `\` on Windows.
 * - **Explicit directory entries.** Not required by the format, but some
 *   extractors rely on them, and their absence is the other half of how an
 *   archive ends up flat.
 * - **One top-level folder,** named for the slug: that is the directory
 *   WordPress installs into.
 * - **Every entry carries the same fixed timestamp.** A zip stores each file's
 *   modification time, so two archives built from identical sources minutes
 *   apart differ in bytes while installing the very same plugin — and a
 *   `composer install` rewrites `vendor/` mtimes, so this happened on every
 *   release build. It made the archive's checksum useless: it identified one
 *   build rather than one plugin, and could not be used to say "the shipped
 *   code did not change".
 *
 *   With the dates fixed the bytes are a function of the contents, so the
 *   checksum answers the question people actually ask of it. Nothing reads
 *   these timestamps: WordPress does not, the updater does not, and the file
 *   dates on a live site come from the extraction, not the archive.
 * - **`.distignore` at each plugin root** is the exclusion list, read from that
 *   plugin's own root rather than the repository's.
 * - **An allow-list decides what ships,** and `.distignore` can only remove
 *   from it. The brief for this phase asked for `.distignore` to be the single
 *   include/exclude source, and this is the one place it is not followed, so
 *   the reason is written here rather than in a commit message nobody reads
 *   again.
 *
 *   A deny-list ships everything not named. This repository's root holds
 *   `node_modules/`, a `vendor/` with forty dev packages, `tests/`, `docs/`,
 *   `admin-ui/` and `dist/` — so a missing line means a release containing
 *   them, and the failure is silent: the zip installs and works, and only the
 *   size or a reviewer says otherwise. The free plugin ships nine named files
 *   out of `vendor/`; expressing "these nine and nothing else" as exclusions
 *   means listing every package that exists today and every one added later.
 *
 *   An allow-list fails the other way. A file that should ship and is not
 *   listed is missing, and the plugin breaks loudly on the first install. That
 *   is the direction to fail in, and it is why this shipped a broken zip once
 *   and has not since.
 */

import { ZipArchive } from 'archiver';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import url from 'node:url';

const ROOT = path.resolve( path.dirname( url.fileURLToPath( import.meta.url ) ), '..' );
const DIST = path.join( ROOT, 'dist' );

/**
 * The timestamp every entry carries.
 *
 * Fixed, so the archive is a function of its contents. The particular moment is
 * arbitrary and deliberately so — it is not the build time, not the release
 * date and not any file's mtime, because each of those would put something in
 * the bytes that is not the plugin.
 */
const ENTRY_DATE = new Date( Date.UTC( 2024, 0, 1, 0, 0, 0 ) );

/**
 * The plugin this repository builds.
 *
 * Pro used to be here too, as a second entry producing a second zip. It lives
 * in its own repository now, so what is left is a table with one row — kept as
 * a table rather than flattened, because the shape is what makes "never inside
 * the free one" structural rather than a rule somebody remembers.
 */
const PLUGINS = {
	pro: {
		slug: 'debloater-pro',
		root: ROOT,
		entry: 'debloater-pro.php',
		requiresBuild: false,
		ship: [
			{ from: 'debloater-pro.php' },
			{ from: 'src' },
			{ from: 'config/freemius.php.dist' },

			// The licensing SDK. Third-party, and it ships: a Pro build
			// without it has no way to know what the customer has paid for.
			//
			// Its own tests, build tooling and development apparatus do not.
			// The filter is a deny-list here rather than an allow-list because
			// the SDK is somebody else's tree and naming every file in it would
			// be a list that goes stale on their release schedule, not ours.
			{
				from: 'vendor/freemius/wordpress-sdk',
				filter: ( rel ) =>
					! rel.startsWith( 'tests/' ) &&
					! rel.startsWith( 'assets/scss/' ) &&
					! rel.endsWith( '.map' ) &&
					! rel.endsWith( '.scss' ) &&
					! /(^|\/)(gulpfile|package|composer)\.(js|json)$/.test( rel ),
			},
		],
	},
};

/**
 * Fail with a message and a way forward.
 *
 * @param {string} message What is wrong.
 * @param {string} [fix]   What to do about it.
 */
function refuse( message, fix ) {
	process.stderr.write( `\nRefusing to build the zip: ${ message }\n` );

	if ( fix ) {
		process.stderr.write( `\n  ${ fix }\n` );
	}

	process.stderr.write( '\n' );
	process.exit( 1 );
}

/**
 * Patterns from one plugin's `.distignore`.
 *
 * Read from **that plugin's own root**, which is the fix for a quiet bug: this
 * used to read the repository's `.distignore` and apply it to both plugins.
 * Its entries are written relative to the repository, so `src` and `tests`
 * meant the free plugin's directories — and matching them against paths inside
 * `pro/` was a comparison of two different things that happened not to collide.
 * Pro now has its own file, and each plugin is measured against its own root.
 *
 * WordPress tooling reads this file too, so honouring it here keeps the two
 * from disagreeing. It can only ever remove: what ships is decided by the ship
 * list, for reasons set out at the top of this file.
 *
 * @param {object} plugin Plugin definition.
 * @return {string[]} Entries.
 */
function distignore( plugin ) {
	const file = path.join( plugin.root, '.distignore' );

	if ( ! fs.existsSync( file ) ) {
		refuse(
			`${ plugin.slug } has no .distignore at ${ path.relative( ROOT, file ).split( path.sep ).join( '/' ) }.`,
			'Every plugin root carries one, so what is excluded is written down next to what it excludes.'
		);
	}

	return fs
		.readFileSync( file, 'utf8' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( ( line ) => '' !== line && ! line.startsWith( '#' ) );
}

/**
 * Whether `.distignore` excludes a path.
 *
 * @param {string[]} patterns Entries from `.distignore`.
 * @param {string}   relative Forward-slash path relative to the plugin root.
 * @return {boolean} True when it must not ship.
 */
function ignored( patterns, relative ) {
	return patterns.some( ( pattern ) => {
		const clean = pattern.replace( /^\/+|\/+$/g, '' );

		if ( '' === clean ) {
			return false;
		}

		if ( clean.startsWith( '*' ) ) {
			return relative.endsWith( clean.slice( 1 ) );
		}

		return relative === clean || relative.startsWith( `${ clean }/` );
	} );
}

/**
 * Every file under a directory, as forward-slash paths relative to it.
 *
 * Sorted, so two builds of the same tree produce the same archive.
 *
 * @param {string} directory Absolute path.
 * @return {string[]} Relative POSIX paths.
 */
function walk( directory ) {
	const found = [];

	for ( const entry of fs.readdirSync( directory, { withFileTypes: true, recursive: true } ) ) {
		if ( ! entry.isFile() ) {
			continue;
		}

		const parent = path.relative( directory, entry.parentPath || entry.path );

		found.push( path.join( parent, entry.name ).split( path.sep ).join( '/' ) );
	}

	return found.sort();
}

/**
 * Resolve one plugin's ship list to POSIX paths relative to its root.
 *
 * @param {object}   plugin   Plugin definition.
 * @param {string[]} patterns `.distignore` entries.
 * @return {string[]} Relative POSIX paths, sorted.
 */
function collect( plugin, patterns ) {
	const files = new Set();

	for ( const entry of plugin.ship ) {
		const source = path.join( plugin.root, entry.from );

		if ( ! fs.existsSync( source ) ) {
			refuse( `${ entry.from } is in the ship list but not in the repository.` );
		}

		if ( fs.statSync( source ).isFile() ) {
			files.add( entry.from );

			continue;
		}

		for ( const relative of walk( source ) ) {
			// Never a dotfile. `.gitkeep` markers were shipping inside src/,
			// and wordpress.org rejects hidden files — but the better reason is
			// that a dotfile in a release is always an accident: it exists to
			// say something to the repository, not to the site.
			if ( relative.split( '/' ).some( ( part ) => part.startsWith( '.' ) ) ) {
				continue;
			}

			if ( entry.filter && ! entry.filter( relative ) ) {
				continue;
			}

			files.add( `${ entry.from }/${ relative }` );
		}
	}

	return [ ...files ].filter( ( file ) => ! ignored( patterns, file ) ).sort();
}

/**
 * Refuse a file list containing something that must never ship.
 *
 * The allow-list makes this close to impossible, which is exactly why it is
 * worth checking: an assertion that never fires costs nothing, and this is the
 * last point at which a mistake is still cheap.
 *
 * @param {object}   plugin Plugin definition.
 * @param {string[]} files  Relative POSIX paths.
 */
function audit( plugin, files ) {
	const forbidden = [
		{ test: ( f ) => f.startsWith( 'tests/' ), why: 'test files' },
		{ test: ( f ) => f.includes( 'node_modules/' ), why: 'node_modules' },
		{ test: ( f ) => f.startsWith( '.github' ), why: 'CI configuration' },
		{ test: ( f ) => f.endsWith( '.map' ), why: 'source maps' },
		{ test: ( f ) => '.wp-env.json' === f, why: 'the local environment config' },
		{ test: ( f ) => f.endsWith( '.pem' ) || f.endsWith( '.key' ), why: 'a key file' },
		{ test: ( f ) => f.split( '/' ).some( ( p ) => p.startsWith( '.' ) ), why: 'a hidden file' },
	];

	for ( const file of files ) {
		for ( const rule of forbidden ) {
			if ( rule.test( file ) ) {
				refuse( `${ plugin.slug } would ship ${ rule.why }: ${ file }` );
			}
		}
	}

	for ( const file of files ) {
		if ( ! /\.(php|json|txt|js|pot|po)$/.test( file ) ) {
			continue;
		}

		const contents = fs.readFileSync( path.join( plugin.root, file ), 'utf8' );

		if ( /-----BEGIN [A-Z ]*PRIVATE KEY-----/.test( contents ) ) {
			refuse( `${ file } contains what looks like a private key (§13 rule 15).` );
		}
	}
}

/**
 * The version from a plugin header, checked against readme.txt where there is one.
 *
 * @param {object} plugin Plugin definition.
 * @return {string} Version.
 */
function version( plugin ) {
	const header = fs.readFileSync( path.join( plugin.root, plugin.entry ), 'utf8' );
	const matched = /^\s*\*\s*Version:\s*(\S+)/m.exec( header );

	if ( ! matched ) {
		refuse( `${ plugin.entry } has no Version header.` );
	}

	const readme = path.join( plugin.root, 'readme.txt' );

	if ( fs.existsSync( readme ) ) {
		const stable = /^Stable tag:\s*(\S+)/m.exec( fs.readFileSync( readme, 'utf8' ) );

		if ( ! stable ) {
			refuse( 'readme.txt has no Stable tag.' );
		}

		if ( stable[ 1 ] !== matched[ 1 ] ) {
			refuse(
				`the plugin header says ${ matched[ 1 ] } and readme.txt says ${ stable[ 1 ] }.`,
				'These have to agree: wordpress.org serves whichever one it reads first.'
			);
		}
	}

	return matched[ 1 ];
}

/**
 * Refuse an autoloader generated with dev dependencies installed.
 *
 * @param {object} plugin Plugin definition.
 */
function requireProductionAutoloader( plugin ) {
	const autoload = path.join( plugin.root, 'vendor', 'autoload.php' );

	if ( ! fs.existsSync( autoload ) ) {
		refuse( 'vendor/autoload.php is missing.', 'composer install --no-dev' );
	}

	for ( const file of [ 'autoload_psr4.php', 'autoload_static.php', 'autoload_classmap.php' ] ) {
		const where = path.join( plugin.root, 'vendor', 'composer', file );

		if ( ! fs.existsSync( where ) ) {
			continue;
		}

		const contents = fs.readFileSync( where, 'utf8' );

		for ( const marker of [ 'Tests\\\\', 'phpunit', 'PHPUnit', 'PHPCSUtils', 'PHPStan', 'Yoast' ] ) {
			if ( contents.includes( marker ) ) {
				refuse(
					`vendor/composer/${ file } mentions ${ marker }, so the autoloader was ` +
						'generated with dev dependencies installed.',
					'composer install --no-dev --classmap-authoritative'
				);
			}
		}
	}
}

/**
 * Write one zip.
 *
 * @param {object}   plugin Plugin definition.
 * @param {string[]} files  Relative POSIX paths.
 * @param {string}   tag    Version.
 * @return {Promise<string>} Path to the archive.
 */
function write( plugin, files, tag ) {
	const archive = path.join( DIST, `${ plugin.slug }-${ tag }.zip` );

	fs.mkdirSync( DIST, { recursive: true } );
	fs.rmSync( archive, { force: true } );

	return new Promise( ( resolve, reject ) => {
		const output = fs.createWriteStream( archive );
		const zip = new ZipArchive( { zlib: { level: 9 } } );

		output.on( 'close', () => resolve( archive ) );
		zip.on( 'warning', reject );
		zip.on( 'error', reject );
		zip.pipe( output );

		// Explicit directory entries, parents before children, so an extractor
		// that relies on them never meets a file before its folder.
		const directories = new Set();

		for ( const file of files ) {
			const parts = file.split( '/' );

			for ( let index = 0; index < parts.length - 1; index++ ) {
				directories.add( parts.slice( 0, index + 1 ).join( '/' ) );
			}
		}

		zip.append( null, { name: `${ plugin.slug }/`, date: ENTRY_DATE } );

		for ( const directory of [ ...directories ].sort() ) {
			zip.append( null, { name: `${ plugin.slug }/${ directory }/`, date: ENTRY_DATE } );
		}

		for ( const file of files ) {
			// Read here rather than handed to `zip.file()` as a path. Given a
			// path, archiver opens the file itself and appends the entry when
			// that read completes — so the order of entries in the archive
			// follows the order the filesystem answered in, which is not the
			// order they were asked for and not the same twice. Two builds of
			// an unchanged tree produced the same 340 entries in different
			// positions, and therefore different bytes.
			//
			// A buffer is already in hand, so each entry is appended in the
			// order this loop visits it. The whole plugin is half a megabyte;
			// there is nothing to stream.
			//
			// Joined with a literal `/`, never `path.join`, which on Windows
			// produces the backslashes this whole file exists to prevent.
			zip.append( fs.readFileSync( path.join( plugin.root, file ) ), {
				name: `${ plugin.slug }/${ file }`,
				date: ENTRY_DATE,
			} );
		}

		zip.finalize();
	} );
}

/**
 * Build one plugin's zip.
 *
 * @param {string} which Key in PLUGINS.
 * @return {Promise<void>}
 */
async function build( which ) {
	const plugin = PLUGINS[ which ];

	if ( plugin.requiresBuild ) {
		const built = path.join( plugin.root, 'build' );

		if ( ! fs.existsSync( built ) || 0 === fs.readdirSync( built ).length ) {
			refuse( 'build/ is empty, so the admin screen would be blank.', 'npm run build' );
		}

		requireProductionAutoloader( plugin );
	}

	const tag = version( plugin );
	const files = collect( plugin, distignore( plugin ) );

	audit( plugin, files );

	const archive = await write( plugin, files, tag );
	const size = fs.statSync( archive ).size;

	process.stdout.write(
		`\n${ path.relative( ROOT, archive ).split( path.sep ).join( '/' ) }\n` +
			`  ${ files.length } files, ${ ( size / 1024 ).toFixed( 0 ) } KB\n`
	);
}

const requested = process.argv[ 2 ] ?? 'all';
const targets = 'all' === requested ? Object.keys( PLUGINS ) : [ requested ];

for ( const target of targets ) {
	if ( ! PLUGINS[ target ] ) {
		refuse( `Unknown plugin "${ target }". Use one of: ${ Object.keys( PLUGINS ).join( ', ' ) }, all.` );
	}
}

for ( const target of targets ) {
	await build( target );
}

process.stdout.write( '\n' );
