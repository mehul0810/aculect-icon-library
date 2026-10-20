<?php
/**
 * Custom icon persistence.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores sanitized custom icons outside the Media Library.
 */
class CustomIconRepository {
	const MAX_ICONS       = 500;
	const MAX_BYTES       = 33554432;
	const LOCK_TTL        = 60;
	const OPTION_LOCK     = 'icon_library_custom_icons_lock';
	const COLLECTION_SLUG = 'custom-icons';
	const OPTION_ICONS    = 'icon_library_custom_icons';

	/**
	 * SVG sanitizer.
	 *
	 * @var SvgSanitizer
	 */
	private $sanitizer;

	/**
	 * Current transaction-scoped metadata lock.
	 *
	 * @var OptionMutationLock|null
	 */
	private $lock;
	/** Whether the last acquisition failed on database support.
	 *
	 * @var bool
	 */
	private $storage_unavailable = false;

	/**
	 * Metadata used to build the cached manifest.
	 *
	 * @var array|null
	 */
	private $manifest_icons;

	/**
	 * Request-local manifest, refreshed when stored metadata changes.
	 *
	 * @var array|null
	 */
	private $manifest;

	/**
	 * Constructor.
	 *
	 * @param SvgSanitizer $sanitizer SVG sanitizer.
	 */
	public function __construct( SvgSanitizer $sanitizer ) {
		$this->sanitizer = $sanitizer;
	}

	/**
	 * Returns stored icon metadata.
	 *
	 * @return array<string,array>
	 */
	public function get_icons() {
		$icons = $this->lock ? $this->lock->read( self::OPTION_ICONS, array() ) : get_option( self::OPTION_ICONS, array() );
		return is_array( $icons ) ? $icons : array();
	}

	/**
	 * Returns the dynamic custom collection manifest.
	 *
	 * @return array|null
	 */
	public function get_manifest() {
		$icons = $this->get_icons();
		if ( $icons === $this->manifest_icons ) {
			return $this->manifest;
		}
		$this->manifest_icons = $icons;
		if ( empty( $icons ) ) {
			$this->manifest = null;
			return null;
		}

		$this->manifest = array(
			'schemaVersion' => 2,
			'slug'          => self::COLLECTION_SLUG,
			'name'          => __( 'Custom Icons', 'aculect-icon-library' ),
			'description'   => __( 'Icons uploaded locally by site administrators.', 'aculect-icon-library' ),
			'version'       => 'site',
			'license'       => array(
				'name' => __( 'Site provided', 'aculect-icon-library' ),
				'url'  => '',
			),
			'source'        => array(
				'name' => __( 'This site', 'aculect-icon-library' ),
				'url'  => '',
			),
			'variants'      => array(
				array(
					'slug'  => 'custom',
					'label' => __( 'Custom', 'aculect-icon-library' ),
				),
			),
			'icons'         => array_values( $icons ),
		);
		return $this->manifest;
	}

	/**
	 * Creates an icon after strict validation.
	 *
	 * @param string $name  Stable icon name.
	 * @param string $label Display label.
	 * @param string $svg   SVG markup.
	 * @return array|WP_Error
	 */
	public function create( $name, $label, $svg ) {
		$raw_name = is_string( $name ) ? trim( $name ) : '';
		$name     = $this->normalize_name( $raw_name );
		$label    = is_string( $label ) ? sanitize_text_field( $label ) : '';

		if ( $raw_name !== $name || ! $name || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name ) ) {
			return new WP_Error( 'icon_library_custom_name_invalid', __( 'Use a lowercase icon name containing letters, numbers, and hyphens.', 'aculect-icon-library' ), array( 'status' => 400 ) );
		}
		if ( '' === $label ) {
			return new WP_Error( 'icon_library_custom_label_invalid', __( 'An icon label is required.', 'aculect-icon-library' ), array( 'status' => 400 ) );
		}

		$icons = $this->get_icons();
		if ( self::MAX_ICONS <= count( $icons ) ) {
			return new WP_Error( 'icon_library_custom_limit', __( 'The custom icon limit has been reached.', 'aculect-icon-library' ), array( 'status' => 409 ) );
		}
		if ( isset( $icons[ $name ] ) ) {
			return new WP_Error( 'icon_library_custom_duplicate', __( 'An icon with that name already exists. Choose a new name to preserve the existing artwork.', 'aculect-icon-library' ), array( 'status' => 409 ) );
		}

		$sanitized = $this->sanitizer->sanitize_custom( $svg );
		if ( is_wp_error( $sanitized ) ) {
			if ( ! is_array( $sanitized->get_error_data() ) || empty( $sanitized->get_error_data()['status'] ) ) {
				$sanitized->add_data( array( 'status' => 400 ) );
			}
			return $sanitized;
		}

		if ( ! $this->acquire_lock() ) {
			return $this->lock_error();
		}

		try {
			$icons = $this->get_icons();
			if ( $this->lock->has_failed() ) {
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be read. Refresh the icon list before retrying.', 'aculect-icon-library' ), array( 'status' => 500 ) );
			}
			if ( self::MAX_ICONS <= count( $icons ) || self::MAX_BYTES < $this->get_retained_bytes( $icons ) + strlen( $sanitized ) ) {
				return new WP_Error( 'icon_library_custom_limit', __( 'The custom icon limit has been reached.', 'aculect-icon-library' ), array( 'status' => 409 ) );
			}
			if ( isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_duplicate', __( 'An icon with that name already exists. Choose a new name to preserve the existing artwork.', 'aculect-icon-library' ), array( 'status' => 409 ) );
			}

			$directory = $this->get_directory( true );
			if ( is_wp_error( $directory ) ) {
				return $directory;
			}

			// A failed or delayed publisher must never overwrite another icon's file.
			$relative_path = '_' . $name . '-' . wp_generate_uuid4() . '.svg';
			$path          = $directory . '/' . $relative_path;
			$temp          = tempnam( $directory, '.icon-library-' );
			// Atomic same-directory replacement in the plugin-owned uploads directory.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! $temp || false === file_put_contents( $temp, $sanitized, LOCK_EX ) || ! rename( $temp, $path ) ) {
				if ( $temp && file_exists( $temp ) ) {
					wp_delete_file( $temp );
				}
				return new WP_Error( 'icon_library_custom_write_failed', __( 'The sanitized icon could not be stored.', 'aculect-icon-library' ) );
			}

			$icons[ $name ] = array(
				'id'           => self::COLLECTION_SLUG . '/custom/' . $name,
				'coreIconName' => self::COLLECTION_SLUG . '/' . $name,
				'label'        => $label,
				'variant'      => 'custom',
				'categories'   => array( 'custom' ),
				'keywords'     => preg_split( '/[-\s]+/', $name ),
				'path'         => $relative_path,
				'sha256'       => hash( 'sha256', $sanitized ),
				'bytes'        => strlen( $sanitized ),
			);

			if ( ! $this->lock->write( self::OPTION_ICONS, $icons ) || ! $this->lock->commit() ) {
				// A lost COMMIT acknowledgement can still mean the metadata was saved.
				// Retain immutable bytes rather than delete potentially published artwork.
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon change could not be confirmed. Refresh the icon list before retrying.', 'aculect-icon-library' ), array( 'status' => 500 ) );
			}

			return $icons[ $name ];
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Resolves one stored icon file.
	 *
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_file_path( $relative_path ) {
		if ( ! is_string( $relative_path ) ) {
			return null;
		}
		if ( basename( $relative_path ) !== $relative_path || '.svg' !== substr( $relative_path, -4 ) ) {
			return null;
		}
		$icons = $this->get_icons();
		$icon  = null;
		foreach ( $icons as $candidate ) {
			if ( is_array( $candidate ) && ( $candidate['path'] ?? '' ) === $relative_path ) {
				$icon = $candidate;
				break;
			}
		}
		if ( ! is_array( $icon ) ) {
			$icon = $icons[ basename( $relative_path, '.svg' ) ] ?? null;
		}
		$stored_path = is_array( $icon ) ? ( $icon['path'] ?? '' ) : '';
		if ( ! is_string( $stored_path ) || '' === $stored_path || basename( $stored_path ) !== $stored_path || '.svg' !== substr( $stored_path, -4 ) ) {
			return null;
		}
		$directory = $this->get_directory( false );
		if ( is_wp_error( $directory ) ) {
			return null;
		}
		$path = $directory . '/' . $stored_path;
		return is_readable( $path ) ? $path : null;
	}

	/**
	 * Reads one stored icon.
	 *
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_svg_content( $relative_path ) {
		$path    = $this->get_file_path( $relative_path );
		$content = $path ? file_get_contents( $path ) : false;
		return is_string( $content ) ? $content : null;
	}

	/**
	 * Updates display metadata without changing the stable registered name.
	 *
	 * @param string $name  Stable name.
	 * @param string $label New label.
	 * @return array|WP_Error
	 */
	public function update_label( $name, $label ) {
		$name  = $this->normalize_name( $name );
		$label = is_string( $label ) ? sanitize_text_field( $label ) : '';
		if ( ! $this->acquire_lock() ) {
			return $this->lock_error();
		}
		try {
			$icons = $this->get_icons();
			if ( ! isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'aculect-icon-library' ), array( 'status' => 404 ) );
			}
			if ( '' === $label ) {
				return new WP_Error( 'icon_library_custom_label_invalid', __( 'An icon label is required.', 'aculect-icon-library' ), array( 'status' => 400 ) );
			}
			$icons[ $name ]['label'] = $label;
			if ( ! $this->lock->write( self::OPTION_ICONS, $icons ) || ! $this->lock->commit() ) {
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'aculect-icon-library' ), array( 'status' => 500 ) );
			}
			return $icons[ $name ];
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Permanently deletes an icon and its stored SVG.
	 *
	 * @param string $name Stable name.
	 * @return true|WP_Error
	 */
	public function delete( $name ) {
		return $this->remove( $name, false );
	}

	/**
	 * Restores an archived icon for new selections.
	 *
	 * Kept for sites upgraded from versions that archived instead of deleting icons;
	 * the current UI no longer creates archived rows.
	 *
	 * @param string $name Stable name.
	 * @return true|WP_Error
	 */
	public function restore( $name ) {
		$name = $this->normalize_name( $name );
		if ( ! $this->acquire_lock() ) {
			return $this->lock_error();
		}
		try {
			$icons = $this->get_icons();
			if ( ! isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'aculect-icon-library' ), array( 'status' => 404 ) );
			}
			if ( empty( $icons[ $name ]['archived'] ) ) {
				return $this->lock->commit();
			}
			unset( $icons[ $name ]['archived'] );
			if ( ! $this->lock->write( self::OPTION_ICONS, $icons ) || ! $this->lock->commit() ) {
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'aculect-icon-library' ), array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Permanently removes an archived icon and its stored SVG.
	 *
	 * This legacy cleanup path remains available for pre-existing archived metadata.
	 *
	 * @param string $name Stable name.
	 * @return true|WP_Error
	 */
	public function purge( $name ) {
		return $this->remove( $name, true );
	}

	/**
	 * Commits metadata removal before cleaning up the immutable artwork path.
	 *
	 * @param string $name Stable name.
	 * @param bool   $archived_only Require an archived row.
	 * @return true|WP_Error
	 */
	private function remove( $name, $archived_only ) {
		$name = $this->normalize_name( $name );
		if ( ! $this->acquire_lock() ) {
			return $this->lock_error();
		}
		try {
			$icons = $this->get_icons();
			if ( ! isset( $icons[ $name ] ) ) {
				return new WP_Error( 'icon_library_custom_not_found', __( 'Custom icon not found.', 'aculect-icon-library' ), array( 'status' => 404 ) );
			}
			if ( $archived_only && empty( $icons[ $name ]['archived'] ) ) {
				return new WP_Error( 'icon_library_custom_not_archived', __( 'Archive the icon before permanently deleting it.', 'aculect-icon-library' ), array( 'status' => 409 ) );
			}
			$path = $this->get_file_path( $icons[ $name ]['path'] ?? '' );
			unset( $icons[ $name ] );
			if ( ! $this->lock->write( self::OPTION_ICONS, $icons ) || ! $this->lock->commit() ) {
				return new WP_Error( 'icon_library_custom_metadata_failed', __( 'The icon metadata could not be updated.', 'aculect-icon-library' ), array( 'status' => 500 ) );
			}
			// Published paths are immutable, so cleanup cannot remove a successor's SVG.
			if ( $path ) {
				wp_delete_file( $path );
				if ( file_exists( $path ) ) {
					return new WP_Error(
						'icon_library_custom_delete_failed',
						__( 'The icon was removed, but its stored artwork could not be cleaned up. Ask your site administrator to check uploads permissions.', 'aculect-icon-library' ),
						array(
							'status'  => 500,
							'removed' => true,
						)
					);
				}
			}
			return true;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Acquires the atomic option lock used for metadata mutations.
	 *
	 * @return bool
	 */
	private function acquire_lock() {
		if ( $this->lock ) {
			return false;
		}
		$lock = new OptionMutationLock( self::OPTION_LOCK );
		if ( ! $lock->acquire( self::LOCK_TTL ) ) {
			$this->storage_unavailable = $lock->storage_unavailable();
			return false;
		}
		$this->lock = $lock;
		return true;
	}

	/** Reports contention separately from unsupported transactional storage.
	 *
	 * @return WP_Error
	 */
	private function lock_error() {
		return $this->storage_unavailable
			? new WP_Error( 'icon_library_custom_storage_unavailable', __( 'Custom icon changes require an available transactional database. Ask your site administrator to check database support and retry outside another transaction.', 'aculect-icon-library' ), array( 'status' => 503 ) )
			: new WP_Error( 'icon_library_custom_busy', __( 'Another custom icon change is in progress. Try again.', 'aculect-icon-library' ), array( 'status' => 409 ) );
	}

	/** Releases the custom icon metadata lock. */
	private function release_lock() {
		if ( $this->lock ) {
			$this->lock->release();
		}
		$this->lock = null;
	}

	/**
	 * Counts bytes retained by active and archived icon files.
	 *
	 * @param array $icons Stored icon metadata.
	 * @return int
	 */
	private function get_retained_bytes( $icons ) {
		$total     = 0;
		$directory = $this->get_directory( false );
		$directory = is_wp_error( $directory ) ? null : $directory;
		foreach ( (array) $icons as $icon ) {
			if ( ! is_array( $icon ) ) {
				continue;
			}
			if ( isset( $icon['bytes'] ) && is_numeric( $icon['bytes'] ) ) {
				$total += max( 0, absint( $icon['bytes'] ) );
				continue;
			}
			$relative_path = isset( $icon['path'] ) && is_string( $icon['path'] ) ? $icon['path'] : '';
			if ( $directory && '' !== $relative_path && basename( $relative_path ) === $relative_path && '.svg' === substr( $relative_path, -4 ) ) {
				$path  = $directory . '/' . $relative_path;
				$bytes = is_file( $path ) ? filesize( $path ) : false;
				if ( false !== $bytes ) {
					$total += $bytes;
				}
			}
		}
		return $total;
	}

	/**
	 * Normalizes a custom icon name without accepting array/object input.
	 *
	 * @param mixed $name Candidate name.
	 * @return string
	 */
	private function normalize_name( $name ) {
		return is_string( $name ) ? sanitize_key( $name ) : '';
	}

	/**
	 * Resolves the plugin-owned upload directory.
	 *
	 * @param bool $create Whether to create the directory.
	 * @return string|WP_Error
	 */
	private function get_directory( $create ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'icon_library_upload_directory', $uploads['error'] );
		}
		$base_path = is_string( $uploads['basedir'] ?? null ) ? $uploads['basedir'] : '';
		if ( $create && $base_path && ! is_dir( $base_path ) && ! wp_mkdir_p( $base_path ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The uploads directory could not be created.', 'aculect-icon-library' ) );
		}
		$base = realpath( $base_path );
		if ( false === $base || ! is_dir( $base ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The uploads directory is not available.', 'aculect-icon-library' ) );
		}
		$directory = $base . '/icon-library/custom-icons';
		if ( $create && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The custom icon directory could not be created.', 'aculect-icon-library' ) );
		}
		// Resolve and re-check the real path rather than trusting the string built above:
		// if the uploads base or the custom-icons directory were ever replaced with a
		// symlink pointing outside uploads, later file writes/deletes keyed off $directory
		// must not silently follow it there.
		$resolved = realpath( $directory );
		if ( false === $resolved || is_link( $directory ) || 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR ) || ! is_dir( $resolved ) ) {
			return new WP_Error( 'icon_library_upload_directory', __( 'The custom icon directory does not exist.', 'aculect-icon-library' ) );
		}
		return $directory;
	}
}
