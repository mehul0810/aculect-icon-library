<?php
/**
 * Builds a deterministic production ZIP.
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

$root = dirname( __DIR__ );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This script must run from the command line.\n" );
	exit( 1 );
}

if ( count( $argv ) > 1 ) {
	fwrite( STDERR, "Usage: php scripts/package-release.php\n" );
	exit( 1 );
}

require_once __DIR__ . '/lib/CollectionBuild.php';

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The Zip extension is required.\n" );
	exit( 1 );
}
if ( ! method_exists( 'ZipArchive', 'setMtimeName' ) ) {
	fwrite( STDERR, "Deterministic packaging requires a PHP Zip extension with ZipArchive::setMtimeName(). Use a build PHP with this method.\n" );
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

$files            = array(
	'.' => array(
		'aculect-icon-library.php',
		'uninstall.php',
		'readme.txt',
		'LICENSE.md',
		'assets/admin.css',
		'assets/aculect-icon.svg',
		'assets/admin.js',
		'assets/build/custom-icons-dataviews.asset.php',
		'assets/build/custom-icons-dataviews.js',
		'assets/src/custom-icons-dataviews.js',
		'assets/icons.css',
		'assets/picker-compat.css',
		'assets/bounded-picker.js',
		'assets/bounded-picker.css',
	),
);
$files['.']       = array_merge(
	$files['.'],
	array_map(
		static function ( $file ) {
			return 'src/' . $file . '.php';
		},
		array(
			'AbilityRegistrar',
			'AdminActions',
			'AdminPage',
			'BlockTreeEditor',
			'CollectionRegistry',
			'CoreDiscoveryFilter',
			'CoreIconMetadataReader',
			'CoreIconRegistrar',
			'CustomIconRepository',
			'CustomIconListing',
			'CustomSvgNormalizer',
			'DatabaseTransaction',
			'IconBlockService',
			'IconNameIndex',
			'InstalledLibraryRepository',
			'LibraryJobStore',
			'ManifestLoader',
			'OptionMutationLock',
			'Plugin',
			'PostContentWriter',
			'RestController',
			'SvgSanitizer',
		)
	)
);
$legacy_svg_paths = array();
$profile          = array(
	'schema_version' => 1,
	'profile'        => 'bundled',
	'icon_families'  => array(),
);

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
	$files['.'][]               = 'assets/icons/' . $collection . '/manifest.json';
	$files['.'][]               = 'assets/icons/' . $collection . '/metadata.json';
	$files['.'][]               = 'assets/icons/' . $collection . '/LICENSE';
	$profile['icon_families'][] = $collection;
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

sort( $profile['icon_families'] );
if ( 10 !== count( $profile['icon_families'] ) ) {
	fwrite( STDERR, "Exactly ten bundled icon families are required for this package.\n" );
	exit( 1 );
}

$profile_json = json_encode( $profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( ! is_string( $profile_json ) ) {
	fwrite( STDERR, "Package profile could not be encoded.\n" );
	exit( 1 );
}
$generated_files = array( 'data/package-profile.json' => $profile_json . "\n" );

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

$suffix      = '.' . $version;
$destination = $build_dir . '/aculect-icon-library' . $suffix . '.zip';
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
foreach ( $generated_files as $relative_path => $content ) {
	$archive_path = 'aculect-icon-library/' . $relative_path;
	$added        = $zip->addFromString( $archive_path, $content );
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
if ( true !== $opened || count( $files ) + count( $generated_files ) !== $entry_count ) {
	if ( true === $opened ) {
		$verification->close();
	}
	fwrite( STDERR, "Release ZIP verification failed.\n" );
	exit( 1 );
}
$profile_entry    = $verification->getFromName( 'aculect-icon-library/data/package-profile.json' );
$verified_profile = is_string( $profile_entry ) ? json_decode( $profile_entry, true ) : null;
$profile_schema   = is_array( $verified_profile ) ? ( $verified_profile['schema_version'] ?? null ) : null;
$profile_name     = is_array( $verified_profile ) ? ( $verified_profile['profile'] ?? null ) : null;
$profile_families = is_array( $verified_profile ) ? ( $verified_profile['icon_families'] ?? null ) : null;
if ( ! is_array( $verified_profile ) || 1 !== $profile_schema || 'bundled' !== $profile_name || $profile['icon_families'] !== $profile_families ) {
	$verification->close();
	fwrite( STDERR, "Package profile verification failed.\n" );
	exit( 1 );
}
for ( $index = 0; $index < $entry_count; ++$index ) {
	$name = $verification->getNameIndex( $index );
	if ( ! is_string( $name ) ) {
		continue;
	}
	if ( 0 === strpos( $name, 'aculect-icon-library/assets/icons/' ) && ! in_array( substr( $name, strlen( 'aculect-icon-library/' ) ), $files, true ) ) {
		$verification->close();
		fwrite( STDERR, "Package contains an unexpected bundled-library path.\n" );
		exit( 1 );
	}
	if ( preg_match( '#^aculect-icon-library/(src/(LibraryInstaller|LibraryAdminController|LibraryPackageValidator|TrustedLibraryCatalog|LibraryDiscoveryCatalog|LibraryDiscoveryController|LegacyAssetStore|LegacyMigration)\.php|assets/library-installer\.js|data/library-catalog\.json)$#', $name ) ) {
		$verification->close();
		fwrite( STDERR, "Package contains deferred runtime installation or migration code.\n" );
		exit( 1 );
	}
}
$verification->close();

printf(
	"Built %s (%d files, sha256 %s).\n",
	$destination,
	count( $files ) + count( $generated_files ),
	hash_file( 'sha256', $destination )
);
