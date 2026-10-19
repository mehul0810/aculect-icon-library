<?php
/**
 * Coordinates explicit and pre-upgrade preservation of bundled artwork.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preserves legacy collections before bundled files can be replaced.
 */
class LegacyMigration {
	/**
	 * Request-local loaders keyed by site and bundled source root.
	 *
	 * @var ManifestLoader[]
	 */
	private static $legacy_loader_cache = array();

	/**
	 * Persistent store.
	 *
	 * @var LegacyAssetStore
	 */
	private $store;
	/**
	 * Bundled source root.
	 *
	 * @var string
	 */
	private $source_root;
	/**
	 * Source metadata loader.
	 *
	 * @var ManifestLoader
	 */
	private $source_loader;

	/**
	 * Returns the legacy loader rooted in preserved uploads when coverage is ready.
	 * This preservation-only API intentionally lives outside the shipped composition root.
	 *
	 * @param LegacyAssetStore|null $store Persistent store override.
	 * @param string|null           $source_root Bundled root override.
	 * @return ManifestLoader
	 */
	public static function legacy_loader( ?LegacyAssetStore $store = null, $source_root = null ) {
		$cache_loader = null === $store;
		$source_root  = is_string( $source_root ) ? $source_root : ICON_LIBRARY_DIR . 'assets/icons';
		$cache_key    = ( function_exists( 'get_current_blog_id' ) ? (string) get_current_blog_id() : '1' ) . ':' . $source_root;
		if ( $cache_loader && isset( self::$legacy_loader_cache[ $cache_key ] ) ) {
			return self::$legacy_loader_cache[ $cache_key ];
		}
		$bundled = new ManifestLoader( $source_root );
		$legacy  = Plugin::legacy_collections( $bundled );
		if ( empty( $legacy ) ) {
			if ( $cache_loader ) {
				self::$legacy_loader_cache[ $cache_key ] = $bundled;
			}
			return $bundled;
		}
		$store  = $store ? $store : new LegacyAssetStore();
		$status = $store->status();
		if ( $status['ready'] && empty( array_diff( $legacy, $status['collections'] ) ) ) {
			$root = $status['root'];
			if ( is_string( $root ) ) {
				$loader = new ManifestLoader( $root );
				if ( $cache_loader ) {
					self::$legacy_loader_cache[ $cache_key ] = $loader;
				}
				return $loader;
			}
		}
		if ( $cache_loader ) {
			self::$legacy_loader_cache[ $cache_key ] = $bundled;
		}
		return $bundled;
	}

	/** Invalidates source-selection hints after a marker or store mutation. */
	public static function clear_legacy_loader_cache() {
		self::$legacy_loader_cache = array();
	}

	/**
	 * Creates the migration coordinator with optional test fixtures.
	 *
	 * @param LegacyAssetStore|null $store         Persistent asset store.
	 * @param string|null           $source_root   Bundled collection directory.
	 * @param ManifestLoader|null   $source_loader Source metadata loader.
	 */
	public function __construct( ?LegacyAssetStore $store = null, $source_root = null, ?ManifestLoader $source_loader = null ) {
		$this->source_root   = is_string( $source_root ) ? $source_root : ICON_LIBRARY_DIR . 'assets/icons';
		$this->source_loader = $source_loader ? $source_loader : new ManifestLoader( $this->source_root );
		$this->store         = $store ? $store : new LegacyAssetStore();
	}

	/** Registers the explicit admin preflight and plugin-update guard. */
	public function register() {
		add_action( 'admin_post_icon_library_preserve_legacy', array( $this, 'submit_form' ) );
		add_filter( 'upgrader_pre_install', array( $this, 'upgrader_pre_install' ), 5, 2 );
	}

	/**
	 * Performs the complete preservation preflight for this site.
	 *
	 * @return true|\WP_Error
	 */
	public function prepare() {
		$markers = get_option( Plugin::OPTION_LEGACY_COLLECTIONS, false );
		$enabled = get_option( Plugin::OPTION_ENABLED_COLLECTIONS, false );
		if ( is_array( $markers ) ) {
			$required = array_values( array_unique( $markers ) );
			if ( empty( $required ) ) {
				return true;
			}
		} elseif ( false === $enabled ) {
			return true;
		} else {
			$required = $this->source_loader->get_collection_slugs();
			if ( empty( $required ) ) {
				return $this->error( 'legacy_source_missing', 'Legacy bundled artwork cannot be found. Do not continue with a lean-plugin update until every affected site has completed a preservation preflight.' );
			}
		}

		$status  = $this->store->status();
		$covered = $status['ready'] && empty( array_diff( $required, $status['collections'] ) );
		if ( ! $covered || ! $this->store->is_ready( true ) ) {
			$result = $this->store->preserve( $this->source_root, $required );
			if ( is_wp_error( $result ) ) {
				self::clear_legacy_loader_cache();
				return $result;
			}
		}
		if ( ! $this->store->is_ready( true ) ) {
			self::clear_legacy_loader_cache();
			return $this->error( 'legacy_verification_failed', 'The preserved legacy artwork did not pass its full integrity check.' );
		}
		$coverage = $this->store->status()['collections'];
		if ( array_diff( $required, $coverage ) ) {
			self::clear_legacy_loader_cache();
			return $this->error( 'legacy_coverage_incomplete', 'The preserved artwork does not cover every legacy collection recorded for this site.' );
		}

		if ( ! is_array( $markers ) ) {
			$recorded = Plugin::legacy_collections( $this->source_loader );
			if ( array_diff( $required, $recorded ) ) {
				self::clear_legacy_loader_cache();
				return $this->error( 'legacy_marker_failed', 'Artwork was preserved, but this site could not record its legacy collection list. Retry the preflight before updating the plugin.' );
			}
		}
		self::clear_legacy_loader_cache();
		return true;
	}

	/** Returns read-only preflight state for CLI and owner-facing UI. */
	public function status() {
		$markers          = get_option( Plugin::OPTION_LEGACY_COLLECTIONS, false );
		$enabled          = get_option( Plugin::OPTION_ENABLED_COLLECTIONS, false );
		$required         = is_array( $markers ) ? array_values( array_unique( $markers ) ) : ( false === $enabled ? array() : $this->source_loader->get_collection_slugs() );
		$source_available = is_dir( $this->source_root ) && ! is_link( $this->source_root );
		$store_status     = $this->store->status();
		$coverage         = $store_status['collections'];
		$legacy_unknown   = ! is_array( $markers ) && false !== $enabled && empty( $required );
		$ready            = ! $legacy_unknown && ( empty( $required ) || ( $store_status['ready'] && ! array_diff( $required, $coverage ) ) );
		return array(
			'ready'                  => $ready,
			'fresh'                  => false === $enabled && ( ! is_array( $markers ) || empty( $markers ) ),
			'required'               => $required,
			'coverage'               => $coverage,
			'source_available'       => $source_available,
			'needs_preflight'        => ! $ready && ! empty( $required ),
			'missing_source'         => ( ! $source_available && ! empty( $required ) ) || $legacy_unknown,
			'legacy_unknown'         => $legacy_unknown,
			'network_update_blocked' => function_exists( 'is_multisite' ) && is_multisite(),
		);
	}

	/**
	 * Stops a matching plugin update when preservation cannot complete first.
	 *
	 * @param mixed $response Existing upgrader response.
	 * @param array $hook_extra Upgrade target metadata.
	 * @return mixed|\WP_Error
	 */
	public function upgrader_pre_install( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || ! $this->is_target_plugin_update( $hook_extra ) ) {
			return $response;
		}
		if ( is_multisite() ) {
			return $this->error( 'multisite_preflight_required', 'This plugin update affects shared files on a multisite network. This bridge does not implement a network-wide all-sites preflight, so plugin updates remain blocked on multisite.' );
		}
		$result = $this->prepare();
		return is_wp_error( $result ) ? $result : $response;
	}

	/** Handles an explicit administrator POST request; GET requests never write. */
	public function submit_form() {
		$permission = LibraryAdminController::installation_permission();
		if ( is_wp_error( $permission ) ) {
			wp_die( esc_html( $permission->get_error_message() ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Legacy artwork preservation requires a form submission.', 'aculect-icon-library' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'icon_library_preserve_legacy' );
		$result = $this->prepare();
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => AdminPage::MENU_SLUG,
					'legacy-preserved' => 1,
				),
				admin_url( 'themes.php' )
			)
		);
		exit;
	}

	/**
	 * Determines whether the upgrader targets this plugin and is doing an update.
	 *
	 * @param mixed $hook_extra Upgrader target metadata.
	 * @return bool
	 */
	private function is_target_plugin_update( $hook_extra ) {
		if ( ! is_array( $hook_extra ) ) {
			return false;
		}
		$basename = function_exists( 'plugin_basename' ) ? plugin_basename( ICON_LIBRARY_FILE ) : 'aculect-icon-library/aculect-icon-library.php';
		if ( ( $hook_extra['plugin'] ?? '' ) !== $basename ) {
			return false;
		}
		if ( isset( $hook_extra['type'] ) || isset( $hook_extra['action'] ) ) {
			return 'plugin' === ( $hook_extra['type'] ?? '' ) && 'update' === ( $hook_extra['action'] ?? '' );
		}
		// WordPress 7.1 bulk_upgrade() omits type/action but supplies temp_backup.
		return $this->has_valid_temp_backup( $hook_extra, $basename );
	}

	/**
	 * Checks the WordPress plugin-update backup metadata shape.
	 *
	 * @param array  $hook_extra Upgrader target metadata.
	 * @param string $basename  Exact plugin basename.
	 * @return bool
	 */
	private function has_valid_temp_backup( $hook_extra, $basename ) {
		$backup = $hook_extra['temp_backup'] ?? null;
		return is_array( $backup )
			&& dirname( $basename ) === ( $backup['slug'] ?? null )
			&& defined( 'WP_PLUGIN_DIR' )
			&& WP_PLUGIN_DIR === ( $backup['src'] ?? null )
			&& 'plugins' === ( $backup['dir'] ?? null );
	}

	/**
	 * Builds a migration-specific actionable error.
	 *
	 * @param string $code    Error code suffix.
	 * @param string $message Actionable message.
	 * @return \WP_Error
	 */
	private function error( $code, $message ) {
		return new \WP_Error( 'icon_library_' . $code, $message );
	}
}
