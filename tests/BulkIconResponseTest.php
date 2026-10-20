<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use PHPUnit\Framework\TestCase;

class BulkIconResponseTest extends TestCase {
	private $previous_capabilities;
	private $previous_registered;
	private $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0L1 1"/></svg>';

	protected function setUp(): void {
		$this->previous_capabilities = $GLOBALS['icon_library_test_capabilities'];
		$this->previous_registered = $GLOBALS['icon_library_test_registered'];
		$GLOBALS['icon_library_test_registered'] = array( 'icons' => array(), 'collections' => array() );
	}

	protected function tearDown(): void {
		$GLOBALS['icon_library_test_capabilities'] = $this->previous_capabilities;
		$GLOBALS['icon_library_test_registered'] = $this->previous_registered;
	}

	private function registrar() {
		$manifest = array( 'slug' => 'bulk-fixture', 'name' => 'Bulk fixture', 'variants' => array( array( 'slug' => 'outline', 'label' => 'Outline' ) ), 'icons' => array( array( 'id' => 'one', 'coreIconName' => 'bulk-fixture/one', 'variant' => 'outline', 'label' => 'One', 'path' => 'one.svg' ) ) );
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'bulk-fixture' ) );
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'bulk-fixture' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'outline' ) );
		$registry->method( 'get_manifest' )->willReturn( $manifest );
		$registry->method( 'get_svg_content' )->willReturn( $this->svg );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icons();
		return $registrar;
	}

	public function test_complete_response_survives_owned_cleanup_and_saved_name_reregistration() {
		$GLOBALS['icon_library_test_capabilities'] = array( 'edit_posts' => true );
		wp_register_icon_collection( 'external-response', array( 'label' => 'External' ) );
		wp_register_icon( 'external-response/one', array( 'content' => $this->svg ) );
		$registrar = $this->registrar();
		$data = array( array( 'name' => 'bulk-fixture-outline/one', 'content' => $this->svg ), array( 'name' => 'external-response/one', 'content' => $this->svg ) );
		$before = json_encode( $data );
		$result = $registrar->release_bulk_response_state( $data, null, new WP_REST_Request( 'GET', '/wp/v2/icons' ) );
		$this->assertSame( $data, $result );
		$this->assertSame( $before, json_encode( $result ) );
		$this->assertArrayNotHasKey( 'bulk-fixture-outline/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$this->assertArrayHasKey( 'external-response/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$this->assertArrayHasKey( 'external-response', $GLOBALS['icon_library_test_registered']['collections'] );
		$registrar->register_icon_block( array( 'blockName' => 'core/icon', 'attrs' => array( 'icon' => 'bulk-fixture-outline/one' ) ) );
		$this->assertArrayHasKey( 'bulk-fixture-outline/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$registrar->reset_for_blog_switch();
	}

	public function test_exact_reads_other_routes_methods_and_unauthorized_responses_keep_state() {
		$registrar = $this->registrar();
		$data = array( array( 'name' => 'bulk-fixture-outline/one' ) );
		$GLOBALS['icon_library_test_capabilities'] = array();
		$this->assertSame( $data, $registrar->release_bulk_response_state( $data, null, new WP_REST_Request( 'GET', '/wp/v2/icons' ) ) );
		$GLOBALS['icon_library_test_capabilities'] = array( 'edit_posts' => true );
		foreach ( array( array( 'GET', '/wp/v2/icons/bulk-fixture-outline/one' ), array( 'GET', '/wp/v2/icon-collections' ), array( 'POST', '/wp/v2/icons' ) ) as $case ) {
			$this->assertSame( $data, $registrar->release_bulk_response_state( $data, null, new WP_REST_Request( $case[0], $case[1] ) ) );
		}
		$this->assertArrayHasKey( 'bulk-fixture-outline/one', $GLOBALS['icon_library_test_registered']['icons'] );
		$registrar->reset_for_blog_switch();
	}
}
