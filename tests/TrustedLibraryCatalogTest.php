<?php
/** @package IconLibrary */

use IconLibrary\TrustedLibraryCatalog;
use PHPUnit\Framework\TestCase;

class TrustedLibraryCatalogTest extends TestCase {
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
	}
}
