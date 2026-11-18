<?php
/**
 * Generates compact metadata without icon records for admin library listings.
 *
 * @package IconLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

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
