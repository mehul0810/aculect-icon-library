<?php
/**
 * Transaction-scoped option mutations.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Pins a lock row until commit, including when its recovery lease expires. */
class OptionMutationLock {
	/** Lock option name.
	 *
	 * @var string
	 */
	private $name;
	/** Database connection used for this mutation.
	 *
	 * @var object|null
	 */
	private $database;
	/** Options table captured before callbacks can switch the site.
	 *
	 * @var string
	 */
	private $table;
	/** Exact serialized owner row.
	 *
	 * @var string
	 */
	private $raw = '';
	/** Whether this instance owns an unfinished mutation.
	 *
	 * @var bool
	 */
	private $active = false;
	/** Changed values for cache invalidation after completion.
	 *
	 * @var array
	 */
	private $changed = array();
	/** Transaction that pins ownership.
	 *
	 * @var DatabaseTransaction|null
	 */
	private $transaction;
	/** Whether acquisition failed because transactional storage is unavailable.
	 *
	 * @var bool
	 */
	private $storage_unavailable = false;
	/** Whether an authoritative database read failed in this mutation.
	 *
	 * @var bool
	 */
	private $failed = false;

	/** Returns whether the mutation must be abandoned.
	 *
	 * @return bool
	 */
	public function has_failed() {
		return $this->failed;
	}

	/** Returns whether transactional storage prevented acquisition.
	 *
	 * @return bool
	 */
	public function storage_unavailable() {
		return $this->storage_unavailable;
	}

	/**
	 * Creates a mutation owner for one lock option.
	 *
	 * @param string $name Lock option name.
	 */
	public function __construct( $name ) {
		$this->name = $name;
	}

	/**
	 * Requires transactional storage and refuses to commit a caller's transaction.
	 *
	 * @param int $ttl Recovery age for locks left by older plugin versions.
	 * @return bool
	 */
	public function acquire( $ttl ) {
		global $wpdb;
		if ( $this->active || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return false;
		}
		$this->database    = $wpdb;
		$this->table       = $wpdb->options;
		$this->transaction = new DatabaseTransaction();
		if ( ! $this->transaction->begin( array( $wpdb->options ) ) ) {
			$this->storage_unavailable = true;
			return false;
		}
		$this->active = true;
		$existing     = $this->read_raw( $this->name );
		if ( $this->failed ) {
			$this->storage_unavailable = true;
			$this->release();
			return false;
		}
		$this->raw = maybe_serialize(
			array(
				'token'   => wp_generate_uuid4(),
				'started' => time(),
			)
		);
		if ( null === $existing ) {
			// Unlike add_option(), this cannot replace another request's token.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $this->table, $this->name, $this->raw ) );
		} else {
			$value   = maybe_unserialize( $existing );
			$started = is_array( $value ) ? absint( $value['started'] ?? 0 ) : absint( $value );
			if ( ! $started || time() - $started <= $ttl ) {
				$this->release();
				return false;
			}
			// The conditional update also pins this row until our transaction ends.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $this->table, $this->raw, $this->name, $existing ) );
		}
		if ( 1 !== (int) $result ) {
			$this->release();
			return false;
		}
		return true;
	}

	/**
	 * Reads authoritative state without using the options object cache.
	 *
	 * @param string $name Option name.
	 * @param mixed  $fallback Missing value.
	 * @return mixed
	 */
	public function read( $name, $fallback = false ) {
		$raw = $this->read_raw( $name );
		if ( $this->failed ) {
			return false;
		}
		return null === $raw ? $fallback : maybe_unserialize( $raw );
	}

	/**
	 * Conditionally stages one option in the current transaction.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Replacement value.
	 * @return bool
	 */
	public function write( $name, $value ) {
		$wpdb = $this->database;
		if ( ! $this->active || $this->failed ) {
			return false;
		}
		$old = $this->read_raw( $name );
		if ( $this->failed ) {
			return false;
		}
		$old_value = null === $old ? false : maybe_unserialize( $old );
		$requested = $value;
		$value     = sanitize_option( $name, $value );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve the existing Core option filter contract during a conditional write.
		$value = apply_filters( "pre_update_option_{$name}", $value, $old_value, $name );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is Core's existing global option filter, not a plugin-defined hook.
		$value = apply_filters( 'pre_update_option', $value, $name, $old_value );
		// These are structured internal-state operations. A veto or transformation
		// cannot satisfy the caller's requested mutation, even if it equals old state.
		if ( $requested !== $value || $this->failed ) {
			return false;
		}
		$new = maybe_serialize( $value );
		if ( $old === $new ) {
			return true;
		}
		if ( null === $old ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve Core's pre-insert option notification.
			do_action( 'add_option', $name, $value );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $this->table, $name, $new ) );
		} else {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve Core's pre-update option notification.
			do_action( 'update_option', $name, $old_value, $value );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query( $wpdb->prepare( "UPDATE %i SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = BINARY %s", $this->table, $new, $name, $old ) );
		}
		if ( 1 !== (int) $result ) {
			return false;
		}
		if ( isset( $this->changed[ $name ] ) ) {
			$this->changed[ $name ][1] = $value;
		} else {
			$this->changed[ $name ] = array( $old_value, $value, null === $old );
		}
		$this->invalidate( $name );
		return true;
	}

	/** Commits before callers report success; release otherwise rolls back. */
	public function commit() {
		$wpdb = $this->database;
		if ( ! $this->active || $this->failed ) {
			$this->release();
			return false;
		}
		// Exact-value deletion cannot remove a successor's token.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s', $this->table, $this->name, $this->raw ) );
		if ( 1 !== (int) $deleted || ! $this->transaction->commit() ) {
			$this->release();
			return false;
		}
		$this->active = false;
		foreach ( $this->changed as $name => $values ) {
			$this->invalidate( $name );
			// Notify consumers only after the whole mutation has committed.
			if ( $values[2] ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Notify existing Core hook consumers only after commit.
				do_action( "add_option_{$name}", $name, $values[1] );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve Core's completed-insert notification.
				do_action( 'added_option', $name, $values[1] );
			} else {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Notify existing Core hook consumers only after commit.
				do_action( "update_option_{$name}", $values[0], $values[1], $name );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve Core's completed-update notification.
				do_action( 'updated_option', $name, $values[0], $values[1] );
			}
		}
		$this->invalidate( $this->name );
		$this->changed = array();
		return true;
	}

	/** Abandons uncommitted work without deleting any successor's lock. */
	public function release() {
		if ( ! $this->active ) {
			return;
		}
		$this->transaction->rollback();
		$this->active = false;
		foreach ( array_keys( $this->changed ) as $name ) {
			$this->invalidate( $name );
		}
		$this->invalidate( $this->name );
		$this->changed = array();
	}

	/**
	 * Retrieves the exact database row for compare-and-swap.
	 *
	 * @param string $name Option name.
	 * @return string|null|false False means a failed read, not an absent option.
	 */
	private function read_raw( $name ) {
		if ( $this->failed ) {
			return false;
		}
		$wpdb = $this->database;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $this->table, $name ) );
		if ( ! empty( $wpdb->last_error ) ) {
			$this->failed = true;
			return false;
		}
		return is_string( $raw ) ? $raw : null;
	}

	/**
	 * Discards positive, negative and autoloaded option-cache entries.
	 *
	 * @param string $name Option name.
	 */
	private function invalidate( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
