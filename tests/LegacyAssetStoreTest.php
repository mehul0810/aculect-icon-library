<?php
/**
 * Legacy asset preservation unit tests.
 *
 * @package IconLibrary
 */

use IconLibrary\LegacyAssetStore;
use PHPUnit\Framework\TestCase;

/** Tests persistent legacy asset preservation and its readiness boundary. */
class LegacyAssetStoreTest extends TestCase {
	/**
	 * Isolated temporary test root.
	 *
	 * @var string
	 */
	private $root;
	/**
	 * Synthetic bundled source directory.
	 *
	 * @var string
	 */
	private $source;
	/**
	 * Persistent-store destination.
	 *
	 * @var string
	 */
	private $storage;

	/** Creates a synthetic collection fixture. */
	protected function setUp(): void {
		$this->root    = sys_get_temp_dir() . '/icon-library-legacy-' . wp_generate_password( 12, false, false );
		$this->source  = $this->root . '/source';
		$this->storage = $this->root . '/uploads/icon-library/legacy-assets';
		mkdir( $this->source . '/heroicons/24/outline', 0700, true );
		mkdir( $this->source . '/heroicons/16', 0700, true );
		mkdir( $this->source . '/heroicons/20', 0700, true );
		file_put_contents( $this->source . '/heroicons/manifest.json', '{"slug":"heroicons","icons":[]}' );
		file_put_contents( $this->source . '/heroicons/metadata.json', '{"slug":"heroicons"}' );
		file_put_contents( $this->source . '/heroicons/LICENSE.txt', 'license fixture' );
		file_put_contents( $this->source . '/heroicons/16/alias.svg', '<svg>alias16</svg>' );
		file_put_contents( $this->source . '/heroicons/20/alias.svg', '<svg>alias20</svg>' );
		file_put_contents( $this->source . '/heroicons/24/outline/alias.svg', '<svg>alias24</svg>' );
	}

	/** Removes the isolated fixture and store. */
	protected function tearDown(): void {
		$this->remove_tree( $this->root );
	}

	/** Copies the complete collection including versioned SVG aliases. */
	public function test_preserves_exact_collection_tree_with_svg_aliases_and_metadata() {
		$before = $this->snapshot( $this->source . '/heroicons' );
		$store  = new LegacyAssetStore( $this->storage );
		$this->assertTrue( $store->preserve( $this->source, array( 'heroicons' ) ) );
		$this->assertTrue( $store->is_ready() );
		$this->assertTrue( $store->is_ready( true ) );
		$this->assertSame( $this->storage, $store->resolve_root() );
		$this->assertSame( array( 'heroicons' ), $store->status()['collections'] );
		$this->assertSame( $before, $this->snapshot( $this->storage . '/heroicons' ) );
		$this->assertFileExists( $this->storage . '/heroicons/manifest.json' );
		$this->assertFileExists( $this->storage . '/heroicons/metadata.json' );
		$this->assertFileExists( $this->storage . '/heroicons/LICENSE.txt' );
		$this->assertLessThan( 1024, filesize( $this->storage . '/readiness.json' ) );
		$this->assertFileExists( $this->storage . '/inventory.json' );
	}

	/** Empty source selection does not create the store or mark it ready. */
	public function test_empty_fresh_store_is_not_ready_and_copies_nothing() {
		$store = new LegacyAssetStore( $this->storage );
		$this->assertTrue( $store->preserve( $this->source, array() ) );
		$this->assertFalse( $store->is_ready() );
		$this->assertNull( $store->resolve_root() );
		$this->assertDirectoryDoesNotExist( dirname( $this->storage ) );
	}

	/** Tampered assets fail full verification and are not overwritten. */
	public function test_detects_tampering_and_never_repairs_or_overwrites_immutable_store() {
		$store = new LegacyAssetStore( $this->storage );
		$this->assertTrue( $store->preserve( $this->source, array( 'heroicons' ) ) );
		file_put_contents( $this->storage . '/heroicons/24/outline/alias.svg', 'tampered' );
		$this->assertTrue( $store->is_ready() );
		$this->assertFalse( $store->is_ready( true ) );
		$this->assertInstanceOf( WP_Error::class, $store->preserve( $this->source, array( 'heroicons' ) ) );
		$this->assertSame( 'tampered', file_get_contents( $this->storage . '/heroicons/24/outline/alias.svg' ) );
	}

	/** Symlinks and traversal identifiers fail closed. */
	public function test_rejects_symlink_and_invalid_slug_without_publishing_readiness() {
		$store = new LegacyAssetStore( $this->storage );
		if ( ! symlink( $this->source . '/heroicons/LICENSE.txt', $this->source . '/heroicons/linked.txt' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable in this test environment.' );
		}
		$result = $store->preserve( $this->source, array( 'heroicons' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $store->is_ready() );
		$this->assertDirectoryDoesNotExist( $this->storage );
		$this->assertInstanceOf( WP_Error::class, $store->preserve( $this->source, array( '../heroicons' ) ) );
	}

	/** A symlinked writer lock is rejected before it can be followed. */
	public function test_rejects_symlinked_lock_path() {
		$store  = new LegacyAssetStore( $this->storage );
		$parent = dirname( $this->storage );
		mkdir( $parent, 0700, true );
		if ( ! symlink( $this->source . '/heroicons/LICENSE.txt', $parent . '/.legacy-assets.lock' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable in this test environment.' );
		}
		$result = $store->preserve( $this->source, array( 'heroicons' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $store->is_ready() );
		$this->assertSame( 'license fixture', file_get_contents( $this->source . '/heroicons/LICENSE.txt' ) );
	}

	/** Failed copy does not publish readiness or mutate its source. */
	public function test_failed_copy_never_marks_partial_store_ready_and_preserves_source() {
		$store = new LegacyAssetStore( $this->storage );
		file_put_contents( $this->source . '/heroicons/odd name.svg', '<svg/>' );
		$before = $this->snapshot( $this->source );
		$this->assertInstanceOf( WP_Error::class, $store->preserve( $this->source, array( 'heroicons' ) ) );
		$this->assertFalse( $store->is_ready() );
		$this->assertDirectoryDoesNotExist( $this->storage );
		$this->assertSame( $before, $this->snapshot( $this->source ) );
	}

	/** A verified store can be reused without changing its descriptor. */
	public function test_repeat_preservation_is_idempotent() {
		$store = new LegacyAssetStore( $this->storage );
		$this->assertTrue( $store->preserve( $this->source, array( 'heroicons' ) ) );
		$descriptor = file_get_contents( $this->storage . '/readiness.json' );
		$this->assertTrue( $store->preserve( $this->source, array( 'heroicons' ) ) );
		$this->assertSame( $descriptor, file_get_contents( $this->storage . '/readiness.json' ) );
	}

	/**
	 * Returns a sorted digest map for the files below a directory.
	 *
	 * @param string $root Directory to snapshot.
	 * @return array<string,string>
	 */
	private function snapshot( $root ) {
		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$relative           = str_replace( $root . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$files[ $relative ] = hash_file( 'sha256', $file->getPathname() );
			}
		}
		ksort( $files );
		return $files;
	}

	/**
	 * Removes the test-owned temporary tree.
	 *
	 * @param string $path Tree path.
	 */
	private function remove_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return; }
		if ( ! is_dir( $path ) ) {
			return; }
		foreach ( scandir( $path ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$this->remove_tree( $path . '/' . $item ); }
		}
		rmdir( $path );
	}
}
