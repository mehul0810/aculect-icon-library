<?php
/**
 * Protects the pinned Rune pixelated artwork and identifiers.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

class RuneIconsManifestTest extends TestCase {
	public function test_pixelated_scope_and_geometry_are_preserved() {
		$root     = dirname( __DIR__ ) . '/assets/icons/rune/';
		$manifest = json_decode( file_get_contents( $root . 'manifest.json' ), true );
		$this->assertSame( 'f649e467d1bc9f272aae3f8daa329d4c924e7340', $manifest['source']['revision'] );
		$this->assertSame( 'Apache-2.0', $manifest['license']['name'] );
		$this->assertSame( array( 'pixelated' ), wp_list_pluck( $manifest['variants'], 'slug' ) );
		$this->assertCount( 215, $manifest['icons'] );
		foreach ( $manifest['icons'] as $icon ) {
			$this->assertStringStartsWith( 'rune/pixelated/', $icon['id'] );
			$this->assertSame( hash_file( 'sha256', $root . $icon['path'] ), $icon['sha256'] );
			$document = new DOMDocument();
			$document->loadXML( file_get_contents( $root . $icon['path'] ) );
			$this->assertFalse( $document->documentElement->hasAttribute( 'fill' ) );
			foreach ( $document->getElementsByTagName( 'path' ) as $path ) {
				$this->assertTrue( $path->hasAttribute( 'fill' ) );
				$this->assertFalse( $path->hasAttribute( 'stroke' ) );
			}
		}
		$svg = file_get_contents( $root . 'pixelated/arrows-arrow-down.svg' );
		$this->assertStringContainsString( 'viewBox="0 0 40 41"', $svg );
		$this->assertStringContainsString( 'fill="black"', $svg );
		$this->assertStringContainsString( 'Aculect modification:', $svg );
	}
}
