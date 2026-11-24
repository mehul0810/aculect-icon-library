<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CoreIconRegistrar;
use IconLibrary\CustomIconListing;
use IconLibrary\CustomIconRepository;
use IconLibrary\ManifestLoader;
use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

class CustomIconArtworkIntegrityTest extends TestCase {
	private $repo;
	private $created = array();

	protected function setUp(): void {
		$GLOBALS['icon_library_test_options'] = array();
		$GLOBALS['icon_library_test_autoload'] = array();
		$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
		$this->repo = new CustomIconRepository( new SvgSanitizer() );
	}

	private function fixture() {
		$icon = $this->repo->create( 'sample', 'Sample', '<svg><path d="M0 0h1v1z"/></svg>' );
		$this->assertIsArray( $icon );
		$path = $this->repo->get_file_path( $icon['path'] );
		$this->assertIsString( $path );
		$this->created[] = $path;
		return array( $icon, $path );
	}

	protected function tearDown(): void {
		foreach ( $this->created as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}

	public function test_verified_preview_and_core_content_reject_changed_missing_or_oversized_artwork() {
		list( $icon, $path ) = $this->fixture();
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ), $this->repo );
		$this->assertNull( $registry->get_svg_path( 'custom-icons', $icon['path'] ) );
		$original = $this->repo->get_svg_content( $icon['path'] );
		$this->assertIsString( $original );
		$this->assertSame( $original, $registry->get_svg_content( 'custom-icons', $icon['path'] ) );
		$this->assertSame( $original, ( new CustomIconListing( $this->repo ) )->get_page( '', 1, 'name', 1, 20 )['items'][0]['svg'] );
		file_put_contents( $path, '<svg><path d="M0 0h2v2z"/></svg>' );
		$this->assertNull( $this->repo->get_svg_content( $icon['path'] ) );
		$this->assertNull( $registry->get_svg_content( 'custom-icons', $icon['path'] ) );
		$this->assertSame( '', ( new CustomIconListing( $this->repo ) )->get_page( '', 1, 'name', 1, 20 )['items'][0]['svg'] );
		file_put_contents( $path, str_repeat( 'x', SvgSanitizer::MAX_FILE_SIZE + 1 ) );
		$this->assertNull( $this->repo->get_svg_content( $icon['path'] ) );
		unlink( $path );
		$this->assertNull( $this->repo->get_svg_content( $icon['path'] ) );
	}

	public function test_legacy_record_is_sanitized_and_missing_or_invalid_digest_is_not_trusted() {
		list( $icon, $path ) = $this->fixture();
		$icons = $this->repo->get_icons();
		unset( $icons['sample']['sha256'] );
		update_option( CustomIconRepository::OPTION_ICONS, $icons );
		file_put_contents( $path, '<svg><path d="M0 0h2v2z"/></svg>' );
		$this->assertIsString( $this->repo->get_svg_content( $icon['path'] ) );
		file_put_contents( $path, '<svg><title>unsupported text</title></svg>' );
		$this->assertNull( $this->repo->get_svg_content( $icon['path'] ) );
		file_put_contents( $path, '<svg><path d="M0 0h2v2z"/></svg>' );
		foreach ( array( null, 'invalid' ) as $malformed_digest ) {
			$icons['sample']['sha256'] = $malformed_digest;
			update_option( CustomIconRepository::OPTION_ICONS, $icons );
			$this->assertNull( $this->repo->get_svg_content( $icon['path'] ) );
		}
	}

	public function test_saved_block_registers_only_verified_content() {
		list( $icon, $path ) = $this->fixture();
		$name = 'custom-icons/sample';
		$block = array( 'blockName' => 'core/icon', 'attrs' => array( 'icon' => $name ) );
		$registry = new CollectionRegistry( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ), $this->repo );
		$GLOBALS['icon_library_test_registered'] = array( 'collections' => array(), 'icons' => array() );
		WP_Icons_Registry::get_instance()->unregister( $name );
		( new CoreIconRegistrar( $registry ) )->register_icon_block( $block );
		$this->assertArrayHasKey( $name, $GLOBALS['icon_library_test_registered']['icons'] );
		$this->assertArrayHasKey( 'content', $GLOBALS['icon_library_test_registered']['icons'][ $name ] );
		$this->assertArrayNotHasKey( 'file_path', $GLOBALS['icon_library_test_registered']['icons'][ $name ] );
		WP_Icons_Registry::get_instance()->unregister( $name );
		$GLOBALS['icon_library_test_registered']['icons'] = array();
		file_put_contents( $path, '<svg><path d="M0 0h2v2z"/></svg>' );
		( new CoreIconRegistrar( $registry ) )->register_icon_block( $block );
		$this->assertArrayNotHasKey( $name, $GLOBALS['icon_library_test_registered']['icons'] );
	}

	public function test_real_repository_reads_one_metadata_snapshot_per_page() {
		list( $icon ) = $this->fixture();
		$icons = array();
		for ( $i = 0; $i < 500; ++$i ) {
			$icons[ sprintf( 'item-%03d', $i ) ] = $icon;
		}
		update_option( CustomIconRepository::OPTION_ICONS, $icons );
		$repo = new class( new SvgSanitizer() ) extends CustomIconRepository {
			public $reads = 0;
			public function get_icons() {
				++$this->reads;
				return parent::get_icons();
			}
		};
		foreach ( array( 20, 100 ) as $per_page ) {
			$repo->reads = 0;
			$page = ( new CustomIconListing( $repo ) )->get_page( '', 1, 'name', 1, $per_page );
			$this->assertSame( 1, $repo->reads );
			$this->assertCount( $per_page, $page['items'] );
			$this->assertNotSame( '', $page['items'][0]['svg'] );
		}
	}

	public function test_symlink_is_not_used_for_preview() {
		list( $icon, $path ) = $this->fixture();
		$this->assertIsString( $this->repo->get_svg_content( $icon['path'] ) );
		$target = $path . '.target';
		$this->created[] = $target;
		rename( $path, $target );
		if ( ! symlink( $target, $path ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable.' );
		}
		$this->assertNull( $this->repo->get_svg_content( $icon['path'] ) );
	}
}
