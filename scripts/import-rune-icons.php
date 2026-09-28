<?php
/**
 * Imports Rune's pinned pixelated variant without changing its artwork.
 *
 * Usage: php scripts/import-rune-icons.php /path/to/runeicons
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

require_once __DIR__ . '/lib/CollectionBuild.php';

$source_dir = $argv[1] ?? '';
$revision   = 'f649e467d1bc9f272aae3f8daa329d4c924e7340';
$source_url = 'https://github.com/Nexvyn/runeicons';
$target_dir = dirname( __DIR__ ) . '/assets/icons/rune';

try {
	CollectionBuild::validate_source_checkout( $source_dir, $source_url, 'LICENSE' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Reads the pinned local source revision.
	$actual = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source_dir ) . ' rev-parse HEAD' ) );
	if ( $revision !== $actual ) {
		throw new RuntimeException( 'Rune source does not match the pinned revision.' );
	}
	$files = glob( $source_dir . '/public/pixelated/*/*.svg' );
	sort( $files );
	if ( 215 !== count( $files ) ) {
		throw new RuntimeException( 'Expected 215 Rune pixelated SVGs.' );
	}
	$icons      = array();
	$outputs    = array();
	$categories = array();
	foreach ( $files as $file ) {
		$category = basename( dirname( $file ) );
		$name     = basename( $file, '.svg' );
		$slug     = $category . '-' . $name;
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			throw new RuntimeException( 'Unexpected Rune filename.' );
		}
		$relative = 'public/pixelated/' . $category . '/' . $name . '.svg';
		$source   = CollectionBuild::get_contained_source_file( $source_dir, $relative );
		$svg      = file_get_contents( $source );
		if ( preg_match( '/<!DOCTYPE|<!ENTITY|<\?/i', $svg ) ) {
			throw new RuntimeException( 'Unexpected Rune XML declaration.' );
		}
		$document = new DOMDocument();
		if ( ! $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS ) ) {
			throw new RuntimeException( 'Invalid Rune XML.' );
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
		$root = $document->documentElement;
		if ( 'none' !== $root->getAttribute( 'fill' ) ) {
			throw new RuntimeException( 'Unexpected root fill.' );
		}
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			if ( $element !== $root && ( 'path' !== $element->tagName || ! $element->hasAttribute( 'fill' ) ) ) {
				throw new RuntimeException( 'Root fill is not demonstrably redundant.' );
			}
		}
		$root->removeAttribute( 'fill' );
		$svg = CollectionBuild::normalize_svg( $document->saveXML( $root ) );
		// Apache-2.0 requires a prominent modification notice on modified files.
		$svg                     = '<!-- Aculect modification: removed redundant root fill; geometry and path fills unchanged. -->' . "\n" . $svg . "\n";
		$path                    = 'pixelated/' . $slug . '.svg';
		$outputs[ $path ]        = $svg;
		$categories[ $category ] = ( $categories[ $category ] ?? 0 ) + 1;
		$icons[]                 = array(
			'id'           => 'rune/pixelated/' . $slug,
			'coreIconName' => 'rune/' . $slug . '-pixelated',
			'label'        => ucwords( str_replace( '-', ' ', $name ) ),
			'variant'      => 'pixelated',
			'categories'   => array( $category ),
			'keywords'     => array_values( array_unique( explode( '-', $slug ) ) ),
			'path'         => $path,
			'sha256'       => hash( 'sha256', $svg ),
		);
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
		'slug'          => 'rune',
		'name'          => 'Rune Icons',
		'description'   => 'The pixelated variant of Rune Icons, preserving original path colors.',
		'version'       => 'commit-' . substr( $revision, 0, 12 ),
		'license'       => array(
			'name' => 'Apache-2.0',
			'url'  => $source_url . '/blob/' . $revision . '/LICENSE',
		),
		'source'        => array(
			'name'     => 'Nexvyn/runeicons',
			'url'      => $source_url,
			'revision' => $revision,
		),
		'variants'      => array(
			array(
				'slug'           => 'pixelated',
				'label'          => 'Pixelated',
				'coreCompatible' => true,
				'defaultEnabled' => true,
				'iconCount'      => count( $icons ),
			),
		),
		'categories'    => $taxonomy,
		'icons'         => $icons,
	);
	// Complete source validation precedes all writes; the fixed revision has no stale-file scope.
	if ( is_link( $target_dir ) || is_link( $target_dir . '/pixelated' ) ) {
		throw new RuntimeException( 'Symlinked target is not allowed.' );
	}
	if ( ! is_dir( $target_dir . '/pixelated' ) && ! mkdir( $target_dir . '/pixelated', 0755, true ) ) {
		throw new RuntimeException( 'Could not create Rune output directory.' );
	}
	$outputs['LICENSE'] = file_get_contents( CollectionBuild::get_contained_source_file( $source_dir, 'LICENSE' ) );
	foreach ( $outputs as $path => $contents ) {
		if ( is_link( $target_dir . '/' . $path ) || false === file_put_contents( $target_dir . '/' . $path, $contents ) ) {
			throw new RuntimeException( 'Could not safely write Rune output.' );
		}
	}
	$errors = CollectionBuild::validate_manifest( $manifest, $target_dir );
	if ( $errors ) {
		throw new RuntimeException( implode( "\n", $errors ) );
	}
	if ( is_link( $target_dir . '/manifest.json' ) || false === file_put_contents( $target_dir . '/manifest.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
		throw new RuntimeException( 'Could not write Rune manifest.' );
	}
	printf( "Imported %d Rune pixelated icons.\n", count( $icons ) );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
