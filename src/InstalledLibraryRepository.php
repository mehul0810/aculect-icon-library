<?php
/** Persistent immutable package storage and provider adapter.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Stores package members under unique immutable per-site version directories. */
class InstalledLibraryRepository {
	/** Authoritative job and installation state.
	 *
	 * @var LibraryJobStore
	 */
	private $jobs;
	/** Optional fixed storage root for isolated tests.
	 *
	 * @var string
	 */
	private $base_override = '';
	/** Paths exposed by manifests in this request.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $allowed_paths = array();
	/** Small per-site provider hints; large manifests remain lazy and bounded.
	 *
	 * @var array
	 */
	private $provider_definitions = array();
	/**
	 * Complete per-site ID lists are only needed for catalog discovery.
	 *
	 * @var array<int,string[]>
	 */
	private $provider_ids = array();

	/** Creates a repository for site-local immutable package storage.
	 *
	 * @param LibraryJobStore $jobs Durable state store.
	 * @param string          $base_dir Optional isolated storage root.
	 */
	public function __construct( LibraryJobStore $jobs, $base_dir = '' ) {
		$this->jobs          = $jobs;
		$this->base_override = is_string( $base_dir ) && '' !== $base_dir ? untrailingslashit( $base_dir ) : '';
		add_action( 'icon_library_installed_state_changed', array( $this, 'clear_request_provider_cache' ) );
	}

	/** Publishes validated package members into immutable versioned storage.
	 *
	 * @param string $library_id Library slug.
	 * @param string $style_id Style slug.
	 * @param string $version Release version.
	 * @param string $package_hash SHA-256 package digest.
	 * @param string $job_id Job UUID.
	 * @param string $source_dir Validated staging directory.
	 * @param array  $members Validated member paths.
	 * @return string|\WP_Error
	 */
	public function publish( $library_id, $style_id, $version, $package_hash, $job_id, $source_dir, $members ) {
		return ( new LibraryPackagePublisher( $this->base_directory(), $this->base_override ) )->publish( $library_id, $style_id, $version, $package_hash, $job_id, $source_dir, $members );
	}

	/** Adds immutable installed collections to the provider boundary.
	 *
	 * @param array         $providers Registered collection providers.
	 * @param string[]|null $slugs      Optional scope for a saved-name or SVG lookup.
	 * @return array
	 */
	public function register_providers( $providers, $slugs = null ) {
		$providers = is_array( $providers ) ? $providers : array();
		$slugs     = is_array( $slugs ) ? $slugs : null;
		$legacy    = get_option( Plugin::OPTION_LEGACY_COLLECTIONS, false );
		if ( false === $legacy ) {
			$legacy = false === get_option( Plugin::OPTION_ENABLED_COLLECTIONS, false ) ? array() : ( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) )->get_collection_slugs();
		}
		$legacy  = is_array( $legacy ) ? $legacy : array();
		$site_id = $this->current_site_id();
		if ( null === $slugs && ! isset( $this->provider_ids[ $site_id ] ) ) {
			$this->provider_ids[ $site_id ] = $this->jobs->all_library_ids();
		}
		$known = $this->provider_ids[ $site_id ] ?? $this->jobs->cached_library_ids();
		if ( null !== $slugs && is_array( $known ) ) {
			$slugs = array_intersect( $slugs, $known );
		}
		foreach ( $slugs ?? $this->provider_ids[ $site_id ] as $library_id ) {
			if ( isset( $providers[ $library_id ] ) || in_array( $library_id, $legacy, true ) || CustomIconRepository::COLLECTION_SLUG === $library_id ) {
				continue;
			}
			if ( ! array_key_exists( $library_id, $this->provider_definitions[ $site_id ] ?? array() ) ) {
				$state = $this->jobs->read( $library_id );
				$this->provider_definitions[ $site_id ][ $library_id ] = null;
				if ( empty( $state['value']['styles'] ) ) {
					continue;
				}
				$this->provider_definitions[ $site_id ][ $library_id ] = array(
					'owner'    => 'aculect-icon-library-installed',
					'retained' => ! array_filter(
						$state['value']['styles'],
						static function ( $record ) {
							return is_array( $record ) && empty( $record['uninstalled'] ); }
					),
					'manifest' => function () use ( $library_id ) {
						return $this->get_manifest( $library_id ); },
					'svg_path' => function ( $path ) use ( $library_id ) {
						return $this->get_svg_path( $library_id, $path ); },
				);
			}
			if ( isset( $this->provider_definitions[ $site_id ][ $library_id ] ) ) {
				$providers[ $library_id ] = $this->provider_definitions[ $site_id ][ $library_id ];
			}
		}
		return $providers;
	}

	/** Invalidates request-local provider hints after an authoritative CAS. */
	public function clear_request_provider_cache() {
		$this->provider_definitions = array();
		$this->provider_ids         = array();
	}

	/** Returns authoritative installation state for one library.
	 *
	 * @param string $library_id Library slug.
	 * @param bool   $include_uninstalled Include retained styles for ownership checks.
	 * @return array|null
	 */
	public function get_installed( $library_id, $include_uninstalled = false ) {
		$state = $this->jobs->read( $library_id );
		if ( ! $state || empty( $state['value']['styles'] ) ) {
			return null; }
		$styles         = array();
		$archived_count = 0;
		foreach ( $state['value']['styles'] as $style => $record ) {
			if ( ! is_array( $record ) || ( ! $include_uninstalled && ! empty( $record['uninstalled'] ) ) ) {
				continue; }
			$styles[ $style ] = array(
				'release_version' => $record['release_version'],
				'package_sha256'  => $record['package_sha256'],
				'installed_at'    => $record['installed_at'],
			);
			$archived_count  += count( $record['archived_icons'] ?? array() );
		}
		if ( ! $styles ) {
			return null;
		}
		return array(
			'library_id'     => $library_id,
			'styles'         => $styles,
			'archived_count' => $archived_count,
		);
	}

	/** Returns the installed Core-compatible manifest for one library.
	 *
	 * @param string $library_id Library slug.
	 * @return array|null
	 */
	public function get_manifest( $library_id ) {
		$site_id                                        = $this->current_site_id();
		$this->allowed_paths[ $site_id ][ $library_id ] = array();
		$state = $this->jobs->read( $library_id );
		if ( ! $state || empty( $state['value']['styles'] ) ) {
			return null; }
		$manifest = null;
		$active   = array_filter(
			$state['value']['styles'],
			static function ( $record ) {
				return is_array( $record ) && empty( $record['uninstalled'] );
			}
		);
		foreach ( $state['value']['styles'] as $style => $record ) {
			$style_manifest = $record['manifest'] ?? null;
			if ( ! is_array( $style_manifest ) ) {
				continue; }
			if ( null === $manifest ) {
				$manifest = array(
					'schemaVersion' => 2,
					'slug'          => $library_id,
					'name'          => $style_manifest['name'],
					'description'   => __( 'Installed locally from a trusted versioned package.', 'aculect-icon-library' ),
					'version'       => $active ? 'installed' : 'retained',
					'license'       => array(
						'name' => 'Package license',
						'url'  => '',
					),
					'source'        => array(
						'name' => 'Installed package',
						'url'  => '',
					),
					'variants'      => array(),
					'icons'         => array(),
				);
			}
			if ( empty( $record['uninstalled'] ) ) {
				$manifest['variants'][] = array(
					'slug'           => $style,
					'label'          => ucwords( str_replace( '-', ' ', $style ) ),
					'defaultEnabled' => false,
				);
			}
			foreach ( (array) ( $style_manifest['icons'] ?? array() ) as $icon ) {
				if ( ! empty( $record['uninstalled'] ) ) {
					$icon['archived'] = true;
				}
				$manifest['icons'][] = $icon;
				if ( is_string( $icon['path'] ?? null ) ) {
					$this->allowed_paths[ $site_id ][ $library_id ][ $icon['path'] ] = true; }
			}
			foreach ( (array) ( $record['archived_icons'] ?? array() ) as $icon ) {
				$manifest['icons'][] = $icon;
				if ( is_string( $icon['path'] ?? null ) ) {
					$this->allowed_paths[ $site_id ][ $library_id ][ $icon['path'] ] = true; }
			}
		}
		return $manifest;
	}

	/** Resolves a previously exposed SVG path within immutable storage.
	 *
	 * @param string $library_id Library slug.
	 * @param string $relative Relative manifest path.
	 * @return string|null
	 */
	public function get_svg_path( $library_id, $relative ) {
		if ( ! is_string( $relative ) || empty( $this->allowed_paths[ $this->current_site_id() ][ $library_id ][ $relative ] ) || 1 !== preg_match( '#^[a-z0-9-]+/[a-f0-9]{64}/icons/[A-Za-z0-9._/-]+\.svg$#', $relative ) || preg_match( '#(?:^|/)\.\.(?:/|$)|//|(?:^|/)\.(?:/|$)#', $relative ) ) {
			return null; }
		$storage      = $this->base_directory();
		$library_dir  = $storage . '/' . $library_id;
		$library_base = realpath( $library_dir );
		$cursor       = $library_dir;
		foreach ( explode( '/', $relative ) as $part ) {
			$cursor .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $cursor ) ) {
				return null; }
		}
		$path = realpath( $library_dir . '/' . $relative );
		if ( ! $library_base || ! $path || is_link( $storage ) || is_link( $library_dir ) || 0 !== strpos( $path, $library_base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
			return null; }
		return $path;
	}


	/** Returns the site-local private package storage root.
	 *
	 * @return string
	 */
	private function base_directory() {
		if ( $this->base_override ) {
			return $this->base_override; }
		$uploads = wp_upload_dir();
		return untrailingslashit( $uploads['basedir'] ) . '/aculect-icon-library';
	}

	/** Returns the current WordPress site identity for request-local path grants.
	 *
	 * @return int
	 */
	private function current_site_id() {
		return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	}
}
