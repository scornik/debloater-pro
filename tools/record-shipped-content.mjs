#!/usr/bin/env node
/**
 * Record what Hakeemify Debloater Pro ships, for `tools/version-discipline.mjs`.
 *
 *     node tools/record-shipped-content.mjs --why "0.2.0: profiles panel"
 *
 * Writes `tests/Packaging/pro-plugin-content.json`: every file in the release
 * archive with its sha256, and the version it shipped at. The version check
 * reads it to answer one question — has the shipped content changed since the
 * version claims it did?
 *
 * Refuses without `--why`, and writes that reason into the file. Regenerating
 * this to make a red build green is how a record stops meaning anything, and
 * the reason is what tells the next person whether the diff was intended.
 *
 * ## No exemptions here
 *
 * The free plugin's equivalent records five files by path rather than by
 * content, because a webpack bundle and Composer's autoloader differ between
 * machines. Pro has neither: it ships its own source, its config template, and
 * the Freemius SDK exactly as Composer installed it. Every file is hashed.
 *
 * If that ever stops being true -- a build step, or a generated autoloader --
 * this needs the free plugin's `generated` list and the reasoning behind it.
 */

import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const TARGET = path.join( ROOT, 'tests', 'Packaging', 'pro-plugin-content.json' );

/**
 * The version in the plugin header.
 *
 * @return {string} The version.
 */
const version = () => {
	const source = fs.readFileSync( path.join( ROOT, 'debloater-pro.php' ), 'utf8' );
	const match = source.match( /^\s*\*\s*Version:\s*(\S+)/m );

	if ( ! match ) {
		process.stderr.write( '\ndebloater-pro.php has no Version header.\n\n' );
		process.exit( 1 );
	}

	return match[ 1 ];
};

const why = ( () => {
	const at = process.argv.indexOf( '--why' );

	return at === -1 ? '' : ( process.argv[ at + 1 ] || '' ).trim();
} )();

if ( '' === why ) {
	process.stderr.write(
		'\nRefusing to re-record without a reason.\n\n' +
			'This file is the record of what a release ships, and the version check\n' +
			'reads it. Regenerating it to make a red build green is how the record\n' +
			'stops meaning anything:\n\n' +
			'    node tools/record-shipped-content.mjs --why "what changed, and why"\n\n'
	);
	process.exit( 1 );
}

const current = version();
const archive = path.join( ROOT, 'dist', `debloater-pro-${ current }.zip` );

if ( ! fs.existsSync( archive ) ) {
	process.stderr.write(
		`\nThere is no ${ path.relative( ROOT, archive ) }.\n\n` +
			'Build it first:\n\n    npm run plugin-zip\n\n'
	);
	process.exit( 1 );
}

const names = execFileSync( 'unzip', [ '-Z1', archive ], {
	encoding: 'utf8',
	maxBuffer: 1 << 28,
} )
	.split( '\n' )
	.filter( ( name ) => name !== '' && ! name.endsWith( '/' ) );

const previous = fs.existsSync( TARGET )
	? JSON.parse( fs.readFileSync( TARGET, 'utf8' ) )
	: { entries: {}, history: [] };

const entries = {};

for ( const name of names ) {
	entries[ name ] = crypto
		.createHash( 'sha256' )
		.update( execFileSync( 'unzip', [ '-p', archive, name ], { maxBuffer: 1 << 28 } ) )
		.digest( 'hex' );
}

const before = previous.entries ?? {};
const added = Object.keys( entries ).filter( ( n ) => ! ( n in before ) );
const removed = Object.keys( before ).filter( ( n ) => ! ( n in entries ) );
const changed = Object.keys( entries ).filter(
	( n ) => n in before && entries[ n ] !== before[ n ]
);

fs.mkdirSync( path.dirname( TARGET ), { recursive: true } );
fs.writeFileSync(
	TARGET,
	`${ JSON.stringify(
		{
			_comment: [
				'What Hakeemify Debloater Pro ships, by content hash, and the version it shipped at.',
				'',
				'tools/version-discipline.mjs reads this to refuse a build whose shipped',
				'content has changed while the version has not. Nothing here is exempt:',
				'Pro has no build step and no generated autoloader, so every file in the',
				'archive is hashed.',
				'',
				'Regenerate with tools/record-shipped-content.mjs --why "...", in the',
				'commit that changes the shipped code.',
			],
			version: current,
			entry_count: Object.keys( entries ).length,
			history: [
				...( previous.history ?? [] ),
				{ version: current, why, added, removed, changed },
			],
			entries,
		},
		null,
		2
	) }\n`
);

process.stdout.write(
	`Recorded ${ Object.keys( entries ).length } files for ${ current }.\n` +
		`  added:   ${ added.length }\n` +
		`  removed: ${ removed.length }\n` +
		`  changed: ${ changed.length }\n`
);
