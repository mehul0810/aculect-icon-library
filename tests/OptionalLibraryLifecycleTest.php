<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\ManifestLoader;
use PHPUnit\Framework\TestCase;

class OptionalLibraryLifecycleTest extends TestCase {
	private $previous_filters;

	protected function setUp(): void {
		$this->previous_filters = $GLOBALS['icon_library_test_filters'];
		$GLOBALS['icon_library_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['icon_library_test_filters'] = $this->previous_filters;
	}

	private function manifest() {
		return array(
			'slug' => 'site-fixture',
			'name' => 'Site fixture',
			'variants' => array( array( 'slug' => 'outline', 'label' => 'Outline' ) ),
			'icons' => array( array( 'id' => 'one', 'coreIconName' => 'site-fixture/one', 'variant' => 'outline', 'label' => 'One', 'path' => 'one.svg' ) ),
		);
	}

	public function test_disabled_style_hides_lazy_canonical_discovery_but_not_exact_reads() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'site-fixture' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'site-fixture' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array() );
		$registry->method( 'get_manifest' )->willReturn( $this->manifest() );
		$registrar = new CoreIconRegistrar( $registry );
		$canonical = array( 'name' => 'site-fixture/one', 'collection' => 'site-fixture' );
		$style = array( 'name' => 'site-fixture-outline/one', 'collection' => 'site-fixture-outline' );
		$external = array( 'name' => 'external/one', 'collection' => 'external' );
		$response = $registrar->filter_core_discovery_response( new WP_REST_Response( array( $canonical, $style, $external ) ), null, new WP_REST_Request( 'GET', '/wp/v2/icons' ) );
		$this->assertSame( array( $external ), $response->get_data() );
		$exact = new WP_REST_Response( $canonical );
		$this->assertSame( $exact, $registrar->filter_core_discovery_response( $exact, null, new WP_REST_Request( 'GET', '/wp/v2/icons/site-fixture/one' ) ) );
		$this->assertSame( $canonical, $exact->get_data() );
	}

	public function test_switch_hook_refreshes_provider_manifests_on_enter_and_restore() {
		$site = 1;
		$old = $GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] ?? array();
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = array(
			static function () use ( &$site ) {
				return array( 'site-fixture' => array( 'manifest' => static function () use ( &$site ) { return array( 'slug' => 'site-fixture', 'name' => 'Site ' . $site, 'icons' => array() ); } ) );
			},
		);
		try {
			$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
			$hooks = array_filter( $GLOBALS['icon_library_test_actions']['switch_blog'], static function ( $hook ) use ( $registry ) { return $hook[0] === array( $registry, 'switch_site' ); } );
			$this->assertCount( 1, $hooks );
			$hook = reset( $hooks );
			$this->assertSame( 'Site 1', $registry->get_manifest( 'site-fixture' )['name'] );
			$site = 2;
			call_user_func( $hook[0] );
			$this->assertSame( 'Site 2', $registry->get_manifest( 'site-fixture' )['name'] );
			$site = 1;
			call_user_func( $hook[0] );
			$this->assertSame( 'Site 1', $registry->get_manifest( 'site-fixture' )['name'] );
		} finally {
			$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = $old;
		}
	}

	public function test_core_switch_cleanup_preserves_external_names_and_allows_reregistration() {
		$GLOBALS['icon_library_test_registered'] = array( 'icons' => array(), 'collections' => array() );
		wp_register_icon_collection( 'external-lifecycle', array( 'label' => 'External' ) );
		wp_register_icon( 'external-lifecycle/one', array( 'content' => '<svg><path d="M0 0"/></svg>' ) );
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'site-fixture' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'outline' ) );
		$registry->method( 'get_manifest' )->willReturn( $this->manifest() );
		$registry->method( 'get_svg_content' )->willReturn( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0L1 1"/></svg>' );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icons();
		$this->assertArrayHasKey( 'site-fixture-outline/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$hooks = array_filter( $GLOBALS['icon_library_test_actions']['switch_blog'], static function ( $hook ) use ( $registrar ) { return $hook[0] === array( $registrar, 'reset_for_blog_switch' ); } );
		$this->assertCount( 1, $hooks );
		$hook = reset( $hooks );
		call_user_func( $hook[0] );
		$this->assertArrayNotHasKey( 'site-fixture-outline/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$this->assertArrayNotHasKey( 'site-fixture', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayHasKey( 'external-lifecycle/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$this->assertArrayHasKey( 'external-lifecycle', $GLOBALS['icon_library_test_registered']['collections'] );
		$registrar->register_icons();
		$this->assertArrayHasKey( 'site-fixture-outline/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$registrar->reset_for_blog_switch();
	}
}
