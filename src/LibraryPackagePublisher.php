<?php
/**
 * Publishes validated legacy library packages into immutable storage.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps deferred package writes out of retained-artwork reads. */
final class LibraryPackagePublisher {
	/**
	 * Absolute storage base.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * Explicit storage base for fixture writes.
	 *
	 * @var string
	 */
	private $base_override;

	/**
	 * Supplies the same storage boundary used by the installed library reader.
	 *
	 * @param string $base Storage base.
	 * @param string $base_override Explicit fixture base, or empty for WordPress uploads.
	 */
	public function __construct( $base, $base_override ) {
		$this->base          = $base;
		$this->base_override = $base_override;
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
		$base         = $this->base;
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem directory publication must remain atomic; a remote adapter cannot provide this guarantee.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only the empty task-owned directory after the symlink-aware traversal above.
		rmdir( $directory );
	}
}
