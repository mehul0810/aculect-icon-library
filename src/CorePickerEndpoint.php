<?php
/**
 * REST adapter for bounded Core picker discovery.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps picker transport separate from catalog selection and Core registration. */
final class CorePickerEndpoint {
	/** Returns the existing page query and optional first-load metadata argument. */
	public static function query_args() {
		return array(
			'collection' => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 100,
				'pattern'   => '^[a-z0-9_-]*$',
			),
			'search'     => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 200,
			),
			'selected'   => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 256,
			),
			'page'       => array(
				'type'    => 'integer',
				'default' => 0,
				'minimum' => 0,
				'maximum' => CollectionRegistry::MAX_PAGE,
			),
			'per_page'   => array(
				'type'    => 'integer',
				'default' => 48,
				'minimum' => 1,
				'maximum' => 48,
			),
			'bootstrap'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Returns one page and, on initial open, Core's collection list.
	 *
	 * @param CoreIconRegistrar $registrar Core picker adapter.
	 * @param WP_REST_Request   $request   Validated picker request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public static function read( CoreIconRegistrar $registrar, WP_REST_Request $request ) {
		$collection  = (string) $request->get_param( 'collection' );
		$selected    = (string) $request->get_param( 'selected' );
		$collections = null;
		if ( true === $request->get_param( 'bootstrap' ) ) {
			// Dispatch in-process to retain Core permissions and discovery filters.
			$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/icon-collections' ) );
			if ( $response->is_error() ) {
				return $response->as_error();
			}
			$collections = $response->get_data();
			if ( ! is_array( $collections ) ) {
				return new WP_Error( 'icon_library_picker_collections_unavailable', __( 'Icon collections could not be loaded.', 'aculect-icon-library' ), array( 'status' => 503 ) );
			}
			$collection = self::initial_collection( $collections, $selected );
		}

		$page = $registrar->get_picker_page(
			$collection,
			(string) $request->get_param( 'search' ),
			(int) $request->get_param( 'page' ),
			(int) ( $request->get_param( 'per_page' ) ?? 48 ),
			$selected
		);
		if ( is_wp_error( $page ) ) {
			// Keep healthy collections reachable when only the initial artwork fails.
			// Preserve the error/status; permission and registry failures stay unchanged.
			if ( null !== $collections && 'icon_library_picker_asset_missing' === $page->get_error_code() ) {
				$data                = (array) $page->get_error_data();
				$data['collections'] = $collections;
				$data['collection']  = $collection;
				$page->add_data( $data );
			}
			return $page;
		}
		if ( null !== $collections ) {
			$page['collections'] = $collections;
			$page['collection']  = $collection;
		}
		return rest_ensure_response( $page );
	}

	/**
	 * Prefers the selected icon's visible namespace, then the first collection.
	 *
	 * @param array  $collections Core collection list.
	 * @param string $selected    Saved icon name.
	 * @return string
	 */
	private static function initial_collection( $collections, $selected ) {
		$namespace = explode( '/', $selected, 2 )[0];
		$slugs     = array_column( $collections, 'slug' );
		return in_array( $namespace, $slugs, true ) ? $namespace : (string) reset( $slugs );
	}
}
