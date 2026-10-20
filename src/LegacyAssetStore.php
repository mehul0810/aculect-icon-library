<?php
/**
 * Preserves bundled legacy icon assets in uploads for upgrade compatibility.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies explicitly requested bundled collections into immutable persistent storage.
 */
class LegacyAssetStore {
	const MAX_FILES           = 20000;
	const MAX_ENTRIES         = 30000;
	const MAX_FILE_BYTES      = 10485760;
	const MAX_TOTAL_BYTES     = 104857600;
	const MAX_INVENTORY_BYTES = 10485760;
	const DESCRIPTOR          = 'readiness.json';
	const INVENTORY           = 'inventory.json';

	/**
	 * Persistent directory containing copied collections.
	 *
	 * @var string
	 */
	private $storage_root;

	/** Builds a store around an optional uploads-relative fixture path.
	 *
	 * @param string|null $storage_root Persistent store directory (defaults to uploads).
	 */
	public function __construct( $storage_root = null ) {
		if ( null === $storage_root ) {
			$uploads      = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
			$storage_root = ! empty( $uploads['basedir'] ) ? $uploads['basedir'] . '/icon-library/legacy-assets' : '';
		}
		$this->storage_root = is_string( $storage_root ) ? rtrim( $storage_root, '/\\' ) : '';
	}

	/**
	 * Copies exact requested collection trees. Empty input is a no-op and never marks ready.
	 *
	 * @param string   $source_root Bundled icon collection directory.
	 * @param string[] $slugs       Explicit collection slugs.
	 * @return true|\WP_Error
	 */
	public function preserve( $source_root, $slugs ) {
		if ( ! is_string( $source_root ) || ! is_array( $slugs ) || empty( $slugs ) || '' === $this->storage_root ) {
			return true === ( is_array( $slugs ) && empty( $slugs ) ) ? true : $this->error( 'invalid_request', 'A source directory, storage directory, and collection list are required.' );
		}
		$source_path = $source_root;
		$source_root = realpath( $source_root );
		if ( false === $source_root || ! is_dir( $source_root ) || is_link( $source_path ) ) {
			return $this->error( 'source_unavailable', 'The bundled icon collection directory is not available.' );
		}
		$slugs = array_values( array_unique( $slugs ) );
		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $slug ) ) {
				return $this->error( 'invalid_slug', 'A collection identifier is invalid; no assets were copied.' );
			}
		}
		if ( ! $this->ensure_directory( dirname( $this->storage_root ) ) ) {
			return $this->error( 'storage_unavailable', 'The persistent icon storage directory could not be created.' );
		}
		$parent = realpath( dirname( $this->storage_root ) );
		if ( false === $parent || is_link( dirname( $this->storage_root ) ) ) {
			return $this->error( 'storage_unavailable', 'The persistent icon storage path is not a safe directory.' );
		}
		$lock_path = $parent . '/.legacy-assets.lock';
		if ( is_link( $lock_path ) ) {
			return $this->error( 'lock_failed', 'The persistent icon storage lock path is not a safe file.' );
		}
		$lock = @fopen( $lock_path, 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock ); }
			return $this->error( 'lock_failed', 'Could not lock persistent icon storage; retry the migration.' );
		}
		$opened_lock = fstat( $lock );
		$path_lock   = @lstat( $lock_path );
		if ( is_link( $lock_path ) || ! is_array( $opened_lock ) || ! is_array( $path_lock ) || $opened_lock['ino'] !== $path_lock['ino'] || 0100000 !== ( $path_lock['mode'] & 0170000 ) ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
			return $this->error( 'lock_failed', 'The persistent icon storage lock path changed during acquisition.' );
		}
		try {
			if ( file_exists( $this->storage_root ) || is_link( $this->storage_root ) ) {
				return $this->is_ready( true ) ? true : $this->error( 'store_invalid', 'Existing preserved icon assets failed verification; they were not changed.' );
			}
			$stage = $parent . '/.legacy-assets-' . str_replace( '.', '', uniqid( '', true ) );
			if ( ! @mkdir( $stage, 0700 ) ) {
				return $this->error( 'staging_failed', 'Could not create private staging for legacy icon assets.' );
			}
			$files   = array();
			$total   = 0;
			$entries = 0;
			foreach ( $slugs as $slug ) {
				$source = $source_root . DIRECTORY_SEPARATOR . $slug;
				if ( is_link( $source ) || ! is_dir( $source ) || false === realpath( $source ) || 0 !== strpos( realpath( $source ), $source_root . DIRECTORY_SEPARATOR ) ) {
					$this->remove_tree( $stage );
					return $this->error( 'source_invalid', 'A requested collection is missing or outside the bundled asset directory.' );
				}
				$result = $this->copy_tree( $source, $stage . DIRECTORY_SEPARATOR . $slug, $slug, $files, $total, $entries );
				if ( is_wp_error( $result ) ) {
					$this->remove_tree( $stage );
					return $result;
				}
			}
			if ( empty( $files ) ) {
				$this->remove_tree( $stage );
				return $this->error( 'empty_assets', 'No files were found in the requested collections.' );
			}
			ksort( $files );
			$inventory       = array(
				'schema_version' => 1,
				'files'          => $files,
				'file_count'     => count( $files ),
				'total_bytes'    => $total,
			);
			$inventory_json  = wp_json_encode( $inventory, JSON_UNESCAPED_SLASHES );
			$descriptor      = array(
				'schema_version'   => 1,
				'collections'      => $slugs,
				'file_count'       => count( $files ),
				'total_bytes'      => $total,
				'inventory_sha256' => is_string( $inventory_json ) ? hash( 'sha256', $inventory_json ) : '',
			);
			$descriptor_json = wp_json_encode( $descriptor, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $inventory_json ) || strlen( $inventory_json ) > self::MAX_INVENTORY_BYTES || ! is_string( $descriptor_json ) || false === file_put_contents( $stage . '/' . self::INVENTORY, $inventory_json, LOCK_EX ) || false === file_put_contents( $stage . '/' . self::DESCRIPTOR, $descriptor_json, LOCK_EX ) || ! $this->verify_directory( $stage, $descriptor, $inventory ) ) {
				$this->remove_tree( $stage );
				return $this->error( 'verification_failed', 'Copied legacy assets failed integrity verification.' );
			}
			if ( ! @rename( $stage, $this->storage_root ) ) {
				$this->remove_tree( $stage );
				return $this->error( 'publish_failed', 'Could not publish preserved icon assets; retry the migration.' );
			}
			return $this->is_ready( true ) ? true : $this->error( 'verification_failed', 'Published legacy assets failed integrity verification.' );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Returns the collections directory after lightweight readiness checks.
	 *
	 * @return string|null
	 */
	public function resolve_root() {
		return $this->is_ready() ? $this->storage_root : null;
	}

	/**
	 * Checks persisted readiness, optionally auditing every file digest.
	 *
	 * Runtime callers should use the default lightweight check; explicit preflight
	 * and preservation callers should request the full audit.
	 *
	 * @param bool $verify Whether to hash and size-check every inventoried file.
	 * @return bool
	 */
	public function is_ready( $verify = false ) {
		$descriptor_path = $this->storage_root . '/' . self::DESCRIPTOR;
		$inventory_path  = $this->storage_root . '/' . self::INVENTORY;
		if ( '' === $this->storage_root || is_link( $this->storage_root ) || ! is_file( $descriptor_path ) || is_link( $descriptor_path ) || filesize( $descriptor_path ) > 65536 || ! is_file( $inventory_path ) || is_link( $inventory_path ) ) {
			return false;
		}
		$data = json_decode( (string) @file_get_contents( $descriptor_path ), true );
		if ( ! is_array( $data ) || 1 !== ( $data['schema_version'] ?? null ) || ! isset( $data['collections'], $data['file_count'], $data['total_bytes'], $data['inventory_sha256'] ) || ! is_array( $data['collections'] ) || ! is_string( $data['inventory_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $data['inventory_sha256'] ) ) {
			return false; }
		foreach ( $data['collections'] as $slug ) {
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $slug ) || is_link( $this->storage_root . '/' . $slug ) || ! is_dir( $this->storage_root . '/' . $slug ) ) {
				return false; }
		}
		if ( ! $verify ) {
			return true;
		}
		if ( filesize( $inventory_path ) > self::MAX_INVENTORY_BYTES ) {
			return false;
		}
		$inventory_json = file_get_contents( $inventory_path );
		$inventory      = is_string( $inventory_json ) ? json_decode( $inventory_json, true ) : null;
		return is_array( $inventory ) && hash_equals( $data['inventory_sha256'], hash( 'sha256', $inventory_json ) ) && $this->verify_directory( $this->storage_root, $data, $inventory );
	}

	/**
	 * Returns readiness, root, and collection identifiers without asset contents.
	 *
	 * @return array
	 */
	public function status() {
		$ready      = $this->is_ready();
		$descriptor = $ready ? json_decode( (string) file_get_contents( $this->storage_root . '/' . self::DESCRIPTOR ), true ) : array();
		return array(
			'ready'       => $ready,
			'root'        => $ready ? $this->storage_root : null,
			'collections' => $ready ? $descriptor['collections'] : array(),
		);
	}

	/**
	 * Copies and inventories one directory tree, rejecting links and unsafe files.
	 *
	 * @param string $source   Source path.
	 * @param string $target   Staging path.
	 * @param string $relative Relative path for the inventory.
	 * @param array  $files    Inventory, updated by reference.
	 * @param int    $total    Total bytes, updated by reference.
	 * @param int    $entries  Number of traversed entries, updated by reference.
	 * @return true|\WP_Error
	 */
	private function copy_tree( $source, $target, $relative, &$files, &$total, &$entries ) {
		++$entries;
		if ( $entries > self::MAX_ENTRIES ) {
			return $this->error( 'limits_exceeded', 'The bundled icon collection exceeds the directory-entry safety limit.' );
		}
		if ( is_link( $source ) ) {
			return $this->error( 'symlink_rejected', 'A symbolic link was found in a bundled collection; no assets were published.' ); }
		if ( is_dir( $source ) ) {
			if ( ! @mkdir( $target, 0700 ) ) {
				return $this->error( 'copy_failed', 'Could not create a directory while preserving icon assets.' ); }
			$items = scandir( $source );
			if ( ! is_array( $items ) ) {
				return $this->error( 'read_failed', 'Could not read a bundled icon directory.' ); }
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue; }
				$result = $this->copy_tree( $source . '/' . $item, $target . '/' . $item, $relative . '/' . $item, $files, $total, $entries );
				if ( is_wp_error( $result ) ) {
					return $result; }
			}
			return true;
		}
		if ( ! is_file( $source ) || ! preg_match( '#^[a-zA-Z0-9._/-]+$#', $relative ) || false !== strpos( $relative, '..' ) ) {
			return $this->error( 'file_rejected', 'A non-regular or unsafe path was found in a bundled collection.' );
		}
		$size = filesize( $source );
		if ( false === $size || $size > self::MAX_FILE_BYTES || count( $files ) >= self::MAX_FILES || $total + $size > self::MAX_TOTAL_BYTES ) {
			return $this->error( 'limits_exceeded', 'The bundled icon assets exceed the preservation safety limits.' );
		}
		if ( ! copy( $source, $target ) || filesize( $target ) !== $size ) {
			return $this->error( 'copy_failed', 'A bundled icon file could not be copied exactly.' ); }
		$hash = hash_file( 'sha256', $target );
		if ( ! is_string( $hash ) || ! hash_equals( (string) hash_file( 'sha256', $source ), $hash ) ) {
			return $this->error( 'verification_failed', 'A copied icon file failed integrity verification.' ); }
		$files[ $relative ] = array(
			'size'   => $size,
			'sha256' => $hash,
		);
		$total             += $size;
		return true;
	}

	/**
	 * Verifies all inventory entries against their persisted digests and sizes.
	 *
	 * @param string $root       Store root.
	 * @param array  $descriptor Readiness descriptor.
	 * @param array  $inventory  Full inventory.
	 * @return bool
	 */
	private function verify_directory( $root, $descriptor, $inventory ) {
		if ( ! isset( $inventory['files'], $inventory['file_count'], $inventory['total_bytes'], $descriptor['file_count'], $descriptor['total_bytes'], $descriptor['collections'] ) || ! is_array( $inventory['files'] ) || count( $inventory['files'] ) > self::MAX_FILES || count( $inventory['files'] ) !== (int) $descriptor['file_count'] || (int) $inventory['file_count'] !== (int) $descriptor['file_count'] || (int) $inventory['total_bytes'] !== (int) $descriptor['total_bytes'] ) {
			return false; }
		$total = 0;
		foreach ( $inventory['files'] as $relative => $expected ) {
			if ( ! is_string( $relative ) || ! preg_match( '#^[a-zA-Z0-9._/-]+$#', $relative ) || false !== strpos( $relative, '..' ) || ! is_array( $expected ) ) {
				return false; }
			$path = $root . '/' . $relative;
			if ( ! $this->is_safe_relative_path( $root, $relative ) || is_link( $path ) || ! is_file( $path ) || filesize( $path ) !== ( $expected['size'] ?? null ) || ! is_string( $expected['sha256'] ?? null ) || ! hash_equals( $expected['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return false; }
			$total += (int) $expected['size'];
		}
		return $total === (int) $descriptor['total_bytes'] && $total <= self::MAX_TOTAL_BYTES;
	}

	/**
	 * Rejects links and missing intermediate directories in an inventory path.
	 *
	 * @param string $root     Store root.
	 * @param string $relative Relative file path.
	 * @return bool
	 */
	private function is_safe_relative_path( $root, $relative ) {
		$parts   = explode( '/', $relative );
		$current = $root;
		foreach ( $parts as $index => $part ) {
			$current .= '/' . $part;
			if ( is_link( $current ) ) {
				return false;
			}
			if ( $index < count( $parts ) - 1 && ! is_dir( $current ) ) {
				return false;
			}
		}
		$root_real = realpath( $root );
		$file_real = realpath( $current );
		return false !== $root_real && false !== $file_real && 0 === strpos( $file_real, $root_real . DIRECTORY_SEPARATOR );
	}

	/**
	 * Creates a private directory if it does not already exist.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	private function ensure_directory( $path ) {
		if ( is_dir( $path ) ) {
			return ! is_link( $path ); }
		return @mkdir( $path, 0700, true ) || ( is_dir( $path ) && ! is_link( $path ) );
	}

	/**
	 * Removes only a private, unpublished staging directory.
	 *
	 * @param string $path Staging path.
	 */
	private function remove_tree( $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return; }
		if ( ! is_dir( $path ) ) {
			return; }
		foreach ( scandir( $path ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$this->remove_tree( $path . '/' . $item ); }
		}
		@rmdir( $path );
	}

	/**
	 * Builds an actionable WordPress error.
	 *
	 * @param string $code    Error code suffix.
	 * @param string $message Actionable message.
	 * @return \WP_Error
	 */
	private function error( $code, $message ) {
		return new \WP_Error( 'icon_library_legacy_' . $code, $message );
	}
}
