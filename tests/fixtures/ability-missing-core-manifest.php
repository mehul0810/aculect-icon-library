<?php
/** Fail-closed checks for missing or incomplete Core manifests. */
$partial = 'partial' === ( $argv[1] ?? '' );
define( 'ABSPATH', $partial ? dirname( __DIR__ ) . '/wordpress/' : __DIR__ . '/missing-core/' );
define( 'WPINC', 'wp-includes' );
function get_bloginfo() { return '7.2'; }
function is_wp_error( $value ) { return false; }
class WP_Icons_Registry {
	protected $registered_icons = array();
	public static function get_instance() {
		$registry = new self();
		foreach ( array( 'envelope', 'missing' ) as $slug ) {
			$name = 'core/' . $slug;
			$registry->registered_icons[ $name ] = array( 'name' => $name, 'label' => $slug, 'collection' => 'core', 'file_path' => ABSPATH . WPINC . '/images/icon-library/' . $slug . '.svg' );
		}
		return $registry;
	}
}
class WP_Icon_Collections_Registry {
	public static function get_instance() { return new self(); }
	public function get_registered( $slug ) { return array( 'public' => true ); }
}
require dirname( __DIR__, 2 ) . '/src/CoreIconRegistrar.php';
require dirname( __DIR__, 2 ) . '/src/CoreIconMetadataReader.php';
$expected = $partial ? array( 'core/envelope' ) : array();
if ( $expected !== array_keys( IconLibrary\CoreIconRegistrar::get_public_core_metadata() ) || array() !== IconLibrary\CoreIconRegistrar::get_public_core_metadata( 'core/missing' ) ) {
	exit( 1 );
}
