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

	/** Creates a repository for site-local immutable package storage.
	 *
	 * @param LibraryJobStore $jobs Durable state store.
	 * @param string          $base_dir Optional isolated storage root.
	 */
	public function __construct( LibraryJobStore $jobs, $base_dir = '' ) {
		$this->jobs          = $jobs;
		$this->base_override = is_string( $base_dir ) && '' !== $base_dir ? untrailingslashit( $base_dir ) : '';
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
		$relative     = $library_id . '/' . $style_id . '/' . $package_hash;
		$base         = $this->base_directory();
		$target       = $base . '/' . $relative;
		$uploads      = wp_upload_dir();
		$uploads_real = realpath( $this->base_override ? dirname( $base ) : untrailingslashit( $uploads['basedir'] ) );
		if ( ! $uploads_real || is_link( $base ) || ( ! file_exists( $base ) && ! wp_mkdir_p( $base ) ) ) {
			return new \WP_Error( 'icon_library_storage', __( 'The private library storage path is not safe.', 'aculect-icon-library' ) ); }
		$base_real = realpath( $base );
		if ( ! $base_real || 0 !== strpos( $base_real, $uploads_real . DIRECTORY_SEPARATOR ) || ! $this->has_safe_parents( $base, $relative ) ) {
			return new \WP_Error( 'icon_library_storage', __( 'The private library storage path is not safe.', 'aculect-icon-library' ) ); }
		if ( is_link( $target ) || file_exists( $target ) && ! is_dir( $target ) ) {
			return new \WP_Error( 'icon_library_storage', __( 'The immutable package location is not a regular directory.', 'aculect-icon-library' ) ); }
		if ( is_dir( $target ) ) {
			foreach ( $members as $member ) {
				$path   = $target . '/' . $member;
				$source = $source_dir . '/' . $member;
				if ( ! is_file( $path ) || is_link( $path ) || ! is_file( $source ) || is_link( $source ) || hash_file( 'sha256', $path ) !== hash_file( 'sha256', $source ) ) {
					return new \WP_Error( 'icon_library_storage', __( 'The existing immutable package directory failed verification.', 'aculect-icon-library' ) ); }
			}
			return $target;
		}
		$parent = dirname( $target );
		if ( ! wp_mkdir_p( $parent ) || ! $this->has_safe_parents( $base, $relative ) ) {
			return new \WP_Error( 'icon_library_storage', __( 'The private library storage directory could not be created safely.', 'aculect-icon-library' ) ); }
		$this->protect_directory( $base );
		$stage = $parent . '/.attempt-' . sanitize_file_name( $job_id ) . '-' . wp_generate_password( 10, false, false );
		if ( ! wp_mkdir_p( $stage ) ) {
			return new \WP_Error( 'icon_library_storage', __( 'The package staging directory could not be created.', 'aculect-icon-library' ) ); }
		try {
			foreach ( $members as $member ) {
				if ( ! is_string( $member ) || ! $this->safe_member( $member ) ) {
					return new \WP_Error( 'icon_library_storage', __( 'The validated package could not be stored.', 'aculect-icon-library' ) ); }
				$source      = $source_dir . '/' . $member;
				$source_real = realpath( $source );
				$source_base = realpath( $source_dir );
				if ( ! $source_real || ! $source_base || 0 !== strpos( $source_real, $source_base . DIRECTORY_SEPARATOR ) || ! is_file( $source_real ) || is_link( $source ) ) {
					return new \WP_Error( 'icon_library_storage', __( 'The validated package staging path is invalid.', 'aculect-icon-library' ) ); }
				$path = $stage . '/' . $member;
				if ( ! wp_mkdir_p( dirname( $path ) ) ) {
					return new \WP_Error( 'icon_library_storage', __( 'The package directory could not be created.', 'aculect-icon-library' ) ); }
				$copied = copy( $source_real, $path );
				if ( ! $copied || filesize( $path ) !== filesize( $source_real ) || hash_file( 'sha256', $path ) !== hash_file( 'sha256', $source_real ) ) {
					return new \WP_Error( 'icon_library_storage', __( 'The validated package could not be written completely.', 'aculect-icon-library' ) ); }
			}
			$this->protect_directory( $stage );
			if ( ! @rename( $stage, $target ) ) {
				if ( is_dir( $target ) && ! is_link( $target ) ) {
					foreach ( $members as $member ) {
						if ( ! is_file( $target . '/' . $member ) || is_link( $target . '/' . $member ) || hash_file( 'sha256', $target . '/' . $member ) !== hash_file( 'sha256', $source_dir . '/' . $member ) ) {
							return new \WP_Error( 'icon_library_storage', __( 'The concurrently published package failed verification.', 'aculect-icon-library' ) ); }
					}
					return $target;
				}
				return new \WP_Error( 'icon_library_storage', __( 'The immutable package directory could not be published.', 'aculect-icon-library' ) );
			}
			return $target;
		} finally {
			if ( is_dir( $stage ) && ! is_link( $stage ) ) {
				$this->remove_tree( $stage ); }
		}
	}

	/** Adds immutable installed collections to the provider boundary.
	 *
	 * @param array $providers Registered collection providers.
	 * @return array
	 */
	public function register_providers( $providers ) {
		$providers = is_array( $providers ) ? $providers : array();
		foreach ( $this->jobs->all_library_ids() as $library_id ) {
			if ( isset( $providers[ $library_id ] ) || in_array( $library_id, ( new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' ) )->get_collection_slugs(), true ) || CustomIconRepository::COLLECTION_SLUG === $library_id ) {
				continue; }
			$state = $this->jobs->read( $library_id );
			if ( ! $state || empty( $state['value']['styles'] ) ) {
				continue; }
			$providers[ $library_id ] = array(
				'owner'    => 'aculect-icon-library-installed',
				'manifest' => function () use ( $library_id ) {
					return $this->get_manifest( $library_id ); },
				'svg_path' => function ( $path ) use ( $library_id ) {
					return $this->get_svg_path( $library_id, $path ); },
			);
		}
		return $providers;
	}

	/** Returns authoritative installation state for one library.
	 *
	 * @param string $library_id Library slug.
	 * @return array|null
	 */
	public function get_installed( $library_id ) {
		$state = $this->jobs->read( $library_id );
		if ( ! $state || empty( $state['value']['styles'] ) ) {
			return null; }
		$styles         = array();
		$archived_count = 0;
		foreach ( $state['value']['styles'] as $style => $record ) {
			if ( ! is_array( $record ) ) {
				continue; }
			$styles[ $style ] = array(
				'release_version' => $record['release_version'],
				'package_sha256'  => $record['package_sha256'],
				'installed_at'    => $record['installed_at'],
			);
			$archived_count  += count( $record['archived_icons'] ?? array() );
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
					'version'       => 'installed',
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
			$manifest['variants'][] = array(
				'slug'           => $style,
				'label'          => ucwords( str_replace( '-', ' ', $style ) ),
				'defaultEnabled' => false,
			);
			foreach ( (array) ( $style_manifest['icons'] ?? array() ) as $icon ) {
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

	/** Checks package member path grammar.
	 *
	 * @param string $member Relative archive member.
	 * @return bool
	 */
	private function safe_member( $member ) {
		return 'manifest.json' === $member || ( 0 === strpos( $member, 'icons/' ) && ! preg_match( '#(?:^|/)\.\.(?:/|$)#', $member ) ) || ( 0 === strpos( $member, 'licenses/' ) && ! preg_match( '#(?:^|/)\.\.(?:/|$)#', $member ) ); }

	/** Rejects symlinked or escaped parent directories.
	 *
	 * @param string $base Storage base.
	 * @param string $relative Relative member path.
	 * @return bool
	 */
	private function has_safe_parents( $base, $relative ) {
		$root = realpath( $base );
		if ( ! $root || is_link( $base ) ) {
			return false; }
		$cursor = $root;
		foreach ( explode( '/', dirname( $relative ) ) as $part ) {
			if ( '.' === $part || '' === $part ) {
				continue; }
			$cursor .= DIRECTORY_SEPARATOR . $part;
			if ( is_link( $cursor ) ) {
				return false; }
			if ( file_exists( $cursor ) ) {
				$resolved = realpath( $cursor );
				if ( ! $resolved || 0 !== strpos( $resolved, $root . DIRECTORY_SEPARATOR ) ) {
					return false; }
			}
		}
		return true;
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

	/** Applies restrictive filesystem permissions to a storage directory.
	 *
	 * @param string $directory Directory path.
	 * @return bool
	 */
	/** Protects the storage directory from browsing and direct file access.
	 *
	 * @param string $directory Directory to protect.
	 */
	private function protect_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory ); }
		if ( ! file_exists( $directory . '/index.php' ) ) {
			file_put_contents( $directory . '/index.php', "<?php\n// Silence is golden.\n" ); }
		if ( ! file_exists( $directory . '/.htaccess' ) ) {
			file_put_contents( $directory . '/.htaccess', "Options -Indexes\n<FilesMatch " . '".*"' . ">\nDeny from all\n</FilesMatch>\n" ); }
	}

	/** Removes a task-owned staging tree without following symlinks.
	 *
	 * @param string $directory Tree path.
	 */
	private function remove_tree( $directory ) {
		$items = scandir( $directory );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue; }
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if ( is_link( $path ) || is_file( $path ) ) {
				wp_delete_file( $path ); } elseif ( is_dir( $path ) ) {
				$this->remove_tree( $path ); }
		}
		rmdir( $directory );
	}
}
