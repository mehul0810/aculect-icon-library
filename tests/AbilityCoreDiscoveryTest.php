<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\IconBlockService;
use PHPUnit\Framework\TestCase;

class AbilityCoreDiscoveryTest extends TestCase {
	private $service;
	private $version;

	protected function setUp(): void {
		$this->version = $GLOBALS['icon_library_test_wp_version'];
		$GLOBALS['icon_library_test_wp_version'] = '7.2';
		wp_register_icon_collection( 'core', array( 'label' => 'Core', 'public' => true ) );
		wp_register_icon_collection( 'private-fixture', array( 'label' => 'Private', 'public' => false ) );
		wp_register_icon_collection( 'third-party', array( 'label' => 'Third party', 'public' => true ) );
		wp_register_icon( 'core/envelope', array( 'label' => 'Envelope', 'keywords' => array( 'letter', 'mail' ), 'file_path' => ABSPATH . WPINC . '/images/icon-library/envelope.svg' ) );
		wp_register_icon( 'core/info', array( 'label' => 'Information', 'keywords' => array( 'help' ), 'file_path' => ABSPATH . WPINC . '/images/icon-library/info.svg' ) );
		wp_register_icon( 'core/third-party-fixture', array( 'label' => 'Intruder letter', 'file_path' => ABSPATH . WPINC . '/images/icon-library/info.svg' ) );
		wp_register_icon( 'core/backup', array( 'label' => 'Backup letter', 'file_path' => ABSPATH . WPINC . '/images/icon-library/backup.svg' ) );
		wp_register_icon( 'core/missing', array( 'label' => 'Missing letter', 'file_path' => ABSPATH . WPINC . '/images/icon-library/missing.svg' ) );
		wp_register_icon( 'private-fixture/secret', array( 'label' => 'Secret', 'keywords' => array( 'letter' ) ) );
		wp_register_icon( 'third-party/letter', array( 'label' => 'Letter' ) );
		$registry = $this->getMockBuilder( CollectionRegistry::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'query_icons', 'get_icon_by_core_name' ) )
			->getMock();
		$registry->method( 'get_icon_by_core_name' )->willReturn( null );
		$registry->method( 'query_icons' )->willReturnCallback(
			static function ( $args ) {
				$show = in_array( $args['collection'], array( '', 'example' ), true ) && '' === $args['search'] && '' === $args['category'];
				$row  = array( 'coreIconName' => 'example/arrow', 'label' => 'Arrow', 'collection' => 'example', 'variant' => 'solid', 'path' => '/not/exposed.svg' );
				return array(
					'items' => $show && 1 === (int) $args['page'] ? array( $row ) : array(),
					'total' => $show ? 1 : 0,
					'variant_counts' => $show ? array( 'solid' => 1 ) : array(),
				);
			}
		);
		$this->service = new IconBlockService( $registry );
	}

	protected function tearDown(): void {
		foreach ( array( 'core/envelope', 'core/info', 'core/third-party-fixture', 'core/backup', 'core/missing', 'private-fixture/secret', 'third-party/letter' ) as $name ) {
			wp_unregister_icon( $name );
		}
		foreach ( array( 'core', 'private-fixture', 'third-party' ) as $name ) {
			wp_unregister_icon_collection( $name );
		}
		$GLOBALS['icon_library_test_wp_version'] = $this->version;
	}

	public function test_keyword_search_and_lookup_return_registered_safe_metadata() {
		$result = $this->service->search_icons( array( 'search' => 'letter' ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'core/envelope', $result['items'][0]['name'] );
		$this->assertSame( 'Envelope', $result['items'][0]['label'] );
		$this->assertSame( array( 'letter', 'mail' ), $result['items'][0]['keywords'] );
		$this->assertArrayNotHasKey( 'content', $result['items'][0] );
		$this->assertArrayNotHasKey( 'file_path', $result['items'][0] );
		$this->assertSame( $result['items'][0], $this->service->get_icon( array( 'icon' => 'core/envelope' ) ) );
		$this->assertSame( 'icon_library_ability_icon_not_available', $this->service->get_icon( array( 'icon' => 'third-party/letter' ) )->get_error_code() );
	}

	public function test_global_pages_include_core_after_curated_icons_and_keep_facets() {
		$first = $this->service->search_icons( array( 'per_page' => 2, 'page' => 1 ) );
		$last  = $this->service->search_icons( array( 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( array( 'example/arrow', 'core/envelope' ), array_column( $first['items'], 'name' ) );
		$this->assertSame( array( 'core/info' ), array_column( $last['items'], 'name' ) );
		$this->assertSame( 3, $first['total'] );
		$this->assertSame( 3, $last['total'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertFalse( $last['has_more'] );
		$this->assertSame( array( 'solid' => 1 ), $first['variant_counts'] );
		$this->assertSame( array( 'solid' => 1 ), $last['variant_counts'] );
	}

	public function test_filters_and_public_visibility_are_respected() {
		$this->assertSame( 2, $this->service->search_icons( array( 'collection' => 'core' ) )['total'] );
		$this->assertSame( 2, $this->service->search_icons( array( 'collection' => 'CORE' ) )['total'] );
		$this->assertSame( 0, $this->service->search_icons( array( 'collection' => 'private-fixture' ) )['total'] );
		$this->assertSame( 0, $this->service->search_icons( array( 'collection' => 'core', 'variant' => 'solid' ) )['total'] );
		$this->assertSame( 0, $this->service->search_icons( array( 'collection' => 'core', 'category' => 'navigation' ) )['total'] );
		$GLOBALS['icon_library_test_registered']['collections']['core']['public'] = false;
		$this->assertSame( 0, $this->service->search_icons( array( 'collection' => 'core' ) )['total'] );
		$this->assertSame( 'icon_library_ability_icon_not_available', $this->service->get_icon( array( 'icon' => 'core/envelope' ) )->get_error_code() );
	}

	public function test_core_namespace_and_manifest_membership_do_not_alone_establish_provenance() {
		foreach ( array( 'core/third-party-fixture', 'core/backup', 'core/missing' ) as $name ) {
			$this->assertSame( 'icon_library_ability_icon_not_available', $this->service->get_icon( array( 'icon' => $name ) )->get_error_code() );
		}
		wp_register_icon( 'core/envelope', array( 'label' => 'Forged', 'keywords' => array( 'letter' ), 'file_path' => '/not-core/envelope.svg' ) );
		$this->assertSame( 0, $this->service->search_icons( array( 'search' => 'letter' ) )['total'] );
		$this->assertSame( 'icon_library_ability_icon_not_available', $this->service->get_icon( array( 'icon' => 'core/envelope' ) )->get_error_code() );
	}

	public function test_provenance_cache_does_not_cache_live_labels() {
		$this->assertSame( 'Envelope', $this->service->get_icon( array( 'icon' => 'core/envelope' ) )['label'] );
		wp_register_icon( 'core/envelope', array( 'label' => 'Localized envelope', 'file_path' => ABSPATH . WPINC . '/images/icon-library/envelope.svg' ) );
		$this->assertSame( 'Localized envelope', $this->service->get_icon( array( 'icon' => 'core/envelope' ) )['label'] );
	}

	public function test_missing_manifest_cannot_expand_discovery() {
		$lines = array();
		$exit  = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/ability-missing-core-manifest.php' ), $lines, $exit );
		$this->assertSame( 0, $exit, implode( "\n", $lines ) );
	}

	public function test_wordpress_71_does_not_require_public_collection_property() {
		$GLOBALS['icon_library_test_wp_version'] = '7.1.2';
		unset( $GLOBALS['icon_library_test_registered']['collections']['core']['public'] );
		$this->assertSame( 'Envelope', $this->service->get_icon( array( 'icon' => 'core/envelope' ) )['label'] );
		$this->assertSame( 1, $this->service->search_icons( array( 'search' => 'mail' ) )['total'] );
	}
}
