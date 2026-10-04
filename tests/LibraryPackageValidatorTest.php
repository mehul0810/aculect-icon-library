<?php
/** @package IconLibrary */

use IconLibrary\LibraryPackageValidator;
use IconLibrary\SvgSanitizer;
use IconLibrary\TrustedLibraryCatalog;
use PHPUnit\Framework\TestCase;

class LibraryPackageValidatorTest extends TestCase {
	private $root;
	private $manifest;
	private $svg;
	private $license;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/icon-library-validator-' . wp_generate_password( 12, false, false );
		mkdir( $this->root, 0700, true );
		$this->svg      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z" fill-rule="evenodd"/></svg>';
		$this->license  = "SPDX-License-Identifier: GPL-2.0-or-later\nSynthetic test package only.\n";
		$this->manifest = array(
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
				'sha256' => hash( 'sha256', $this->license ),
			),
			'icons'           => array(
				array(
					'id'             => 'test-square',
					'core_icon_name' => 'test-square',
					'label'          => 'Test square',
					'keywords'       => array( 'test', 'square' ),
					'path'           => 'icons/test-square.svg',
					'sha256'         => hash( 'sha256', $this->svg ),
				),
			),
		);
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->root );
	}

	public function test_validates_fixture_and_streams_each_declared_member_to_private_staging() {
		$package = $this->build_package();
		$extract = $this->root . '/extract';
		mkdir( $extract, 0700 );
		$result = ( new LibraryPackageValidator( new SvgSanitizer(), true ) )->validate( $package['path'], $package['descriptor'], $extract );
		$this->assertIsArray( $result );
		$this->assertSame( 'synthetic-test/test-square', $result['manifest']['icons'][0]['coreIconName'] );
		$members = $result['members'];
		sort( $members );
		$this->assertSame( array( 'icons/test-square.svg', 'licenses/LICENSE.txt', 'manifest.json' ), $members );
		$this->assertFileExists( $extract . '/icons/test-square.svg' );
		$this->assertSame( $this->svg, file_get_contents( $extract . '/icons/test-square.svg' ) );
	}

	public function test_production_validator_rejects_synthetic_fixture_even_with_matching_digest() {
		$package = $this->build_package();
		$result  = ( new LibraryPackageValidator( new SvgSanitizer() ) )->validate( $package['path'], $package['descriptor'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_package_provenance', $result->get_error_code() );
	}

	public function test_rejects_traversal_and_casefold_collisions_before_extraction() {
		$package = $this->build_package( array( 'icons/../escape.svg' => $this->svg ) );
		$result  = ( new LibraryPackageValidator( new SvgSanitizer(), true ) )->validate( $package['path'], $package['descriptor'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_package_member', $result->get_error_code() );
	}

	public function test_rejects_malformed_path_grammar_and_external_svg_references() {
		$this->svg                            = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M4"/></svg>';
		$this->manifest['icons'][0]['sha256'] = hash( 'sha256', $this->svg );
		$bad_path                             = $this->build_package();
		$this->assertInstanceOf( WP_Error::class, ( new LibraryPackageValidator( new SvgSanitizer(), true ) )->validate( $bad_path['path'], $bad_path['descriptor'] ) );
		$this->svg                            = '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h1v1" fill="url(https://example.invalid/a.svg)"/></svg>';
		$this->manifest['icons'][0]['sha256'] = hash( 'sha256', $this->svg );
		$bad_reference                        = $this->build_package();
		$this->assertInstanceOf( WP_Error::class, ( new LibraryPackageValidator( new SvgSanitizer(), true ) )->validate( $bad_reference['path'], $bad_reference['descriptor'] ) );
	}

	public function test_rejects_package_digest_mismatch_and_empty_production_catalog() {
		$package = $this->build_package();
		file_put_contents( $package['path'], 'corrupt' );
		$result = ( new LibraryPackageValidator( new SvgSanitizer(), true ) )->validate( $package['path'], $package['descriptor'] );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), ( new TrustedLibraryCatalog() )->get_entries() );
	}

	public function test_semver_order_ignores_build_metadata_and_orders_prereleases() {
		$catalog = new TrustedLibraryCatalog( array() );
		$this->assertTrue( $catalog->is_valid_version( '1.2.3-rc.10+build.7' ) );
		$this->assertSame( 0, $catalog->compare_versions( '1.2.3+one', '1.2.3+two' ) );
		$this->assertSame( -1, $catalog->compare_versions( '1.2.3-rc.2', '1.2.3-rc.10' ) );
		$this->assertSame( -1, $catalog->compare_versions( '1.2.3-rc.10', '1.2.3' ) );
		$this->assertFalse( $catalog->is_valid_version( '1.1234567890.0' ) );
		$this->assertFalse( $catalog->is_valid_version( '1.2.3-rc..1' ) );
	}

	private function build_package( $extra_members = array() ) {
		$manifest = wp_json_encode( $this->manifest, JSON_UNESCAPED_SLASHES );
		$path     = $this->root . '/package-' . wp_generate_password( 6, false, false ) . '.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$members = array(
			'manifest.json'         => $manifest,
			'licenses/LICENSE.txt'  => $this->license,
			'icons/test-square.svg' => $this->svg,
		) + $extra_members;
		foreach ( $members as $name => $contents ) {
			$this->assertTrue( $zip->addFromString( $name, $contents ) ); }
		$this->assertTrue( $zip->close() );
		return array(
			'path'       => $path,
			'descriptor' => array(
				'library_id'      => 'synthetic-test',
				'style_id'        => 'outline',
				'release_version' => '1.0.0',
				'package_sha256'  => hash_file( 'sha256', $path ),
				'manifest_sha256' => hash( 'sha256', $manifest ),
				'package_bytes'   => filesize( $path ),
			),
		);
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
