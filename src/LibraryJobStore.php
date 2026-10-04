<?php
/**
 * Durable per-site library job and active-version state with compare-and-swap.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Uses one non-autoloaded option per library as the authoritative state row. */
class LibraryJobStore {
	const OPTION_PREFIX = 'icon_library_install_';

	/**
	 * Reads the database row directly, bypassing the options object cache.
	 *
	 * @param string $library_id Library identifier.
	 * @return array|null
	 */
	public function read( $library_id ) {
		global $wpdb;
		$name = $this->option_name( $library_id );
		if ( ! $name || ! isset( $wpdb->options ) ) {
			return null; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		if ( ! is_string( $raw ) ) {
			return null; }
		$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
		return is_array( $value ) ? array(
			'raw'   => $raw,
			'value' => $value,
		) : null;
	}

	/** Returns libraries with authoritative installer state without autoloading it. */
	public function all_library_ids() {
		global $wpdb;
		$cached = wp_cache_get( 'installed_library_ids', 'icon_library' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached; }
		if ( ! isset( $wpdb->options ) ) {
			return array(); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pattern = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( self::OPTION_PREFIX ) . '%' : addcslashes( self::OPTION_PREFIX, '_%\\' ) . '%';
		$names   = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		$ids     = array();
		foreach ( (array) $names as $name ) {
			$id = substr( $name, strlen( self::OPTION_PREFIX ) );
			if ( 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id ) ) {
				$ids[] = $id; }
		}
		wp_cache_set( 'installed_library_ids', $ids, 'icon_library' );
		return $ids;
	}

	/**
	 * Writes conditionally; a null expected value means insert-if-absent.
	 *
	 * @param string      $library_id Library identifier.
	 * @param string|null $expected_raw Exact previously read serialized value.
	 * @param array       $value Replacement state.
	 * @return bool
	 */
	public function compare_and_swap( $library_id, $expected_raw, $value ) {
		global $wpdb;
		$name = $this->option_name( $library_id );
		if ( ! $name || ! isset( $wpdb->options ) ) {
			return false; }
		$serialized = maybe_serialize( $value );
		if ( null === $expected_raw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $serialized ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $serialized, $name, $expected_raw ) );
		}
		if ( 1 !== (int) $result ) {
			return false; }
		wp_cache_delete( $name, 'options' );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' ); }
		wp_cache_delete( 'installed_library_ids', 'icon_library' );
		return true;
	}

	/**
	 * Builds an option name only for a valid library identifier.
	 *
	 * @param mixed $library_id Candidate identifier.
	 * @return string
	 */
	private function option_name( $library_id ) {
		return is_string( $library_id ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $library_id ) ? self::OPTION_PREFIX . $library_id : '';
	}
}
