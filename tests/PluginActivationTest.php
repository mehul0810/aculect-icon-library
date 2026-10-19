<?php
/**
 * Verifies activation and collection state behavior.
 *
 * @package IconLibrary
 */

use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

/** Plugin activation tests. */
class PluginActivationTest extends TestCase {
	/** Resets the state used by activation tests. */
	protected function setUp(): void {
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ] );
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_LEGACY_COLLECTIONS ] );
	}

	/** Fresh activation leaves every bundled collection disabled. */
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

	/** The default registry discovers the complete bundle without enabling it. */
	public function test_default_registry_discovers_every_bundled_collection_disabled() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$loader   = new IconLibrary\ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$registry = new IconLibrary\CollectionRegistry( $loader, new IconLibrary\CustomIconRepository( new IconLibrary\SvgSanitizer() ) );
		$slugs    = $loader->get_collection_slugs();

		$this->assertCount( 10, $slugs );
		$this->assertSame( $slugs, array_keys( $registry->get_collections() ) );
		$this->assertSame( array(), $registry->get_enabled_collection_slugs() );
		$this->assertFalse( $registry->get_collection( $slugs[0] )['enabled'] );
	}

	/** Activation preserves saved collection state. */
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

	/** Upgrades preserve disabled collections and variant preferences. */
	public function test_upgrade_without_reactivation_preserves_disabled_collections_and_preferences() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ]    = array( 'heroicons' => array( 'solid' ) );
		$loader = new IconLibrary\ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$this->assertContains( 'heroicons', Plugin::legacy_collections( $loader ) );
		$this->assertSame( array(), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertSame( array( 'heroicons' => array( 'solid' ) ), get_option( Plugin::OPTION_ENABLED_VARIANTS ) );
		$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ Plugin::OPTION_LEGACY_COLLECTIONS ] );
	}

	/** Site switches do not leak one site's retained collections to another. */
	public function test_site_switch_does_not_leak_legacy_collections_to_a_fresh_site() {
		$loader = new IconLibrary\ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'heroicons' );
		$legacy   = Plugin::legacy_collections( $loader );
		$registry = new IconLibrary\CollectionRegistry( $loader, null, $legacy );
		$this->assertNotNull( $registry->get_collection( 'heroicons' ) );
		$GLOBALS['icon_library_test_options'] = array(
			Plugin::OPTION_ENABLED_COLLECTIONS => array(),
			Plugin::OPTION_LEGACY_COLLECTIONS  => array(),
		);
		$registry->switch_site();
		$this->assertSame( array(), $registry->get_collections() );
		$this->assertNull( $registry->get_collection( 'heroicons' ) );
		$this->assertNull( $registry->get_manifest( 'heroicons' ) );
		$this->assertFalse( $registry->set_collection_enabled( 'heroicons', true ) );
		$GLOBALS['icon_library_test_options'] = array(
			Plugin::OPTION_ENABLED_COLLECTIONS => array( 'heroicons' ),
			Plugin::OPTION_LEGACY_COLLECTIONS  => $legacy,
		);
		$registry->switch_site();
		$this->assertNotNull( $registry->get_collection( 'heroicons' ) );
		$this->assertSame( array( 'heroicons' ), $registry->get_enabled_collection_slugs() );
	}
}
