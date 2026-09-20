<?php
/**
 * Verifies the bundled Radix Icons scope and provenance.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

/**
 * Protects the approved non-logo Radix Icons collection boundary.
 */
class RadixIconsManifestTest extends TestCase {
	/** Tests the exact collection scope and source revision. */
	public function test_manifest_preserves_the_approved_scope() {
		$manifest = $this->read_json( 'manifest.json' );

		$this->assertSame( '1.3.2', $manifest['version'] );
		$this->assertSame( 'bde33b13aa5848555f5512ac12155930fb4beb7d', $manifest['source']['revision'] );
		$this->assertSame( array( 'default' ), wp_list_pluck( $manifest['variants'], 'slug' ) );
		$this->assertSame( 299, $manifest['variants'][0]['iconCount'] );
		$this->assertCount( 299, $manifest['icons'] );
		$this->assertSame(
			array( 'typography', 'music', 'abstract', 'arrows', 'objects', 'design', 'components', 'borders-and-corners', 'alignment', 'general' ),
			wp_list_pluck( $manifest['categories'], 'slug' )
		);

		foreach ( $manifest['icons'] as $icon ) {
			$this->assertStringStartsWith( 'radix/default/', $icon['id'] );
			$this->assertSame( 'default', $icon['variant'] );
			$this->assertNotEmpty( $icon['categories'] );
			$this->assertNotEmpty( $icon['keywords'] );
			$this->assertStringNotContainsString( '-logo', $icon['id'] );
		}
	}

	/** Tests the deterministic compatibility and trademark exclusion report. */
	public function test_exclusion_report_accounts_for_every_excluded_icon() {
		$report = $this->read_json( 'exclusions.json' );

		$this->assertSame( '1.3.2', $report['version'] );
		$this->assertSame( 'bde33b13aa5848555f5512ac12155930fb4beb7d', $report['sourceRevision'] );
		$this->assertSame( 'f3c1e3c9c219b0dad91cb3137f9bd1e68aafec03', $report['sourceTagObject'] );
		$this->assertSame( 299, $report['includedIconCount'] );
		$this->assertSame( 19, $report['excludedIconCount'] );
		$this->assertCount( 19, $report['exclusions'] );
		$this->assertCount( 14, array_filter( $report['exclusions'], static fn( $item ) => 'trademark' === $item['type'] ) );
		$this->assertCount( 5, array_filter( $report['exclusions'], static fn( $item ) => 'compatibility' === $item['type'] ) );
		foreach ( $report['exclusions'] as $exclusion ) {
			$this->assertNotEmpty( $exclusion['slug'] );
			$this->assertNotEmpty( $exclusion['reason'] );
		}
	}

	/** Tests that converted SVGs retain the strict Core-compatible shape set. */
	public function test_converted_svgs_use_only_supported_geometry() {
		$paths = glob( dirname( __DIR__ ) . '/assets/icons/radix/default/*.svg' );
		$this->assertCount( 299, $paths );
		foreach ( $paths as $path ) {
			$svg = file_get_contents( $path );
			$this->assertStringNotContainsString( '<rect', $svg );
			$this->assertStringNotContainsString( '<circle', $svg );
			$this->assertStringNotContainsString( 'opacity=', $svg );
		}
	}

	/**
	 * Reads one generated Radix collection JSON file.
	 *
	 * @param string $filename Collection filename.
	 * @return array
	 */
	private function read_json( $filename ) {
		$contents = file_get_contents( dirname( __DIR__ ) . '/assets/icons/radix/' . $filename );
		$data     = json_decode( $contents, true );
		$this->assertIsArray( $data );
		return $data;
	}
}
