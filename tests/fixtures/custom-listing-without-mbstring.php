<?php
/** @package IconLibrary */

namespace IconLibrary;

// Isolate optional-extension discovery without changing the test host's PHP setup.
function function_exists( $name ) {
	return 'mb_strtolower' === $name ? false : \function_exists( $name );
}

function mb_strtolower( $value, $encoding ) {
	throw new \RuntimeException( 'The fallback must not call mbstring.' );
}

require dirname( __DIR__ ) . '/bootstrap.php';

$repository = new class() extends CustomIconRepository {
	public function __construct() {}
	public function get_icons() {
		return array( 'shape' => array( 'label' => 'Étoile Company' ) );
	}
	public function get_svg_content( $path ) {
		return '<svg />';
	}
};
$listing = new CustomIconListing( $repository );
echo json_encode(
	array(
		$listing->get_page( 'COMPANY', 1, 'name', 1, 20 )['total'],
		$listing->get_page( 'ÉTOILE', 1, 'name', 1, 20 )['total'],
		$listing->get_page( 'étoile', 1, 'name', 1, 20 )['total'],
	)
);
