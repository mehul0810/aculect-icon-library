<?php
/** Transactional options double; intentionally models SQL rather than add_option. */
class IconLibrary_Test_Option_Database {
	public $options = 'wp_options';
	public $engine = 'InnoDB';
	public $autocommit = '1';
	public $active = false;
	public $fail_commit = false;
	public $fail_commit_applied = false;
	public $fail_option = '';
	public $last_error = '';
	public $fail_reads = array();
	public $read_count = 0;
	public $before_query;
	public $before_read;
	public $queries = array();
	private $undo = array();
	private $suppressed = false;
	private static $row_owners = array();

	public function prepare( $sql, ...$args ) {
		if ( false !== strpos( $sql, '%i' ) ) {
			$sql = str_replace( '%i', '`' . array_shift( $args ) . '`', $sql );
		}
		return array( $sql, $args );
	}

	public function suppress_errors( $value ) {
		$previous = $this->suppressed;
		$this->suppressed = $value;
		return $previous;
	}

	public function get_row( $query ) {
		return (object) array( 'Engine' => $this->engine );
	}

	public function get_var( $query ) {
		$this->last_error = '';
		if ( 'SELECT @@autocommit' === $query ) {
			return $this->autocommit;
		}
		list( $sql, $args ) = $query;
		++$this->read_count;
		if ( in_array( $this->read_count, $this->fail_reads, true ) ) {
			$this->last_error = 'Simulated transient SELECT failure';
			return null;
		}
		if ( $this->before_read ) {
			call_user_func( $this->before_read, $sql, $args );
		}
		return array_key_exists( $args[0], $GLOBALS['icon_library_test_options'] ) ? (string) maybe_serialize( $GLOBALS['icon_library_test_options'][ $args[0] ] ) : null;
	}

	public function query( $query ) {
		list( $sql, $args ) = is_array( $query ) ? $query : array( $query, array() );
		$this->queries[] = array( $sql, $args );
		if ( $this->before_query ) {
			call_user_func( $this->before_query, $sql, $args );
		}
		if ( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' === $sql ) {
			return $this->active ? false : 0;
		}
		if ( 'START TRANSACTION' === $sql ) {
			if ( $this->active ) {
				throw new RuntimeException( 'START TRANSACTION would implicitly commit the caller.' );
			}
			$this->undo = array();
			$this->active = true;
			return 0;
		}
		if ( 'COMMIT' === $sql && $this->fail_commit ) {
			return false;
		}
		if ( 'COMMIT' === $sql || 'ROLLBACK' === $sql ) {
			if ( 'ROLLBACK' === $sql && $this->active ) {
				foreach ( $this->undo as $name => $previous ) {
					if ( $previous[0] ) {
						$GLOBALS['icon_library_test_options'][ $name ] = $previous[1];
					} else {
						unset( $GLOBALS['icon_library_test_options'][ $name ] );
					}
					$GLOBALS['icon_library_test_autoload'][ $name ] = $previous[2];
				}
			}
			$this->active = false;
			foreach ( self::$row_owners as $name => $owner ) {
				if ( $owner === $this ) {
					unset( self::$row_owners[ $name ] );
				}
			}
			return 'COMMIT' === $sql && $this->fail_commit_applied ? false : 0;
		}
		$insert = 0 === strpos( $sql, 'INSERT IGNORE' );
		$delete = 0 === strpos( $sql, 'DELETE' );
		$name = $insert || $delete ? $args[0] : $args[1];
		if ( $name === $this->fail_option ) {
			return false;
		}
		if ( isset( self::$row_owners[ $name ] ) && self::$row_owners[ $name ] !== $this ) {
			return false; // A real contender waits or hits its lock-wait timeout.
		}
		$exists = array_key_exists( $name, $GLOBALS['icon_library_test_options'] );
		$raw = $exists ? (string) maybe_serialize( $GLOBALS['icon_library_test_options'][ $name ] ) : null;
		if ( $insert ? $exists : ( ! $exists || $raw !== ( $delete ? $args[1] : $args[2] ) ) ) {
			return 0;
		}
		if ( $this->active ) {
			if ( ! array_key_exists( $name, $this->undo ) ) {
				$this->undo[ $name ] = array( $exists, $exists ? $GLOBALS['icon_library_test_options'][ $name ] : null, $GLOBALS['icon_library_test_autoload'][ $name ] ?? null );
			}
			self::$row_owners[ $name ] = $this;
		}
		if ( $delete ) {
			unset( $GLOBALS['icon_library_test_options'][ $name ] );
		} else {
			$GLOBALS['icon_library_test_options'][ $name ] = maybe_unserialize( $insert ? $args[1] : $args[0] );
			$GLOBALS['icon_library_test_autoload'][ $name ] = false;
		}
		return 1;
	}
}
