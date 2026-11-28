<?php
/**
 * Selects one page of Core picker metadata without touching SVG files.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps page selection separate from Core registration and SVG hydration. */
final class CorePickerPage {
	/**
	 * Streams matching rows and keeps only the requested or selected page.
	 *
	 * @param iterable $rows     Candidate rows in discovery order.
	 * @param string   $search   Search text.
	 * @param int      $page     Page; zero locates the selected icon.
	 * @param int      $per_page Maximum returned rows.
	 * @param string   $selected Selected name.
	 * @return array
	 */
	public static function select( $rows, $search, $page, $per_page, $selected ) {
		$page        = min( CollectionRegistry::MAX_PAGE, max( 0, (int) $page ) );
		$per_page    = min( 48, max( 1, (int) $per_page ) );
		$search      = trim( $search );
		$matches     = self::matcher( $search );
		$locate      = 0 === $page && '' !== $selected;
		$page        = max( 1, $page );
		$chosen_page = 0;
		$offset      = ( $page - 1 ) * $per_page;
		$total       = 0;
		$first       = array();
		$chunk       = array();
		$chosen      = array();
		foreach ( $rows as $row ) {
			if ( ! $matches( $row ) ) {
				continue;
			}
			$position = $total++;
			if ( ! $locate ) {
				if ( $position >= $offset && $position < $offset + $per_page ) {
					$chosen[] = $row;
				}
				continue;
			}
			if ( 0 === $position % $per_page ) {
				$chunk = array();
			}
			$chunk[] = $row;
			if ( $position < $per_page ) {
				$first[] = $row;
			}
			if ( $row['name'] === $selected ) {
				$chosen_page = intdiv( $position, $per_page ) + 1;
			}
			$current_page = intdiv( $position, $per_page ) + 1;
			if ( $chosen_page && $current_page === $chosen_page ) {
				$chosen = $chunk;
			}
		}

		return array(
			'items'       => $locate && ! $chosen_page ? $first : $chosen,
			'total'       => $total,
			'page'        => $chosen_page ? $chosen_page : $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Builds one matcher for the picker and ability catalog.
	 *
	 * @param string $search Search text.
	 * @return callable
	 */
	public static function matcher( $search ) {
		$search = trim( $search );
		if ( '' === $search ) {
			return static function () {
				return true;
			};
		}
		$accents = function_exists( 'remove_accents' );
		$pattern = '/' . preg_quote( $accents ? remove_accents( $search ) : $search, '/' ) . '/iu';
		return static function ( $row ) use ( $accents, $pattern ) {
			$haystack = $row['name'] . "\n" . self::label( $row );
			if ( ! empty( $row['keywords'] ) && is_array( $row['keywords'] ) ) {
				$haystack .= "\n" . implode( "\n", array_filter( $row['keywords'], 'is_string' ) );
			}
			return 1 === preg_match( $pattern, $accents ? remove_accents( $haystack ) : $haystack );
		};
	}

	/**
	 * Normalizes plugin labels without changing external Core labels.
	 *
	 * @param array $row Metadata row.
	 * @return string
	 */
	public static function label( $row ) {
		return isset( $row['library'] ) ? sanitize_text_field( $row['label'] ) : $row['label'];
	}
}
