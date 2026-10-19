<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CustomIconRepository;
use IconLibrary\RestController;
use PHPUnit\Framework\TestCase;

class RestPermissionsTest extends TestCase {
	private function controller() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$custom   = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		return new RestController( $registry, $custom );
	}

	public function test_mutation_requires_manage_options() {
		$GLOBALS['icon_library_test_capabilities'] = array();
		$result                                    = $this->controller()->can_manage_collections();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_rest_cannot_manage', $result->get_error_code() );
	}

	public function test_administrator_can_mutate() {
		$GLOBALS['icon_library_test_capabilities'] = array( 'manage_options' => true );
		$this->assertTrue( $this->controller()->can_manage_collections() );
	}

	public function test_repeated_variant_state_is_an_idempotent_success() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_collection', 'set_variant_enabled' ) )->getMock();
		$registry->method( 'get_collection' )->willReturn(
			array(
				'enabled'  => true,
				'variants' => array( array( 'slug' => 'solid' ) ),
			)
		);
		$registry->expects( $this->once() )->method( 'set_variant_enabled' )->willReturn( true );
		$custom = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		$result = ( new RestController( $registry, $custom ) )->activate_variant(
			new WP_REST_Request(
				'POST',
				'',
				array(
					'slug'    => 'test',
					'variant' => 'solid',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
	}

	public function test_variant_write_failure_is_not_reported_as_not_found() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_collection', 'set_variant_enabled' ) )->getMock();
		$registry->method( 'get_collection' )->willReturn(
			array(
				'enabled'  => true,
				'variants' => array( array( 'slug' => 'solid' ) ),
			)
		);
		$registry->method( 'set_variant_enabled' )->willReturn( false );
		$custom = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		$result = ( new RestController( $registry, $custom ) )->activate_variant(
			new WP_REST_Request(
				'POST',
				'',
				array(
					'slug'    => 'test',
					'variant' => 'solid',
				)
			)
		);

		$this->assertSame( 'icon_library_variant_update_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_icon_catalog_returns_pagination_metadata() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'query_icons' ) )->getMock();
		$registry->expects( $this->once() )->method( 'query_icons' )->willReturn(
			array(
				'items'          => array( array( 'id' => 'test/solid/one' ) ),
				'total'          => 3,
				'variant_counts' => array( 'solid' => 3 ),
			)
		);
		$custom   = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->getMock();
		$response = ( new RestController( $registry, $custom ) )->get_icons(
			new WP_REST_Request(
				'GET',
				'',
				array(
					'page'     => 2,
					'per_page' => 2,
				)
			)
		);

		$this->assertSame( 2, $response->get_data()['page'] );
		$this->assertSame( 2, $response->get_data()['per_page'] );
		$this->assertSame( 2, $response->get_data()['total_pages'] );
	}

	public function test_custom_icon_listing_reads_svg_only_for_requested_page() {
		$icons = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$name          = sprintf( 'icon-%02d', $i );
			$icons[ $name ] = array( 'label' => sprintf( 'Icon %02d', $i ) );
		}
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$custom   = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->onlyMethods( array( 'get_icons', 'get_svg_content' ) )->getMock();
		$custom->method( 'get_icons' )->willReturn( $icons );
		$read_count = 0;
		$custom->expects( $this->exactly( 5 ) )->method( 'get_svg_content' )->willReturnCallback(
			static function ( $path ) use ( &$read_count ) {
				$read_count++;
				return '<svg data-path="' . $path . '" />';
			}
		);
		$response = ( new RestController( $registry, $custom ) )->get_custom_icons( new WP_REST_Request( 'GET', '', array( 'page' => 2, 'per_page' => 5 ) ) );
		$this->assertSame( 25, $response->get_data()['total'] );
		$this->assertSame( 5, $read_count );
		$this->assertCount( 5, $response->get_data()['items'] );
	}

	public function test_custom_icon_listing_out_of_range_page_reads_no_svgs() {
		$custom = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->onlyMethods( array( 'get_icons', 'get_svg_content' ) )->getMock();
		$custom->method( 'get_icons' )->willReturn( array( 'one' => array( 'label' => 'One' ) ) );
		$custom->expects( $this->never() )->method( 'get_svg_content' );
		$response = ( new RestController( $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock(), $custom ) )->get_custom_icons( new WP_REST_Request( 'GET', '', array( 'page' => 99, 'per_page' => 5 ) ) );
		$this->assertSame( 1, $response->get_data()['total'] );
		$this->assertSame( array(), $response->get_data()['items'] );
	}
}
