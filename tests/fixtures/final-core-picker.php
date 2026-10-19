<?php
/** Ensures unsupported final Core registry returns 503 before bridge autoload. */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( $text ) { return $text; }
	function add_action() {}
	class WP_Error {
		private $code;
		public function __construct( $code ) { $this->code = $code; }
		public function get_error_code() { return $this->code; }
	}
	final class WP_Icons_Registry {}
}

namespace IconLibrary {
	class CollectionRegistry {}
	require_once dirname( __DIR__, 2 ) . '/src/CoreDiscoveryFilter.php';
	require_once dirname( __DIR__, 2 ) . '/src/CoreIconRegistrar.php';
	$result = ( new CoreIconRegistrar( new CollectionRegistry() ) )->get_picker_page();
	if ( ! $result instanceof \WP_Error || 'icon_library_picker_registry_unsupported' !== $result->get_error_code() ) {
		throw new \RuntimeException( 'Final Core registry was not rejected with 503.' );
	}
	echo "Final Core registry rejected before adapter autoload.\n";
}
