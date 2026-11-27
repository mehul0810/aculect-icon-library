<?php
/** Fail-closed check when the installed Core manifest is missing. */
define( 'ABSPATH', __DIR__ . '/missing-core/' );
define( 'WPINC', 'wp-includes' );
function get_bloginfo() { return '7.2'; }
class WP_Icons_Registry {
	protected $registered_icons = array();
	public static function get_instance() { return new self(); }
}
class WP_Icon_Collections_Registry {
	public static function get_instance() { return new self(); }
	public function get_registered( $slug ) { return array( 'public' => true ); }
}
require dirname( __DIR__, 2 ) . '/src/CoreIconRegistrar.php';
if ( array() !== IconLibrary\CoreIconRegistrar::get_public_core_metadata() || array() !== IconLibrary\CoreIconRegistrar::get_public_core_metadata( 'core/envelope' ) ) {
	exit( 1 );
}
