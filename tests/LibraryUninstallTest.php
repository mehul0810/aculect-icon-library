<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\InstalledLibraryRepository;
use IconLibrary\LibraryInstaller;
use IconLibrary\LibraryJobStore;
use IconLibrary\LibraryPackageValidator;
use IconLibrary\ManifestLoader;
use IconLibrary\SvgSanitizer;
use IconLibrary\TrustedLibraryCatalog;
use PHPUnit\Framework\TestCase;

class LibraryUninstallTest extends TestCase {
	private $state;
	private $root;
	private $jobs;
	private $repository;
	private $registry;
	private $installer;
	private $filters;
	private $cas_failures = 0;

	protected function setUp(): void {
		$this->filters = $GLOBALS['icon_library_test_filters'];
		$GLOBALS['icon_library_test_filters'] = array();
		$GLOBALS['icon_library_test_options'] = array( IconLibrary\Plugin::OPTION_ENABLED_COLLECTIONS => array( 'fixture-library', 'other-library' ), IconLibrary\Plugin::OPTION_LEGACY_COLLECTIONS => array() );
		$this->root = sys_get_temp_dir() . '/ail-uninstall-' . wp_generate_password( 12, false, false );
		mkdir( $this->root, 0700, true );
		$styles = array();
		$entries = array();
		foreach ( array( 'outline', 'filled' ) as $style ) {
			$hash = hash( 'sha256', $style );
			$relative = $style . '/' . $hash . '/icons/one.svg';
			$path = $this->root . '/fixture-library/' . $relative;
			mkdir( dirname( $path ), 0700, true );
			file_put_contents( $path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>' );
			$styles[ $style ] = array( 'release_version' => '1.0.0', 'package_sha256' => $hash, 'installed_at' => 1, 'manifest' => array( 'name' => 'Fixture Library', 'icons' => array( array( 'id' => $style, 'coreIconName' => 'fixture-library/one-' . $style, 'variant' => $style, 'label' => $style, 'path' => $relative ) ) ), 'archived_icons' => array() );
			$entries[] = array( 'library_id' => 'fixture-library', 'style_id' => $style, 'release_version' => '1.0.0', 'package_sha256' => $hash, 'manifest_sha256' => str_repeat( 'b', 64 ), 'package_bytes' => 100 );
		}
		$this->state = array( 'styles' => $styles, 'generation' => 1, 'identities' => array( 'stable-id' => array( 'style' => 'outline', 'local' => 'one-outline' ) ), 'job' => array( 'status' => 'succeeded', 'job_id' => 'old-job' ), 'lease_token' => '', 'lease_expires' => 0 );
		$this->jobs = $this->getMockBuilder( LibraryJobStore::class )->disableOriginalConstructor()->getMock();
		$this->jobs->method( 'read' )->willReturnCallback( function ( $library ) { return 'fixture-library' === $library ? array( 'raw' => serialize( $this->state ), 'value' => $this->state ) : null; } );
		$this->jobs->method( 'all_library_ids' )->willReturn( array( 'fixture-library' ) );
		$this->jobs->method( 'compare_and_swap' )->willReturnCallback( function ( $library, $raw, $value ) {
			if ( $this->cas_failures > 0 ) { --$this->cas_failures; return false; }
			if ( 'fixture-library' !== $library || serialize( $this->state ) !== $raw ) { return false; }
			$this->state = $value; return true;
		} );
		$this->repository = new InstalledLibraryRepository( $this->jobs, $this->root );
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = array( array( $this->repository, 'register_providers' ), static function ( $providers ) { $providers['other-library'] = array( 'manifest' => array( 'slug' => 'other-library', 'name' => 'Other', 'variants' => array(), 'icons' => array() ) ); return $providers; } );
		$this->registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ), null, array() );
		$this->installer = new LibraryInstaller( new TrustedLibraryCatalog( $entries ), $this->repository, $this->jobs, new LibraryPackageValidator( new SvgSanitizer() ), function () { throw new RuntimeException( 'Uninstall must not download' ); }, new CoreIconRegistrar( $this->registry ), $this->registry );
	}

	protected function tearDown(): void {
		$GLOBALS['icon_library_test_filters'] = $this->filters;
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) { if ( $file->isDir() ) { rmdir( $file->getPathname() ); } else { unlink( $file->getPathname() ); } }
		rmdir( $this->root );
	}

	public function test_uninstall_hides_discovery_preserves_saved_paths_and_is_idempotent() {
		$before = $this->state;
		$this->assertArrayHasKey( 'fixture-library', $this->registry->get_collections() );
		$this->assertTrue( $this->installer->uninstall( 'fixture-library' ) );
		$this->assertNull( $this->installer->get_installed( 'fixture-library' ) );
		$this->assertSame( $before['identities'], $this->state['identities'] );
		$this->assertSame( array( 'other-library' ), get_option( IconLibrary\Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertArrayNotHasKey( 'fixture-library', $this->registry->get_collections() );
		$this->assertNull( $this->registry->get_collection( 'fixture-library' ) );
		$this->assertFalse( $this->registry->set_collection_enabled( 'fixture-library', true ) );
		$manifest = $this->registry->get_manifest( 'fixture-library' );
		$this->assertSame( 'retained', $manifest['version'] );
		$this->assertSame( array(), $manifest['variants'] );
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertTrue( $icon['archived'] );
			$this->assertFileExists( $this->repository->get_svg_path( 'fixture-library', $icon['path'] ) );
		}
		$this->assertCount( 2, $this->repository->get_installed( 'fixture-library', true )['styles'] );
		$removed = $this->state;
		$this->assertTrue( $this->installer->uninstall( 'fixture-library' ) );
		$this->assertSame( $removed, $this->state );
		// Stale activation preferences cannot expose a retained-only provider.
		update_option( IconLibrary\Plugin::OPTION_ENABLED_COLLECTIONS, array( 'fixture-library', 'other-library' ) );
		$this->assertSame( array( 'other-library' ), $this->registry->get_enabled_collection_slugs() );
	}

	public function test_reinstall_queues_verification_and_only_restores_the_selected_style() {
		$this->assertTrue( $this->installer->uninstall( 'fixture-library' ) );
		$job = $this->installer->request_install( 'fixture-library', 'outline', '1.0.0' );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertNotSame( 'old-job', $job['job_id'] );
		$this->assertNull( $this->installer->get_installed( 'fixture-library' ) );
		$this->assertTrue( $this->state['styles']['filled']['uninstalled'] );
		$this->assertSame( 'icon_library_job_busy', $this->installer->uninstall( 'fixture-library' )->get_error_code() );
		// A persisted verified outline commit must not expose retained filled assets.
		$this->state['styles']['outline']['uninstalled'] = false;
		$this->registry->clear_request_caches();
		$this->assertSame( array( 'outline' ), array_keys( $this->installer->get_installed( 'fixture-library' )['styles'] ) );
		$this->assertSame( array( 'outline' ), array_column( $this->registry->get_manifest( 'fixture-library' )['variants'], 'slug' ) );
		$this->assertTrue( $this->registry->get_manifest( 'fixture-library' )['icons'][1]['archived'] );
		$this->assertNotContains( 'fixture-library', $this->registry->get_enabled_collection_slugs() );
	}

	public function test_pending_jobs_and_missing_libraries_fail_without_mutation() {
		foreach ( array( 'queued', 'running' ) as $status ) {
			$this->state['job']['status'] = $status;
			$before = $this->state;
			$this->assertSame( 'icon_library_job_busy', $this->installer->uninstall( 'fixture-library' )->get_error_code() );
			$this->assertSame( $before, $this->state );
		}
		$this->assertSame( 'icon_library_not_installed', $this->installer->uninstall( 'missing' )->get_error_code() );
		$this->assertSame( 'icon_library_invalid_library', $this->installer->uninstall( '../fixture-library' )->get_error_code() );
	}

	public function test_cas_retry_preserves_styles_and_failed_job_identity() {
		$this->state['job']['status'] = 'failed';
		$before = $this->state;
		$this->cas_failures = 1;
		$this->assertTrue( $this->installer->uninstall( 'fixture-library' ) );
		$this->assertSame( $before['generation'] + 1, $this->state['generation'] );
		$this->assertNull( $this->state['job'] );
		$this->assertSame( $before['identities'], $this->state['identities'] );
		$this->assertSame( $before['styles']['outline']['manifest'], $this->state['styles']['outline']['manifest'] );
	}

	public function test_retained_version_on_an_external_provider_remains_visible() {
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'][] = static function ( $providers ) {
			$providers['external-retained'] = array( 'owner' => 'another-plugin', 'manifest' => array( 'slug' => 'external-retained', 'name' => 'External', 'version' => 'retained', 'variants' => array(), 'icons' => array() ) );
			return $providers;
		};
		$this->assertArrayHasKey( 'external-retained', $this->registry->get_collections() );
		$this->assertIsArray( $this->registry->get_collection( 'external-retained' ) );
	}

	public function test_stale_enabled_state_is_filtered_without_loading_retained_geometry() {
		$GLOBALS['icon_library_test_filters']['icon_library_collection_providers'] = array( static function () {
			return array( 'fixture-library' => array( 'owner' => 'aculect-icon-library-installed', 'retained' => true, 'manifest' => static function () { throw new RuntimeException( 'Unexpected geometry load' ); } ) );
		} );
		$this->assertSame( array(), $this->registry->get_enabled_collection_slugs() );
	}
}
