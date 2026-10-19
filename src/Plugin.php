<?php
/**
 * Plugin composition root.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin services to WordPress hooks.
 */
class Plugin {
	const OPTION_ENABLED_COLLECTIONS   = 'icon_library_enabled_collections';
	const OPTION_INSTALLED_COLLECTIONS = 'icon_library_installed_collections';
	const OPTION_ENABLED_VARIANTS      = 'icon_library_enabled_variants';
	const OPTION_LEGACY_COLLECTIONS    = 'icon_library_legacy_collections';
	const REST_NAMESPACE               = 'icon-library/v1';
	/** Bootstrap callback used by the plugin file and removable by isolated runtime harnesses. */
	public static function bootstrap() {
		$plugin = new self();
		$plugin->register();
	}

	/**
	 * Registers WordPress hooks.
	 */
	public function register() {
		$sanitizer           = new SvgSanitizer();
		$library_jobs        = new LibraryJobStore();
		$library_repository  = new InstalledLibraryRepository( $library_jobs );
		$custom_icons        = new CustomIconRepository( $sanitizer );
		$manifest_loader     = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$collection_registry = new CollectionRegistry( $manifest_loader, $custom_icons );
		$core_registrar      = new CoreIconRegistrar( $collection_registry );
		$rest_controller     = new RestController( $collection_registry, $custom_icons, $core_registrar );
		$ability_registrar   = new AbilityRegistrar( $collection_registry );

		add_filter( 'icon_library_collection_providers', array( $library_repository, 'register_providers' ) );

		// Core collections are registered only for icon REST requests or saved
		// blocks that actually need them. This avoids catalog work on public pages.
		add_filter( 'rest_pre_dispatch', array( $core_registrar, 'prepare_core_icon_request' ), 10, 3 );
		add_filter( 'rest_pre_echo_response', array( $core_registrar, 'release_bulk_response_state' ), PHP_INT_MAX, 3 );
		add_action( 'wp', array( $core_registrar, 'register_queried_post_icons' ) );
		add_filter( 'render_block_data', array( $core_registrar, 'register_icon_block' ) );
		add_action( 'enqueue_block_assets', array( $core_registrar, 'enqueue_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $core_registrar, 'enqueue_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $core_registrar, 'enqueue_editor_picker_compat_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $core_registrar, 'enqueue_bounded_picker' ) );
		add_filter( 'rest_request_after_callbacks', array( $core_registrar, 'filter_core_discovery_response' ), 10, 3 );
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		$ability_registrar->register();

		if ( is_admin() ) {
			$admin_page = new AdminPage( $collection_registry, $sanitizer );
			$admin_page->register();
			( new AdminActions( $collection_registry, $custom_icons ) )->register();
		}
	}

	/**
	 * Sets the initial enabled collections without autoloading large plugin state.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_INSTALLED_COLLECTIONS, false ) ) {
			add_option( self::OPTION_INSTALLED_COLLECTIONS, array(), '', false );
		}
		if ( false === get_option( self::OPTION_ENABLED_COLLECTIONS, false ) ) {
			add_option( self::OPTION_ENABLED_COLLECTIONS, array(), '', false );
			add_option( self::OPTION_LEGACY_COLLECTIONS, array(), '', false );
		}
	}

	/**
	 * Returns the pre-upgrade bundled inventory used for provider ownership.
	 * Missing markers are inferred and recorded for the deferred migration API.
	 *
	 * @param ManifestLoader $loader Bundled metadata loader.
	 * @return string[]
	 */
	public static function legacy_collections( ManifestLoader $loader ) {
		$legacy = get_option( self::OPTION_LEGACY_COLLECTIONS, false );
		if ( false === $legacy ) {
			$legacy = false === get_option( self::OPTION_ENABLED_COLLECTIONS, false ) ? array() : $loader->get_collection_slugs();
			add_option( self::OPTION_LEGACY_COLLECTIONS, $legacy, '', false );
		}
		return is_array( $legacy ) ? $legacy : array();
	}
}
