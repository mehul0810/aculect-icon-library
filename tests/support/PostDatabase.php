<?php
/** In-memory transaction and interleaving model; real database proof is separate. */
class IconLibrary_Test_Post_Database {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $active = false;
	public $fail_commit = false;
	public $before_lock;
	public $queries = array();
	private $snapshot;

	public function get_driver() { return $this; }
	public function inTransaction() { return $this->active; }
	public function prepare( $query, $value ) { return sprintf( $query, (int) $value ); }
	public function get_row( $query ) {
		if ( $this->before_lock ) {
			$callback = $this->before_lock;
			$this->before_lock = null;
			$callback();
			// Model a writer committed before our locking read, not our own write.
			$this->snapshot = unserialize( serialize( $GLOBALS['icon_library_test_posts'] ) );
		}
		preg_match( '/WHERE ID = (\d+) FOR UPDATE$/', $query, $match );
		return isset( $match[1], $GLOBALS['icon_library_test_posts'][ (int) $match[1] ] ) ? clone $GLOBALS['icon_library_test_posts'][ (int) $match[1] ] : null;
	}
	public function query( $query ) {
		$this->queries[] = $query;
		if ( 'START TRANSACTION' === $query ) {
			$this->active = true;
			$this->snapshot = unserialize( serialize( $GLOBALS['icon_library_test_posts'] ) );
		} elseif ( 'COMMIT' === $query ) {
			if ( $this->fail_commit ) { return false; }
			$this->active = false;
		} elseif ( 'ROLLBACK' === $query ) {
			$GLOBALS['icon_library_test_posts'] = $this->snapshot;
			$this->active = false;
		}
		return 0;
	}
}
