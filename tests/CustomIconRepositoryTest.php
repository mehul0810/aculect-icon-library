<?php
/** @package IconLibrary */

use IconLibrary\CustomIconRepository;
use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

class CustomIconRepositoryTest extends TestCase {
	private $repository;

	protected function setUp(): void {
		$GLOBALS['icon_library_test_options']  = array();
		$GLOBALS['icon_library_test_autoload'] = array();
		$this->repository                      = new CustomIconRepository( new SvgSanitizer() );
	}

	protected function tearDown(): void {
		$directory = $GLOBALS['icon_library_test_upload_dir'] . '/icon-library/custom-icons';
		foreach ( glob( $directory . '/*.svg' ) ?: array() as $file ) {
			unlink( $file );
		}
	}

	public function test_create_uses_non_autoloaded_metadata_and_atomic_file() {
		$result = $this->repository->create( 'test-icon', 'Test Icon', '<svg><path d="M0 0h1v1z"/></svg>' );
		$this->assertIsArray( $result );
		$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ CustomIconRepository::OPTION_ICONS ] );
		$this->assertFileExists( $this->repository->get_file_path( 'test-icon.svg' ) );
	}

	public function test_failed_sanitization_persists_nothing() {
		$result = $this->repository->create( 'unsafe', 'Unsafe', '<svg onload="alert(1)"><path d="M0 0"/></svg>' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $this->repository->get_icons() );
		$this->assertNull( $this->repository->get_file_path( 'unsafe.svg' ) );
	}

	public function test_create_persists_normalized_export_and_can_read_it_back() {
		$result = $this->repository->create( 'exported-icon', 'Exported Icon', '<svg id="Layer_1"><style>.a{fill:#FDB913;}</style><g id="fill"><path class="a" d="M0 0h1v1z"/></g></svg>' );
		$this->assertIsArray( $result );
		$saved = $this->repository->get_svg_content( 'exported-icon.svg' );
		$this->assertIsString( $saved );
		$this->assertStringContainsString( 'fill="#FDB913"', $saved );
		$this->assertStringNotContainsString( '<style', $saved );
		$this->assertStringNotContainsString( 'class=', $saved );
		$this->assertSame( $saved, ( new SvgSanitizer() )->sanitize_custom( $saved ) );
	}

	public function test_manifest_cache_reflects_label_and_delete_changes() {
		$this->repository->create( 'cached', 'Before', '<svg><path d="M0 0h1v1z"/></svg>' );
		$this->assertSame( 'Before', $this->repository->get_manifest()['icons'][0]['label'] );
		$this->repository->update_label( 'cached', 'After' );
		$this->assertSame( 'After', $this->repository->get_manifest()['icons'][0]['label'] );
		$this->repository->delete( 'cached' );
		$this->assertNull( $this->repository->get_manifest() );
	}

	public function test_delete_permanently_removes_metadata_and_saved_source() {
		$this->repository->create( 'retained', 'Retained', '<svg><path d="M0 0h1v1z"/></svg>' );
		$path = $this->repository->get_file_path( 'retained.svg' );

		$this->assertTrue( $this->repository->delete( 'retained' ) );
		$this->assertArrayNotHasKey( 'retained', $this->repository->get_icons() );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_legacy_archived_icon_can_be_restored_or_purged_explicitly() {
		$this->repository->create( 'lifecycle', 'Lifecycle', '<svg><path d="M0 0h1v1z"/></svg>' );
		$path = $this->repository->get_file_path( 'lifecycle.svg' );
		$icons                         = $this->repository->get_icons();
		$icons['lifecycle']['archived'] = true;
		update_option( CustomIconRepository::OPTION_ICONS, $icons, false );

		$this->assertTrue( $this->repository->restore( 'lifecycle' ) );
		$this->assertArrayNotHasKey( 'archived', $this->repository->get_icons()['lifecycle'] );
		$icons                         = $this->repository->get_icons();
		$icons['lifecycle']['archived'] = true;
		update_option( CustomIconRepository::OPTION_ICONS, $icons, false );
		$this->assertTrue( $this->repository->purge( 'lifecycle' ) );
		$this->assertArrayNotHasKey( 'lifecycle', $this->repository->get_icons() );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_create_replaces_a_legacy_archived_icon_with_the_same_name() {
		$this->repository->create( 'reusable', 'Old label', '<svg><path d="M0 0h1v1z"/></svg>' );
		$icons                         = $this->repository->get_icons();
		$icons['reusable']['archived'] = true;
		update_option( CustomIconRepository::OPTION_ICONS, $icons, false );

		$result = $this->repository->create( 'reusable', 'New label', '<svg><path d="M0 0h2v2z"/></svg>' );

		$this->assertIsArray( $result );
		$this->assertSame( 'New label', $result['label'] );
		$this->assertArrayNotHasKey( 'archived', $result );
		$this->assertStringContainsString( 'h2v2z', $this->repository->get_svg_content( 'reusable.svg' ) );
	}

	public function test_svg_validation_errors_are_client_errors() {
		$result = $this->repository->create( 'unsafe', 'Unsafe', '<svg onload="alert(1)"><path d="M0 0"/></svg>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_retained_byte_budget_includes_archived_rows() {
		$GLOBALS['icon_library_test_options'][ CustomIconRepository::OPTION_ICONS ] = array(
			'old' => array(
				'path'  => 'old.svg',
				'bytes' => CustomIconRepository::MAX_BYTES,
			),
		);

		$result = $this->repository->create( 'new', 'New', '<svg><path d="M0 0h1v1z"/></svg>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_limit', $result->get_error_code() );
	}
}
