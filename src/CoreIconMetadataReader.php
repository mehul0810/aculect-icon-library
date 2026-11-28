<?php
/**
 * Read-only bridge to Core icon metadata before file-backed artwork is loaded.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

/** Core has no public metadata-only enumeration method in WordPress 7.1. */
final class CoreIconMetadataReader extends \WP_Icons_Registry {
	/**
	 * Reads metadata from the existing singleton without replacing or mutating it.
	 *
	 * @param string $name Optional exact registered name.
	 * @return array|\WP_Error Registered icon rows or a compatibility error.
	 */
	public static function get_registered_metadata( $name = '' ) {
		$registry = \WP_Icons_Registry::get_instance();
		if ( ! is_object( $registry ) ) {
			return self::unsupported_registry();
		}
		$vars = get_object_vars( $registry );
		if ( ! isset( $vars['registered_icons'] ) || ! is_array( $vars['registered_icons'] ) ) {
			return self::unsupported_registry();
		}
		$icons = '' === $name ? $vars['registered_icons'] : ( isset( $vars['registered_icons'][ $name ] ) ? array( $name => $vars['registered_icons'][ $name ] ) : array() );
		foreach ( $icons as $icon_name => $icon ) {
			if ( ! is_string( $icon_name ) || ! is_array( $icon ) || ( $icon['name'] ?? null ) !== $icon_name || ! isset( $icon['collection'], $icon['label'] ) || ! is_string( $icon['collection'] ) || ! is_string( $icon['label'] ) ) {
				return self::unsupported_registry();
			}
		}
		return $icons;
	}

	/**
	 * Returns a service-unavailable error for unsupported Core registry layouts.
	 *
	 * @return \WP_Error Compatibility error.
	 */
	private static function unsupported_registry() {
		return new \WP_Error( 'icon_library_picker_registry_unsupported', __( 'Icon browsing is unavailable with this WordPress Icon API version.', 'aculect-icon-library' ), array( 'status' => 503 ) );
	}
}
