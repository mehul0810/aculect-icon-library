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
echo "WordPress.org directory assets valid.\n";
