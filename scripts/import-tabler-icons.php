<?php
/**
 * Imports the pinned Tabler Icons Filled collection.
 *
 * Usage: php scripts/import-tabler-icons.php /path/to/tabler-icons-checkout
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/CollectionBuild.php';

$source_dir = isset( $argv[1] ) ? rtrim( $argv[1], DIRECTORY_SEPARATOR ) : '';
$plugin_dir = dirname( __DIR__ );
$target_dir = $plugin_dir . '/assets/icons/tabler-icons';

const TABLER_EXPECTED_SOURCE_COUNT = 1054;
const TABLER_EXPECTED_BRAND_COUNT  = 35;
const TABLER_EXPECTED_VERSION      = '3.47.0';
const TABLER_EXPECTED_REVISION     = '87e7c390fb4ec332ccad0bde25160b233241eb8f';
const TABLER_TAG_OBJECT            = 'c940317930743839f3ba8dd02ebdbba930fb8be5';

if ( ! is_dir( $source_dir ) ) {
	fwrite( STDERR, "Provide the official Tabler Icons source checkout.\n" );
	exit( 1 );
}

$package = json_decode( (string) @file_get_contents( $source_dir . '/package.json' ), true );
$version = is_array( $package ) && isset( $package['version'] ) ? (string) $package['version'] : '';
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Reads the pinned revision from a pre-validated local checkout.
$revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source_dir ) . ' rev-parse HEAD 2>/dev/null' ) );

if ( TABLER_EXPECTED_VERSION !== $version || TABLER_EXPECTED_REVISION !== $revision ) {
	fwrite( STDERR, "Source checkout does not match the pinned Tabler Icons release.\n" );
	exit( 1 );
}

try {
	CollectionBuild::validate_source_checkout( $source_dir, 'https://github.com/tabler/tabler-icons', 'LICENSE' );
	$license_source = CollectionBuild::get_contained_source_file( $source_dir, 'LICENSE' );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

$source_files = glob( $source_dir . '/icons/filled/*.svg' );
sort( $source_files );
if ( TABLER_EXPECTED_SOURCE_COUNT !== count( $source_files ) ) {
	fwrite( STDERR, sprintf( "Expected %d pinned Filled icons; found %d.\n", TABLER_EXPECTED_SOURCE_COUNT, count( $source_files ) ) );
	exit( 1 );
}

/**
 * Removes a previous generated collection without following symlinks.
 *
 * @param string $directory Generated collection directory.
 * @return void
 */
$reset_directory = static function ( $directory ) use ( $plugin_dir ) {
	$parent = realpath( dirname( $directory ) );
	$root   = realpath( $plugin_dir . '/assets/icons' );
	if ( false === $parent || false === $root || $parent !== $root || is_link( $directory ) ) {
		throw new RuntimeException( 'Generated collection path is unsafe.' );
	}
	if ( ! is_dir( $directory ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$path = $entry->getPathname();
		if ( $entry->isLink() || $entry->isFile() ) {
			unlink( $path );
		} elseif ( $entry->isDir() ) {
			rmdir( $path );
		}
	}
	rmdir( $directory );
};

$build_dir = $target_dir . '.tmp-' . getmypid();
try {
	$reset_directory( $build_dir );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

if ( ! mkdir( $build_dir . '/filled', 0755, true ) ) {
	fwrite( STDERR, "Could not create the Tabler Icons target directory.\n" );
	exit( 1 );
}

if ( ! copy( $license_source, $build_dir . '/LICENSE' ) ) {
	fwrite( STDERR, "Could not copy the Tabler Icons license.\n" );
	exit( 1 );
}

/**
 * Reads category and search tags from the corresponding upstream outline SVG.
 *
 * @param string $source_dir Source checkout.
 * @param string $slug       Icon slug.
 * @return array{category:string,tags:string[]}
 */
$read_metadata = static function ( $source_dir, $slug ) {
	$path = CollectionBuild::get_contained_source_file( $source_dir, 'icons/outline/' . $slug . '.svg' );

	$contents = file_get_contents( $path );
	$comment  = is_string( $contents ) && preg_match( '/<!--(.*?)-->/s', $contents, $matches ) ? $matches[1] : '';
	if ( ! preg_match( '/^category:\s*(.+?)\s*$/mi', $comment, $matches ) ) {
		throw new RuntimeException( 'Corresponding outline metadata has no category.' );
	}
	$category = trim( $matches[1], " \t\n\r\0\x0B\"'" );
	$tags     = array();
	if ( preg_match( '/^tags:\s*\[(.*?)\]\s*$/mi', $comment, $matches ) ) {
		$tags = array_values(
			array_filter(
				array_map(
					static function ( $tag ) {
						return strtolower( trim( $tag, " \t\n\r\0\x0B\"'" ) );
					},
					explode( ',', $matches[1] )
				)
			)
		);
	}

	return array(
		'category' => $category,
		'tags'     => $tags,
	);
};

$slugify = static function ( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( (string) $value, '-' );
};

$icons            = array();
$exclusions       = array();
$category_members = array();

foreach ( $source_files as $file ) {
	$slug = basename( $file, '.svg' );
	try {
		$metadata = $read_metadata( $source_dir, $slug );
	} catch ( RuntimeException $exception ) {
		fwrite( STDERR, $slug . ': ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}
	$is_brand_name     = 0 === strpos( $slug, 'brand-' );
	$is_brand_category = 'brand' === $slugify( $metadata['category'] );
	if ( $is_brand_name !== $is_brand_category ) {
		fwrite( STDERR, $slug . ": brand filename and category metadata disagree.\n" );
		exit( 1 );
	}
	if ( $is_brand_name ) {
		$exclusions[] = array(
			'slug'     => $slug,
			'variant'  => 'filled',
			'category' => $metadata['category'],
			'reason'   => 'Brand icon excluded from the non-brand 1.1.0 collection scope.',
		);
		continue;
	}

	try {
		$source = CollectionBuild::get_contained_source_file( $source_dir, 'icons/filled/' . $slug . '.svg' );
		$svg    = CollectionBuild::normalize_svg( file_get_contents( $source ) );
	} catch ( RuntimeException $exception ) {
		fwrite( STDERR, $slug . ': ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}

	$relative = 'filled/' . $slug . '.svg';
	$target   = $build_dir . '/' . $relative;
	if ( false === file_put_contents( $target, $svg . "\n" ) ) {
		fwrite( STDERR, "Could not write generated SVG: $relative.\n" );
		exit( 1 );
	}

	$category_slug = $slugify( $metadata['category'] );
	if ( '' === $category_slug ) {
		$category_slug = 'general';
	}
	$category_members[ $category_slug ]['label']          = (string) $metadata['category'];
	$category_members[ $category_slug ]['icons'][ $slug ] = true;
	$keywords = array_values( array_unique( array_merge( explode( '-', $slug ), $metadata['tags'] ) ) );

	$icons[] = array(
		'id'           => 'tabler-icons/filled/' . $slug,
		'coreIconName' => 'tabler-icons/' . $slug . '-filled',
		'label'        => ucwords( str_replace( '-', ' ', $slug ) ),
		'variant'      => 'filled',
		'categories'   => array( $category_slug ),
		'keywords'     => $keywords,
		'path'         => $relative,
		'sha256'       => hash_file( 'sha256', $target ),
	);
}

if ( TABLER_EXPECTED_BRAND_COUNT !== count( $exclusions ) ) {
	fwrite( STDERR, sprintf( "Expected %d brand exclusions; found %d.\n", TABLER_EXPECTED_BRAND_COUNT, count( $exclusions ) ) );
	exit( 1 );
}

ksort( $category_members );
$categories = array();
foreach ( $category_members as $category_slug => $category ) {
	$categories[] = array(
		'slug'      => $category_slug,
		'label'     => $category['label'],
		'iconCount' => count( $category['icons'] ),
	);
}

$manifest = array(
	'schemaVersion' => CollectionBuild::SCHEMA_VERSION,
	'slug'          => 'tabler-icons',
	'name'          => 'Tabler Icons',
	'description'   => 'The non-brand Filled icon collection from Tabler Icons.',
	'version'       => $version,
	'license'       => array(
		'name' => 'MIT',
		'url'  => 'https://github.com/tabler/tabler-icons/blob/main/LICENSE',
	),
	'source'        => array(
		'name'     => 'tabler/tabler-icons',
		'url'      => 'https://github.com/tabler/tabler-icons',
		'revision' => $revision,
	),
	'variants'      => array(
		array(
			'slug'           => 'filled',
			'label'          => 'Filled',
			'coreCompatible' => true,
			'defaultEnabled' => true,
			'iconCount'      => count( $icons ),
		),
	),
	'categories'    => $categories,
	'icons'         => $icons,
);

$errors = CollectionBuild::validate_manifest( $manifest, $build_dir );
if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

$exclusion_report = array(
	'schemaVersion'     => 1,
	'slug'              => 'tabler-icons',
	'version'           => $version,
	'sourceRevision'    => $revision,
	'sourceTagObject'   => TABLER_TAG_OBJECT,
	'includedIconCount' => count( $icons ),
	'excludedIconCount' => count( $exclusions ),
	'exclusionRule'     => 'Exclude Filled icons whose slug starts with brand- and whose upstream category is Brand.',
	'exclusions'        => $exclusions,
);

foreach ( array(
	'manifest.json'   => $manifest,
	'exclusions.json' => $exclusion_report,
) as $filename => $data ) {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	if ( false === file_put_contents( $build_dir . '/' . $filename, $json ) ) {
		fwrite( STDERR, "Could not write $filename.\n" );
		exit( 1 );
	}
}

try {
	$reset_directory( $target_dir );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
if ( ! rename( $build_dir, $target_dir ) ) {
	fwrite( STDERR, "Could not finalize the generated Tabler Icons collection.\n" );
	exit( 1 );
}

printf( "Imported %d Tabler Filled icons and recorded %d brand exclusions.\n", count( $icons ), count( $exclusions ) );
