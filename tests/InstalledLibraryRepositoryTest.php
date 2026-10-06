<?php
/** @package IconLibrary */

use IconLibrary\InstalledLibraryRepository;
use IconLibrary\LibraryInstaller;
use IconLibrary\LibraryJobStore;
use IconLibrary\LibraryPackageValidator;
use IconLibrary\SvgSanitizer;
use IconLibrary\TrustedLibraryCatalog;
use PHPUnit\Framework\TestCase;

class InstalledLibraryRepositoryTest extends TestCase {
	private $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/icon-library-repository-' . wp_generate_password( 12, false, false );
		mkdir( $this->root, 0700, true );
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->root );
	}

	public function test_published_svg_path_resolves_through_installed_provider() {
		$jobs     = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$hash     = str_repeat( 'a', 64 );
		$relative = 'outline/' . $hash . '/icons/test.svg';
		$source   = $this->root . '/source';
		mkdir( $source . '/icons', 0700, true );
		file_put_contents( $source . '/icons/test.svg', '<svg/>' );
		$jobs->method( 'read' )->with( 'fixture-library' )->willReturn(
			array(
				'raw'   => '',
				'value' => array(
					'styles' => array(
						'outline' => array(
							'manifest'       => array(
								'slug'  => 'fixture-library',
								'name'  => 'Fixture Library',
								'icons' => array(
									array(
										'id'           => 'test',
										'coreIconName' => 'fixture-library/test',
										'variant'      => 'outline',
										'path'         => $relative,
									),
								),
							),
							'archived_icons' => array(),
						),
					),
				),
			)
		);
		$repository = new InstalledLibraryRepository( $jobs, $this->root . '/storage' );
		$published  = $repository->publish( 'fixture-library', 'outline', '1.0.0', $hash, 'job-one', $source, array( 'icons/test.svg' ) );
		$this->assertSame( $this->root . '/storage/fixture-library/outline/' . $hash, $published );
		$manifest = $repository->get_manifest( 'fixture-library' );
		$this->assertSame( $relative, $manifest['icons'][0]['path'] );
		$this->assertSame( '<svg/>', file_get_contents( $repository->get_svg_path( 'fixture-library', $relative ) ) );
		$GLOBALS['icon_library_test_blog_id'] = 2;
		$this->assertNull( $repository->get_svg_path( 'fixture-library', $relative ) );
		$GLOBALS['icon_library_test_blog_id'] = 1;
		$this->assertSame( '<svg/>', file_get_contents( $repository->get_svg_path( 'fixture-library', $relative ) ) );
	}

	public function test_rejects_namespace_already_used_by_external_core_icon() {
		$jobs = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$jobs->method( 'all_library_ids' )->willReturn( array() );
		$installer     = new LibraryInstaller( new TrustedLibraryCatalog( array() ), new InstalledLibraryRepository( $jobs ), $jobs, new LibraryPackageValidator( new SvgSanitizer() ) );
		$external_name = 'external-' . strtolower( wp_generate_password( 8, false, false ) ) . '/icon';
		wp_register_icon( $external_name, array( 'label' => 'Externally registered icon' ) );
		$method = new ReflectionMethod( LibraryInstaller::class, 'has_namespace_collision' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$this->assertTrue( $method->invoke( $installer, strtok( $external_name, '/' ), 'outline' ) );
	}

	public function test_fresh_site_can_install_old_bundled_names_but_legacy_site_reserves_them() {
		$core_icons = WP_Icons_Registry::get_instance();
		$core_state = new ReflectionProperty( $core_icons, 'icons' );
		if ( PHP_VERSION_ID < 80100 ) { $core_state->setAccessible( true ); }
		$registered = $core_state->getValue( $core_icons );
		$core_state->setValue( $core_icons, array() );
		$jobs = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$jobs->method( 'all_library_ids' )->willReturn( array( 'heroicons' ) );
		$jobs->method( 'read' )->willReturn( array( 'value' => array( 'styles' => array( 'solid' => array( 'release_version' => '1.0.0', 'package_sha256' => str_repeat( 'a', 64 ), 'installed_at' => 1 ) ) ) ) );
		$repository = new InstalledLibraryRepository( $jobs, $this->root . '/storage' );
		$installer = new LibraryInstaller( new TrustedLibraryCatalog( array() ), $repository, $jobs, new LibraryPackageValidator( new SvgSanitizer() ) );
		$method = new ReflectionMethod( LibraryInstaller::class, 'has_namespace_collision' );
		if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
		$before = get_option( IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS, false );
		update_option( IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS, array() );
		$this->assertFalse( $method->invoke( $installer, 'heroicons', 'solid' ) );
		$this->assertArrayHasKey( 'heroicons', $repository->register_providers( array() ) );
		update_option( IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS, array( 'heroicons' ) );
		$this->assertTrue( $method->invoke( $installer, 'heroicons', 'solid' ) );
		$this->assertArrayNotHasKey( 'heroicons', $repository->register_providers( array() ) );
		if ( false === $before ) { delete_option( IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS ); } else { update_option( IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS, $before ); }
		$core_state->setValue( $core_icons, $registered );
	}

	public function test_rejects_external_collection_provider_using_library_namespace() {
		$jobs = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$jobs->method( 'all_library_ids' )->willReturn( array() );
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = array(
			static function ( $providers ) {
							$providers['external-fixture'] = array( 'manifest' => array() );
							return $providers;
			},
		);
		$installer = new LibraryInstaller( new TrustedLibraryCatalog( array() ), new InstalledLibraryRepository( $jobs ), $jobs, new LibraryPackageValidator( new SvgSanitizer() ) );
		$method    = new ReflectionMethod( LibraryInstaller::class, 'has_namespace_collision' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$this->assertTrue( $method->invoke( $installer, 'external-fixture', 'outline' ) );
		unset( $GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] );
	}

	public function test_rejects_external_collection_provider_style_alias_collision() {
		$jobs = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$jobs->method( 'all_library_ids' )->willReturn( array() );
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = array(
			static function ( $providers ) {
							$providers['other-library'] = array( 'manifest' => array( 'variants' => array( array( 'slug' => 'outline' ) ) ) );
							return $providers;
			},
		);
		$installer = new LibraryInstaller( new TrustedLibraryCatalog( array() ), new InstalledLibraryRepository( $jobs ), $jobs, new LibraryPackageValidator( new SvgSanitizer() ) );
		$method    = new ReflectionMethod( LibraryInstaller::class, 'has_namespace_collision' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$this->assertTrue( $method->invoke( $installer, 'other-library-outline', 'solid' ) );
		unset( $GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] );
	}

	public function test_rejects_saved_style_alias_that_exceeds_name_length_limit() {
		$library_id = str_repeat( 'a', 70 );
		$style_id   = str_repeat( 'b', 70 );
		$jobs       = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$jobs->method( 'all_library_ids' )->willReturn( array() );
		$jobs->method( 'read' )->willReturn( array( 'value' => array( 'identities' => array() ) ) );
		$installer = new LibraryInstaller( new TrustedLibraryCatalog( array() ), new InstalledLibraryRepository( $jobs ), $jobs, new LibraryPackageValidator( new SvgSanitizer() ) );
		$entry     = array(
			'library_id' => $library_id,
			'style_id'   => $style_id,
		);
		$manifest  = array(
			'icons' => array(
				array(
					'id'             => 'icon',
					'core_icon_name' => str_repeat( 'c', 60 ),
				),
			),
		);
		$method    = new ReflectionMethod( LibraryInstaller::class, 'validate_identities' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$result    = $method->invoke( $installer, $library_id, $entry, $manifest );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_identity_length', $result->get_error_code() );
	}

	public function test_accepts_saved_style_alias_at_exact_name_length_limit() {
		$library_id = str_repeat( 'a', 70 );
		$style_id   = str_repeat( 'b', 70 );
		$jobs       = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$jobs->method( 'all_library_ids' )->willReturn( array() );
		$jobs->method( 'read' )->willReturn( array( 'value' => array( 'identities' => array() ) ) );
		$installer = new LibraryInstaller( new TrustedLibraryCatalog( array() ), new InstalledLibraryRepository( $jobs ), $jobs, new LibraryPackageValidator( new SvgSanitizer() ) );
		$entry     = array(
			'library_id' => $library_id,
			'style_id'   => $style_id,
		);
		$manifest  = array(
			'icons' => array(
				array(
					'id'             => 'icon',
					'core_icon_name' => str_repeat( 'c', 58 ),
				),
			),
		);
		$method    = new ReflectionMethod( LibraryInstaller::class, 'validate_identities' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$this->assertSame( true, $method->invoke( $installer, $library_id, $entry, $manifest ) );
	}

	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			} return; }
		foreach ( scandir( $path ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$this->remove_tree( $path . '/' . $item ); }
		}
		rmdir( $path );
	}
}
