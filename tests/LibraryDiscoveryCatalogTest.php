<?php
/** @package IconLibrary */

use IconLibrary\LibraryDiscoveryCatalog;
use PHPUnit\Framework\TestCase;

// Transport responses use WordPress HTTP's public shape.
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? 0; }
	function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
	function wp_remote_retrieve_header( $response, $key ) { return $response['headers'][ $key ] ?? ''; }
	function wp_parse_url( $url ) { return parse_url( $url ); }
}

class LibraryDiscoveryCatalogTest extends TestCase {
	private $entry;
	private $raw;

	protected function setUp(): void {
		$GLOBALS['icon_library_test_options'] = array();
		$GLOBALS['icon_library_test_cron'] = array();
		$GLOBALS['icon_library_test_capabilities'] = array( 'manage_options' => true );
		$this->raw = json_encode( array( 'schema_version' => 1, 'library_id' => 'sample', 'style_id' => 'outline', 'release_version' => '1.0.0', 'samples' => array( array( 'label' => 'Square', 'svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>' ) ) ) );
		$this->entry = array( 'library_id' => 'sample', 'style_id' => 'outline', 'release_version' => '1.0.0', 'package_sha256' => str_repeat( 'a', 64 ), 'manifest_sha256' => str_repeat( 'b', 64 ), 'package_bytes' => 100, 'preview_sha256' => hash( 'sha256', $this->raw ), 'preview_bytes' => strlen( $this->raw ), 'discoverable' => true );
	}

	private function response( $body, $code = 200, $headers = array() ) {
		return array( 'response' => array( 'code' => $code ), 'body' => $body, 'headers' => $headers );
	}

	private function index() {
		return json_encode( array( 'schema_version' => 1, 'libraries' => array( $this->entry ) ) );
	}

	public function test_fresh_discovery_lists_reviewed_snapshot_without_network_or_install_state() {
		$calls = 0;
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( &$calls ) { ++$calls; throw new RuntimeException( 'Unexpected request' ); } );
		$this->assertCount( 1, $catalog->get_entries() );
		$this->assertSame( 'sample', $catalog->get_entries()[0]['library_id'] );
		$this->assertSame( 0, $catalog->refreshed_at() );
		$this->assertInstanceOf( WP_Error::class, $catalog->preview( 'sample', 'outline', '1.0.0', false ) );
		$this->assertSame( 0, $calls );
		$this->assertSame( array(), $GLOBALS['icon_library_test_options'] );
		$this->assertFalse( $catalog->updates_enabled() );
		$catalog->maybe_schedule_update();
		$catalog->run_scheduled_update();
		$this->assertSame( array(), $GLOBALS['icon_library_test_cron'] );
		$this->assertSame( 0, $calls );
	}

	public function test_prepared_release_can_preview_from_pinned_source_without_becoming_installable() {
		$this->raw = json_encode( array_merge( json_decode( $this->raw, true ), array( 'license' => 'Original attribution <script>data only</script>' ) ) );
		$this->entry['preview_sha256'] = hash( 'sha256', $this->raw );
		$this->entry['preview_bytes'] = strlen( $this->raw );
		$this->entry['preview_revision'] = str_repeat( 'a', 40 );
		$this->entry['availability'] = 'pending-publication';
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function ( $url ) {
			return $this->response( LibraryDiscoveryCatalog::INDEX_URL === $url ? $this->index() : $this->raw );
		} );
		$entries = $catalog->refresh();
		$this->assertSame( 'pending-publication', $entries[0]['availability'] );
		$this->assertSame( 'https://raw.githubusercontent.com/mehul0810/aculect-icon-libraries/' . str_repeat( 'a', 40 ) . '/data/previews/sample-outline-1.0.0.preview.json', $catalog->preview_url( $entries[0] ) );
		$this->assertIsArray( $catalog->preview( 'sample', 'outline', '1.0.0' ) );
		$this->assertSame( 'Original attribution <script>data only</script>', $catalog->preview_license( 'sample', 'outline', '1.0.0' ) );
		update_option( 'icon_library_preview_' . $this->entry['preview_sha256'], str_replace( 'Original', 'Modified', $this->raw ) );
		$this->assertSame( '', $catalog->preview_license( 'sample', 'outline', '1.0.0' ) );
		$altered = $this->entry;
		$altered['preview_revision'] = str_repeat( 'b', 40 );
		$bad = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( $altered ) { return $this->response( json_encode( array( 'schema_version' => 1, 'libraries' => array( $altered ) ) ) ); } );
		$this->assertInstanceOf( WP_Error::class, $bad->refresh() );
	}

	public function test_refresh_verifies_pins_and_keeps_offline_cache_on_failure() {
		$offline = false;
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function ( $url, $options ) use ( &$offline ) {
			$this->assertSame( LibraryDiscoveryCatalog::INDEX_URL, $url );
			$this->assertSame( 0, $options['redirection'] );
			$this->assertTrue( $options['sslverify'] );
			$this->assertSame( LibraryDiscoveryCatalog::MAX_INDEX + 1, $options['limit_response_size'] );
			return $offline ? new WP_Error( 'offline' ) : $this->response( $this->index() );
		} );
		$this->assertCount( 1, $catalog->refresh() );
		$before = get_option( LibraryDiscoveryCatalog::OPTION );
		$offline = true;
		$this->assertInstanceOf( WP_Error::class, $catalog->refresh() );
		$this->assertSame( $before, get_option( LibraryDiscoveryCatalog::OPTION ) );
		$this->assertCount( 1, $catalog->get_entries() );
		$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ LibraryDiscoveryCatalog::OPTION ] );
	}

	public function test_remote_hash_or_identity_changes_cannot_create_install_authority() {
		foreach ( array( 'package_sha256', 'manifest_sha256', 'preview_sha256', 'package_bytes', 'preview_bytes', 'library_id', 'style_id', 'release_version' ) as $key ) {
			$altered = $this->entry;
			$altered[ $key ] = is_int( $altered[ $key ] ) ? $altered[ $key ] + 1 : 'changed';
			$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( $altered ) { return $this->response( json_encode( array( 'schema_version' => 1, 'libraries' => array( $altered ) ) ) ); } );
			$this->assertInstanceOf( WP_Error::class, $catalog->refresh() );
			$this->assertSame( $this->entry['package_sha256'], $catalog->find( 'sample', 'outline', '1.0.0' )['package_sha256'] );
			$this->assertFalse( get_option( LibraryDiscoveryCatalog::OPTION ) );
		}
	}

	public function test_malformed_oversized_and_redirected_indexes_preserve_cache() {
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () { return $this->response( $this->index() ); } );
		$catalog->refresh();
		$before = get_option( LibraryDiscoveryCatalog::OPTION );
		foreach ( array( $this->response( '{broken' ), $this->response( str_repeat( 'x', LibraryDiscoveryCatalog::MAX_INDEX + 1 ) ), $this->response( '', 302, array( 'location' => 'https://attacker.invalid/index.json' ) ), $this->response( '', 404 ) ) as $response ) {
			$bad = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( $response ) { return $response; } );
			$this->assertInstanceOf( WP_Error::class, $bad->refresh() );
			$this->assertSame( $before, get_option( LibraryDiscoveryCatalog::OPTION ) );
		}
	}

	public function test_preview_is_a_separate_bounded_cached_request_without_install_state() {
		$calls = array();
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function ( $url, $options ) use ( &$calls ) {
			$calls[] = $url;
			if ( LibraryDiscoveryCatalog::INDEX_URL === $url ) { return $this->response( $this->index() ); }
			$this->assertStringEndsWith( '.preview.json', $url );
			$this->assertSame( LibraryDiscoveryCatalog::MAX_PREVIEW + 1, $options['limit_response_size'] );
			return $this->response( $this->raw );
		} );
		$catalog->refresh();
		$preview = $catalog->preview( 'sample', 'outline', '1.0.0' );
		$this->assertCount( 1, $preview );
		$this->assertStringContainsString( '<svg', $preview[0]['svg'] );
		$this->assertSame( $preview, $catalog->preview( 'sample', 'outline', '1.0.0' ) );
		$this->assertCount( 2, $calls );
		foreach ( array_keys( $GLOBALS['icon_library_test_options'] ) as $option ) {
			$this->assertTrue( LibraryDiscoveryCatalog::OPTION === $option || 0 === strpos( $option, 'icon_library_preview_' ) );
		}
	}

	public function test_preview_corrupt_unsafe_or_overfull_data_is_rejected_without_cache() {
		foreach ( array( 'corrupt', 'unsafe', 'overfull' ) as $case ) {
			$data = json_decode( $this->raw, true );
			if ( 'unsafe' === $case ) { $data['samples'][0]['svg'] = '<svg><script>alert(1)</script></svg>'; }
			if ( 'overfull' === $case ) { $data['samples'] = array_fill( 0, 13, $data['samples'][0] ); }
			$raw = json_encode( $data );
			$entry = $this->entry;
			$entry['preview_sha256'] = hash( 'sha256', $raw );
			$entry['preview_bytes'] = strlen( $raw );
			$GLOBALS['icon_library_test_options'] = array( LibraryDiscoveryCatalog::OPTION => array( 'libraries' => array( $entry ) ) );
			$catalog = new LibraryDiscoveryCatalog( array( $entry ), function () use ( $raw, $case ) { return $this->response( 'corrupt' === $case ? 'different' : $raw ); } );
			$this->assertInstanceOf( WP_Error::class, $catalog->preview( 'sample', 'outline', '1.0.0' ) );
			$this->assertCount( 1, $GLOBALS['icon_library_test_options'] );
		}
	}

	public function test_preview_redirect_rejects_external_hosts_and_credential_urls() {
		foreach ( array( 'https://attacker.invalid/a', 'http://release-assets.githubusercontent.com/a', 'https://user@release-assets.githubusercontent.com/a', 'https://release-assets.githubusercontent.com:444/a' ) as $url ) {
			$GLOBALS['icon_library_test_options'] = array( LibraryDiscoveryCatalog::OPTION => array( 'libraries' => array( $this->entry ) ) );
			$calls = 0;
			$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( $url, &$calls ) { ++$calls; return $this->response( '', 302, array( 'location' => $url ) ); } );
			$this->assertInstanceOf( WP_Error::class, $catalog->preview( 'sample', 'outline', '1.0.0' ) );
			$this->assertSame( 1, $calls );
		}
	}

	public function test_corrupt_preview_cache_is_read_only_until_explicit_retry_and_can_recover() {
		$key = 'icon_library_preview_' . $this->entry['preview_sha256'];
		$GLOBALS['icon_library_test_options'] = array( LibraryDiscoveryCatalog::OPTION => array( 'libraries' => array( $this->entry ) ), $key => 'broken' );
		$calls = 0;
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( &$calls ) { ++$calls; return $this->response( $this->raw ); } );
		$this->assertInstanceOf( WP_Error::class, $catalog->preview( 'sample', 'outline', '1.0.0', false ) );
		$this->assertSame( 0, $calls );
		$this->assertCount( 1, $catalog->preview( 'sample', 'outline', '1.0.0' ) );
		$this->assertSame( 1, $calls );
		$this->assertSame( $this->raw, get_option( $key ) );
	}

	public function test_background_updates_require_consent_and_schedule_once_without_blocking_views() {
		$calls = 0;
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function ( $url, $options ) use ( &$calls ) {
			++$calls;
			$this->assertSame( LibraryDiscoveryCatalog::INDEX_URL, $url );
			$this->assertSame( 5, $options['timeout'] );
			return $this->response( $this->index() );
		} );
		$catalog->set_updates_enabled( true );
		for ( $i = 0; $i < 20; ++$i ) {
			$catalog->get_entries();
			$catalog->maybe_schedule_update();
		}
		$this->assertSame( 0, $calls );
		$this->assertCount( 1, $GLOBALS['icon_library_test_cron'] );
		$this->assertGreaterThan( time(), wp_next_scheduled( LibraryDiscoveryCatalog::UPDATE_HOOK ) );
		$catalog->run_scheduled_update();
		$catalog->run_scheduled_update();
		$this->assertSame( 1, $calls );
		$this->assertFalse( $catalog->update_failed() );
		$this->assertFalse( get_option( LibraryDiscoveryCatalog::OPTION_LOCK ) );
		foreach ( array( LibraryDiscoveryCatalog::OPTION_UPDATES, LibraryDiscoveryCatalog::OPTION_ATTEMPT, LibraryDiscoveryCatalog::OPTION ) as $option ) {
			$this->assertFalse( $GLOBALS['icon_library_test_autoload'][ $option ] );
		}
		$this->assertFalse( get_option( 'icon_library_enabled_collections' ) );
	}

	public function test_offline_update_uses_snapshot_and_keeps_cache_with_hourly_backoff() {
		$calls = 0;
		$offline = true;
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( &$calls, &$offline ) {
			++$calls;
			return $offline ? new WP_Error( 'offline' ) : $this->response( $this->index() );
		} );
		$catalog->set_updates_enabled( true );
		$catalog->run_scheduled_update();
		$this->assertTrue( $catalog->update_failed() );
		$this->assertCount( 1, $catalog->get_entries() );
		$this->assertFalse( get_option( LibraryDiscoveryCatalog::OPTION ) );
		wp_clear_scheduled_hook( LibraryDiscoveryCatalog::UPDATE_HOOK );
		$catalog->maybe_schedule_update();
		$catalog->run_scheduled_update();
		$this->assertSame( 1, $calls );
		$this->assertFalse( wp_next_scheduled( LibraryDiscoveryCatalog::UPDATE_HOOK ) );
		update_option( LibraryDiscoveryCatalog::OPTION_ATTEMPT, time() - LibraryDiscoveryCatalog::RETRY_DELAY - 1 );
		$offline = false;
		$catalog->run_scheduled_update();
		$this->assertSame( 2, $calls );
		$this->assertFalse( $catalog->update_failed() );
		$before = get_option( LibraryDiscoveryCatalog::OPTION );
		$before['refreshed_at'] = time() - LibraryDiscoveryCatalog::CACHE_TTL - 1;
		update_option( LibraryDiscoveryCatalog::OPTION, $before );
		update_option( LibraryDiscoveryCatalog::OPTION_ATTEMPT, time() - LibraryDiscoveryCatalog::RETRY_DELAY - 1 );
		$offline = true;
		$catalog->run_scheduled_update();
		$this->assertSame( $before, get_option( LibraryDiscoveryCatalog::OPTION ) );
		$this->assertCount( 1, $catalog->get_entries() );
	}

	public function test_revoking_consent_cancels_pending_work_and_preserves_data() {
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () { throw new RuntimeException( 'Unexpected request' ); } );
		$catalog->set_updates_enabled( true );
		update_option( 'icon_library_enabled_collections', array( 'custom' ) );
		update_option( LibraryDiscoveryCatalog::OPTION, array( 'libraries' => array( $this->entry ), 'refreshed_at' => time() ) );
		$before = get_option( LibraryDiscoveryCatalog::OPTION );
		$catalog->set_updates_enabled( false );
		$catalog->run_scheduled_update();
		$this->assertFalse( wp_next_scheduled( LibraryDiscoveryCatalog::UPDATE_HOOK ) );
		$this->assertSame( $before, get_option( LibraryDiscoveryCatalog::OPTION ) );
		$this->assertSame( array( 'custom' ), get_option( 'icon_library_enabled_collections' ) );
	}

	public function test_unprivileged_views_and_concurrent_jobs_do_not_schedule_or_fetch() {
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () { throw new RuntimeException( 'Unexpected request' ); } );
		update_option( LibraryDiscoveryCatalog::OPTION_UPDATES, true );
		$GLOBALS['icon_library_test_capabilities'] = array();
		$catalog->maybe_schedule_update();
		$this->assertSame( array(), $GLOBALS['icon_library_test_cron'] );
		update_option( LibraryDiscoveryCatalog::OPTION_LOCK, time() );
		$catalog->run_scheduled_update();
		$this->assertFalse( get_option( LibraryDiscoveryCatalog::OPTION_ATTEMPT ) );
		$this->assertCount( 1, $catalog->get_entries() );
	}

	public function test_empty_verified_catalog_withdraws_availability_without_restoring_snapshot() {
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () { return $this->response( '{"schema_version":1,"libraries":[]}' ); } );
		update_option( 'icon_library_enabled_collections', array( 'sample' ) );
		$this->assertSame( array(), $catalog->refresh() );
		$this->assertSame( array(), $catalog->get_entries() );
		$this->assertSame( array( 'sample' ), get_option( 'icon_library_enabled_collections' ) );
	}

	public function test_duplicate_malformed_and_unsafe_url_descriptors_preserve_valid_cache() {
		$good = new LibraryDiscoveryCatalog( array( $this->entry ), function () { return $this->response( $this->index() ); } );
		$good->refresh();
		$before = get_option( LibraryDiscoveryCatalog::OPTION );
		$unsafe = array_merge( $this->entry, array( 'url' => 'https://attacker.invalid/package.zip' ) );
		$changed = array_merge( $this->entry, array( 'package_sha256' => str_repeat( 'c', 64 ) ) );
		foreach ( array( array( $this->entry, $this->entry ), array( 'broken' ), array( $unsafe ), array( $changed ), array( 'associative' => $this->entry ) ) as $entries ) {
			$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( $entries ) { return $this->response( json_encode( array( 'schema_version' => 1, 'libraries' => $entries ) ) ); } );
			$this->assertInstanceOf( WP_Error::class, $catalog->refresh() );
			$this->assertSame( $before, get_option( LibraryDiscoveryCatalog::OPTION ) );
		}
		update_option( LibraryDiscoveryCatalog::OPTION, array( 'libraries' => array( $changed ), 'refreshed_at' => time() + 100 ) );
		$this->assertSame( $this->entry['package_sha256'], $good->get_entries()[0]['package_sha256'] );
		$this->assertSame( 0, $good->refreshed_at() );
	}

	public function test_unreviewed_releases_cannot_expand_a_verified_catalog() {
		$unknown = array_merge( $this->entry, array( 'library_id' => 'unreviewed' ) );
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( $unknown ) { return $this->response( json_encode( array( 'schema_version' => 1, 'libraries' => array( $this->entry, $unknown ) ) ) ); } );
		$this->assertCount( 1, $catalog->refresh() );
		$this->assertNull( $catalog->find( 'unreviewed', 'outline', '1.0.0' ) );
	}

	public function test_wordpress_scalar_option_roundtrip_and_expired_lock_recovery() {
		$calls = 0;
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function () use ( &$calls ) { ++$calls; return $this->response( $this->index() ); } );
		update_option( LibraryDiscoveryCatalog::OPTION_UPDATES, '1' );
		update_option( LibraryDiscoveryCatalog::OPTION_LOCK, (string) ( time() - 301 ) );
		$this->assertTrue( $catalog->updates_enabled() );
		$catalog->run_scheduled_update();
		$this->assertSame( 1, $calls );
		$this->assertFalse( get_option( LibraryDiscoveryCatalog::OPTION_LOCK ) );
	}

	public function test_preview_accepts_only_pinned_data_after_bounded_github_asset_redirect() {
		$GLOBALS['icon_library_test_options'] = array( LibraryDiscoveryCatalog::OPTION => array( 'libraries' => array( $this->entry ) ) );
		$calls = 0;
		$catalog = new LibraryDiscoveryCatalog( array( $this->entry ), function ( $url ) use ( &$calls ) {
			++$calls;
			return 1 === $calls ? $this->response( '', 302, array( 'location' => 'https://release-assets.githubusercontent.com/github-production-release-asset/sample.json?token=test' ) ) : $this->response( $this->raw );
		} );
		$this->assertCount( 1, $catalog->preview( 'sample', 'outline', '1.0.0' ) );
		$this->assertSame( 2, $calls );
	}
}
