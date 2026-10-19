<?php
/**
 * Two-stage legacy migration tests.
 *
 * @package IconLibrary
 */

use IconLibrary\LegacyAssetStore;
use IconLibrary\LegacyMigration;
use IconLibrary\Plugin;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ICON_LIBRARY_FILE' ) ) {
	define( 'ICON_LIBRARY_FILE', dirname( __DIR__ ) . '/aculect-icon-library.php' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/wp-content/plugins' );
}
if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Returns the test plugin's installed slug.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	function plugin_basename( $file ) {
		unset( $file );
		return 'aculect-icon-library/aculect-icon-library.php';
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	/** Returns the fixture's configurable network-mode state. */
	function is_multisite() {
		return ! empty( $GLOBALS['icon_library_test_multisite'] );
	}
}
if ( ! function_exists( 'wp_is_file_mod_allowed' ) ) {
	/**
	 * Allows test fixtures to exercise the migration form authorization path.
	 *
	 * @param string $context File-modification context.
	 * @return bool
	 */
	function wp_is_file_mod_allowed( $context ) {
		unset( $context );
		return true;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Keeps the isolated form test independent of a full WordPress bootstrap.
	 *
	 * @param string $text Text to escape.
	 * @return string
	 */
	function esc_html( $text ) {
		return (string) $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Keeps the isolated form test independent of a full WordPress bootstrap.
	 *
	 * @param string      $text   Text to translate and escape.
	 * @param string|null $domain Text domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = null ) {
		unset( $domain );
		return (string) $text;
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Converts a form termination into a testable exception.
	 *
	 * @param string $message Error text.
	 * @throws RuntimeException Always, to stop the test request.
	 */
	function wp_die( $message = '' ) {
		throw new RuntimeException( (string) $message );
	}
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Simulates an invalid form nonce and records its expected action.
	 *
	 * @param string $action Nonce action.
	 * @throws RuntimeException Always, to simulate nonce rejection.
	 */
	function check_admin_referer( $action ) {
		$GLOBALS['icon_library_test_nonce_action'] = $action;
		throw new RuntimeException( 'Invalid test nonce.' );
	}
}

/** Tests bridge-stage preservation and upgrade gating. */
class LegacyMigrationTest extends TestCase {
	/**
	 * Isolated fixture root.
	 *
	 * @var string
	 */
	private $root;
	/**
	 * Synthetic bundled collection directory.
	 *
	 * @var string
	 */
	private $source;
	/**
	 * Isolated persistent asset store.
	 *
	 * @var LegacyAssetStore
	 */
	private $store;
	/**
	 * Test option snapshot.
	 *
	 * @var array
	 */
	private $options;
	/**
	 * Test capability snapshot.
	 *
	 * @var array
	 */
	private $capabilities;
	/**
	 * File-modification test state snapshot.
	 *
	 * @var array
	 */
	private $file_mods;

	/** Creates the synthetic source and isolates site options. */
	protected function setUp(): void {
		$this->root   = sys_get_temp_dir() . '/icon-library-migration-' . wp_generate_password( 12, false, false );
		$this->source = $this->root . '/bundled';
		mkdir( $this->source . '/heroicons/24', 0700, true );
		file_put_contents( $this->source . '/heroicons/manifest.json', '{"slug":"heroicons","icons":[]}' );
		file_put_contents( $this->source . '/heroicons/metadata.json', '{"slug":"heroicons","iconCount":1,"variants":[]}' );
		file_put_contents( $this->source . '/heroicons/24/one.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>' );
		$this->store                               = new LegacyAssetStore( $this->root . '/uploads/icon-library/legacy-assets' );
		$this->options                             = $GLOBALS['icon_library_test_options'];
		$this->capabilities                        = $GLOBALS['icon_library_test_capabilities'];
		$this->file_mods                           = array_key_exists( 'icon_library_test_file_mods', $GLOBALS )
			? array( true, $GLOBALS['icon_library_test_file_mods'] )
			: array( false, null );
		$GLOBALS['icon_library_test_options']      = array();
		$GLOBALS['icon_library_test_capabilities'] = array();
		$GLOBALS['icon_library_test_multisite']    = false;
		$GLOBALS['icon_library_test_file_mods']    = true;
	}

	/** Cleans the fixture and restores shared test state. */
	protected function tearDown(): void {
		$GLOBALS['icon_library_test_options']      = $this->options;
		$GLOBALS['icon_library_test_capabilities'] = $this->capabilities;
		$GLOBALS['icon_library_test_multisite']    = false;
		if ( $this->file_mods[0] ) {
			$GLOBALS['icon_library_test_file_mods'] = $this->file_mods[1];
		} else {
			unset( $GLOBALS['icon_library_test_file_mods'] );
		}
		$this->remove_tree( $this->root );
	}

	/** Records legacy slugs only after verified artwork is copied. */
	public function test_explicit_prepare_copies_before_recording_marker_and_preserves_other_state() {
		$enabled  = array( 'heroicons' );
		$variants = array( 'heroicons' => array( 'outline' ) );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = $enabled;
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_VARIANTS ]    = $variants;
		$migration = new LegacyMigration( $this->store, $this->source );
		$this->assertTrue( $migration->prepare() );
		$this->assertSame( $enabled, get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertSame( $variants, get_option( Plugin::OPTION_ENABLED_VARIANTS ) );
		$this->assertSame( array( 'heroicons' ), get_option( Plugin::OPTION_LEGACY_COLLECTIONS ) );
		$this->assertTrue( $migration->status()['ready'] );
	}

	/** Uses preserved files when the bundle is no longer present. */
	public function test_persistent_factory_loads_artwork_after_bundled_source_is_gone() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_LEGACY_COLLECTIONS ]  = array( 'heroicons' );
		$migration = new LegacyMigration( $this->store, $this->source );
		$this->assertTrue( $migration->prepare() );
		$this->remove_tree( $this->source );
		$loader = LegacyMigration::legacy_loader( $this->store, $this->source );
		$path   = $loader->get_svg_path( 'heroicons', '24/one.svg' );
		$this->assertSame( realpath( $this->store->resolve_root() . '/heroicons/24/one.svg' ), $path );
		$this->assertSame( '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>', file_get_contents( $path ) );
	}

	/** Distinguishes fresh sites from unmarked legacy sites missing their source. */
	public function test_fresh_site_is_noop_and_old_site_without_source_is_not_marked_empty() {
		$migration = new LegacyMigration( $this->store, $this->root . '/absent' );
		$this->assertTrue( $migration->prepare() );
		$this->assertSame( array(), $migration->status()['required'] );
		$this->assertFalse( array_key_exists( Plugin::OPTION_LEGACY_COLLECTIONS, $GLOBALS['icon_library_test_options'] ) );
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		$status = $migration->status();
		$this->assertFalse( $status['ready'] );
		$this->assertTrue( $status['legacy_unknown'] );
		$this->assertInstanceOf( WP_Error::class, $migration->prepare() );
		$this->assertFalse( array_key_exists( Plugin::OPTION_LEGACY_COLLECTIONS, $GLOBALS['icon_library_test_options'] ) );
	}

	/** Blocks only matching plugin updates when preservation fails. */
	public function test_matching_update_is_blocked_on_preservation_failure_and_other_plugin_is_untouched() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array();
		if ( ! symlink( $this->source . '/heroicons/manifest.json', $this->source . '/heroicons/link.json' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable in this test environment.' );
		}
		$migration = new LegacyMigration( $this->store, $this->source );
		$extra     = array(
			'plugin'      => 'other-plugin/plugin.php',
			'temp_backup' => $this->temp_backup( 'other-plugin/plugin.php' ),
		);
		$this->assertSame( 'sentinel', $migration->upgrader_pre_install( 'sentinel', $extra ) );
		$target = 'aculect-icon-library/aculect-icon-library.php';
		$this->assertSame(
			'sentinel',
			$migration->upgrader_pre_install(
				'sentinel',
				array_merge(
					$this->temp_backup( $target ),
					array(
						'plugin' => $target,
						'type'   => 'plugin',
						'action' => 'install',
					)
				)
			)
		);
		$result = $migration->upgrader_pre_install(
			null,
			array_merge( $this->temp_backup( $target ), array( 'plugin' => $target ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$result = $migration->upgrader_pre_install(
			null,
			array_merge(
				$this->temp_backup( $target ),
				array(
					'plugin' => $target,
					'type'   => 'plugin',
					'action' => 'update',
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$result = $migration->upgrader_pre_install(
			null,
			array(
				'plugin' => $target,
				'type'   => 'plugin',
				'action' => 'update',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertFalse( $this->store->is_ready() );
		$this->assertSame( array(), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
		$this->assertFalse( array_key_exists( Plugin::OPTION_LEGACY_COLLECTIONS, $GLOBALS['icon_library_test_options'] ) );
	}

	/** Refuses network-shared plugin updates without complete network preflight. */
	public function test_multisite_update_fails_closed_without_changing_state() {
		$GLOBALS['icon_library_test_options'][ Plugin::OPTION_ENABLED_COLLECTIONS ] = array( 'heroicons' );
		$GLOBALS['icon_library_test_multisite']                                     = true;
		$migration = new LegacyMigration( $this->store, $this->source );
		$result    = $migration->upgrader_pre_install(
			null,
			array_merge(
				$this->temp_backup( 'aculect-icon-library/aculect-icon-library.php' ),
				array( 'plugin' => 'aculect-icon-library/aculect-icon-library.php' )
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_multisite_preflight_required', $result->get_error_code() );
		$this->assertFalse( $this->store->is_ready() );
		$this->assertSame( array( 'heroicons' ), get_option( Plugin::OPTION_ENABLED_COLLECTIONS ) );
	}

	/** Builds WordPress 7.1 bulk-upgrade backup metadata for a plugin basename.
	 *
	 * @param string $plugin Plugin basename.
	 * @return array
	 */
	private function temp_backup( $plugin ) {
		return array(
			'temp_backup' => array(
				'slug' => dirname( $plugin ),
				'src'  => WP_PLUGIN_DIR,
				'dir'  => 'plugins',
			),
		);
	}

	/** The admin endpoint rejects GET and invalid nonces before doing filesystem work. */
	public function test_admin_form_requires_post_and_nonce_before_copy() {
		$GLOBALS['icon_library_test_capabilities'] = array(
			'manage_options'  => true,
			'install_plugins' => true,
		);
		$migration                                 = new LegacyMigration( $this->store, $this->source );
		$old_method                                = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD']                 = 'GET';
		try {
			$migration->submit_form();
			$this->fail( 'GET must be rejected.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'requires a form submission', $exception->getMessage() );
		}
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			$migration->submit_form();
			$this->fail( 'An invalid nonce must be rejected.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'Invalid test nonce.', $exception->getMessage() );
		}
		if ( null === $old_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $old_method;
		}
		$this->assertSame( 'icon_library_preserve_legacy', $GLOBALS['icon_library_test_nonce_action'] );
		$this->assertFalse( $this->store->is_ready() );
		$this->assertFalse( array_key_exists( Plugin::OPTION_LEGACY_COLLECTIONS, $GLOBALS['icon_library_test_options'] ) );
	}

	/**
	 * Removes only test-owned fixture files.
	 *
	 * @param string $path Fixture path.
	 */
	private function remove_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$this->remove_tree( $path . '/' . $item );
			}
		}
		rmdir( $path );
	}
}
