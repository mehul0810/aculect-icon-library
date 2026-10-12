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

	public function test_cleanup_retains_same_name_registrations_replaced_by_another_provider() {
		$GLOBALS['icon_library_test_registered'] = array( 'icons' => array(), 'collections' => array() );
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'site-fixture' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'outline' ) );
		$registry->method( 'get_manifest' )->willReturn( $this->manifest() );
		$registry->method( 'get_svg_content' )->willReturn( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0L1 1"/></svg>' );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icons();
		wp_unregister_icon( 'site-fixture-outline/one' );
		wp_register_icon( 'site-fixture-outline/one', array( 'content' => '<svg><path d="M2 2L3 3"/></svg>', 'label' => 'External replacement' ) );
		wp_unregister_icon_collection( 'site-fixture-outline' );
		wp_register_icon_collection( 'site-fixture-outline', array( 'label' => 'External replacement' ) );
		$icon = $GLOBALS['icon_library_test_registered']['icons']['site-fixture-outline/one'];
		$collection = $GLOBALS['icon_library_test_registered']['collections']['site-fixture-outline'];
		$registrar->reset_for_blog_switch();
		$this->assertSame( $icon, $GLOBALS['icon_library_test_registered']['icons']['site-fixture-outline/one'] );
		$this->assertSame( $collection, $GLOBALS['icon_library_test_registered']['collections']['site-fixture-outline'] );
	}

	public function test_evicted_name_indexes_reload_without_removing_registered_saved_icons() {
		$GLOBALS['icon_library_test_registered'] = array( 'icons' => array(), 'collections' => array() );
		$loads = array();
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'cache-one', 'cache-two', 'cache-three' ) );
		$registry->method( 'get_manifest' )->willReturnCallback( static function ( $library ) use ( &$loads ) {
			$loads[ $library ] = ( $loads[ $library ] ?? 0 ) + 1;
			return array( 'slug' => $library, 'name' => $library, 'variants' => array(), 'icons' => array( array( 'id' => 'one', 'coreIconName' => $library . '/one', 'label' => 'One', 'path' => 'one.svg' ), array( 'id' => 'two', 'coreIconName' => $library . '/two', 'label' => 'Two', 'path' => 'two.svg' ) ) );
		} );
		$registry->method( 'get_svg_content' )->willReturn( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0L1 1"/></svg>' );
		$registrar = new CoreIconRegistrar( $registry );
		foreach ( array( 'cache-one/one', 'cache-two/one', 'cache-three/one', 'cache-one/two' ) as $name ) {
			$registrar->register_icon_block( array( 'blockName' => 'core/icon', 'attrs' => array( 'icon' => $name ) ) );
			$this->assertArrayHasKey( $name, $GLOBALS['icon_library_test_registered']['icons'] );
		}
		$this->assertSame( 2, $loads['cache-one'] );
		$this->assertArrayHasKey( 'cache-one/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$property = new ReflectionProperty( CoreIconRegistrar::class, 'collection_indexes' );
		if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
		$this->assertCount( CoreIconRegistrar::MAX_REQUEST_INDEXES, $property->getValue( $registrar ) );
	}

	public function test_provider_manifest_cache_reloads_evicted_metadata_and_keeps_hot_reads_local() {
		$loads = array();
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = array( static function () use ( &$loads ) {
			$providers = array();
			foreach ( array( 'cache-one', 'cache-two', 'cache-three' ) as $slug ) {
				$providers[ $slug ] = array( 'manifest' => static function () use ( $slug, &$loads ) { $loads[ $slug ] = ( $loads[ $slug ] ?? 0 ) + 1; return array( 'slug' => $slug, 'name' => $slug, 'icons' => array() ); } );
			}
			return $providers;
		} );
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ), null, array() );
		foreach ( array( 'cache-one', 'cache-two', 'cache-one', 'cache-three', 'cache-two' ) as $slug ) {
			$this->assertSame( $slug, $registry->get_manifest( $slug )['name'] );
		}
		$this->assertSame( array( 'cache-one' => 1, 'cache-two' => 2, 'cache-three' => 1 ), $loads );
		$property = new ReflectionProperty( CollectionRegistry::class, 'provider_manifests' );
		if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
		$this->assertCount( CollectionRegistry::MAX_REQUEST_MANIFESTS, $property->getValue( $registry ) );
	}

	public function test_admin_memory_allowance_applies_only_after_authorized_bulk_icon_read() {
		$GLOBALS['icon_library_test_memory_raises'] = array();
		$GLOBALS['icon_library_test_capabilities'] = array();
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array() );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->prepare_core_icon_request( null, null, new WP_REST_Request( 'GET', '/wp/v2/icons' ) );
		$this->assertSame( array(), $GLOBALS['icon_library_test_memory_raises'] );
		$GLOBALS['icon_library_test_capabilities'] = array( 'edit_posts' => true );
		$registrar->prepare_core_icon_request( null, null, new WP_REST_Request( 'GET', '/wp/v2/icon-collections' ) );
		$this->assertSame( array(), $GLOBALS['icon_library_test_memory_raises'] );
		$registrar->prepare_core_icon_request( null, null, new WP_REST_Request( 'GET', '/wp/v2/icons' ) );
		$this->assertSame( array( 'admin' ), $GLOBALS['icon_library_test_memory_raises'] );
		$GLOBALS['icon_library_test_capabilities'] = array();
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
