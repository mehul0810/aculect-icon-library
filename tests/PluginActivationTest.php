<?php
/** @package IconLibrary */

use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

class PluginActivationTest extends TestCase {
	protected function setUp(): void {
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_LEGACY_COLLECTIONS ] );
	}

	public function test_fresh_activation_installs_no_collections() {
		Plugin::activate();

		$this->assertSame( array(), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
		$loader = new IconLibrary\ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$this->assertSame( array(), Plugin::legacy_collections( $loader ) );
		$registry = new IconLibrary\CollectionRegistry( $loader, null, Plugin::legacy_collections( $loader ) );
		$this->assertSame( array(), $registry->get_collections() );
		$this->assertNull( $registry->get_collection( 'heroicons' ) );
		$this->assertNull( $registry->get_manifest( 'heroicons' ) );
		$this->assertNull( $registry->get_svg_content( 'heroicons', '24-solid/academic-cap.svg' ) );
		$this->assertFalse( $registry->set_collection_enabled( 'heroicons', true ) );
	}

	public function test_activation_preserves_existing_collection_state() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'heroicons' );

		Plugin::activate();

		$this->assertSame( array( 'heroicons' ), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$loader = new IconLibrary\ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$this->assertContains( 'heroicons', Plugin::legacy_collections( $loader ) );
		$registry = new IconLibrary\CollectionRegistry( $loader, null, Plugin::legacy_collections( $loader ) );
		$this->assertSame( array( 'heroicons' ), $registry->get_enabled_collection_slugs() );
		$this->assertNotNull( $registry->get_manifest( 'heroicons' ) );
	}

	public function test_upgrade_without_reactivation_preserves_disabled_collections_and_preferences() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ] = array( 'heroicons' => array( 'solid' ) );
		$loader = new IconLibrary\ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$this->assertContains( 'heroicons', Plugin::legacy_collections( $loader ) );
		$this->assertSame( array(), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertSame( array( 'heroicons' => array( 'solid' ) ), get_option( Plugin::OPTION_ENABLED_VARIANTS ) );
		$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ Plugin::OPTION_LEGACY_COLLECTIONS ] );
	}

	public function test_site_switch_does_not_leak_legacy_collections_to_a_fresh_site() {
		$loader = new IconLibrary\ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'heroicons' );
		$legacy = Plugin::legacy_collections( $loader );
		$registry = new IconLibrary\CollectionRegistry( $loader, null, $legacy );
		$this->assertNotNull( $registry->get_collection( 'heroicons' ) );
		$GLOBALS['icon_library_test_options'] = array( Plugin::OPTION_ENABLED_COLLECTIONS => array(), Plugin::OPTION_LEGACY_COLLECTIONS => array() );
		$registry->switch_site();
		$this->assertSame( array(), $registry->get_collections() );
		$this->assertNull( $registry->get_collection( 'heroicons' ) );
		$this->assertNull( $registry->get_manifest( 'heroicons' ) );
		$this->assertFalse( $registry->set_collection_enabled( 'heroicons', true ) );
		$GLOBALS['icon_library_test_options'] = array( Plugin::OPTION_ENABLED_COLLECTIONS => array( 'heroicons' ), Plugin::OPTION_LEGACY_COLLECTIONS => $legacy );
		$registry->switch_site();
		$this->assertNotNull( $registry->get_collection( 'heroicons' ) );
		$this->assertSame( array( 'heroicons' ), $registry->get_enabled_collection_slugs() );
	}
}
