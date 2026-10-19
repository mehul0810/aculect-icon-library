<?php
/**
 * Builds paginated custom-icon metadata and preview responses.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns custom-icon list filtering, sorting, pagination, and page hydration. */
class CustomIconListing {
	/**
	 * Metadata and artwork store.
	 *
	 * @var CustomIconRepository
	 */
	private $custom_icons;

	/**
	 * Receives the custom artwork store.
	 *
	 * @param CustomIconRepository $custom_icons Custom icon repository.
	 */
	public function __construct( CustomIconRepository $custom_icons ) {
		$this->custom_icons = $custom_icons;
	}

	/**
	 * Returns a filtered page with previews hydrated only for returned rows.
	 *
	 * @param string $search Lowercase search term.
	 * @param int    $order Sort direction (1 ascending, -1 descending).
	 * @param string $orderby Metadata field to sort by.
	 * @param int    $page One-based page number.
	 * @param int    $per_page Maximum rows per page.
	 * @return array
	 */
	public function get_page( $search, $order, $orderby, $page, $per_page ) {
		$items = array();
		foreach ( $this->custom_icons->get_icons() as $name => $icon ) {
			if ( ! is_array( $icon ) || ! empty( $icon['archived'] ) ) {
				continue;
			}
			$label     = (string) ( $icon['label'] ?? $name );
			$icon_name = CustomIconRepository::COLLECTION_SLUG . '/' . $name;
			if ( '' !== $search && false === strpos( strtolower( $label ), $search ) && false === strpos( strtolower( $icon_name ), $search ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $name,
				'name'     => $name,
				'label'    => $label,
				'iconName' => $icon_name,
				'path'     => $icon['path'] ?? '',
			);
		}

		usort(
			$items,
			static function ( $left, $right ) use ( $orderby, $order ) {
				return $order * strcasecmp( (string) $left[ $orderby ], (string) $right[ $orderby ] );
			}
		);
		$total = count( $items );
		$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
		foreach ( $items as &$item ) {
			$svg         = $this->custom_icons->get_svg_content( $item['path'] );
			$item['svg'] = is_string( $svg ) ? $svg : '';
			unset( $item['path'] );
		}
		unset( $item );

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}
}
