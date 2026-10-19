<?php
/**
 * Short transactions on supported WordPress database backends.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Never commits or replaces a transaction owned by its caller. */
class DatabaseTransaction {
	/** Whether this instance owns the connection's transaction.
	 *
	 * @var bool
	 */
	private $active = false;
	/** Database connection owned by this scope.
	 *
	 * @var object|null
	 */
	private $database;
	/** Connections already owned by another plugin transaction scope.
	 *
	 * @var array
	 */
	private static $connections = array();

	/**
	 * Starts a transaction only when it cannot commit unrelated work.
	 *
	 * @param string[] $tables Tables that require transactional storage.
	 * @return bool
	 */
	public function begin( $tables ) {
		global $wpdb;
		if ( $this->active || ! is_object( $wpdb ) || isset( self::$connections[ spl_object_hash( $wpdb ) ] ) ) {
			return false;
		}
		if ( method_exists( $wpdb, 'get_driver' ) ) {
			try {
				$driver = $wpdb->get_driver();
			} catch ( \Throwable $error ) {
				return false;
			}
			if ( ! is_object( $driver ) || ! method_exists( $driver, 'inTransaction' ) || $driver->inTransaction() ) {
				return false;
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$autocommit = $wpdb->get_var( 'SELECT @@autocommit' );
			if ( '1' !== (string) $autocommit ) {
				return false;
			}
			foreach ( $tables as $table_name ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name ) );
				$table = is_object( $table ) ? array_change_key_case( (array) $table ) : array();
				if ( 'innodb' !== strtolower( $table['engine'] ?? '' ) ) {
					return false;
				}
			}
			// MySQL rejects a next-transaction isolation change inside an active
			// transaction. Unlike START TRANSACTION, this cannot implicitly commit it.
			$previous = $wpdb->suppress_errors( true );
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ready = $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );
			} finally {
				$wpdb->suppress_errors( $previous );
			}
			if ( false === $ready ) {
				return false;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}
		$this->database                                = $wpdb;
		$this->active                                  = true;
		self::$connections[ spl_object_hash( $wpdb ) ] = true;
		return true;
	}

	/** Commits owned work and reports any database failure.
	 *
	 * @return bool
	 */
	public function commit() {
		$wpdb = $this->database;
		if ( ! $this->active ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$this->rollback();
			return false;
		}
		$this->active = false;
		unset( self::$connections[ spl_object_hash( $wpdb ) ] );
		return true;
	}

	/** Rolls back only this instance's unfinished transaction. */
	public function rollback() {
		$wpdb = $this->database;
		if ( $this->active ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			$this->active = false;
			unset( self::$connections[ spl_object_hash( $wpdb ) ] );
		}
	}
}
