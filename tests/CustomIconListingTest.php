<?php
/**
 * Custom icon search and page hydration tests.
 *
 * @package IconLibrary
 */

use IconLibrary\CollectionRegistry;
use IconLibrary\CustomIconListing;
use IconLibrary\CustomIconRepository;
use IconLibrary\RestController;
use PHPUnit\Framework\TestCase;

/** Covers the custom icon listing through its REST and direct entry points. */
class CustomIconListingTest extends TestCase {
	/**
	 * Supplies icon metadata and asserts the number of artwork reads.
	 *
	 * @param array $icons Icon metadata by name.
	 * @param int   $reads Expected preview reads.
	 * @return CustomIconRepository
	 */
	private function repository( $icons, $reads ) {
		$repository = $this->getMockBuilder( CustomIconRepository::class )->disableOriginalConstructor()->onlyMethods( array( 'get_icons', 'get_svg_content' ) )->getMock();
		$repository->method( 'get_icons' )->willReturn( $icons );
		$repository->expects( $this->exactly( $reads ) )->method( 'get_svg_content' )->willReturn( '<svg />' );
		return $repository;
	}

	/** Supplies case changes in either direction for Latin and Cyrillic. */
	public function unicode_pairs() {
		return array(
			array( "\u{00C9}toile", "\u{00E9}toile" ),
			array( "\u{00E9}toile", "\u{00C9}TOILE" ),
			array( "\u{0414}\u{043E}\u{043C}", "\u{0434}\u{043E}\u{043C}" ),
			array( "\u{0434}\u{043E}\u{043C}", "\u{0414}\u{041E}\u{041C}" ),
		);
	}

	/**
	 * Exercises case-insensitive REST searches without changing stored labels.
	 *
	 * @param string $label Stored display label.
	 * @param string $query Search query.
	 * @dataProvider unicode_pairs
	 */
	public function test_rest_search_matches_unicode_in_both_directions( $label, $query ) {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			$this->markTestSkipped( 'Unicode case conversion requires optional mbstring.' );
		}
		$repository = $this->repository( array( 'shape-mark' => array( 'label' => $label ) ), 1 );
		$registry   = $this->createMock( CollectionRegistry::class );
		$data       = ( new RestController( $registry, $repository ) )->get_custom_icons(
			new WP_REST_Request(
				'GET',
				'',
				array(
					'search'   => $query,
					'page'     => 1,
					'per_page' => 20,
				)
			)
		)->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'shape-mark', $data['items'][0]['name'] );
		$this->assertSame( $label, $data['items'][0]['label'] );
	}

	/** Filtering excludes archives and reads artwork only for the current page. */
	public function test_unicode_filter_preserves_counts_and_page_bounded_reads() {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			$this->markTestSkipped( 'Unicode case conversion requires optional mbstring.' );
		}
		$icons   = array(
			'first'   => array( 'label' => "\u{00C9}toile 1" ),
			'second'  => array( 'label' => "\u{00E9}toile 2" ),
			'third'   => array( 'label' => "\u{00C9}TOILE 3" ),
			'other'   => array( 'label' => 'Other' ),
			'archive' => array(
				'label'    => "\u{00E9}toile archived",
				'archived' => true,
			),
		);
		$listing = new CustomIconListing( $this->repository( $icons, 1 ) );
		$page    = $listing->get_page( "\u{00E9}TOILE", 1, 'name', 2, 2 );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( 2, $page['total_pages'] );
		$this->assertSame( array( 'third' ), array_column( $page['items'], 'name' ) );
		$this->assertSame( array(), $listing->get_page( "\u{00C9}TOILE", 1, 'name', 3, 2 )['items'] );
	}

	/** ASCII name/label, unfiltered lists, and no-match behavior are unchanged. */
	public function test_ascii_empty_and_no_match_queries_are_unchanged() {
		$icons   = array(
			'brand-mark' => array( 'label' => 'Company' ),
			'other'      => array( 'label' => 'Apple' ),
		);
		$listing = new CustomIconListing( $this->repository( $icons, 4 ) );
		$this->assertSame( 'brand-mark', $listing->get_page( 'COMPANY', 1, 'label', 1, 20 )['items'][0]['name'] );
		$this->assertSame( 'brand-mark', $listing->get_page( 'BRAND-MARK', 1, 'name', 1, 20 )['items'][0]['name'] );
		$this->assertSame( array( 'other', 'brand-mark' ), array_column( $listing->get_page( '', 1, 'label', 1, 20 )['items'], 'name' ) );
		$this->assertSame( 0, $listing->get_page( 'missing', 1, 'name', 1, 20 )['total'] );
	}

	/** The optional-extension fallback is safe and preserves ASCII matching. */
	public function test_without_mbstring() {
		$process = proc_open(
			array( PHP_BINARY, __DIR__ . '/fixtures/custom-listing-without-mbstring.php' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error . $output );
		$this->assertSame( '[1,1,0]', trim( $output ) );
	}
}
