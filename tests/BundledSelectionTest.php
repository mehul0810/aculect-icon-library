<?php
/**
 * Local bundled library selection tests.
 *
 * @package IconLibrary
 */

use IconLibrary\CollectionRegistry;
use IconLibrary\ManifestLoader;
use IconLibrary\Plugin;
use IconLibrary\RestController;
use IconLibrary\CustomIconRepository;
use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

/** Tests installed and enabled state independently. */
class BundledSelectionTest extends TestCase {
	/** Reset mutable fixture options and providers. */
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
		$GLOBALS['icon_library_test_cached_options'] = array();
		$GLOBALS['icon_library_test_options'] = array(
			Plugin::OPTION_ENABLED_COLLECTIONS   => array(),
			Plugin::OPTION_INSTALLED_COLLECTIONS => array(),
		);
		unset( $GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] );
		unset( $GLOBALS['icon_library_test_filters']['icon_library_enabled_collections'] );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
		$GLOBALS['icon_library_test_cached_options'] = array();
	}

	/** A fresh bundle is previewable but remains unselected and disabled. */
	public function test_select_then_enable_disable_and_remove() {
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		$this->assertFalse( $registry->get_collection( 'heroicons' )['installed'] );
		$this->assertNotNull( $registry->get_manifest( 'heroicons' ) );
		$this->assertTrue( $registry->set_collection_installed( 'heroicons', true ) );
		$this->assertTrue( $registry->get_collection( 'heroicons' )['installed'] );
		$this->assertFalse( $registry->get_collection( 'heroicons' )['enabled'] );
		$this->assertTrue( $registry->set_collection_enabled( 'heroicons', true ) );
		$this->assertTrue( $registry->set_collection_enabled( 'heroicons', false ) );
		$this->assertTrue( $registry->get_collection( 'heroicons' )['installed'] );
		$this->assertTrue( $registry->set_collection_installed( 'heroicons', false ) );
		$this->assertFalse( $registry->get_collection( 'heroicons' )['installed'] );
		$this->assertNotNull( $registry->get_manifest( 'heroicons' ) );
	}

	/** Older activation callers select the bundle without changing their API. */
	public function test_enable_implies_installed_and_missing_marker_is_read_only() {
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_INSTALLED_COLLECTIONS ] );
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		$this->assertTrue( $registry->set_collection_enabled( 'radix', true ) );
		$this->assertTrue( $registry->get_collection( 'radix' )['installed'] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_INSTALLED_COLLECTIONS ] );
		$this->assertContains( 'radix', $registry->get_installed_collection_slugs() );
		$this->assertArrayNotHasKey( Plugin::OPTION_INSTALLED_COLLECTIONS, $GLOBALS['icon_library_test_options'] );
		$this->assertTrue( $registry->set_collection_enabled( 'radix', false ) );
		$this->assertContains( 'radix', $registry->get_installed_collection_slugs() );
	}

	/** Filter-enabled bundles stay installed without a read-time write. */
	public function test_effective_enabled_filter_implies_installed_and_blocks_removal() {
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_INSTALLED_COLLECTIONS ] );
		$GLOBALS['icon_library_test_filters']['icon_library_enabled_collections'] = array(
			static function ( $enabled ) {
				$enabled[] = 'heroicons';
				return $enabled;
			},
		);
		try {
			$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
			$this->assertTrue( $registry->get_collection( 'heroicons' )['enabled'] );
			$this->assertTrue( $registry->get_collection( 'heroicons' )['installed'] );
			$this->assertArrayNotHasKey( Plugin::OPTION_INSTALLED_COLLECTIONS, $GLOBALS['icon_library_test_options'] );
			$this->assertFalse( $registry->set_collection_installed( 'heroicons', false ) );
			$this->assertTrue( $registry->get_collection( 'heroicons' )['installed'] );
			$this->assertArrayNotHasKey( Plugin::OPTION_INSTALLED_COLLECTIONS, $GLOBALS['icon_library_test_options'] );
			$this->assertTrue( $registry->set_collection_installed( 'heroicons', true ) );
			$this->assertFalse( $registry->set_collection_installed( 'heroicons', false ) );
			$this->assertContains( 'heroicons', get_option( Plugin::OPTION_INSTALLED_COLLECTIONS ) );
		} finally {
			unset( $GLOBALS['icon_library_test_filters']['icon_library_enabled_collections'] );
		}
	}

	/** Provider-owned colliding slugs cannot be locally selected or removed. */
	public function test_provider_collision_remains_visible_but_not_local_target() {
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = array(
			static function () {
				return array(
					'heroicons' => array(
						'manifest' => array(
							'slug'     => 'heroicons',
							'name'     => 'Existing provider',
							'variants' => array(),
							'icons'    => array(),
						),
					),
				);
			},
		);
		try {
			$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
			$this->assertNotContains( 'heroicons', $registry->get_bundled_collection_slugs() );
			$this->assertSame( 'Existing provider', $registry->get_collection( 'heroicons' )['name'] );
			$this->assertTrue( $registry->get_collection( 'heroicons' )['installed'] );
			$this->assertFalse( $registry->set_collection_installed( 'heroicons', false ) );
		} finally {
			unset( $GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] );
		}
	}

	/** The read-only REST inventory and schema both carry selection state. */
	public function test_rest_collections_expose_installed_state() {
		$custom   = new CustomIconRepository( new SvgSanitizer() );
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ), $custom );
		$rest     = new RestController( $registry, $custom );
		$rows     = $rest->get_collections()->get_data();
		$this->assertArrayHasKey( 'installed', $rest->get_collection_schema()['properties'] );
		$this->assertFalse( $rows[0]['installed'] );
		$this->assertTrue( $registry->set_collection_installed( $rows[0]['slug'], true ) );
		$this->assertTrue( $rest->get_collections()->get_data()[0]['installed'] );
	}

	/** Inspects uninstall cleanup without executing it against a WordPress site. */
	public function test_uninstall_cleanup_includes_installed_option() {
		$source = file_get_contents( ICON_LIBRARY_DIR . 'uninstall.php' );
		$this->assertIsString( $source );
		$this->assertSame( 1, preg_match( "/delete_option\\( 'icon_library_installed_collections' \\);/", $source ) );
	}

	/** Cached preflight state cannot erase a completed library/variant change. */
	public function test_mutations_bypass_stale_option_caches() {
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		$this->assertTrue( $registry->set_collection_enabled( 'radix', true ) );
		$GLOBALS['icon_library_test_cached_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$GLOBALS['icon_library_test_cached_options'][ Plugin::OPTION_INSTALLED_COLLECTIONS ] = array();
		$this->assertTrue( $registry->set_collection_enabled( 'heroicons', true ) );
		$this->assertContains( 'radix', $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
		$this->assertContains( 'heroicons', $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
		$this->assertContains( 'radix', $GLOBALS['icon_library_test_options'][ Plugin::OPTION_INSTALLED_COLLECTIONS ] );
		$this->assertContains( 'heroicons', $GLOBALS['icon_library_test_options'][ Plugin::OPTION_INSTALLED_COLLECTIONS ] );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ] = array( 'radix' => array( 'outline' ) );
		$GLOBALS['icon_library_test_cached_options'][ Plugin::OPTION_ENABLED_VARIANTS ] = array();
		$this->assertTrue( $registry->set_variant_enabled( 'heroicons', 'solid', false ) );
		$this->assertSame( array( 'outline' ), $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ]['radix'] );
	}

	/** Failure of the second option write cannot publish a partial selection. */
	public function test_enable_rolls_back_installed_marker_if_enabled_write_fails() {
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		$GLOBALS['wpdb']->fail_option = Plugin::OPTION_ENABLED_COLLECTIONS;
		$this->assertFalse( $registry->set_collection_enabled( 'heroicons', true ) );
		$this->assertSame( array(), get_option( Plugin::OPTION_INSTALLED_COLLECTIONS ) );
		$this->assertSame( array(), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertFalse( $GLOBALS['wpdb']->active );
	}

	/** A filter veto cannot report activation or leave a partial installed marker. */
	public function test_filter_veto_rolls_back_the_requested_collection_state() {
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		$filter = 'pre_update_option_' . Plugin::OPTION_ENABLED_COLLECTIONS;
		$GLOBALS['icon_library_test_filters'][ $filter ][] = static function ( $value, $old ) { return $old; };
		try {
			$this->assertFalse( $registry->set_collection_enabled( 'heroicons', true ) );
			$this->assertSame( array(), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
			$this->assertSame( array(), get_option( Plugin::OPTION_INSTALLED_COLLECTIONS ) );
		} finally {
			unset( $GLOBALS['icon_library_test_filters'][ $filter ] );
		}
	}
}
