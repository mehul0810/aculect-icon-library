<?php
/**
 * Generates compact bundled library metadata and icon indexes.
 *
 * @package IconLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/src/IconNameIndex.php';

use IconLibrary\IconNameIndex;

$check  = in_array( '--check', $argv, true );
$failed = false;
foreach ( glob( dirname( __DIR__ ) . '/assets/icons/*/manifest.json' ) as $path ) {
	$manifest = json_decode( file_get_contents( $path ), true );
	if ( ! is_array( $manifest ) || ! isset( $manifest['icons'] ) ) {
		fwrite( STDERR, "Invalid manifest: $path\n" );
		exit( 1 );
	}
	$counts      = array();
	$total       = 0;
	$picker_rows = array();
	foreach ( $manifest['icons'] as $icon ) {
		if ( ! empty( $icon['archived'] ) ) {
			continue;
		}
		++$total;
		$variant            = $icon['variant'] ?? '';
		$counts[ $variant ] = ( $counts[ $variant ] ?? 0 ) + 1;
		if ( isset( $icon['coreIconName'], $icon['label'], $icon['path'] ) && is_string( $icon['coreIconName'] ) && is_string( $icon['label'] ) && is_string( $icon['path'] ) ) {
			$picker_rows[] = array( $icon['coreIconName'], $icon['label'], $variant, $icon['path'] );
		}
	}
	$picker_output = json_encode( $picker_rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	$picker_target = dirname( $path ) . '/picker-index.json';
	if ( $check ) {
		if ( ! is_file( $picker_target ) || file_get_contents( $picker_target ) !== $picker_output ) {
			fwrite( STDERR, "Stale picker index: $picker_target\n" );
			$failed = true;
		}
	} elseif ( false === file_put_contents( $picker_target, $picker_output ) ) {
		$failed = true;
	}

	// Reuse runtime alias precedence without loading WordPress or site hooks.
	$index     = IconNameIndex::build(
		$manifest['slug'],
		$manifest,
		static function ( $variant ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $variant ) );
		}
	);
	$saved     = array(
		'version' => 1,
		'names'   => array(),
		'icons'   => array(),
	);
	$positions = array();
	foreach ( $index as $name => $entry ) {
		$icon      = $entry['icon'];
		$canonical = $icon['coreIconName'];
		if ( ! isset( $positions[ $canonical ] ) ) {
			$positions[ $canonical ] = count( $saved['icons'] );
			$saved['icons'][]        = array( $canonical, $icon['label'], $icon['variant'] ?? '', $icon['path'], ! empty( $icon['archived'] ) );
		}
		// Encode the row offset and registration mode in one integer.
		$mode                    = isset( $entry['style'] ) ? IconNameIndex::MODE_STYLED : ( empty( $entry['legacy'] ) ? IconNameIndex::MODE_CURRENT : IconNameIndex::MODE_LEGACY );
		$saved['names'][ $name ] = $positions[ $canonical ] * IconNameIndex::MODE_COUNT + $mode;
	}
	$saved_output = json_encode( $saved, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	$saved_target = dirname( $path ) . '/saved-index.json';
	if ( $check ) {
		if ( ! is_file( $saved_target ) || file_get_contents( $saved_target ) !== $saved_output ) {
			fwrite( STDERR, "Stale saved icon index: $saved_target\n" );
			$failed = true;
		}
	} elseif ( false === file_put_contents( $saved_target, $saved_output ) ) {
		$failed = true;
	}

	unset( $manifest['icons'] );
	$manifest['iconCount'] = $total;
	foreach ( $manifest['variants'] as &$variant ) {
		$variant['iconCount'] = $counts[ $variant['slug'] ] ?? 0;
	}
	unset( $variant );
	$output = json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	$target = dirname( $path ) . '/metadata.json';
	if ( $check ) {
		if ( ! is_file( $target ) || file_get_contents( $target ) !== $output ) {
			fwrite( STDERR, "Stale catalog metadata: $target\n" );
			$failed = true;
		}
	} elseif ( false === file_put_contents( $target, $output ) ) {
		$failed = true;
	}
}
exit( $failed ? 1 : 0 );
