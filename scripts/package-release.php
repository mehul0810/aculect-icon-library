<?php
/**
 * Builds a deterministic production ZIP.
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

$root = dirname( __DIR__ );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}

require_once __DIR__ . '/lib/CollectionBuild.php';

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The Zip extension is required.\n" );
	exit( 1 );
}

$plugin_source = file_get_contents( $root . '/aculect-icon-library.php' );
if ( ! preg_match( '/^[ \t*#@]*Version:\s*(\d+\.\d+\.\d+)/mi', $plugin_source, $version_match ) ) {
	fwrite( STDERR, "Plugin version could not be read.\n" );
	exit( 1 );
}
$version = $version_match[1];

$readme = file_get_contents( $root . '/readme.txt' );
if ( ! preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $stable_match ) || $version !== $stable_match[1] ) {
	fwrite( STDERR, "Plugin version and readme stable tag do not match.\n" );
	exit( 1 );
}

$files        = array(
	'.' => array(
		'aculect-icon-library.php',
		'uninstall.php',
		'readme.txt',
		'LICENSE.md',
		'assets/admin.css',
		'assets/aculect-icon.svg',
		'assets/admin.js',
		'assets/library-installer.js',
		'assets/build/custom-icons-dataviews.asset.php',
		'assets/build/custom-icons-dataviews.js',
		'assets/src/custom-icons-dataviews.js',
		'assets/icons.css',
		'assets/picker-compat.css',
	),
);
$catalog_path = $root . '/data/library-catalog.json';
$catalog_raw  = is_file( $catalog_path ) && ! is_link( $catalog_path ) ? file_get_contents( $catalog_path ) : false;
$catalog      = is_string( $catalog_raw ) && strlen( $catalog_raw ) <= 1048576 ? json_decode( $catalog_raw, true ) : null;
if ( ! is_array( $catalog ) || 1 !== ( $catalog['schema_version'] ?? null ) || ! is_array( $catalog['libraries'] ?? null ) ) {
	fwrite( STDERR, "Trusted library catalog is missing or invalid.\n" );
	exit( 1 );
}
$catalog_keys = array();
foreach ( $catalog['libraries'] as $entry ) {
	if ( ! is_array( $entry ) || ! isset( $entry['library_id'], $entry['style_id'], $entry['release_version'], $entry['package_sha256'], $entry['manifest_sha256'], $entry['package_bytes'] ) || ! is_string( $entry['library_id'] ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry['library_id'] ) || ! is_string( $entry['style_id'] ) || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry['style_id'] ) || ! is_string( $entry['release_version'] ) || strlen( $entry['release_version'] ) > 64 || ! preg_match( '/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})(?:-([0-9A-Za-z.-]+))?(?:\+([0-9A-Za-z.-]+))?$/', $entry['release_version'], $version_parts ) || ! is_string( $entry['package_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $entry['package_sha256'] ) || ! is_string( $entry['manifest_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $entry['manifest_sha256'] ) || ! is_int( $entry['package_bytes'] ) || $entry['package_bytes'] < 1 || $entry['package_bytes'] > 33554432 ) {
		fwrite( STDERR, "Trusted library catalog entry is invalid.\n" );
		exit( 1 );
	}
	foreach ( array( 4, 5 ) as $part_index ) {
		if ( empty( $version_parts[ $part_index ] ) ) {
			continue; }
		foreach ( explode( '.', $version_parts[ $part_index ] ) as $identifier ) {
			if ( '' === $identifier || ( 4 === $part_index && ctype_digit( $identifier ) && strlen( $identifier ) > 1 && '0' === $identifier[0] ) ) {
				fwrite( STDERR, "Trusted library catalog version is invalid.\n" );
				exit( 1 );
			}
		}
	}
	$key           = $entry['library_id'] . '/' . $entry['style_id'] . '/' . $entry['release_version'];
	$canonical_url = 'https://github.com/mehul0810/aculect-icon-libraries/releases/download/' . rawurlencode( $entry['library_id'] . '-' . $entry['style_id'] . '-' . $entry['release_version'] ) . '/' . rawurlencode( $entry['library_id'] . '-' . $entry['style_id'] . '-' . $entry['release_version'] . '.zip' );
	if ( isset( $catalog_keys[ $key ] ) || ( isset( $entry['url'] ) && $entry['url'] !== $canonical_url ) ) {
		fwrite( STDERR, "Trusted library catalog entry is duplicated or uses a non-canonical source.\n" );
		exit( 1 );
	}
	$catalog_keys[ $key ] = true;
}
$files['.'][]     = 'data/library-catalog.json';
$legacy_svg_paths = array();

foreach ( glob( $root . '/src/*.php' ) as $source_file ) {
	$files['.'][] = substr( $source_file, strlen( $root ) + 1 );
}
foreach ( glob( $root . '/assets/icons/*/manifest.json' ) as $manifest_path ) {
	$collection      = basename( dirname( $manifest_path ) );
	$manifest        = json_decode( file_get_contents( $manifest_path ), true );
	$manifest_errors = is_array( $manifest ) ? CollectionBuild::validate_manifest( $manifest, dirname( $manifest_path ) ) : array( 'Manifest must decode to an object.' );
	if ( ! is_array( $manifest ) || ! empty( $manifest_errors ) || 0 !== strcmp( $collection, $manifest['slug'] ?? '' ) ) {
		fwrite( STDERR, sprintf( "Collection manifest is invalid: %s\n", $collection ) );
		foreach ( $manifest_errors as $manifest_error ) {
			fwrite( STDERR, sprintf( "- %s\n", $manifest_error ) );
		}
		exit( 1 );
	}
	$files['.'][] = 'assets/icons/' . $collection . '/manifest.json';
	$files['.'][] = 'assets/icons/' . $collection . '/metadata.json';
	$files['.'][] = 'assets/icons/' . $collection . '/LICENSE';
	if ( is_file( $root . '/assets/icons/' . $collection . '/exclusions.json' ) ) {
		$files['.'][] = 'assets/icons/' . $collection . '/exclusions.json';
	}
	foreach ( $manifest['icons'] as $icon ) {
		if ( empty( $icon['path'] ) || false !== strpos( $icon['path'], '..' ) ) {
			fwrite( STDERR, "Manifest contains an unsafe icon path.\n" );
			exit( 1 );
		}
		$files['.'][] = 'assets/icons/' . $collection . '/' . $icon['path'];
	}

	// Keep the old Heroicons size paths available for blocks saved before the
	// collection moved to style-based variants.
	if ( 'heroicons' === $collection ) {
		foreach ( array( '16-solid', '20-solid', '24-solid' ) as $legacy_variant ) {
			foreach ( glob( $root . '/assets/icons/heroicons/' . $legacy_variant . '/*.svg' ) as $legacy_file ) {
				$legacy_path                      = 'assets/icons/heroicons/' . $legacy_variant . '/' . basename( $legacy_file );
				$files['.'][]                     = $legacy_path;
				$legacy_svg_paths[ $legacy_path ] = true;
			}
		}
	}
}

$files = array_values( array_unique( $files['.'] ) );
sort( $files );

foreach ( $files as $relative_path ) {
	$source_path = $root . '/' . $relative_path;
	$resolved    = realpath( $source_path );
	if ( false === $resolved || is_link( $source_path ) || 0 !== strpos( $resolved, $root . DIRECTORY_SEPARATOR ) || ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
		fwrite( STDERR, sprintf( "Package file is unreadable: %s\n", $relative_path ) );
		exit( 1 );
	}
	if ( 'svg' === strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) ) && isset( $legacy_svg_paths[ $relative_path ] ) ) {
		try {
			CollectionBuild::normalize_svg( file_get_contents( $root . '/' . $relative_path ), true );
		} catch ( RuntimeException $exception ) {
			fwrite( STDERR, sprintf( "Legacy SVG is invalid: %s (%s)\n", $relative_path, $exception->getMessage() ) );
			exit( 1 );
		}
	}
}

$build_dir = $root . '/build';
if ( ! is_dir( $build_dir ) && ! mkdir( $build_dir, 0775, true ) ) {
	fwrite( STDERR, "Build directory could not be created.\n" );
	exit( 1 );
}

$destination = $build_dir . '/aculect-icon-library.' . $version . '.zip';
$temporary   = $destination . '.tmp';
if ( file_exists( $temporary ) ) {
	unlink( $temporary );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Release ZIP could not be created.\n" );
	exit( 1 );
}

$timestamp = 946684800;
foreach ( $files as $relative_path ) {
	$archive_path = 'aculect-icon-library/' . $relative_path;
	if ( isset( $legacy_svg_paths[ $relative_path ] ) ) {
		try {
			$content = CollectionBuild::normalize_svg( file_get_contents( $root . '/' . $relative_path ), true );
		} catch ( RuntimeException $exception ) {
			$zip->close();
			fwrite( STDERR, sprintf( "Legacy SVG is invalid: %s (%s)\n", $relative_path, $exception->getMessage() ) );
			exit( 1 );
		}
		$added = $zip->addFromString( $archive_path, $content . "\n" );
	} else {
		$added = $zip->addFile( $root . '/' . $relative_path, $archive_path );
	}
	if ( ! $added || ! $zip->setMtimeName( $archive_path, $timestamp ) || ! $zip->setCompressionName( $archive_path, ZipArchive::CM_DEFLATE, 9 ) ) {
		$zip->close();
		fwrite( STDERR, sprintf( "Could not add package file: %s\n", $relative_path ) );
		exit( 1 );
	}
}
if ( ! $zip->close() ) {
	fwrite( STDERR, "Release ZIP could not be closed.\n" );
	exit( 1 );
}

if ( filesize( $temporary ) >= 10000000 ) {
	unlink( $temporary );
	fwrite( STDERR, "Release ZIP must be under 10,000,000 bytes for WordPress.org submission.\n" );
	exit( 1 );
}

if ( ! rename( $temporary, $destination ) ) {
	fwrite( STDERR, "Release ZIP could not be finalized.\n" );
	exit( 1 );
}

$verification = new ZipArchive();
$opened       = $verification->open( $destination );
// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this standard property name.
$entry_count = true === $opened ? $verification->numFiles : 0;
if ( true !== $opened || count( $files ) !== $entry_count ) {
	if ( true === $opened ) {
		$verification->close();
	}
	fwrite( STDERR, "Release ZIP verification failed.\n" );
	exit( 1 );
}
$verification->close();

printf(
	"Built %s (%d files, sha256 %s).\n",
	$destination,
	count( $files ),
	hash_file( 'sha256', $destination )
);
