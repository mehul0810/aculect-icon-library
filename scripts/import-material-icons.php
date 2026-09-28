<?php
/**
 * Imports the strictly Core-compatible subset of pinned Material Icons.
 *
 * Usage: php scripts/import-material-icons.php /path/to/material-design-icons
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

require_once __DIR__ . '/lib/CollectionBuild.php';

$source_dir = $argv[1] ?? '';
$revision   = '27e9ef1dbeedc13d682fece4a58e1eda4cb0961a';
$source_url = 'https://github.com/google/material-design-icons';
$target_dir = dirname( __DIR__ ) . '/assets/icons/material-icons';

try {
	CollectionBuild::validate_source_checkout( $source_dir, $source_url, 'LICENSE' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Reads the pinned local source revision.
	$actual = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source_dir ) . ' rev-parse HEAD' ) );
	if ( $revision !== $actual ) {
		throw new RuntimeException( 'Material source does not match the pinned revision.' );
	}
	$files = glob( $source_dir . '/src/*/*/materialicons/24px.svg' );
	sort( $files );
	if ( 2170 !== count( $files ) ) {
		throw new RuntimeException( 'Expected 2170 filled Material SVGs.' );
	}
	$icons      = array();
	$outputs    = array();
	$categories = array();
	$exclusions = array();
	foreach ( $files as $file ) {
		$name     = basename( dirname( $file, 2 ) );
		$category = basename( dirname( $file, 3 ) );
		$slug     = str_replace( '_', '-', $name );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) || ! preg_match( '/^[a-z0-9_]+$/', $category ) ) {
			throw new RuntimeException( 'Unexpected Material filename.' );
		}
		$source_path = 'src/' . $category . '/' . $name . '/materialicons/24px.svg';
		$source      = CollectionBuild::get_contained_source_file( $source_dir, $source_path );
		try {
			$svg = CollectionBuild::normalize_svg( file_get_contents( $source ) );
		} catch ( RuntimeException $exception ) {
			$exclusions[] = array(
				'source' => $source_path,
				'reason' => $exception->getMessage(),
			);
			continue;
		}
		$path = 'filled/' . $slug . '.svg';
		if ( isset( $outputs[ $path ] ) ) {
			throw new RuntimeException( 'Duplicate Material icon identifier.' );
		}
		// Apache-2.0 requires modified files to identify their modifications.
		$svg                     = '<!-- Aculect modification: normalized XML formatting; geometry and presentation preserved. -->' . "\n" . $svg . "\n";
		$outputs[ $path ]        = $svg;
		$category                = str_replace( '_', '-', $category );
		$categories[ $category ] = ( $categories[ $category ] ?? 0 ) + 1;
		$icons[]                 = array(
			'id'           => 'material-icons/filled/' . $slug,
			'coreIconName' => 'material-icons/' . $slug . '-filled',
			'label'        => ucwords( str_replace( '-', ' ', $slug ) ),
			'variant'      => 'filled',
			'categories'   => array( $category ),
			'keywords'     => array_values( array_unique( array_merge( array( $name ), explode( '-', $slug ) ) ) ),
			'path'         => $path,
			'sha256'       => hash( 'sha256', $svg ),
		);
	}
	if ( 1038 !== count( $icons ) || 1132 !== count( $exclusions ) ) {
		throw new RuntimeException( 'Material compatibility inventory changed; review before importing.' );
	}
	$taxonomy = array();
	foreach ( $categories as $slug => $count ) {
		$taxonomy[] = array(
			'slug'      => $slug,
			'label'     => ucwords( str_replace( '-', ' ', $slug ) ),
			'iconCount' => $count,
		);
	}
	$manifest = array(
		'schemaVersion' => CollectionBuild::SCHEMA_VERSION,
		'slug'          => 'material-icons',
		'name'          => 'Google Material Icons',
		'description'   => 'Core-compatible filled 24px Material Icons; other styles are not bundled.',
		'version'       => 'commit-' . substr( $revision, 0, 12 ),
		'license'       => array(
			'name' => 'Apache-2.0',
			'url'  => $source_url . '/blob/' . $revision . '/LICENSE',
		),
		'source'        => array(
			'name'     => 'google/material-design-icons',
			'url'      => $source_url,
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
		'categories'    => $taxonomy,
		'icons'         => $icons,
	);
	if ( is_link( $target_dir ) || is_link( $target_dir . '/filled' ) ) {
		throw new RuntimeException( 'Symlinked target is not allowed.' );
	}
	if ( ! is_dir( $target_dir . '/filled' ) && ! mkdir( $target_dir . '/filled', 0755, true ) ) {
		throw new RuntimeException( 'Could not create Material output directory.' );
	}
	$outputs['LICENSE']         = file_get_contents( CollectionBuild::get_contained_source_file( $source_dir, 'LICENSE' ) );
	$outputs['exclusions.json'] = json_encode(
		array(
			'sourceRevision'    => $revision,
			'includedIconCount' => count( $icons ),
			'excludedIconCount' => count( $exclusions ),
			'exclusions'        => $exclusions,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
	foreach ( $outputs as $path => $contents ) {
		if ( is_link( $target_dir . '/' . $path ) || false === file_put_contents( $target_dir . '/' . $path, $contents ) ) {
			throw new RuntimeException( 'Could not safely write Material output.' );
		}
	}
	$errors = CollectionBuild::validate_manifest( $manifest, $target_dir );
	if ( $errors ) {
		throw new RuntimeException( implode( "\n", $errors ) );
	}
	if ( is_link( $target_dir . '/manifest.json' ) || false === file_put_contents( $target_dir . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
		throw new RuntimeException( 'Could not write Material manifest.' );
	}
	printf( "Imported %d Material icons; recorded %d exclusions.\n", count( $icons ), count( $exclusions ) );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
