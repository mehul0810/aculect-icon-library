<?php
/**
 * Validates WordPress.org directory image exports.
 *
 * @package IconLibrary
 */

$root   = dirname( __DIR__ );
$images = array(
	'banner-772x250.png'  => array( 772, 250, 4 * 1024 * 1024 ),
	'banner-1544x500.png' => array( 1544, 500, 4 * 1024 * 1024 ),
	'icon-128x128.png'    => array( 128, 128, 1024 * 1024 ),
	'icon-256x256.png'    => array( 256, 256, 1024 * 1024 ),
);
foreach ( $images as $name => $expected ) {
	$file = $root . '/.wordpress-org/' . $name;
	$size = is_file( $file ) ? getimagesize( $file ) : false;
	if ( ! $size || $size[0] !== $expected[0] || $size[1] !== $expected[1] || IMAGETYPE_PNG !== $size[2] || filesize( $file ) > $expected[2] ) {
		fwrite( STDERR, "Invalid directory image: $name\n" );
		exit( 1 );
	}
}
$icon = $root . '/.wordpress-org/icon.svg';
if ( ! is_file( $icon ) || hash_file( 'sha256', $icon ) !== hash_file( 'sha256', $root . '/assets/aculect-icon.svg' ) ) {
	fwrite( STDERR, "Directory SVG must match the original Aculect mark.\n" );
	exit( 1 );
}

$blueprint_file = $root . '/.wordpress-org/blueprints/blueprint.json';
$blueprint_json = is_file( $blueprint_file ) ? file_get_contents( $blueprint_file ) : false;
$blueprint      = is_string( $blueprint_json ) ? json_decode( $blueprint_json, true ) : null;
if ( ! is_array( $blueprint ) || JSON_ERROR_NONE !== json_last_error() ) {
	fwrite( STDERR, "Invalid WordPress.org preview Blueprint JSON.\n" );
	exit( 1 );
}

$steps       = isset( $blueprint['steps'] ) && is_array( $blueprint['steps'] ) ? $blueprint['steps'] : array();
$plugin_step = isset( $steps[1] ) && is_array( $steps[1] ) ? $steps[1] : array();
$seed_step   = isset( $steps[2] ) && is_array( $steps[2] ) ? $steps[2] : array();
if (
	'https://playground.wordpress.net/blueprint-schema.json' !== ( $blueprint['$schema'] ?? '' ) ||
	'8.3' !== ( $blueprint['preferredVersions']['php'] ?? '' ) ||
	'7.1' !== ( $blueprint['preferredVersions']['wp'] ?? '' ) ||
	'/wp-admin/themes.php?page=icon-library' !== ( $blueprint['landingPage'] ?? '' ) ||
	'login' !== ( $steps[0]['step'] ?? '' ) ||
	'installPlugin' !== ( $plugin_step['step'] ?? '' ) ||
	true !== ( $plugin_step['options']['activate'] ?? null ) ||
	'url' !== ( $plugin_step['pluginData']['resource'] ?? '' ) ||
	! is_string( $plugin_step['pluginData']['url'] ?? null ) ||
	1 !== preg_match( '#^https://downloads\.wordpress\.org/plugin/aculect-icon-library\.(\d+\.\d+\.\d+)\.zip$#', $plugin_step['pluginData']['url'], $blueprint_version_match ) ||
	'runPHP' !== ( $seed_step['step'] ?? '' ) ||
	! is_string( $seed_step['code'] ?? null ) ||
	false === strpos( $seed_step['code'], 'heroicons-solid/academic-cap-solid' )
) {
	fwrite( STDERR, "Invalid WordPress.org preview Blueprint configuration.\n" );
	exit( 1 );
}

$plugin_file     = $root . '/aculect-icon-library.php';
$plugin_contents = is_file( $plugin_file ) ? file_get_contents( $plugin_file ) : false;
if (
	! is_string( $plugin_contents ) ||
	1 !== preg_match( '/^[\t ]*\*?[\t ]*Version:[\t ]*([^\r\n]+?)[\t ]*$/mi', $plugin_contents, $plugin_version_match ) ||
	$blueprint_version_match[1] !== $plugin_version_match[1]
) {
	fwrite( STDERR, "Preview Blueprint ZIP version must match the plugin header version.\n" );
	exit( 1 );
}

echo "WordPress.org directory assets valid.\n";
