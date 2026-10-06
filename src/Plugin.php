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
	const OPTION_ENABLED_COLLECTIONS = 'icon_library_enabled_collections';
	const OPTION_ENABLED_VARIANTS    = 'icon_library_enabled_variants';
	const OPTION_LEGACY_COLLECTIONS  = 'icon_library_legacy_collections';
	const REST_NAMESPACE             = 'icon-library/v1';
	/**
	 * Optional trusted catalog injection.
	 *
	 * @var TrustedLibraryCatalog|null
	 */
	private $library_catalog;
	/**
	 * Optional package validator injection.
	 *
	 * @var LibraryPackageValidator|null
	 */
	private $library_validator;
	/**
	 * Optional transport for isolated tests.
	 *
	 * @var callable|null
	 */
	private $library_transport;
	/**
	 * Optional isolated storage directory.
	 *
	 * @var string
	 */
	private $library_storage_dir;
	/**
	 * Optional job state store injection.
	 *
	 * @var LibraryJobStore|null
	 */
	private $library_jobs;

	/**
	 * Constructor with optional service injection for isolated runtime tests.
	 *
	 * @param TrustedLibraryCatalog|null   $catalog Trusted catalog override.
	 * @param LibraryPackageValidator|null $validator Package validator override.
	 * @param callable|null                $transport Test transport override.
	 * @param string                       $storage_dir Optional isolated storage root.
	 * @param LibraryJobStore|null         $jobs Optional isolated state store.
	 */
	public function __construct( ?TrustedLibraryCatalog $catalog = null, ?LibraryPackageValidator $validator = null, $transport = null, $storage_dir = '', ?LibraryJobStore $jobs = null ) {
		$this->library_catalog     = $catalog;
		$this->library_validator   = $validator;
		$this->library_transport   = is_callable( $transport ) ? $transport : null;
		$this->library_storage_dir = is_string( $storage_dir ) ? $storage_dir : '';
		$this->library_jobs        = $jobs;
	}

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
		$library_jobs        = $this->library_jobs ? $this->library_jobs : new LibraryJobStore();
		$library_repository  = new InstalledLibraryRepository( $library_jobs, $this->library_storage_dir );
		$library_validator   = $this->library_validator ? $this->library_validator : new LibraryPackageValidator( $sanitizer );
		$library_catalog     = $this->library_catalog ? $this->library_catalog : new LibraryDiscoveryCatalog();
		$library_installer   = new LibraryInstaller( $library_catalog, $library_repository, $library_jobs, $library_validator, $this->library_transport );
		$custom_icons        = new CustomIconRepository( $sanitizer );
		$manifest_loader     = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		$collection_registry = new CollectionRegistry( $manifest_loader, $custom_icons, self::legacy_collections( $manifest_loader ) );
		$core_registrar      = new CoreIconRegistrar( $collection_registry );
		$rest_controller     = new RestController( $collection_registry, $custom_icons );
		$ability_registrar   = new AbilityRegistrar( $collection_registry );

		add_filter( 'icon_library_collection_providers', array( $library_repository, 'register_providers' ) );
		( new LibraryAdminController( $library_installer ) )->register();
		if ( $library_catalog instanceof LibraryDiscoveryCatalog ) {
			( new LibraryDiscoveryController( $library_catalog ) )->register();
		}

		// Core collections are registered only for icon REST requests or saved
		// blocks that actually need them. This avoids catalog work on public pages.
		add_filter( 'rest_pre_dispatch', array( $core_registrar, 'prepare_core_icon_request' ), 10, 3 );
		add_action( 'wp', array( $core_registrar, 'register_queried_post_icons' ) );
		add_filter( 'render_block_data', array( $core_registrar, 'register_icon_block' ) );
		add_action( 'enqueue_block_assets', array( $core_registrar, 'enqueue_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $core_registrar, 'enqueue_styles' ) );
		add_action( 'enqueue_block_editor_assets', array( $core_registrar, 'enqueue_editor_picker_compat_styles' ) );
		add_filter( 'rest_request_after_callbacks', array( $core_registrar, 'filter_core_discovery_response' ), 10, 3 );
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		$ability_registrar->register();

		if ( is_admin() ) {
			$admin_page = new AdminPage( $collection_registry, $sanitizer, $library_installer, $library_catalog instanceof LibraryDiscoveryCatalog ? $library_catalog : null );
			$admin_page->register();
			( new AdminActions( $collection_registry, $custom_icons ) )->register();
		}
	}

	/**
	 * Sets the initial enabled collections without autoloading large plugin state.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION_ENABLED_COLLECTIONS, false ) ) {
			add_option( self::OPTION_ENABLED_COLLECTIONS, array(), '', false );
			add_option( self::OPTION_LEGACY_COLLECTIONS, array(), '', false );
		}
	}

	/**
	 * Retains bundled discovery for existing sites, including skipped upgrades.
	 * No artwork is read or downloaded while recording this compatibility marker.
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
