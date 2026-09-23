<?php
/** Phosphor collection provenance and opt-in integration tests. */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\ManifestLoader;
use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

class PhosphorManifestTest extends TestCase {
	public function test_pinned_scope_and_exclusions() {
		$root = dirname( __DIR__ ) . '/assets/icons/phosphor/';
		$manifest = json_decode( file_get_contents( $root . 'manifest.json' ), true );
		$report = json_decode( file_get_contents( $root . 'exclusions.json' ), true );
		$this->assertSame( 'd42782b2abe747d904b971ccab48b182a1455f86', $manifest['source']['revision'] );
		$this->assertSame( array( 'regular', 'fill' ), array_column( $manifest['variants'], 'slug' ) );
		$this->assertSame( array( 1248, 1239 ), array_column( $manifest['variants'], 'iconCount' ) );
		$this->assertCount( 2487, $manifest['icons'] );
		$this->assertCount( 9, $report['exclusions'] );
		$this->assertSame( 'v2.0.8', $report['sourceTag'] );
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
		$this->assertNotContains( 'phosphor', $registry->get_enabled_collection_slugs() );
		( new CoreIconRegistrar( $registry ) )->register_icons( 'phosphor' );
		$this->assertSame( array(), $GLOBALS['icon_library_test_registered']['icons'] );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'phosphor' );
		$registry->clear_request_caches();
		( new CoreIconRegistrar( $registry ) )->register_icons( 'phosphor' );
		$this->assertCount( 2487, $GLOBALS['icon_library_test_registered']['icons'] );
		$this->assertArrayHasKey( 'phosphor-regular/heart-regular', $GLOBALS['icon_library_test_registered']['icons'] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
	}
}
