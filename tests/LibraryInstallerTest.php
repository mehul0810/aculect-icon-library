<?php
/** @package IconLibrary */

use IconLibrary\InstalledLibraryRepository;
use IconLibrary\LibraryInstaller;
use IconLibrary\LibraryJobStore;
use IconLibrary\LibraryPackageValidator;
use IconLibrary\SvgSanitizer;
use IconLibrary\TrustedLibraryCatalog;
use PHPUnit\Framework\TestCase;

class LibraryInstallerTest extends TestCase {
	private $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/icon-library-installer-' . wp_generate_password( 12, false, false );
		mkdir( $this->root, 0700, true );
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->root );
	}

	public function test_queued_job_keeps_its_exact_release_descriptor_and_activates_only_after_install() {
		$manifest_bytes = wp_json_encode(
			array(
				'schema_version'  => 1,
				'library_id'      => 'synthetic-test',
				'style_id'        => 'outline',
				'release_version' => '1.0.0',
				'test_fixture'    => true,
				'upstream'        => array(
					'name'     => 'Synthetic geometry',
					'revision' => 'synthetic-test:source-only',
				),
				'conversion'      => array(
					'tool'     => 'fixture-test',
					'revision' => 'synthetic-test:conversion-only',
				),
				'license'         => array(
					'path'   => 'licenses/LICENSE.txt',
					'sha256' => hash( 'sha256', "fixture license\n" ),
				),
				'icons'           => array(
					array(
						'id'             => 'test-square',
						'core_icon_name' => 'test-square',
						'label'          => 'Test square',
						'keywords'       => array( 'test' ),
						'path'           => 'icons/test-square.svg',
						'sha256'         => hash( 'sha256', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>' ),
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		);
		$svg            = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>';
		$archive        = $this->root . '/package.zip';
		$zip            = new ZipArchive();
		$this->assertTrue( true === $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'manifest.json', $manifest_bytes );
		$zip->addFromString( 'licenses/LICENSE.txt', "fixture license\n" );
		$zip->addFromString( 'icons/test-square.svg', $svg );
		$this->assertTrue( $zip->close() );
		$descriptor = array(
			'library_id'      => 'synthetic-test',
			'style_id'        => 'outline',
			'release_version' => '1.0.0',
			'package_sha256'  => hash_file( 'sha256', $archive ),
			'manifest_sha256' => hash( 'sha256', $manifest_bytes ),
			'package_bytes'   => filesize( $archive ),
		);
		$catalog    = new TrustedLibraryCatalog( array( $descriptor ) );
		$jobs       = new class() extends LibraryJobStore {
			public $rows                    = array();
			public $fail_succeeded_cas_once = false;
			public function read( $id ) {
				return isset( $this->rows[ $id ] ) ? array(
					'raw'   => serialize( $this->rows[ $id ] ),
					'value' => $this->rows[ $id ],
				) : null; }
			public function compare_and_swap( $id, $expected, $value ) {
				$current = isset( $this->rows[ $id ] ) ? serialize( $this->rows[ $id ] ) : null;
				if ( $current !== $expected ) {
					return false; }
				if ( $this->fail_succeeded_cas_once && 'succeeded' === ( $value['job']['status'] ?? '' ) ) {
					$this->fail_succeeded_cas_once = false;
					return false; }
				$this->rows[ $id ] = $value;
				return true;
			}
			public function all_library_ids() {
				return array_keys( $this->rows ); }
		};
		$repository = new InstalledLibraryRepository( $jobs, $this->root . '/storage' );
		$validator  = new LibraryPackageValidator( new SvgSanitizer(), true );
		$installer  = new LibraryInstaller(
			$catalog,
			$repository,
			$jobs,
			$validator,
			static function () use ( $archive ) {
				return $archive;
			}
		);
		$queued     = $installer->request_install( 'synthetic-test', 'outline', '1.0.0' );
		$this->assertSame( 'queued', $queued['status'] );
		$this->assertNull( $installer->get_installed( 'synthetic-test' ) );

		$changed                   = $descriptor;
		$changed['package_sha256'] = str_repeat( 'f', 64 );
		$property                  = new ReflectionProperty( TrustedLibraryCatalog::class, 'entries' );
		if ( PHP_VERSION_ID < 80100 ) {
			$property->setAccessible( true );
		}
		$property->setValue( $catalog, array( $changed ) );
		$jobs->fail_succeeded_cas_once = true;
		$result                        = $installer->run( 'synthetic-test', $queued['job_id'] );
		$this->assertSame( 'succeeded', $result['status'] );
		$this->assertSame( $descriptor['package_sha256'], $installer->get_installed( 'synthetic-test' )['styles']['outline']['package_sha256'] );
		$manifest = $repository->get_manifest( 'synthetic-test' );
		$this->assertSame( 'synthetic-test/test-square', $manifest['icons'][0]['coreIconName'] );
		$svg_path = $repository->get_svg_path( 'synthetic-test', $manifest['icons'][0]['path'] );
		$this->assertFileExists( $svg_path );
		$this->assertSame( 'outline/' . $descriptor['package_sha256'] . '/icons/test-square.svg', $manifest['icons'][0]['path'] );
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
