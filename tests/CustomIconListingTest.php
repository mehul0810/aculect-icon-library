<?php
/** @package IconLibrary */

use IconLibrary\CollectionRegistry;
use IconLibrary\CustomIconListing;
use IconLibrary\CustomIconRepository;
use IconLibrary\RestController;
use PHPUnit\Framework\TestCase;

class CustomIconListingTest extends TestCase {
	private function repository( $icons, $reads ) {
		$repository = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->onlyMethods( array( 'get_icons', 'get_svg_content_for_icon' ) )->getMock();
		$repository->method( 'get_icons' )->willReturn( $icons );
		$repository->expects( $this->exactly( $reads ) )->method( 'get_svg_content_for_icon' )->willReturn( '<svg />' );
		return $repository;
	}

	public function unicode_pairs() {
		return array(
			array( 'Étoile', 'étoile' ),
			array( 'étoile', 'ÉTOILE' ),
			array( 'Дом', 'дом' ),
			array( 'дом', 'ДОМ' ),
		);
	}

	/** @dataProvider unicode_pairs */
	public function test_rest_search_matches_unicode_in_both_case_directions( $label, $query ) {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			$this->markTestSkipped( 'Unicode case conversion requires optional mbstring.' );
		}
		$repository = $this->repository( array( 'shape-mark' => array( 'label' => $label ) ), 1 );
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->getMock();
		$response = ( new RestController( $registry, $repository ) )->get_custom_icons(
			new WP_REST_Request( 'GET', '', array( 'search' => $query, 'page' => 1, 'per_page' => 20 ) )
		)->get_data();
		$this->assertSame( 1, $response['total'] );
		$this->assertSame( 'shape-mark', $response['items'][0]['name'] );
		$this->assertSame( $label, $response['items'][0]['label'] );
	}

	public function test_unicode_filter_keeps_page_totals_archive_exclusion_and_bounded_reads() {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			$this->markTestSkipped( 'Unicode case conversion requires optional mbstring.' );
		}
		$icons = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$icons[ sprintf( 'mark-%03d', $i ) ] = array( 'label' => 'Étoile ' . $i );
		}
		$icons['hidden'] = array( 'label' => 'étoile hidden', 'archived' => true );
		$icons['other'] = array( 'label' => 'Other' );
		$listing = new CustomIconListing( $this->repository( $icons, 20 ) );
		$page = $listing->get_page( 'éTOILE', -1, 'name', 2, 20 );
		$this->assertSame( 500, $page['total'] );
		$this->assertSame( 25, $page['total_pages'] );
		$this->assertCount( 20, $page['items'] );
		$this->assertSame( 'mark-479', $page['items'][0]['name'] );
		$this->assertSame( 'mark-460', $page['items'][19]['name'] );
		$this->assertSame( array(), $listing->get_page( 'ÉTOILE', 1, 'name', 26, 20 )['items'] );
	}

	public function test_page_uses_one_metadata_snapshot_for_preview_reads() {
		$icons = array();
		for ( $i = 0; $i < 500; ++$i ) {
			$icons[ sprintf( 'mark-%03d', $i ) ] = array( 'label' => sprintf( 'Mark %03d', $i ) );
		}
		$repository = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->onlyMethods( array( 'get_icons', 'get_svg_content_for_icon' ) )->getMock();
		$repository->expects( $this->once() )->method( 'get_icons' )->willReturn( $icons );
		$repository->expects( $this->exactly( 20 ) )->method( 'get_svg_content_for_icon' )->willReturn( '<svg />' );
		$page = ( new CustomIconListing( $repository ) )->get_page( 'Mark', 1, 'name', 2, 20 );
		$this->assertSame( 500, $page['total'] );
		$this->assertCount( 20, $page['items'] );
		$this->assertSame( 'mark-020', $page['items'][0]['name'] );
	}

	public function test_ascii_labels_names_empty_and_no_matches_remain_unchanged() {
		$icons = array(
			'brand-mark' => array( 'label' => 'Company' ),
			'other' => array( 'label' => 'Apple' ),
			'archived' => array( 'label' => 'Company', 'archived' => true ),
		);
		$listing = new CustomIconListing( $this->repository( $icons, 4 ) );
		$this->assertSame( 'brand-mark', $listing->get_page( 'COMPANY', 1, 'label', 1, 20 )['items'][0]['name'] );
		$this->assertSame( 'brand-mark', $listing->get_page( 'BRAND-MARK', 1, 'name', 1, 20 )['items'][0]['name'] );
		$this->assertSame( array( 'other', 'brand-mark' ), array_column( $listing->get_page( '', 1, 'label', 1, 20 )['items'], 'name' ) );
		$this->assertSame( 0, $listing->get_page( 'missing', 1, 'name', 1, 20 )['total'] );
	}

	public function test_no_mbstring_fallback_is_safe_and_documents_case_limitation() {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/custom-listing-without-mbstring.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error . $output );
		$this->assertSame( '[1,1,0]', trim( $output ) );
	}
}
