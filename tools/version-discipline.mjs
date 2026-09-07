#!/usr/bin/env node
/**
 * Shipped content and the version number move together, or the build fails.
 *
 *     node tools/version-discipline.mjs
 *
 * Run in CI after the release archive is built. It compares the archive against
 * `tests/Packaging/free-plugin-content.json` — the record of what shipped and
 * at which version — and refuses a state where the content has changed and the
 * version has not.
 *
 * It never bumps anything. A tool that fixed this by editing the version would
 * make the next release's number a side effect of a build rather than a
 * decision, and "what changed in 0.2.0" would have no answer.
 *
 * ## Why not "since the last git tag"
 *
 * That was the obvious formulation and it cannot work here.
 *
 * This repository has no tags at all. It was created by the split and nothing
 * has ever been tagged in it, so "the state at the last git tag" has no value
 * to compare against and the check could never run. The free plugin has the
 * opposite problem -- one tag, orphaned by the same rewrite -- and the same
 * answer.
 *
 * The content record does the job the tag was supposed to do, and does it
 * better: it names the files *and* the version they shipped at, in one file, in
 * the repository. `docs/RELEASING.md` keeps the tag in step with it, so once
 * real tags exist the two agree by construction rather than by hope.
 *
 * ## What counts as a change
 *
 * Every file in the archive. Pro has no build step and no generated
 * autoloader, so nothing it ships differs between machines -- unlike the free
 * plugin, which exempts five files for that reason.
 */

import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

const RECORD = path.join( ROOT, 'tests', 'Packaging', 'pro-plugin-content.json' );
const ENTRY = path.join( ROOT, 'debloater-pro.php' );
const README = path.join( ROOT, 'readme.txt' );
const PACKAGE = path.join( ROOT, 'package.json' );
const PRO_CLASS = path.join( ROOT, 'src', 'Pro.php' );

/**
 * Stop with a message and a non-zero status.
 *
 * @param {string[]} lines What is wrong and what to do.
 */
const refuse = ( lines ) => {
	process.stderr.write( `\n${ lines.join( '\n' ) }\n\n` );
	process.exit( 1 );
};

/**
 * The version in the plugin header.
 *
 * @return {string} The version.
 */
const headerVersion = () => {
	const match = fs.readFileSync( ENTRY, 'utf8' ).match( /^\s*\*\s*Version:\s*(\S+)/m );

	if ( ! match ) {
		refuse( [ `${ path.basename( ENTRY ) } has no Version header.` ] );
	}

	return match[ 1 ];
};

/**
 * The readme's stable tag.
 *
 * @return {string|null} The tag.
 */
const stableTag = () => {
	const match = fs.readFileSync( README, 'utf8' ).match( /^Stable tag:\s*(\S+)/m );

	return match ? match[ 1 ] : null;
};

/**
 * Entry names in a zip.
 *
 * @param {string} archive Path.
 * @return {string[]} Names.
 */
const entries = ( archive ) =>
	execFileSync( 'unzip', [ '-Z1', archive ], { encoding: 'utf8', maxBuffer: 1 << 28 } )
		.split( '\n' )
		.filter( ( name ) => name !== '' && ! name.endsWith( '/' ) );

/**
 * The sha256 of one entry's contents.
 *
 * @param {string} archive Path.
 * @param {string} name    Entry.
 * @return {string} Hex digest.
 */
const hashOf = ( archive, name ) =>
	crypto
		.createHash( 'sha256' )
		.update( execFileSync( 'unzip', [ '-p', archive, name ], { maxBuffer: 1 << 28 } ) )
		.digest( 'hex' );

/* ------------------------------------------------------------------- run */

const record = JSON.parse( fs.readFileSync( RECORD, 'utf8' ) );
const version = headerVersion();
const tag = stableTag();
const pkg = JSON.parse( fs.readFileSync( PACKAGE, 'utf8' ) ).version;

// The three places a version is written must agree before anything else is
// worth checking. A header that says 0.2.0 while the readme says 0.1.1 ships a
// plugin whose own files disagree about what it is.
const disagreements = [];

if ( tag && tag !== version ) {
	disagreements.push( `readme.txt Stable tag is ${ tag }, the header says ${ version }` );
}

if ( pkg !== version ) {
	disagreements.push( `package.json says ${ pkg }, the header says ${ version }` );
}

// Pro carries it a fourth time, in code. `Pro::VERSION` is sent to the cloud
// client as the product version, so a stale one misreports which Pro a site is
// running to the one service that aggregates it.
const constant = fs
	.readFileSync( PRO_CLASS, 'utf8' )
	.match( /public const VERSION = '([^']+)'/ );

if ( ! constant ) {
	refuse( [ 'src/Pro.php has no VERSION constant.' ] );
}

if ( constant[ 1 ] !== version ) {
	disagreements.push( `Pro::VERSION is ${ constant[ 1 ] }, the header says ${ version }` );
}

if ( disagreements.length > 0 ) {
	refuse( [
		'The version is written in more than one place and they disagree:',
		'',
		...disagreements.map( ( one ) => `  - ${ one }` ),
		'',
		'docs/RELEASING.md lists every place that has to move together.',
	] );
}

const archive = path.join( ROOT, 'dist', `debloater-pro-${ version }.zip` );

if ( ! fs.existsSync( archive ) ) {
	refuse( [
		`There is no ${ path.relative( ROOT, archive ) }.`,
		'',
		'This check reads the built archive. Build it first:',
		'',
		'    npm run plugin-zip',
	] );
}

const recorded = record.entries ?? {};
const built = {};

for ( const name of entries( archive ) ) {
	built[ name ] = hashOf( archive, name );
}

const changed = Object.keys( built ).filter(
	( name ) => name in recorded && built[ name ] !== recorded[ name ]
);
const added = Object.keys( built ).filter( ( name ) => ! ( name in recorded ) );
const removed = Object.keys( recorded ).filter( ( name ) => ! ( name in built ) );

const moved = [ ...changed, ...added, ...removed ];

if ( 0 === moved.length ) {
	process.stdout.write(
		`Shipped content is unchanged since ${ record.version }, and the version is ` +
			`${ version }. Nothing to reconcile.\n`
	);
	process.exit( 0 );
}

if ( version === record.version ) {
	refuse( [
		`${ moved.length } shipped file(s) differ from what ${ record.version } shipped, ` +
			`and the version is still ${ record.version }.`,
		'',
		...changed.map( ( name ) => `  changed  ${ name }` ),
		...added.map( ( name ) => `  added    ${ name }` ),
		...removed.map( ( name ) => `  removed  ${ name }` ),
		'',
		'A release that ships different code under the same version number lies',
		'about itself: sites cannot tell they have something new, and "what',
		'changed in ' + record.version + '" stops having an answer.',
		'',
		'Bump the version and record the change. docs/RELEASING.md has the steps.',
		'This check will not do it for you — which version comes next is a',
		'decision, not a side effect of a build.',
	] );
}

process.stdout.write(
	`${ moved.length } shipped file(s) differ from ${ record.version }, and the version ` +
		`is now ${ version }. That is a release.\n`
);

for ( const name of changed.slice( 0, 10 ) ) {
	process.stdout.write( `  changed  ${ name }\n` );
}

for ( const name of added.slice( 0, 10 ) ) {
	process.stdout.write( `  added    ${ name }\n` );
}

for ( const name of removed.slice( 0, 10 ) ) {
	process.stdout.write( `  removed  ${ name }\n` );
}

if ( moved.length > 30 ) {
	process.stdout.write( `  … and ${ moved.length - 30 } more\n` );
}
