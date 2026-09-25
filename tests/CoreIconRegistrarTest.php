<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\ManifestLoader;
use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

class CoreIconRegistrarTest extends TestCase {
	public function test_mobile_picker_compat_style_is_scoped_to_wordpress_71() {
		$registry  = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$registrar = new CoreIconRegistrar( $registry );

		$GLOBALS['icon_library_test_wp_version']      = '7.1.2';
		$GLOBALS['icon_library_test_enqueued_styles'] = array();
		$registrar->enqueue_editor_picker_compat_styles();
		$this->assertSame(
			array( ICON_LIBRARY_URL . 'assets/picker-compat.css', array(), ICON_LIBRARY_VERSION ),
			$GLOBALS['icon_library_test_enqueued_styles']['icon-library-picker-compat']
		);

		$GLOBALS['icon_library_test_wp_version']      = '7.2';
		$GLOBALS['icon_library_test_enqueued_styles'] = array();
		$registrar->enqueue_editor_picker_compat_styles();
		$this->assertSame( array(), $GLOBALS['icon_library_test_enqueued_styles'] );
		$GLOBALS['icon_library_test_wp_version'] = '7.1.2';
	}

	public function test_metadata_discovery_never_resolves_svg_files() {
		$GLOBALS['icon_library_test_registered'] = array( 'collections' => array(), 'icons' => array() );
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_enabled_collection_slugs', 'get_manifest', 'get_enabled_variants', 'get_svg_path', 'get_svg_content' ) )->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array() );
		$registry->method( 'get_manifest' )->willReturn( array( 'name' => 'Test', 'icons' => array( array( 'coreIconName' => 'test/one', 'label' => 'One', 'path' => 'one.svg' ) ) ) );
		$registry->expects( $this->never() )->method( 'get_svg_path' );
		$registry->expects( $this->never() )->method( 'get_svg_content' );
		( new CoreIconRegistrar( $registry ) )->register_icons( '', true );
		$this->assertArrayHasKey( 'test', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertSame( array(), $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_namespace_discovery_skips_unrelated_manifests() {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_enabled_collection_slugs', 'get_manifest', 'get_enabled_variants' ) )->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'test', 'unrelated' ) );
		$registry->expects( $this->once() )->method( 'get_manifest' )->with( 'test' )->willReturn( array() );
		$registry->method( 'get_enabled_variants' )->willReturn( array() );
		( new CoreIconRegistrar( $registry ) )->register_icons( 'test-solid' );
	}

	public function test_scoped_core_request_skips_unrelated_manifests() {
		$GLOBALS['icon_library_test_registered'] = array( 'collections' => array(), 'icons' => array() );
		$GLOBALS['icon_library_test_capabilities']['edit_posts'] = true;
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_enabled_collection_slugs', 'get_manifest', 'get_enabled_variants', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'test', 'unrelated' ) );
		$registry->expects( $this->once() )->method( 'get_manifest' )->with( 'test' )->willReturn(
			array(
				'name'  => 'Test',
				'icons' => array(
					array(
						'coreIconName' => 'test/one',
						'label'        => 'One',
						'path'         => 'one.svg',
					),
				),
			)
		);
		$registry->method( 'get_enabled_variants' )->with( 'test' )->willReturn( array() );
		$registry->expects( $this->once() )->method( 'get_svg_path' )->with( 'test', 'one.svg' )->willReturn( __FILE__ );

		$request = new WP_REST_Request( 'GET', '/wp/v2/icons', array( 'collection' => 'test' ) );
		( new CoreIconRegistrar( $registry ) )->prepare_core_icon_request( null, null, $request );
		unset( $GLOBALS['icon_library_test_capabilities']['edit_posts'] );

		$this->assertArrayHasKey( 'test/one', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	private function style_registrar( $enabled = true ) {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( $enabled ? array( 'test' ) : array() );
		$registry->method( 'get_enabled_variants' )->willReturn( $enabled ? array( 'solid', 'mini' ) : array() );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'     => 'Test',
				'variants' => array(
					array(
						'slug'  => 'solid',
						'label' => 'Solid',
					),
					array(
						'slug'  => 'mini',
						'label' => 'Mini',
					),
					array(
						'slug'  => 'outline',
						'label' => 'Outline',
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'test/one-solid',
						'label'        => 'One',
						'variant'      => 'solid',
						'path'         => 'solid/one.svg',
					),
					array(
						'coreIconName' => 'test/one-mini',
						'label'        => 'One',
						'variant'      => 'mini',
						'path'         => 'mini/one.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icons();
		return $registrar;
	}

	public function test_registers_styles_and_retains_legacy_icon_names() {
		$this->style_registrar();
		$registered = $GLOBALS['icon_library_test_registered'];
		$this->assertSame( 'Test - Solid', $registered['collections']['test-solid']['label'] );
		$this->assertSame( 'Test - Mini', $registered['collections']['test-mini']['label'] );
		$this->assertArrayNotHasKey( 'test-outline', $registered['collections'] );
		$this->assertArrayHasKey( 'test-solid/one-solid', $registered['icons'] );
		$this->assertArrayHasKey( 'test-mini/one-mini', $registered['icons'] );
		$this->assertArrayNotHasKey( 'test/one-solid', $registered['icons'] );
		$this->assertArrayNotHasKey( 'test-solid/one-mini', $registered['icons'] );
		$registrar = $this->style_registrar();
		$registrar->register_icon_block(
			array(
				'blockName' => 'core/icon',
				'attrs'     => array( 'icon' => 'test/one-solid' ),
			)
		);
		$this->assertArrayHasKey( 'test/one-solid', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_hides_parent_collection_without_hiding_enabled_styles() {
		$registrar = $this->style_registrar();
		$response  = new WP_REST_Response(
			array(
				array( 'slug' => 'core' ),
				array( 'slug' => 'test' ),
				array( 'slug' => 'test-solid' ),
				array( 'slug' => 'test-mini' ),
			)
		);
		$result    = $registrar->filter_core_discovery_response( $response, null, new WP_REST_Request( 'GET', '/wp/v2/icon-collections' ) );
		$this->assertSame( array( 'core', 'test-solid', 'test-mini' ), array_column( $result->get_data(), 'slug' ) );
	}

	public function test_disabled_collection_registers_nothing_until_rendered() {
		$registrar = $this->style_registrar( false );
		$this->assertSame( array(), $GLOBALS['icon_library_test_registered']['icons'] );

		$registrar->register_icon_block(
			array(
				'blockName' => 'core/icon',
				'attrs'     => array( 'icon' => 'test-solid/one-solid' ),
			)
		);
		$this->assertArrayHasKey( 'test-solid/one-solid', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_individual_icon_response_remains_available_when_uninstalled() {
		$registrar = $this->style_registrar( false );
		foreach ( array( 'test/one-solid', 'test-solid/one-solid' ) as $name ) {
			$data     = array(
				'name'    => $name,
				'content' => '<svg/>',
			);
			$response = new WP_REST_Response( $data );
			$result   = $registrar->filter_core_discovery_response( $response, null, new WP_REST_Request( 'GET', '/wp/v2/icons/' . $name ) );
			$this->assertSame( $data, $result->get_data() );
		}
	}

	public function test_registers_collection_and_icon_with_core() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array() );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'        => 'Test',
				'description' => 'Test icons',
				'icons'       => array(
					array(
						'coreIconName' => 'test/one',
						'label'        => 'One',
						'path'         => 'one.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );

		( new CoreIconRegistrar( $registry ) )->register_icons();

		$this->assertArrayHasKey( 'test', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayHasKey( 'test/one', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_registers_default_only_collections_in_their_manifest_namespace() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'radix' ) );
		$registry->method( 'get_enabled_variants' )->with( 'radix' )->willReturn( array( 'default' ) );
		$registry->method( 'get_manifest' )->with( 'radix' )->willReturn(
			array(
				'name'     => 'Radix Icons',
				'variants' => array(
					array(
						'slug'  => 'default',
						'label' => 'Default',
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'radix/accessibility-default',
						'label'        => 'Accessibility',
						'variant'      => 'default',
						'path'         => 'default/accessibility.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );

		( new CoreIconRegistrar( $registry ) )->register_icons( 'radix' );

		$this->assertSame( 'Radix Icons', $GLOBALS['icon_library_test_registered']['collections']['radix']['label'] );
		$this->assertArrayNotHasKey( 'radix-default', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayHasKey( 'radix/accessibility-default', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_registers_all_enabled_radix_icons_in_the_manifest_collection() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'radix' );

		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		( new CoreIconRegistrar( $registry ) )->register_icons( 'radix' );

		$this->assertSame( 'Radix Icons', $GLOBALS['icon_library_test_registered']['collections']['radix']['label'] );
		$this->assertArrayNotHasKey( 'radix-default', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertCount(
			299,
			array_filter(
				array_keys( $GLOBALS['icon_library_test_registered']['icons'] ),
				static function ( $name ) {
					return 0 === strpos( $name, 'radix/' );
				}
			)
		);

		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );
	}

	public function test_does_not_register_radix_until_the_collection_is_enabled() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		unset( $GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] );

		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) );
		( new CoreIconRegistrar( $registry ) )->register_icons( 'radix' );

		$this->assertArrayNotHasKey( 'radix', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertSame( array(), $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_preserves_the_former_default_style_namespace_for_saved_icons() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'radix' ) );
		$registry->method( 'get_manifest' )->with( 'radix' )->willReturn(
			array(
				'name'     => 'Radix Icons',
				'variants' => array(
					array(
						'slug'  => 'default',
						'label' => 'Default',
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'radix/accessibility-default',
						'label'        => 'Accessibility',
						'variant'      => 'default',
						'path'         => 'default/accessibility.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );

		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icon_block(
			array(
				'blockName' => 'core/icon',
				'attrs'     => array( 'icon' => 'radix-default/accessibility-default' ),
			)
		);

		$this->assertArrayHasKey( 'radix-default', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayHasKey( 'radix-default/accessibility-default', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_custom_icons_use_their_collection_label_without_a_variant_suffix() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'custom-icons' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'custom' ) );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'     => 'Custom Icons',
				'variants' => array(
					array(
						'slug'  => 'custom',
						'label' => 'Custom',
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'custom-icons/example',
						'label'        => 'Example',
						'variant'      => 'custom',
						'path'         => 'example.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );

		( new CoreIconRegistrar( $registry ) )->register_icons();

		$this->assertSame( 'Custom Icons', $GLOBALS['icon_library_test_registered']['collections']['custom-icons']['label'] );
		$this->assertArrayNotHasKey( 'custom-icons-custom', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayHasKey( 'custom-icons/example', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_marks_core_incompatible_icons_before_core_sanitizes_them() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path', 'get_svg_content' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'test' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'outline' ) );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'     => 'Test',
				'variants' => array(
					array(
						'slug'           => 'outline',
						'label'          => 'Outline',
						'coreCompatible' => false,
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'test/one-outline',
						'label'        => 'One',
						'variant'      => 'outline',
						'path'         => 'outline/one.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );
		$registry->method( 'get_svg_content' )->willReturn( '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M1 1h22"/></svg>' );

		( new CoreIconRegistrar( $registry ) )->register_icons();

		$registered = $GLOBALS['icon_library_test_registered']['icons'];
		$this->assertStringContainsString( 'icon-library-stroked', $registered['test-outline/one-outline']['content'] );
		$this->assertArrayNotHasKey( 'test/one-outline', $registered );
		$this->assertArrayNotHasKey( 'file_path', $registered['test-outline/one-outline'] );
	}

	public function test_registers_zero_label_and_legacy_heroicons_size_names() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_enabled_collection_slugs', 'get_enabled_variants', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'heroicons' ) );
		$registry->method( 'get_enabled_collection_slugs' )->willReturn( array( 'heroicons' ) );
		$registry->method( 'get_enabled_variants' )->willReturn( array( 'solid' ) );
		$registry->method( 'get_manifest' )->willReturn(
			array(
				'name'     => 'Heroicons',
				'variants' => array(
					array(
						'slug'  => 'solid',
						'label' => 'Solid',
					),
				),
				'icons'    => array(
					array(
						'coreIconName' => 'heroicons/0-solid',
						'label'        => '0',
						'path'         => 'solid/0.svg',
						'variant'      => 'solid',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );
		( new CoreIconRegistrar( $registry ) )->register_icons();
		$registered = $GLOBALS['icon_library_test_registered']['icons'];
		$this->assertArrayHasKey( 'heroicons-solid', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayNotHasKey( 'heroicons-mini', $GLOBALS['icon_library_test_registered']['collections'] );
		$this->assertArrayNotHasKey( 'heroicons/0-solid', $registered );
		$this->assertArrayNotHasKey( 'heroicons/0-24-solid', $registered );
		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icon_block(
			array(
				'blockName' => 'core/icon',
				'attrs'     => array( 'icon' => 'heroicons/0-24-solid' ),
			)
		);
		$this->assertArrayHasKey( 'heroicons/0-24-solid', $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_lazy_resolution_does_not_load_unrelated_collections() {
		$GLOBALS['icon_library_test_registered'] = array(
			'collections' => array(),
			'icons'       => array(),
		);
		$registry                                = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_available_collection_slugs', 'get_manifest', 'get_svg_path' ) )->getMock();
		$registry->method( 'get_available_collection_slugs' )->willReturn( array( 'test', 'unrelated' ) );
		$registry->expects( $this->once() )->method( 'get_manifest' )->with( 'test' )->willReturn(
			array(
				'name'  => 'Test',
				'icons' => array(
					array(
						'coreIconName' => 'test/one',
						'label'        => 'One',
						'path'         => 'one.svg',
					),
				),
			)
		);
		$registry->method( 'get_svg_path' )->willReturn( __FILE__ );

		$registrar = new CoreIconRegistrar( $registry );
		$registrar->register_icon_block(
			array(
				'blockName' => 'core/icon',
				'attrs'     => array( 'icon' => 'test/one' ),
			)
		);

		$this->assertArrayHasKey( 'test/one', $GLOBALS['icon_library_test_registered']['icons'] );
	}
}
