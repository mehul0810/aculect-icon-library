<?php
/**
 * Verifies the bundled Tabler Icons scope and provenance.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

/**
 * Protects the approved Tabler Filled collection boundary.
 */
class TablerIconsManifestTest extends TestCase {
	/** Tests the exact collection scope and source revision. */
	public function test_manifest_preserves_the_approved_scope() {
		$manifest = $this->read_json( 'manifest.json' );

		$this->assertSame( '3.47.0', $manifest['version'] );
		$this->assertSame( '87e7c390fb4ec332ccad0bde25160b233241eb8f', $manifest['source']['revision'] );
		$this->assertSame( array( 'filled' ), wp_list_pluck( $manifest['variants'], 'slug' ) );
		$this->assertSame( 1012, $manifest['variants'][0]['iconCount'] );
		$this->assertCount( 1012, $manifest['icons'] );
		$this->assertNotEmpty( $manifest['categories'] );

		foreach ( $manifest['icons'] as $icon ) {
			$this->assertFalse( 0 === strpos( $icon['id'], 'tabler-icons/filled/brand-' ) );
			$this->assertSame( 'filled', $icon['variant'] );
			$this->assertNotEmpty( $icon['categories'] );
			$this->assertNotEmpty( $icon['keywords'] );
		}
	}

	/** Tests the deterministic brand and sensitive-name exclusion report. */
	public function test_exclusion_report_accounts_for_every_excluded_icon() {
		$report = $this->read_json( 'exclusions.json' );

		$this->assertSame( '3.47.0', $report['version'] );
		$this->assertSame( '87e7c390fb4ec332ccad0bde25160b233241eb8f', $report['sourceRevision'] );
		$this->assertSame( 'c940317930743839f3ba8dd02ebdbba930fb8be5', $report['sourceTagObject'] );
		$this->assertSame( 1012, $report['includedIconCount'] );
		$this->assertSame( 42, $report['excludedIconCount'] );
		$this->assertCount( 42, $report['exclusions'] );
		$brand_exclusions = array_filter( $report['exclusions'], static fn( $item ) => 'brand-category' === $item['type'] );
		$this->assertCount( 35, $brand_exclusions );
		foreach ( $brand_exclusions as $exclusion ) {
			$this->assertStringStartsWith( 'brand-', $exclusion['slug'] );
			$this->assertSame( 'filled', $exclusion['variant'] );
			$this->assertSame( 'Brand', $exclusion['category'] );
			$this->assertNotEmpty( $exclusion['reason'] );
		}

		$sensitive_exclusions = array_values( array_filter( $report['exclusions'], static fn( $item ) => 'trademark-or-character' === $item['type'] ) );
		$this->assertSame(
			array( 'michelin-star', 'mickey', 'pacman', 'xbox-a', 'xbox-b', 'xbox-x', 'xbox-y' ),
			wp_list_pluck( $sensitive_exclusions, 'slug' )
		);
	}

	/**
	 * Reads one generated Tabler collection JSON file.
	 *
	 * @param string $filename Collection filename.
	 * @return array
	 */
	private function read_json( $filename ) {
		$contents = file_get_contents( dirname( __DIR__ ) . '/assets/icons/tabler-icons/' . $filename );
		$data     = json_decode( $contents, true );
		$this->assertIsArray( $data );
		return $data;
	}
}
