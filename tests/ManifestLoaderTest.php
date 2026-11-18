<?php
/**
 * Manifest loader cache tests.
 *
 * @package IconLibrary
 */

use IconLibrary\ManifestLoader;
use IconLibrary\IconNameIndex;
use PHPUnit\Framework\TestCase;

/** Tests manifest loading and request-specific filtering over shared raw manifests. */
class ManifestLoaderTest extends TestCase {
	/**
	 * Temporary fixture directory.
	 *
	 * @var string
	 */
	private $directory;

	/** Creates a temporary collection fixture. */
	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/icon-library-manifest-' . getmypid();
		wp_mkdir_p( $this->directory . '/test' );
		file_put_contents( $this->directory . '/test/manifest.json', '{"slug":"test","name":"Raw"}' );
		$GLOBALS['icon_library_test_cache']   = array();
		$GLOBALS['icon_library_test_filters'] = array();
	}

	/** Removes the temporary collection fixture. */
	protected function tearDown(): void {
		unlink( $this->directory . '/test/manifest.json' );
		rmdir( $this->directory . '/test' );
		rmdir( $this->directory );
	}

	/** Persistent cache stores raw data and filters each request independently. */
	public function test_bundled_metadata_does_not_hydrate_icon_records() {
		$loader = $this->getMockBuilder( ManifestLoader::class )->setConstructorArgs( array( ICON_LIBRARY_DIR . 'assets/icons' ) )->onlyMethods( array( 'get_manifest' ) )->getMock();
		$loader->expects( $this->never() )->method( 'get_manifest' );
		$registry    = new IconLibrary\CollectionRegistry( $loader );
		$collections = $registry->get_collections();
		foreach ( array( 'bootstrap-icons', 'font-awesome', 'heroicons', 'tabler-icons' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $collections );
		}
		$this->assertSame( 648, $registry->get_collection( 'heroicons' )['iconCount'] );
		$this->assertSame( 1012, $registry->get_collection( 'tabler-icons' )['iconCount'] );
	}

	/** Runtime manifest filters bypass generated metadata. */
	public function test_manifest_filter_bypasses_generated_metadata() {
		$GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] = array(
			static function ( $manifest ) {
				$manifest['name'] = 'Filtered';
				return $manifest;
			},
		);
		$this->assertSame( 'Filtered', ( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) )->get_metadata( 'heroicons' )['name'] );
	}

	/** Generated picker rows preserve order and are bypassed when filters can change them. */
	public function test_generated_picker_index_is_ordered_compact_and_bypassed_for_filters() {
		$loader = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$rows   = $loader->get_picker_rows( 'heroicons' );
		$this->assertIsArray( $rows );
		$this->assertSame( array( 'heroicons/academic-cap-outline', 'Academic Cap', 'outline', 'outline/academic-cap.svg' ), $rows[0] );
		$this->assertLessThan( filesize( ICON_LIBRARY_DIR . 'assets/icons/heroicons/manifest.json' ), filesize( ICON_LIBRARY_DIR . 'assets/icons/heroicons/picker-index.json' ) );

		$GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] = array(
			static function ( $manifest ) {
				$manifest['icons'] = array();
				return $manifest;
			},
		);
		try {
			$this->assertNull( ( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) )->get_picker_rows( 'heroicons' ) );
		} finally {
			unset( $GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] );
		}
	}

	/** The generated path resolves every packaged name exactly as the runtime manifest index. */
	public function test_generated_saved_index_preserves_all_bundled_names() {
		$loader = $this->getMockBuilder( ManifestLoader::class )->setConstructorArgs( array( ICON_LIBRARY_DIR . 'assets/icons' ) )->onlyMethods( array( 'get_manifest' ) )->getMock();
		$loader->expects( $this->never() )->method( 'get_manifest' );
		foreach ( $loader->get_collection_slugs() as $slug ) {
			$manifest = json_decode( file_get_contents( ICON_LIBRARY_DIR . 'assets/icons/' . $slug . '/manifest.json' ), true );
			$expected = IconNameIndex::build( $slug, $manifest );
			$packed   = json_decode( file_get_contents( ICON_LIBRARY_DIR . 'assets/icons/' . $slug . '/saved-index.json' ), true );
			$this->assertSame( array_keys( $expected ), array_keys( $packed['names'] ) );
			foreach ( $expected as $name => $entry ) {
				$icon       = $entry['icon'];
				$resolved   = $loader->get_saved_icon( $slug, $name );
				$projection = array(
					'library' => $slug,
					'icon'    => array(
						'coreIconName' => $icon['coreIconName'],
						'label'        => $icon['label'],
						'variant'      => $icon['variant'] ?? '',
						'path'         => $icon['path'],
						'archived'     => ! empty( $icon['archived'] ),
					),
				);
				unset( $resolved['manifest'] );
				$this->assertSame( $projection + array_intersect_key( $entry, array_flip( array( 'style', 'variant', 'legacy' ) ) ), $resolved, $name );
			}
		}
		$this->assertNull( $loader->get_saved_icon( 'heroicons', 'heroicons/not-an-icon' ) );
	}

	/** A plausible row for a different name must not replace saved artwork. */
	public function test_saved_index_rejects_invalid_references() {
		$path = $this->directory . '/test/saved-index.json';
		file_put_contents( $path, '{"version":1,"names":{"test/wrong-name":0,"test/one":1,"test/missing-row":99},"icons":[["test/one","One","solid","one.svg",false]]}' );
		try {
			$loader = new ManifestLoader( $this->directory );
			foreach ( array( 'test/wrong-name', 'test/one', 'test/missing-row' ) as $name ) {
				$this->assertNull( $loader->get_saved_icon( 'test', $name ) );
			}
		} finally {
			unlink( $path );
		}
	}

	/** Missing indexes and runtime hooks preserve manifest-based lookup. */
	public function test_saved_index_defers_to_runtime_manifest_when_needed() {
		$this->assertNull( ( new ManifestLoader( $this->directory ) )->get_saved_icon( 'test', 'test/anything' ) );
		$loader = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$this->assertNotEmpty( $loader->get_saved_icon( 'heroicons', 'heroicons/academic-cap-24-solid' ) );
		foreach ( array( 'icon_library_icon_manifest', 'sanitize_key' ) as $filter ) {
			$GLOBALS['icon_library_test_filters'][ $filter ] = array(
				static function ( $value ) {
								return $value;
				},
			);
			try {
				$this->assertNull( $loader->get_saved_icon( 'heroicons', 'heroicons/academic-cap-24-solid' ) );
			} finally {
				unset( $GLOBALS['icon_library_test_filters'][ $filter ] );
			}
		}
	}

	/** Persistent cache stores raw data and filters each request independently. */
	public function test_filter_result_is_not_shared_through_persistent_cache() {
		$GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] = array(
			static function ( $manifest ) {
				$manifest['name'] = 'First request';
				return $manifest;
			},
		);
		$this->assertSame( 'First request', ( new ManifestLoader( $this->directory ) )->get_manifest( 'test' )['name'] );

		$GLOBALS['icon_library_test_filters']['icon_library_icon_manifest'] = array(
			static function ( $manifest ) {
				$manifest['name'] = 'Second request';
				return $manifest;
			},
		);
		$this->assertSame( 'Second request', ( new ManifestLoader( $this->directory ) )->get_manifest( 'test' )['name'] );
	}
}
