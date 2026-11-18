<?php
/**
 * Shared current, variant and legacy icon name resolution.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

/** Produces a name index without doing any registration or file I/O. */
class IconNameIndex {
	const MODE_CURRENT = 0;
	const MODE_STYLED  = 1;
	const MODE_LEGACY  = 2;
	const MODE_COUNT   = 3;

	/**
	 * Enumerates one icon's saved names and their registration modes.
	 *
	 * @param string      $library Collection slug.
	 * @param string      $name Canonical Core name.
	 * @param string      $variant Normalized variant slug.
	 * @param string|null $path Manifest-relative icon path.
	 * @return array<string,int>
	 */
	public static function names( $library, $name, $variant, $path ) {
		$names = array( $name => self::MODE_CURRENT );
		if ( $variant ) {
			$names[ $library . '-' . $variant . substr( $name, strlen( $library ) ) ] = self::MODE_STYLED;
		}
		if ( 'heroicons' === $library && 'solid' === $variant && is_string( $path ) ) {
			$base = basename( $path, '.svg' );
			if ( 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $base ) ) {
				foreach ( array( '24-solid', '20-solid', '16-solid' ) as $legacy ) {
					$names[ $library . '/' . $base . '-' . $legacy ] = self::MODE_LEGACY;
				}
			}
		}
		return $names;
	}

	/**
	 * Indexes one manifest for both rendering and catalog lookup.
	 *
	 * @param string   $library Library slug.
	 * @param array    $manifest Collection manifest.
	 * @param callable $normalize_variant Optional normalizer for the offline catalog build.
	 * @return array
	 */
	public static function build( $library, $manifest, $normalize_variant = 'sanitize_key' ) {
		$index = array();
		foreach ( (array) ( $manifest['icons'] ?? array() ) as $icon ) {
			if ( ! is_array( $icon ) || ! is_string( $icon['coreIconName'] ?? null ) || 1 !== preg_match( '/^' . preg_quote( $library, '/' ) . '\/[a-z0-9][a-z0-9_-]*$/', $icon['coreIconName'] ) ) {
				continue;
			}
			$name    = $icon['coreIconName'];
			$variant = is_string( $icon['variant'] ?? null ) ? $normalize_variant( $icon['variant'] ) : '';
			$entry   = array(
				'library'  => $library,
				'manifest' => $manifest,
				'icon'     => $icon,
			);
			foreach ( self::names( $library, $name, $variant, $icon['path'] ?? null ) as $alias => $mode ) {
				if ( self::MODE_STYLED === $mode ) {
					$index[ $alias ] = array_merge(
						$entry,
						array(
							'style'   => $library . '-' . $variant,
							'variant' => $variant,
						)
					);
				} elseif ( self::MODE_LEGACY === $mode ) {
					$index[ $alias ] = array_merge( $entry, array( 'legacy' => true ) );
				} else {
					$index[ $alias ] = $entry;
				}
			}
		}
		return $index;
	}
}
