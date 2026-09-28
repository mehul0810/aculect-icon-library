<?php
/**
 * Protects Iconoir's explicitly bounded compatible subset.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

/** Verifies exact source, scope, notices, and strict geometry. */
class IconoirManifestTest extends TestCase {

	/** Verifies the pinned subset and complete exclusion accounting. */
	public function test_scope_and_provenance() {
		$root     = dirname( __DIR__ ) . '/assets/icons/iconoir/';
		$manifest = json_decode( file_get_contents( $root . 'manifest.json' ), true );
		$report   = json_decode( file_get_contents( $root . 'exclusions.json' ), true );
		$this->assertSame( '7.12.1', $manifest['version'] );
		$this->assertSame( 'd7dfa4d0341df0670bfed9fc24221c9d7ef2112e', $manifest['source']['revision'] );
		$this->assertCount( 210, $manifest['icons'] );
		$this->assertSame( array( 'solid' ), wp_list_pluck( $manifest['variants'], 'slug' ) );
		$this->assertFalse( $manifest['variants'][0]['defaultEnabled'] );
		$this->assertCount( 1461, $report['exclusions'] );
		$this->assertCount( 1383, array_filter( $report['exclusions'], static fn( $icon ) => 'regular' === $icon['variant'] ) );
		$this->assertCount( 78, array_filter( $report['exclusions'], static fn( $icon ) => 'solid' === $icon['variant'] ) );
		$this->assertStringContainsString( 'Copyright (c) 2021 Luca Burgio', file_get_contents( $root . 'LICENSE' ) );
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertStringStartsWith( 'iconoir/solid/', $icon['id'] );
			$this->assertNotEmpty( $icon['keywords'] );
			$svg = file_get_contents( $root . $icon['path'] );
			$this->assertStringNotContainsString( 'stroke', $svg );
			$this->assertStringNotContainsString( '<g', $svg );
			$this->assertStringNotContainsString( '<rect', $svg );
			$this->assertStringNotContainsString( '<circle', $svg );
			$this->assertSame( $icon['sha256'], hash( 'sha256', $svg ) );
		}
	}
}
