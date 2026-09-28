<?php
/**
 * Protects Material Icons source, identifiers and exclusion accounting.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

class MaterialIconsManifestTest extends TestCase {
	public function test_filled_scope_and_exclusions_are_pinned() {
		$root = dirname( __DIR__ ) . '/assets/icons/material-icons/';
		$manifest = json_decode( file_get_contents( $root . 'manifest.json' ), true );
		$report = json_decode( file_get_contents( $root . 'exclusions.json' ), true );
		$this->assertSame( '27e9ef1dbeedc13d682fece4a58e1eda4cb0961a', $manifest['source']['revision'] );
		$this->assertSame( $manifest['source']['revision'], $report['sourceRevision'] );
		$this->assertSame( 'Apache-2.0', $manifest['license']['name'] );
		$this->assertSame( array( 'filled' ), wp_list_pluck( $manifest['variants'], 'slug' ) );
		$this->assertCount( 1038, $manifest['icons'] );
		$this->assertCount( 1132, $report['exclusions'] );
		$this->assertSame( 2170, $report['includedIconCount'] + $report['excludedIconCount'] );
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertStringStartsWith( 'material-icons/filled/', $icon['id'] );
			$this->assertSame( hash_file( 'sha256', $root . $icon['path'] ), $icon['sha256'] );
			$this->assertNotEmpty( $icon['categories'] );
			$this->assertNotEmpty( $icon['keywords'] );
			$this->assertStringContainsString( 'Aculect modification:', file_get_contents( $root . $icon['path'] ) );
		}
		foreach ( $report['exclusions'] as $entry ) {
			$this->assertStringEndsWith( '/materialicons/24px.svg', $entry['source'] );
			$this->assertNotEmpty( $entry['reason'] );
		}
	}
}
