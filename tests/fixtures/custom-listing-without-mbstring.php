<?php
/**
 * Runs the custom listing with no mbstring support in an isolated process.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

/**
 * Simulates the unavailable optional function without modifying the test host.
 *
 * @param string $name Function name.
 * @return bool
 */
function function_exists( $name ) {
	return 'mb_strtolower' === $name ? false : \function_exists( $name );
}

/**
 * Fails if the unavailable optional function is ever called.
 *
 * @throws \RuntimeException Always when called.
 */
function mb_strtolower() {
	throw new \RuntimeException( 'The fallback must not call mbstring.' );
}

require dirname( __DIR__ ) . '/bootstrap.php';

$repository = new class() extends CustomIconRepository {
	/** Avoid initializing a storage backend for this fixture. */
	public function __construct() {}

	/** Provides one searchable fixture label. */
	public function get_icons() {
		return array( 'shape' => array( 'label' => "\u{00C9}toile Company" ) );
	}

	/**
	 * Returns a minimal preview without filesystem access.
	 *
	 * @param string $path Fixture artwork path.
	 * @return string
	 */
	public function get_svg_content( $path ) {
		return '<svg />';
	}
};
$listing    = new CustomIconListing( $repository );
echo json_encode(
	array(
		$listing->get_page( 'COMPANY', 1, 'name', 1, 20 )['total'],
		$listing->get_page( "\u{00C9}TOILE", 1, 'name', 1, 20 )['total'],
		$listing->get_page( "\u{00E9}toile", 1, 'name', 1, 20 )['total'],
	)
);
