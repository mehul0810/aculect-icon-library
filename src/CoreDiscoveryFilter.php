<?php
/**
 * Filters WordPress Core icon discovery for plugin collection state.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns discovery visibility rules without owning icon registration. */
class CoreDiscoveryFilter {
	/**
	 * Collection metadata and activation state.
	 *
	 * @var CollectionRegistry
	 */
	private $collection_registry;

	/**
	 * Receives collection state without owning registration.
	 *
	 * @param CollectionRegistry $collection_registry Collection state provider.
	 */
	public function __construct( CollectionRegistry $collection_registry ) {
		$this->collection_registry = $collection_registry;
	}

	/**
	 * Hides disabled namespaces and archived or disabled-variant icons.
	 *
	 * @param mixed    $response Response from the Core discovery route.
	 * @param mixed    $server REST server.
	 * @param mixed    $request REST request.
	 * @param string[] $style_collections Registered style namespaces mapped to libraries.
	 * @param string[] $legacy_collections Legacy namespaces hidden from new discovery.
	 * @return mixed
	 */
	public function filter( $response, $server, $request, $style_collections, $legacy_collections ) {
		unset( $server );
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() ) {
			return $response;
		}

		$route = $request->get_route();
		if ( '/wp/v2/icon-collections' !== $route && 1 !== preg_match( '#^/wp/v2/icons(?:/[^/]+)?$#', $route ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$available       = $this->collection_registry->get_available_collection_slugs();
		$enabled         = $this->collection_registry->get_enabled_collection_slugs();
		$available       = is_array( $available ) ? array_values( array_filter( $available, 'is_string' ) ) : array();
		$enabled         = is_array( $enabled ) ? array_values( array_filter( $enabled, 'is_string' ) ) : array();
		$disabled        = array_values( array_diff( $available, $enabled ) );
		$hidden_icons    = array();
		$candidate_slugs = array();
		if ( '/wp/v2/icons' === $route || 1 === preg_match( '#^/wp/v2/icons/[^/]+$#', $route ) ) {
			foreach ( $data as $icon ) {
				if ( ! is_array( $icon ) || empty( $icon['name'] ) || ! is_string( $icon['name'] ) ) {
					continue;
				}
				$name = $icon['name'];
				foreach ( $available as $slug ) {
					if ( 0 === strpos( $name, $slug . '/' ) || 0 === strpos( $name, $slug . '-' ) ) {
						$candidate_slugs[ $slug ] = true;
					}
				}
			}
		}
		foreach ( array_keys( $candidate_slugs ) as $slug ) {
			if ( in_array( $slug, $disabled, true ) ) {
				continue;
			}
			$manifest         = $this->collection_registry->get_manifest( $slug );
			$enabled_variants = ! empty( $manifest['variants'] ) ? $this->collection_registry->get_enabled_variants( $slug ) : array();
			$enabled_variants = is_array( $enabled_variants ) ? $enabled_variants : array();
			foreach ( (array) ( $manifest['icons'] ?? array() ) as $icon ) {
				$variant          = is_array( $icon ) && isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';
				$variant_disabled = ! empty( $manifest['variants'] ) && '' !== $variant && ! in_array( $variant, $enabled_variants, true );
				if ( is_array( $icon ) && ( ! empty( $icon['archived'] ) || $variant_disabled ) && isset( $icon['coreIconName'] ) && is_string( $icon['coreIconName'] ) && '' !== $icon['coreIconName'] ) {
					$hidden_icons[] = $icon['coreIconName'];
					if ( $variant && 0 === strpos( $icon['coreIconName'], $slug . '/' ) ) {
						$hidden_icons[] = $slug . '-' . $variant . substr( $icon['coreIconName'], strlen( $slug ) );
					}
				}
			}
		}
		foreach ( $style_collections as $style_slug => $library_slug ) {
			$variant          = substr( $style_slug, strlen( $library_slug ) + 1 );
			$enabled_variants = $this->collection_registry->get_enabled_variants( $library_slug );
			$enabled_variants = is_array( $enabled_variants ) ? $enabled_variants : array();
			if ( ! in_array( $library_slug, $enabled, true ) || ! in_array( $variant, $enabled_variants, true ) ) {
				$disabled[] = $style_slug;
			}
		}
		$disabled = array_merge( $disabled, $legacy_collections );

		if ( empty( $disabled ) && empty( $hidden_icons ) ) {
			return $response;
		}
		if ( '/wp/v2/icon-collections' === $route ) {
			$data = array_values(
				array_filter(
					$data,
					static function ( $collection ) use ( $disabled ) {
						return ! is_array( $collection ) || empty( $collection['slug'] ) || ! in_array( $collection['slug'], $disabled, true );
					}
				)
			);
		} elseif ( 1 === preg_match( '#^/wp/v2/icons(?:/([^/]+))?$#', $route, $matches ) ) {
			if ( ! empty( $matches[1] ) && in_array( $matches[1], $disabled, true ) ) {
				$data = array();
			} else {
				$data = array_values(
					array_filter(
						$data,
						static function ( $icon ) use ( $disabled, $hidden_icons ) {
							return ! is_array( $icon ) || ( ( empty( $icon['collection'] ) || ! in_array( $icon['collection'], $disabled, true ) ) && ( empty( $icon['name'] ) || ! in_array( $icon['name'], $hidden_icons, true ) ) );
						}
					)
				);
			}
		}
		$response->set_data( $data );
		return $response;
	}
}
