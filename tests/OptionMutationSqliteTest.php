<?php
/** Optional real SQLite drop-in SQL proof, using only task-owned temporary files. */

use IconLibrary\DatabaseTransaction;
use IconLibrary\OptionMutationLock;
use PHPUnit\Framework\TestCase;

class IconLibrary_Test_Real_Sqlite_Database {
	public $options = 'wp_options';
	private $driver;
	public function __construct( $path ) {
		$this->driver = new WP_MySQL_On_SQLite( 'mysql-on-sqlite:path=' . $path . ';dbname=integrity_test' );
		$this->driver->get_sqlite_pdo()->setAttribute( PDO::ATTR_TIMEOUT, 0 );
	}
	public function get_driver() { return $this->driver; }
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace_callback( '/%[sdi]/', function ( $match ) use ( $arg ) {
				if ( '%i' === $match[0] ) { return '`' . str_replace( '`', '``', $arg ) . '`'; }
				return '%d' === $match[0] ? (string) (int) $arg : $this->driver->quote( $arg );
			}, $sql, 1 );
		}
		return $sql;
	}
	public function query( $sql ) {
		try { return $this->driver->exec( $sql ); } catch ( Throwable $error ) { return false; }
	}
	public function get_var( $sql ) {
		$value = $this->driver->query( $sql )->fetchColumn();
		return false === $value ? null : (string) $value;
	}
	public function get_row( $sql ) { return $this->driver->query( $sql )->fetchObject(); }
}

class OptionMutationSqliteTest extends TestCase {
	public function test_real_translator_transactions_cas_contention_and_for_update() {
		$loader = getenv( 'ICON_LIBRARY_SQLITE_DRIVER_LOAD' );
		if ( ! $loader || ! is_file( $loader ) ) {
			$this->markTestSkipped( 'Set ICON_LIBRARY_SQLITE_DRIVER_LOAD for a disposable real SQLite driver proof.' );
		}
		require_once $loader;
		$original = $GLOBALS['wpdb'];
		$path = tempnam( sys_get_temp_dir(), 'ail-integrity-sqlite-' );
		try {
			$one = new IconLibrary_Test_Real_Sqlite_Database( $path );
			$this->assertNotFalse( $one->query( 'CREATE TABLE wp_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL, PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB' ) );
			$this->assertNotFalse( $one->query( 'CREATE TABLE wp_posts (ID bigint unsigned NOT NULL, post_content longtext NOT NULL, PRIMARY KEY (ID)) ENGINE=InnoDB' ) );
			$this->assertNotFalse( $one->query( "INSERT INTO wp_posts (ID, post_content) VALUES (1, 'original')" ) );
			$two = new IconLibrary_Test_Real_Sqlite_Database( $path );
			$GLOBALS['wpdb'] = $one;
			$holder = new OptionMutationLock( 'lock' );
			$this->assertTrue( $holder->acquire( -1 ) );
			$this->assertTrue( $holder->write( 'state', array( 'first' ) ) );
			$GLOBALS['wpdb'] = $two;
			$contender = new OptionMutationLock( 'lock' );
			$this->assertFalse( $contender->acquire( -1 ) );
			// Commit must use the captured first connection, not the replaced global.
			$this->assertTrue( $holder->commit() );
			$this->assertTrue( $contender->acquire( 30 ) );
			$this->assertSame( array( 'first' ), $contender->read( 'state' ) );
			$this->assertTrue( $contender->write( 'state', array( 'first', 'second' ) ) );
			$this->assertTrue( $contender->commit() );
			$holder->release();
			$this->assertNull( $one->get_var( "SELECT option_value FROM wp_options WHERE option_name='lock'" ) );
			$this->assertNotFalse( $one->query( 'START TRANSACTION' ) );
			$GLOBALS['wpdb'] = $one;
			$nested = new DatabaseTransaction();
			$this->assertFalse( $nested->begin( array( 'wp_posts' ) ) );
			$this->assertTrue( $one->get_driver()->inTransaction() );
			$this->assertNotFalse( $one->query( 'ROLLBACK' ) );
			$post_transaction = new DatabaseTransaction();
			$this->assertTrue( $post_transaction->begin( array( 'wp_posts' ) ) );
			$this->assertSame( 'original', $one->get_row( 'SELECT * FROM wp_posts WHERE ID = 1 FOR UPDATE' )->post_content );
			$this->assertNotFalse( $one->query( "UPDATE wp_posts SET post_content='changed' WHERE ID=1" ) );
			$post_transaction->rollback();
			$this->assertSame( 'original', $one->get_var( 'SELECT post_content FROM wp_posts WHERE ID=1' ) );
		} finally {
			$GLOBALS['wpdb'] = $original;
			unset( $holder, $contender, $post_transaction, $nested, $one, $two );
			foreach ( array( '', '-wal', '-shm', '-journal' ) as $suffix ) {
				if ( is_file( $path . $suffix ) ) { unlink( $path . $suffix ); }
			}
		}
	}
}
