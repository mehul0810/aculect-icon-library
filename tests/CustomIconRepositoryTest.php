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
		$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
		$GLOBALS['icon_library_test_cached_options'] = array();
		$this->repository                      = new CustomIconRepository( new SvgSanitizer() );
	}

	protected function tearDown(): void {
		$GLOBALS['icon_library_test_cached_options'] = array();
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

	public function test_create_preserves_archived_artwork_and_requires_a_new_name() {
		$this->repository->create( 'reusable', 'Old label', '<svg><path d="M0 0h1v1z"/></svg>' );
		$icons                         = $this->repository->get_icons();
		$icons['reusable']['archived'] = true;
		update_option( CustomIconRepository::OPTION_ICONS, $icons, false );
		$original_path = $this->repository->get_file_path( 'reusable.svg' );
		$original_svg  = file_get_contents( $original_path );

		$result = $this->repository->create( 'reusable', 'New label', '<svg><path d="M0 0h2v2z"/></svg>' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_duplicate', $result->get_error_code() );
		$this->assertSame( $icons, $this->repository->get_icons() );
		$this->assertSame( $original_svg, file_get_contents( $original_path ) );
		$this->assertIsArray( $this->repository->create( 'new-name', 'New label', '<svg><path d="M0 0h2v2z"/></svg>' ) );
	}

	public function test_create_reads_authoritative_metadata_after_cached_absence_and_another_create() {
		$other = new CustomIconRepository( new SvgSanitizer() );
		$sanitizer = $this->getMockBuilder( SvgSanitizer::class )->onlyMethods( array( 'sanitize_custom' ) )->getMock();
		$sanitizer->method( 'sanitize_custom' )->willReturnCallback( function ( $svg ) use ( $other ) {
			$this->assertIsArray( $other->create( 'other', 'Other', $svg ) );
			$GLOBALS['icon_library_test_cached_options'][ CustomIconRepository::OPTION_ICONS ] = array();
			return ( new SvgSanitizer() )->sanitize_custom( $svg );
		} );
		$repository = new CustomIconRepository( $sanitizer );
		$this->assertIsArray( $repository->create( 'current', 'Current', '<svg><path d="M0 0h1v1z"/></svg>' ) );
		$this->assertSame( array( 'other', 'current' ), array_keys( $repository->get_icons() ) );
	}

	public function test_create_does_not_resurrect_deleted_or_undo_renamed_cached_rows() {
		$this->repository->create( 'deleted', 'Delete me', '<svg><path d="M0 0h1v1z"/></svg>' );
		$this->repository->create( 'renamed', 'Before', '<svg><path d="M0 0h1v1z"/></svg>' );
		$stale = $this->repository->get_icons();
		$sanitizer = $this->getMockBuilder( SvgSanitizer::class )->onlyMethods( array( 'sanitize_custom' ) )->getMock();
		$sanitizer->method( 'sanitize_custom' )->willReturnCallback( function ( $svg ) use ( $stale ) {
			$this->assertTrue( $this->repository->delete( 'deleted' ) );
			$this->assertIsArray( $this->repository->update_label( 'renamed', 'After' ) );
			$GLOBALS['icon_library_test_cached_options'][ CustomIconRepository::OPTION_ICONS ] = $stale;
			return ( new SvgSanitizer() )->sanitize_custom( $svg );
		} );
		$repository = new CustomIconRepository( $sanitizer );
		$this->assertIsArray( $repository->create( 'current', 'Current', '<svg><path d="M0 0h1v1z"/></svg>' ) );
		$this->assertArrayNotHasKey( 'deleted', $repository->get_icons() );
		$this->assertSame( 'After', $repository->get_icons()['renamed']['label'] );
	}

	public function test_failed_commit_preserves_existing_icon_and_retains_recoverable_bytes() {
		$this->repository->create( 'existing', 'Existing', '<svg><path d="M0 0h1v1z"/></svg>' );
		$existing = $this->repository->get_icons();
		$GLOBALS['wpdb']->fail_commit = true;
		$result = $this->repository->create( 'failed', 'Failed', '<svg><path d="M0 0h2v2z"/></svg>' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $existing, $this->repository->get_icons() );
		$this->assertCount( 2, glob( $GLOBALS['icon_library_test_upload_dir'] . '/icon-library/custom-icons/*.svg' ) );
		$result = $this->repository->delete( 'existing' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFileExists( $this->repository->get_file_path( 'existing.svg' ) );
		$GLOBALS['wpdb']->fail_commit = false;
	}

	public function test_lost_commit_acknowledgement_never_deletes_published_artwork() {
		$GLOBALS['wpdb']->fail_commit_applied = true;
		$result = $this->repository->create( 'applied', 'Applied', '<svg><path d="M0 0h2v2z"/></svg>' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'applied', $this->repository->get_icons() );
		$this->assertFileExists( $this->repository->get_file_path( 'applied.svg' ) );
		$icons = $this->repository->get_icons();
		$path = $this->repository->get_file_path( $icons['applied']['path'] );
		$artwork = file_get_contents( $path );
		$GLOBALS['wpdb']->fail_commit_applied = false;
		$retry = $this->repository->create( 'applied', 'Retry', '<svg><path d="M0 0h3v3z"/></svg>' );
		$this->assertInstanceOf( WP_Error::class, $retry );
		$this->assertSame( 'icon_library_custom_duplicate', $retry->get_error_code() );
		$this->assertSame( $icons, $this->repository->get_icons() );
		$this->assertSame( $artwork, file_get_contents( $path ) );
		$this->assertFalse( $GLOBALS['wpdb']->active );
		$this->assertArrayNotHasKey( CustomIconRepository::OPTION_LOCK, $GLOBALS['icon_library_test_options'] );
	}

	public function test_failed_creation_at_byte_budget_can_retry_without_leaking_files_or_exceeding_limit() {
		$svg = '<svg><path d="M0 0h2v2z"/></svg>';
		$bytes = strlen( ( new SvgSanitizer() )->sanitize_custom( $svg ) );
		$this->assertIsArray( $this->repository->create( 'existing', 'Existing', $svg ) );
		$icons = $this->repository->get_icons();
		$path = $this->repository->get_file_path( $icons['existing']['path'] );
		// Model retained legacy artwork at the byte ceiling without a 32 MiB fixture.
		$icons['existing']['bytes'] = CustomIconRepository::MAX_BYTES - $bytes;
		update_option( CustomIconRepository::OPTION_ICONS, $icons, false );
		$GLOBALS['wpdb']->fail_option = CustomIconRepository::OPTION_ICONS;
		$result = $this->repository->create( 'last', 'Last', $svg );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_metadata_failed', $result->get_error_code() );
		$this->assertSame( $icons, $this->repository->get_icons() );
		$this->assertSame( array( $path ), glob( dirname( $path ) . '/*.svg' ) );
		$GLOBALS['wpdb']->fail_option = '';
		$this->assertIsArray( $this->repository->create( 'last', 'Last', $svg ) );
		$this->assertSame( CustomIconRepository::MAX_BYTES, array_sum( array_column( $this->repository->get_icons(), 'bytes' ) ) );
		$files = glob( dirname( $path ) . '/*.svg' );
		$this->assertCount( 2, $files );
		$result = $this->repository->create( 'overflow', 'Overflow', $svg );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_limit', $result->get_error_code() );
		$this->assertSame( $files, glob( dirname( $path ) . '/*.svg' ) );
		$this->assertFalse( $GLOBALS['wpdb']->active );
	}

	public function test_failed_create_preserves_an_icon_committed_after_its_initial_read() {
		$other = new CustomIconRepository( new SvgSanitizer() );
		$sanitizer = $this->getMockBuilder( SvgSanitizer::class )->onlyMethods( array( 'sanitize_custom' ) )->getMock();
		$sanitizer->method( 'sanitize_custom' )->willReturnCallback( function ( $svg ) use ( $other ) {
			$this->assertIsArray( $other->create( 'concurrent', 'Concurrent', $svg ) );
			$GLOBALS['wpdb']->fail_option = CustomIconRepository::OPTION_ICONS;
			return ( new SvgSanitizer() )->sanitize_custom( $svg );
		} );
		$result = ( new CustomIconRepository( $sanitizer ) )->create( 'failed', 'Failed', '<svg><path d="M0 0h2v2z"/></svg>' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_metadata_failed', $result->get_error_code() );
		$icons = $other->get_icons();
		$this->assertSame( array( 'concurrent' ), array_keys( $icons ) );
		$path = $other->get_file_path( $icons['concurrent']['path'] );
		$this->assertFileExists( $path );
		$this->assertSame( array( $path ), glob( dirname( $path ) . '/*.svg' ) );
		$this->assertFalse( $GLOBALS['wpdb']->active );
	}

	public function test_repeated_metadata_write_failures_do_not_accumulate_unpublished_files() {
		$this->assertIsArray( $this->repository->create( 'existing', 'Existing', '<svg><path d="M0 0h1v1z"/></svg>' ) );
		$icons     = $this->repository->get_icons();
		$path      = $this->repository->get_file_path( $icons['existing']['path'] );
		$artwork   = file_get_contents( $path );
		$directory = dirname( $path );
		$GLOBALS['wpdb']->fail_option = CustomIconRepository::OPTION_ICONS;
		$GLOBALS['wpdb']->queries     = array();
		foreach ( array( 'first', 'second', 'third' ) as $name ) {
			$result = $this->repository->create( $name, 'Unpublished', '<svg><path d="M0 0h2v2z"/></svg>' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( $icons, $this->repository->get_icons() );
			$this->assertSame( array( $path ), glob( $directory . '/*.svg' ) );
			$this->assertSame( strlen( $artwork ), array_sum( array_map( 'filesize', glob( $directory . '/*.svg' ) ) ) );
			$this->assertSame( $artwork, file_get_contents( $path ) );
			$this->assertFalse( $GLOBALS['wpdb']->active );
			$this->assertArrayNotHasKey( CustomIconRepository::OPTION_LOCK, $GLOBALS['icon_library_test_options'] );
		}
		$this->assertNotContains( 'COMMIT', array_column( $GLOBALS['wpdb']->queries, 0 ) );
		$GLOBALS['wpdb']->fail_option = '';
		$this->assertIsArray( $this->repository->create( 'first', 'Retry', '<svg><path d="M0 0h2v2z"/></svg>' ) );
		$this->assertCount( 2, glob( $directory . '/*.svg' ) );
	}

	public function test_unpublished_file_cleanup_failure_reports_retained_bytes_and_releases_lock() {
		$GLOBALS['wpdb']->fail_option = CustomIconRepository::OPTION_ICONS;
		$GLOBALS['icon_library_test_filters']['wp_delete_file'][] = static function () { return ''; };
		try {
			$result = $this->repository->create( 'retained-failure', 'Retained failure', '<svg><path d="M0 0h2v2z"/></svg>' );
			$files  = glob( $GLOBALS['icon_library_test_upload_dir'] . '/icon-library/custom-icons/*.svg' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'icon_library_custom_cleanup_failed', $result->get_error_code() );
			$this->assertSame( 500, $result->get_error_data()['status'] );
			$this->assertCount( 1, $files );
			$this->assertSame( filesize( $files[0] ), $result->get_error_data()['retained_bytes'] );
			$this->assertSame( array(), $this->repository->get_icons() );
			$this->assertFalse( $GLOBALS['wpdb']->active );
			$this->assertArrayNotHasKey( CustomIconRepository::OPTION_LOCK, $GLOBALS['icon_library_test_options'] );
		} finally {
			unset( $GLOBALS['icon_library_test_filters']['wp_delete_file'] );
		}
	}

	public function test_unsupported_storage_reports_actionable_error_without_writes() {
		$GLOBALS['wpdb']->engine = 'MyISAM';
		$result = $this->repository->create( 'test', 'Test', '<svg><path d="M0 0h1v1z"/></svg>' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_custom_storage_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->repository->get_icons() );
	}

	public function test_failed_select_during_acquire_read_or_write_preserves_existing_metadata() {
		$this->assertIsArray( $this->repository->create( 'existing', 'Existing', '<svg><path d="M0 0h1v1z"/></svg>' ) );
		$original = $this->repository->get_icons();
		foreach ( array( 1, 2, 3 ) as $failed_read ) {
			$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
			$GLOBALS['wpdb']->fail_reads = array( $failed_read );
			$result = $this->repository->create( 'new', 'New', '<svg><path d="M0 0h2v2z"/></svg>' );
			$this->assertInstanceOf( WP_Error::class, $result, 'Failed read ' . $failed_read );
			$this->assertSame( $original, $this->repository->get_icons() );
			$this->assertFileExists( $this->repository->get_file_path( $original['existing']['path'] ) );
			$this->assertFalse( $GLOBALS['wpdb']->active );
		}
	}

	public function test_vetoed_label_change_reports_failure_without_claiming_new_label() {
		$this->repository->create( 'existing', 'Before', '<svg><path d="M0 0h1v1z"/></svg>' );
		$filter = 'pre_update_option_' . CustomIconRepository::OPTION_ICONS;
		$GLOBALS['icon_library_test_filters'][ $filter ][] = static function ( $value, $old ) { return $old; };
		try {
			$result = $this->repository->update_label( 'existing', 'After' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'Before', $this->repository->get_icons()['existing']['label'] );
		} finally {
			unset( $GLOBALS['icon_library_test_filters'][ $filter ] );
		}
	}

	public function test_filtered_create_cannot_report_artwork_published_when_metadata_is_vetoed() {
		$filter = 'pre_update_option_' . CustomIconRepository::OPTION_ICONS;
		$GLOBALS['icon_library_test_filters'][ $filter ][] = static function () { return array(); };
		try {
			$result = $this->repository->create( 'vetoed', 'Vetoed', '<svg><path d="M0 0h1v1z"/></svg>' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( array(), $this->repository->get_icons() );
			$this->assertSame( array(), glob( $GLOBALS['icon_library_test_upload_dir'] . '/icon-library/custom-icons/*.svg' ) );
			$this->assertFalse( $GLOBALS['wpdb']->active );
		} finally {
			unset( $GLOBALS['icon_library_test_filters'][ $filter ] );
		}
	}

	public function test_distinct_uuid_like_names_keep_their_artwork_in_core_listing_and_delete() {
		$first = $this->repository->create( 'first', 'First', '<svg><path d="M0 0h1v1z"/></svg>' );
		$this->assertSame( '_', $first['path'][0] );
		$first_svg = $this->repository->get_svg_content( $first['path'] );
		// Model an existing UUID filename produced before storage names were disjoint.
		$old_path = substr( $first['path'], 1 );
		$directory = dirname( $this->repository->get_file_path( $first['path'] ) );
		$this->assertTrue( rename( $directory . '/' . $first['path'], $directory . '/' . $old_path ) );
		$icons = $this->repository->get_icons();
		$icons['first']['path'] = $old_path;
		update_option( CustomIconRepository::OPTION_ICONS, $icons, false );
		$second_name = basename( $old_path, '.svg' );
		$second = $this->repository->create( $second_name, 'Second', '<svg><path d="M0 0h2v2z"/></svg>' );
		$this->assertIsArray( $second );
		$this->assertNotSame( $first['id'], $second['id'] );
		$this->assertSame( $first_svg, $this->repository->get_svg_content( $old_path ) );
		$this->assertStringContainsString( 'h2v2z', $this->repository->get_svg_content( $second['path'] ) );
		$listing = new \IconLibrary\CustomIconListing( $this->repository );
		$page = $listing->get_page( '', 1, 'label', 1, 20 );
		$this->assertSame( $first_svg, $page['items'][0]['svg'] );
		$this->assertStringContainsString( 'h2v2z', $page['items'][1]['svg'] );
		$this->assertArrayNotHasKey( 'path', $page['items'][0] );
		$this->assertTrue( $this->repository->delete( $second_name ) );
		$this->assertSame( $first_svg, $this->repository->get_svg_content( $old_path ) );
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
