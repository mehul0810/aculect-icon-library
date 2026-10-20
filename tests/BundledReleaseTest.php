<?php
/**
 * Verifies the release bundle's built-in collection inventory.
 *
 * @package IconLibrary
 */

use IconLibrary\CollectionRegistry;
use IconLibrary\CustomIconRepository;
use IconLibrary\ManifestLoader;
use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/** Bundled release tests. */
class BundledReleaseTest extends TestCase {
	/** Provider discovery does not persist inferred compatibility ownership. */
	public function test_provider_discovery_does_not_write_legacy_ownership() {
		unset( $GLOBALS['icon_library_test_options'][ IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS ] );
		$GLOBALS['icon_library_test_options'][ IconLibrary\Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$jobs = $this->getMockBuilder( IconLibrary\LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$jobs->expects( $this->once() )->method( 'all_library_ids' )->willReturn( array() );
		$repository = new IconLibrary\InstalledLibraryRepository( $jobs );

		$this->assertSame( array(), $repository->register_providers( array() ) );
		$this->assertFalse( array_key_exists( IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS, $GLOBALS['icon_library_test_options'] ) );
	}

	/** All ten bundled families are discoverable and disabled by default. */
	public function test_release_bundle_contains_ten_discoverable_disabled_families() {
		$GLOBALS['icon_library_test_options'][ IconLibrary\Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$loader   = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$registry = new CollectionRegistry( $loader, new CustomIconRepository( new SvgSanitizer() ) );
		$slugs    = $loader->get_collection_slugs();

		$this->assertSame(
			array( 'bootstrap-icons', 'font-awesome', 'heroicons', 'iconoir', 'ionicons', 'material-icons', 'phosphor', 'radix', 'rune', 'tabler-icons' ),
			$slugs
		);
		$this->assertSame( $slugs, array_keys( $registry->get_collections() ) );
		$this->assertSame( array(), $registry->get_enabled_collection_slugs() );

		foreach ( $slugs as $slug ) {
			$this->assertNotNull( $registry->get_manifest( $slug ), $slug );
			$this->assertFalse( $registry->get_collection( $slug )['enabled'], $slug );
		}
	}
}
