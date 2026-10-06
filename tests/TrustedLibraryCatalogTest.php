<?php
/** @package IconLibrary */

use IconLibrary\TrustedLibraryCatalog;
use PHPUnit\Framework\TestCase;

class TrustedLibraryCatalogTest extends TestCase {
	public function test_shipped_catalog_contains_only_valid_unique_local_descriptors() {
		$path    = ICON_LIBRARY_DIR . 'data/library-catalog.json';
		$raw     = file_get_contents( $path );
		$catalog = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$this->assertIsArray( $catalog );
		$this->assertSame( 1, $catalog['schema_version'] ?? null );
		$this->assertIsArray( $catalog['libraries'] ?? null );
		$this->assertCount( 37, $catalog['libraries'], 'Thirty-five prepared packs and two preserved legacy pins are reviewed.' );
		$expected_descriptors = array(
			array(
				'library_id'      => 'lucide',
				'style_id'        => 'outline',
				'release_version' => '1.0.0',
				'package_sha256'  => 'f9fff966a7695b52939ab93bcfc6121db1fa86178125eb1a94396a15d5a1b284',
				'manifest_sha256' => '99db2ceb4547085a6c5dc7199620c669645c3f8005322e96dd2ad33ec49fae63',
				'package_bytes'   => 3039664,
				'preview_sha256'  => '49d242611b1949c044bfd7ba0fcb99e834442d3144747f3b36e17fc02fd6da90',
				'preview_bytes'   => 57635,
			),
			array(
				'library_id'      => 'hugeicons',
				'style_id'        => 'stroke-rounded',
				'release_version' => '1.0.0',
				'package_sha256'  => 'a3f97b3f35c0775b06c4dbdccd1012b13b46a750405e0fdbd2cc21070bf44904',
				'manifest_sha256' => '300555c84a938a773cee554612ec46103de6cc82d92b0d6a9ea5bff1acdee474',
				'package_bytes'   => 12635782,
				'preview_sha256'  => '13c9b5b226df7a8feab24944717c6f5c2031153e2f7d849b8307a0114e924cba',
				'preview_bytes'   => 82440,
			),
		);
		$this->assertSame( $expected_descriptors, array_slice( $catalog['libraries'], -2 ) );
		$this->assertCount( 15, ( new TrustedLibraryCatalog() )->get_planned_libraries() );
		$families = ( new TrustedLibraryCatalog() )->get_planned_libraries();
		$this->assertCount( 13, array_filter( $families, function ( $family ) { return 'available' === $family['status']; } ) );
		$this->assertCount( 2, array_filter( $families, function ( $family ) { return 'gated' === $family['status']; } ) );
		$this->assertNotContains( 'simple-icons', array_column( $catalog['libraries'], 'library_id' ) );
		$this->assertNotContains( 'keyline', array_column( $catalog['libraries'], 'library_id' ) );

		$seen = array();
		foreach ( $catalog['libraries'] as $descriptor ) {
			$this->assertIsArray( $descriptor );
			$key = ( $descriptor['library_id'] ?? '' ) . '/' . ( $descriptor['style_id'] ?? '' ) . '/' . ( $descriptor['release_version'] ?? '' );
			$this->assertArrayNotHasKey( $key, $seen, 'Catalog release descriptors must be unique.' );
			$seen[ $key ] = true;
			if ( isset( $descriptor['preview_revision'] ) ) {
				$this->assertSame( 'available', $descriptor['availability'], 'Only independently verified public releases are marked available.' );
				$this->assertMatchesRegularExpression( '/^[a-f0-9]{40}$/', $descriptor['preview_revision'] );
				$this->assertLessThanOrEqual( IconLibrary\LibraryDiscoveryCatalog::MAX_PREVIEW, $descriptor['preview_bytes'] );
			}
		}

		$default_entries = ( new TrustedLibraryCatalog() )->get_entries();
		$this->assertCount( count( $catalog['libraries'] ), $default_entries );
		$expected = new TrustedLibraryCatalog( $catalog['libraries'] );
		$this->assertSame( $expected->get_entries(), $default_entries );
		foreach ( $catalog['libraries'] as $descriptor ) {
			$entry = $expected->find( $descriptor['library_id'], $descriptor['style_id'], $descriptor['release_version'] );
			$this->assertIsArray( $entry, 'Every shipped descriptor must pass catalog validation.' );
			$this->assertSame( TrustedLibraryCatalog::RELEASE_BASE . rawurlencode( $descriptor['library_id'] . '-' . $descriptor['style_id'] . '-' . $descriptor['release_version'] ) . '/' . rawurlencode( $descriptor['library_id'] . '-' . $descriptor['style_id'] . '-' . $descriptor['release_version'] . '.zip' ), $entry['url'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $entry['package_sha256'] );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $entry['manifest_sha256'] );
			$this->assertGreaterThan( 0, $entry['package_bytes'] );
			$this->assertLessThanOrEqual( IconLibrary\LibraryPackageValidator::MAX_ARCHIVE, $entry['package_bytes'] );
		}
	}

	public function test_validates_and_derives_release_url_for_pinned_entry() {
		$descriptor = array(
			'library_id'      => 'fixture-library',
			'style_id'        => 'outline',
			'release_version' => '1.2.3-rc.1+build.7',
			'package_sha256'  => str_repeat( 'a', 64 ),
			'manifest_sha256' => str_repeat( 'b', 64 ),
			'package_bytes'   => 1234,
		);
		$catalog    = new TrustedLibraryCatalog( array( $descriptor ) );
		$entry      = $catalog->find( 'fixture-library', 'outline', '1.2.3-rc.1+build.7' );
		$this->assertIsArray( $entry );
		$this->assertSame( TrustedLibraryCatalog::RELEASE_BASE . 'fixture-library-outline-1.2.3-rc.1%2Bbuild.7/fixture-library-outline-1.2.3-rc.1%2Bbuild.7.zip', $entry['url'] );
		$this->assertCount( 1, $catalog->get_entries() );
	}

	public function test_rejects_descriptor_with_noncanonical_url_and_collision_namespace() {
		$descriptor = array(
			'library_id'      => 'fixture-library',
			'style_id'        => 'outline',
			'release_version' => '1.0.0',
			'package_sha256'  => str_repeat( 'a', 64 ),
			'manifest_sha256' => str_repeat( 'b', 64 ),
			'package_bytes'   => 1234,
			'url'             => 'https://attacker.invalid/package.zip',
		);
		$this->assertSame( array(), ( new TrustedLibraryCatalog( array( $descriptor ) ) )->get_entries() );
		$descriptor['url']            = null;
		$descriptor['package_sha256'] = 'not-a-digest';
		$this->assertSame( array(), ( new TrustedLibraryCatalog( array( $descriptor ) ) )->get_entries() );
	}

	public function test_empty_catalog_override_stays_empty() {
		$this->assertSame( array(), ( new TrustedLibraryCatalog( array() ) )->get_entries() );
	}
}
