<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use PHPUnit\Framework\TestCase;

class BoundedPickerTest extends TestCase {
	private $before;

	protected function setUp(): void {
		$this->before = WP_Icons_Registry::get_instance()->get_registered_icons();
		foreach ( $this->before as $icon ) { wp_unregister_icon( $icon['name'] ); }
	}

	protected function tearDown(): void {
		foreach ( WP_Icons_Registry::get_instance()->get_registered_icons() as $icon ) { wp_unregister_icon( $icon['name'] ); }
		foreach ( $this->before as $icon ) { WP_Icons_Registry::get_instance()->set_registered_icon( $icon['name'], $icon ); }
	}

	private function fixture( $reads, $enabled = true, $icons = null ) {
		if ( null === $icons ) {
			$icons = array();
			for ( $i = 0; $i < 121; $i++ ) {
				$icons[] = array( 'coreIconName' => 'bounded/item-' . sprintf( '%03d', $i ), 'label' => 'Icon ' . $i, 'path' => $i . '.svg', 'variant' => 'solid' );
			}
		}
		$manifest = array( 'name' => 'Bounded', 'variants' => array( array( 'slug' => 'solid', 'label' => 'Solid' ), array( 'slug' => 'outline', 'label' => 'Outline' ) ), 'icons' => $icons );
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( $enabled ? array( 'bounded' ) : array() );
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'bounded' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'solid' ) );
		$registry->method( 'get_manifest' )->willReturn( $manifest );
		$registry->expects( $this->exactly( $reads ) )->method( 'get_svg_path' )->willReturn( null );
		$registry->expects( $this->exactly( $reads ) )->method( 'get_svg_content' )->willReturn( '<svg><path d="M0 0"/></svg>' );
		return new CoreIconRegistrar( $registry );
	}

	public function test_counts_entire_catalog_but_reads_only_requested_page_artwork() {
		$data = $this->fixture( 48 )->get_picker_page( '', '', 2, 48 );
		$this->assertSame( 121, $data['total'] );
		$this->assertCount( 48, $data['items'] );
		$this->assertSame( 'bounded-solid/item-048', $data['items'][0]['name'] );
		$this->assertSame( 'bounded-solid/item-095', $data['items'][47]['name'] );
		$this->assertArrayNotHasKey( 'file_path', $data['items'][0] );
	}

	public function test_selected_late_icon_and_search_never_load_unrelated_artwork() {
		$core = $this->fixture( 26 );
		$data = $core->get_picker_page( 'bounded-solid', '', 0, 48, 'bounded-solid/item-120' );
		$this->assertSame( 3, $data['page'] );
		$this->assertCount( 25, $data['items'] );
		$data = $core->get_picker_page( '', 'ITEM-120', 0, 48 );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'bounded-solid/item-120', $data['items'][0]['name'] );
	}

	public function test_disabled_collection_stays_absent_and_external_provider_is_preserved() {
		wp_register_icon_collection( 'external-picker', array( 'label' => 'External' ) );
		wp_register_icon( 'external-picker/one', array( 'label' => 'External one', 'content' => '<svg><path d="M0 0"/></svg>' ) );
		$data = $this->fixture( 0, false )->get_picker_page();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'external-picker/one', $data['items'][0]['name'] );
	}

	public function test_retained_and_disabled_style_metadata_cannot_be_selected() {
		$icons = array(
			array( 'coreIconName' => 'bounded/active', 'label' => 'Active', 'path' => 'active.svg', 'variant' => 'solid' ),
			array( 'coreIconName' => 'bounded/archived', 'label' => 'Archived', 'path' => 'archived.svg', 'variant' => 'solid', 'archived' => true ),
			array( 'coreIconName' => 'bounded/disabled', 'label' => 'Disabled', 'path' => 'disabled.svg', 'variant' => 'outline' ),
		);
		$data = $this->fixture( 1, true, $icons )->get_picker_page( '', '', 1, 500 );
		$this->assertSame( 48, $data['per_page'] );
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'bounded-solid/active', $data['items'][0]['name'] );
	}

	public function test_unsupported_registry_shape_fails_closed() {
		wp_register_icon( 'external-invalid/one', array( 'label' => array(), 'content' => '<svg/>' ) );
		$data = $this->fixture( 0, false )->get_picker_page();
		$this->assertInstanceOf( WP_Error::class, $data );
		$this->assertSame( 'icon_library_picker_registry_unsupported', $data->get_error_code() );
	}

	public function test_real_core_registry_keeps_external_artwork_lazy() {
		$script = __DIR__ . '/fixtures/real-core-picker.php';
		$process = proc_open( array( PHP_BINARY, $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error . $output );
		$this->assertStringContainsString( 'lazy and bounded', $output );
	}

	public function test_final_core_registry_fails_before_adapter_autoload() {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/final-core-picker.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error . $output );
		$this->assertStringContainsString( 'before adapter autoload', $output );
	}
}
