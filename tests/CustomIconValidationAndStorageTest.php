<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\CustomIconRepository;
use IconLibrary\CorePickerEndpoint;
use IconLibrary\ManifestLoader;
use IconLibrary\RestController;
use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

class CustomIconValidationAndStorageTest extends TestCase {
	private $repository;

	protected function setUp(): void {
		$GLOBALS['icon_library_test_options'] = array();
		$GLOBALS['icon_library_test_autoload'] = array();
		$GLOBALS['icon_library_test_cached_options'] = array();
		$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
		$this->repository = new CustomIconRepository( new SvgSanitizer() );
	}

	protected function tearDown(): void {
		$directory = $GLOBALS['icon_library_test_upload_dir'] . '/icon-library/custom-icons';
		foreach ( glob( $directory . '/*.svg' ) ?: array() as $file ) {
			unlink( $file );
		}
	}

	public function test_name_and_unicode_label_limits_are_applied_in_repository_and_rest_callback() {
		$svg = '<svg><path d="M0 0h1v1z"/></svg>';
		$valid_name = str_repeat( 'a', 100 );
		$valid_label = str_repeat( '😀', 200 );
		$result = $this->repository->create( $valid_name, $valid_label, $svg );
		$this->assertIsArray( $result );
		$this->assertSame( $valid_label, $result['label'] );
		$this->assertSame( 'icon_library_custom_name_too_long', $this->repository->create( str_repeat( 'b', 101 ), 'Label', $svg )->get_error_code() );
		$this->assertSame( 'icon_library_custom_label_too_long', $this->repository->create( 'valid', str_repeat( 'é', 201 ), $svg )->get_error_code() );
		$this->assertSame( 'icon_library_custom_label_too_long', $this->repository->create( 'valid', "\xC3\x28", $svg )->get_error_code() );
		$this->assertSame( 'icon_library_custom_label_too_long', $this->repository->update_label( $valid_name, str_repeat( '😀', 201 ) )->get_error_code() );
		$this->assertSame( $valid_label, $this->repository->get_icons()[ $valid_name ]['label'] );

		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$controller = new RestController( $registry, $this->repository );
		$response = $controller->create_custom_icon( new WP_REST_Request( 'POST', '', array( 'name' => 'rest-name', 'label' => str_repeat( 'é', 201 ), 'svg' => $svg ) ) );
		$this->assertSame( 'icon_library_custom_label_too_long', $response->get_error_code() );
	}

	public function test_existing_long_name_remains_manageable_and_renders_when_saved() {
		$name = str_repeat( 'a', 251 );
		$full_name = 'custom-icons/' . $name;
		$this->assertSame( strlen( $full_name ), CorePickerEndpoint::query_args()['selected']['maxLength'] );
		$directory = $GLOBALS['icon_library_test_upload_dir'] . '/icon-library/custom-icons';
		wp_mkdir_p( $directory );
		$filename = $name . '.svg';
		file_put_contents( $directory . '/' . $filename, '<svg><path d="M0 0h1v1z"/></svg>' );
		$icon = array( 'coreIconName' => $full_name, 'label' => 'Existing', 'variant' => 'custom', 'path' => $filename );
		$GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] = array( $name => $icon );
		$this->assertIsArray( $this->repository->update_label( $name, 'Updated' ) );

		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ), $this->repository );
		$GLOBALS['icon_library_test_registered'] = array( 'collections' => array(), 'icons' => array() );
		( new CoreIconRegistrar( $registry ) )->register_icon_block( array( 'blockName' => 'core/icon', 'attrs' => array( 'icon' => $full_name ) ) );
		$this->assertStringContainsString( '<svg', $GLOBALS['icon_library_test_registered']['icons'][ $full_name ]['content'] );
	}

	/** @dataProvider mutation_methods */
	public function test_failed_metadata_read_does_not_claim_not_found( $method ) {
		$existing = array( 'one' => array( 'label' => 'One', 'archived' => true, 'path' => 'one.svg' ) );
		$GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] = $existing;
		$GLOBALS['wpdb']->fail_reads = array( 2 );
		$result = 'update_label' === $method ? $this->repository->update_label( 'one', 'Updated' ) : $this->repository->$method( 'one' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_metadata_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
		$this->assertSame( $existing, $GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] );
		$this->assertFalse( $GLOBALS['wpdb']->active );
	}

	public function mutation_methods() {
		return array( array( 'update_label' ), array( 'delete' ), array( 'restore' ), array( 'purge' ) );
	}

	public function test_failed_artwork_metadata_read_during_delete_does_not_remove_icon() {
		$existing = array( 'one' => array( 'label' => 'One', 'path' => 'one.svg' ) );
		$GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] = $existing;
		$GLOBALS['wpdb']->fail_reads = array( 3 );
		$result = $this->repository->delete( 'one' );
		$this->assertSame( 'icon_library_custom_metadata_failed', $result->get_error_code() );
		$this->assertSame( $existing, $GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] );
		$this->assertFalse( $GLOBALS['wpdb']->active );
	}

	public function test_confirmed_missing_row_still_returns_not_found() {
		$result = $this->repository->delete( 'missing' );
		$this->assertSame( 'icon_library_custom_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_failed_noop_restore_commit_is_error_and_successful_noop_is_idempotent() {
		$existing = array( 'one' => array( 'label' => 'One' ) );
		$GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] = $existing;
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$controller = new RestController( $registry, $this->repository );
		$request = new WP_REST_Request( 'POST', '', array( 'name' => 'one' ) );
		$GLOBALS['wpdb']->fail_commit = true;
		$result = $controller->restore_custom_icon( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_metadata_failed', $result->get_error_code() );
		$this->assertSame( $existing, $GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] );
		$GLOBALS['wpdb']->fail_commit = false;
		$this->assertSame( array( 'restored' => true ), $controller->restore_custom_icon( $request )->get_data() );
	}
}
