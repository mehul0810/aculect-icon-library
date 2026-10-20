<?php
/** @package IconLibrary */

use IconLibrary\DatabaseTransaction;
use IconLibrary\OptionMutationLock;
use PHPUnit\Framework\TestCase;

class OptionMutationLockTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['icon_library_test_options'] = array();
		$GLOBALS['icon_library_test_autoload'] = array();
		$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
	}

	public function test_core_style_add_option_upsert_is_not_an_exclusive_lock() {
		$GLOBALS['icon_library_test_before_add_option'] = static function ( $name ) {
			$GLOBALS['icon_library_test_options'][ $name ] = 'other-request-token';
		};
		try {
			$this->assertTrue( add_option( 'lock', 'current-request-token' ) );
			$this->assertSame( 'current-request-token', get_option( 'lock' ) );
		} finally {
			unset( $GLOBALS['icon_library_test_before_add_option'] );
		}
	}

	public function test_fresh_contender_cannot_replace_existing_lock() {
		$GLOBALS['icon_library_test_options']['lock'] = array( 'token' => 'owner', 'started' => time() );
		$lock = new OptionMutationLock( 'lock' );
		$this->assertFalse( $lock->acquire( 30 ) );
		$this->assertSame( 'owner', get_option( 'lock' )['token'] );
	}

	public function test_absent_lock_insert_does_not_upsert_racing_owner() {
		$GLOBALS['wpdb']->before_query = static function ( $sql, $args ) {
			if ( 0 === strpos( $sql, 'INSERT IGNORE' ) && 'lock' === $args[0] ) {
				$GLOBALS['icon_library_test_options']['lock'] = array( 'token' => 'winner', 'started' => time() );
			}
		};
		$lock = new OptionMutationLock( 'lock' );
		$this->assertFalse( $lock->acquire( 30 ) );
		$this->assertSame( 'winner', get_option( 'lock' )['token'] );
		$queries = $GLOBALS['wpdb']->queries;
		$this->assertStringStartsWith( 'INSERT IGNORE', $queries[2][0] );
	}

	public function test_expired_reclaim_uses_exact_previous_value() {
		$GLOBALS['icon_library_test_options']['lock'] = array( 'token' => 'expired', 'started' => time() - 120 );
		$lock = new OptionMutationLock( 'lock' );
		$this->assertTrue( $lock->acquire( 30 ) );
		$this->assertNotSame( 'expired', get_option( 'lock' )['token'] );
		$this->assertTrue( $lock->write( 'metadata', array( 'one' ) ) );
		$this->assertTrue( $lock->commit() );
		$this->assertSame( array( 'one' ), get_option( 'metadata' ) );
		$this->assertFalse( get_option( 'lock' ) );
	}

	public function test_expired_holder_row_stays_pinned_and_old_release_cannot_remove_successor() {
		$db = $GLOBALS['wpdb'];
		$old = new OptionMutationLock( 'lock' );
		$this->assertTrue( $old->acquire( -1 ) );
		$GLOBALS['wpdb'] = new IconLibrary_Test_Option_Database();
		$contender = new OptionMutationLock( 'lock' );
		$this->assertFalse( $contender->acquire( -1 ) );
		$GLOBALS['wpdb'] = $db;
		$this->assertTrue( $old->commit() );
		$successor = new OptionMutationLock( 'lock' );
		$this->assertTrue( $successor->acquire( 30 ) );
		$token = get_option( 'lock' )['token'];
		$old->release();
		$this->assertSame( $token, get_option( 'lock' )['token'] );
		$successor->release();
	}

	public function test_failed_second_write_rolls_back_first_state_change() {
		$GLOBALS['icon_library_test_options']['one'] = array( 'original' );
		$lock = new OptionMutationLock( 'lock' );
		$this->assertTrue( $lock->acquire( 30 ) );
		$this->assertTrue( $lock->write( 'one', array( 'new' ) ) );
		$lock->release();
		$this->assertSame( array( 'original' ), get_option( 'one' ) );
		$this->assertFalse( get_option( 'lock' ) );
	}

	public function test_existing_caller_transaction_is_not_implicitly_committed() {
		$GLOBALS['wpdb']->active = true;
		$transaction = new DatabaseTransaction();
		$this->assertFalse( $transaction->begin( array( 'wp_options' ) ) );
		$this->assertTrue( $GLOBALS['wpdb']->active );
		$this->assertSame( array( array( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', array() ) ), $GLOBALS['wpdb']->queries );
		$GLOBALS['wpdb']->active = false;
	}

	public function test_failed_commit_rolls_back_and_reports_failure() {
		$lock = new OptionMutationLock( 'lock' );
		$this->assertTrue( $lock->acquire( 30 ) );
		$this->assertTrue( $lock->write( 'metadata', array( 'new' ) ) );
		$GLOBALS['wpdb']->fail_commit = true;
		$this->assertFalse( $lock->commit() );
		$this->assertFalse( get_option( 'metadata' ) );
		$this->assertFalse( $GLOBALS['wpdb']->active );
	}

	public function test_option_filters_and_correct_post_commit_notifications_are_preserved() {
		$filters = $GLOBALS['icon_library_test_filters'];
		$actions = $GLOBALS['icon_library_test_actions'];
		$events = array();
		$pre_events = array();
		$filter_calls = array();
		$GLOBALS['icon_library_test_filters']['sanitize_option_state'][] = static function ( $value ) use ( &$filter_calls ) { $filter_calls[] = 'sanitize'; return $value; };
		$GLOBALS['icon_library_test_filters']['pre_update_option_state'][] = static function ( $value ) use ( &$filter_calls ) { $filter_calls[] = 'pre_update'; return $value; };
		add_action( 'added_option', static function ( $name, $value ) use ( &$events ) { $events[] = array( 'add', $name, $value ); }, 10, 2 );
		add_action( 'update_option_state', static function ( $old, $new, $name ) use ( &$events ) { $events[] = array( 'update', $name, $old, $new ); }, 10, 3 );
		add_action( 'add_option', static function ( $name, $value ) use ( &$pre_events ) { $pre_events[] = array( 'add', $name, $value ); }, 10, 2 );
		add_action( 'update_option', static function ( $name, $old, $new ) use ( &$pre_events ) { $pre_events[] = array( 'update', $name, $old, $new ); }, 10, 3 );
		try {
			$lock = new OptionMutationLock( 'lock' );
			$this->assertTrue( $lock->acquire( 30 ) );
			$this->assertTrue( $lock->write( 'state', array( 'first' ) ) );
			$this->assertSame( array(), $events );
			$this->assertTrue( $lock->commit() );
			$this->assertSame( array( 'add', 'state', array( 'first' ) ), $events[0] );
			$this->assertTrue( $lock->acquire( 30 ) );
			$this->assertTrue( $lock->write( 'state', array( 'second' ) ) );
			$this->assertTrue( $lock->commit() );
			$this->assertSame( array( 'update', 'state', array( 'first' ), array( 'second' ) ), $events[1] );
			$this->assertSame( array( 'sanitize', 'pre_update', 'sanitize', 'pre_update' ), $filter_calls );
			$this->assertSame( array( array( 'add', 'state', array( 'first' ) ), array( 'update', 'state', array( 'first' ), array( 'second' ) ) ), $pre_events );
		} finally {
			$GLOBALS['icon_library_test_filters'] = $filters;
			$GLOBALS['icon_library_test_actions'] = $actions;
		}
	}

	public function test_failed_read_poison_prevents_fallback_writes_and_commit() {
		$GLOBALS['icon_library_test_options']['state'] = array( 'original' );
		$lock = new OptionMutationLock( 'lock' );
		$this->assertTrue( $lock->acquire( 30 ) );
		$this->assertTrue( $lock->write( 'other', array( 'uncommitted' ) ) );
		$GLOBALS['wpdb']->fail_reads = array( $GLOBALS['wpdb']->read_count + 1 );
		$this->assertFalse( $lock->read( 'state', array( 'fallback' ) ) );
		$this->assertTrue( $lock->has_failed() );
		$this->assertFalse( $lock->write( 'state', array( 'replacement' ) ) );
		$this->assertFalse( $lock->commit() );
		$this->assertSame( array( 'original' ), get_option( 'state' ) );
		$this->assertFalse( get_option( 'other' ) );
	}
}
