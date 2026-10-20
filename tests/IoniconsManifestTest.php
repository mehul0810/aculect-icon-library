<?php
/** Ionicons provenance and opt-in integration tests. */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\ManifestLoader;
use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

class IoniconsManifestTest extends TestCase {
	public function test_pinned_scope_and_exclusions() {
		$root = dirname( __DIR__ ) . '/assets/icons/ionicons/';
		$manifest = json_decode( file_get_contents( $root . 'manifest.json' ), true );
		$report = json_decode( file_get_contents( $root . 'exclusions.json' ), true );
		$this->assertSame( 'a9d1b7e23d7b9dec29f2041897ab14b2cef55064', $manifest['source']['revision'] );
		$this->assertSame( array( 'filled', 'sharp', 'outline', 'brands' ), array_column( $manifest['variants'], 'slug' ) );
		$this->assertSame( array( 313, 284, 14, 68 ), array_column( $manifest['variants'], 'iconCount' ) );
		$this->assertCount( 679, $manifest['icons'] );
		$this->assertCount( 678, $report['exclusions'] );
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertSame( hash_file( 'sha256', $root . $icon['path'] ), $icon['sha256'] );
			$this->assertNotEmpty( $icon['keywords'] );
		}
	}

	public function test_collection_is_opt_in_and_registers_every_icon_when_enabled() {
		$GLOBALS['icon_library_test_options'] = array();
		$GLOBALS['icon_library_test_cache'] = array();
		$GLOBALS['icon_library_test_filters'] = array();
		$GLOBALS['icon_library_test_registered'] = array( 'collections' => array(), 'icons' => array() );
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		$this->assertNotContains( 'ionicons', $registry->get_enabled_collection_slugs() );
		( new CoreIconRegistrar( $registry ) )->register_icons( 'ionicons' );
		$this->assertSame( array(), $GLOBALS['icon_library_test_registered']['icons'] );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'ionicons' );
		$registry->clear_request_caches();
		( new CoreIconRegistrar( $registry ) )->register_icons( 'ionicons' );
		$this->assertCount( 679, $GLOBALS['icon_library_test_registered']['icons'] );
		$this->assertArrayHasKey( 'ionicons-filled/heart-filled', $GLOBALS['icon_library_test_registered']['icons'] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
	}
}
