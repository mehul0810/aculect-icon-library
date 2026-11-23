<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\CorePickerEndpoint;
use PHPUnit\Framework\TestCase;

function rest_do_request( $request ) {
	$dispatch = $GLOBALS['icon_library_test_picker_dispatch'];
	return $dispatch( $request );
}

class CorePickerEndpointTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['icon_library_test_picker_dispatch'] );
	}

	public function test_bootstrap_dispatches_core_once_and_selects_the_saved_namespace() {
		$collections = array( array( 'slug' => 'core' ), array( 'slug' => 'external-outline' ) );
		$dispatches  = 0;
		$GLOBALS['icon_library_test_picker_dispatch'] = function ( $request ) use ( $collections, &$dispatches ) {
			++$dispatches;
			$this->assertSame( 'GET', $request->get_method() );
			$this->assertSame( '/wp/v2/icon-collections', $request->get_route() );
			return new class( $collections ) extends WP_REST_Response {
				public function is_error() {
					return false;
				}
			};
		};
		$adapter = $this->createMock( CoreIconRegistrar::class );
		$page    = array( 'items' => array(), 'page' => 3, 'total' => 145, 'per_page' => 48, 'total_pages' => 4 );
		$adapter->expects( $this->once() )->method( 'get_picker_page' )->with( 'external-outline', '', 0, 48, 'external-outline/one' )->willReturn( $page );
		$result = CorePickerEndpoint::read( $adapter, new WP_REST_Request( 'GET', '/icon-library/v1/picker', array( 'bootstrap' => true, 'selected' => 'external-outline/one' ) ) );
		$this->assertSame( $page + array( 'collections' => $collections, 'collection' => 'external-outline' ), $result->get_data() );
		$this->assertSame( 1, $dispatches );
	}

	public function test_page_requests_preserve_the_original_response_shape_without_core_dispatch() {
		$adapter = $this->createMock( CoreIconRegistrar::class );
		$page    = array( 'items' => array(), 'page' => 2, 'total' => 0, 'per_page' => 48, 'total_pages' => 0 );
		$adapter->expects( $this->once() )->method( 'get_picker_page' )->with( 'core', 'arrow', 2, 48, '' )->willReturn( $page );
		$result = CorePickerEndpoint::read( $adapter, new WP_REST_Request( 'GET', '/icon-library/v1/picker', array( 'collection' => 'core', 'search' => 'arrow', 'page' => 2 ) ) );
		$this->assertSame( $page, $result->get_data() );
	}

	public function test_core_errors_propagate_without_hydrating_icons() {
		$GLOBALS['icon_library_test_picker_dispatch'] = static function () {
			return new class( array() ) extends WP_REST_Response {
				public function is_error() {
					return true;
				}

				public function as_error() {
					return new WP_Error( 'rest_forbidden', 'Not permitted.', array( 'status' => 403 ) );
				}
			};
		};
		$adapter = $this->createMock( CoreIconRegistrar::class );
		$adapter->expects( $this->never() )->method( 'get_picker_page' );
		$result = CorePickerEndpoint::read( $adapter, new WP_REST_Request( 'GET', '/icon-library/v1/picker', array( 'bootstrap' => true ) ) );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_page_size_contract_is_unchanged() {
		$args = CorePickerEndpoint::query_args();
		$this->assertSame( 48, $args['per_page']['maximum'] );
		$this->assertSame( CollectionRegistry::MAX_PAGE, $args['page']['maximum'] );
		$this->assertFalse( $args['bootstrap']['default'] );
	}

	public function test_missing_bootstrap_artwork_retains_collections_without_hiding_the_error() {
		$collections = array( array( 'slug' => 'broken' ), array( 'slug' => 'healthy' ) );
		$GLOBALS['icon_library_test_picker_dispatch'] = static function () use ( $collections ) {
			return new class( $collections ) extends WP_REST_Response {
				public function is_error() {
					return false;
				}
			};
		};
		$adapter = $this->createMock( CoreIconRegistrar::class );
		$error   = new WP_Error( 'icon_library_picker_asset_missing', 'Artwork unavailable.', array( 'status' => 503 ) );
		$adapter->method( 'get_picker_page' )->willReturn( $error );
		$result = CorePickerEndpoint::read( $adapter, new WP_REST_Request( 'GET', '/icon-library/v1/picker', array( 'bootstrap' => true ) ) );
		$this->assertSame( $error, $result );
		$this->assertSame( array( 'status' => 503, 'collections' => $collections, 'collection' => 'broken' ), $result->get_error_data() );
	}

	public function test_non_bootstrap_and_unrelated_errors_do_not_gain_collection_metadata() {
		$adapter = $this->createMock( CoreIconRegistrar::class );
		$error   = new WP_Error( 'icon_library_picker_asset_missing', 'Artwork unavailable.', array( 'status' => 503 ) );
		$adapter->method( 'get_picker_page' )->willReturn( $error );
		CorePickerEndpoint::read( $adapter, new WP_REST_Request( 'GET', '/icon-library/v1/picker' ) );
		$this->assertSame( array( 'status' => 503 ), $error->get_error_data() );

		$GLOBALS['icon_library_test_picker_dispatch'] = static function () {
			return new class( array( array( 'slug' => 'core' ) ) ) extends WP_REST_Response {
				public function is_error() {
					return false;
				}
			};
		};
		$forbidden = new WP_Error( 'rest_forbidden', 'Not permitted.', array( 'status' => 403 ) );
		$adapter   = $this->createMock( CoreIconRegistrar::class );
		$adapter->method( 'get_picker_page' )->willReturn( $forbidden );
		$this->assertSame( $forbidden, CorePickerEndpoint::read( $adapter, new WP_REST_Request( 'GET', '/icon-library/v1/picker', array( 'bootstrap' => true ) ) ) );
		$this->assertSame( array( 'status' => 403 ), $forbidden->get_error_data() );
	}
}
