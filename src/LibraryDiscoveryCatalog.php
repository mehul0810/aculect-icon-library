<?php
/**
 * Immediate reviewed discovery, consented updates and bounded sample previews.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Remote visibility cannot change the locally reviewed package trust anchors. */
class LibraryDiscoveryCatalog extends TrustedLibraryCatalog {
	const INDEX_URL      = 'https://raw.githubusercontent.com/mehul0810/aculect-icon-libraries/main/data/catalog.json';
	const OPTION         = 'icon_library_discovery_catalog';
	const MAX_INDEX      = 1048576;
	const MAX_PREVIEW    = 262144;
	const MAX_SAMPLES    = 12;
	const OPTION_UPDATES = 'icon_library_catalog_updates';
	const OPTION_ATTEMPT = 'icon_library_catalog_attempt';
	const OPTION_ERROR   = 'icon_library_catalog_error';
	const OPTION_LOCK    = 'icon_library_catalog_update_lock';
	const UPDATE_HOOK    = 'icon_library_catalog_update';
	const CACHE_TTL      = 86400;
	const RETRY_DELAY    = 3600;

	/**
	 * Optional isolated transport.
	 *
	 * @var callable|null
	 */
	private $transport;

	/**
	 * Loads local pins; never contacts GitHub during construction or discovery.
	 *
	 * @param array|null    $entries Reviewed descriptors.
	 * @param callable|null $transport Isolated transport.
	 */
	public function __construct( $entries = null, $transport = null ) {
		parent::__construct( $entries );
		$this->transport = is_callable( $transport ) ? $transport : null;
	}

	/**
	 * Returns verified cache or the shipped GitHub snapshot without network access.
	 *
	 * @return array
	 */
	public function get_entries() {
		$cache = get_option( self::OPTION, null );
		if ( is_array( $cache ) && is_array( $cache['libraries'] ?? null ) ) {
			$entries = $this->verified_entries( $cache['libraries'] );
			if ( ! is_wp_error( $entries ) ) {
				return $entries;
			}
		}
		return array_values(
			array_filter(
				parent::get_entries(),
				static function ( $entry ) {
					return true === ( $entry['discoverable'] ?? false );
				}
			)
		);
	}

	/** Whether an administrator opted in to background metadata updates.
	 *
	 * @return bool
	 */
	public function updates_enabled() {
		return in_array( get_option( self::OPTION_UPDATES, false ), array( true, 1, '1' ), true );
	}

	/** Saves explicit consent without fetching or changing installed collections.
	 *
	 * @param bool $enabled Whether background updates are allowed.
	 */
	public function set_updates_enabled( $enabled ) {
		update_option( self::OPTION_UPDATES, (bool) $enabled, false );
		if ( $enabled ) {
			$this->maybe_schedule_update();
		} else {
			wp_clear_scheduled_hook( self::UPDATE_HOOK );
		}
	}

	/** Schedules one bounded update from a privileged catalog view, after consent. */
	public function maybe_schedule_update() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->update_due() || wp_next_scheduled( self::UPDATE_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::UPDATE_HOOK );
	}

	/** Runs metadata only in cron, keeping the last valid cache on every failure. */
	public function run_scheduled_update() {
		if ( ! $this->update_due() ) {
			return;
		}
		$lock = get_option( self::OPTION_LOCK, 0 );
		if ( (int) $lock < time() - 300 ) {
			delete_option( self::OPTION_LOCK );
		}
		if ( ! add_option( self::OPTION_LOCK, time(), '', false ) ) {
			return;
		}
		try {
			update_option( self::OPTION_ATTEMPT, time(), false );
			$result = $this->refresh();
			if ( is_wp_error( $result ) ) {
				update_option(
					self::OPTION_ERROR,
					array(
						'at'   => time(),
						'code' => $result->get_error_code(),
					),
					false
				);
			}
		} finally {
			delete_option( self::OPTION_LOCK );
		}
	}

	/** Whether the latest background update failed.
	 *
	 * @return bool
	 */
	public function update_failed() {
		return is_array( get_option( self::OPTION_ERROR, false ) );
	}

	/** Applies daily cache freshness and an hourly failure backoff.
	 *
	 * @return bool
	 */
	private function update_due() {
		return $this->updates_enabled() && $this->refreshed_at() <= time() - self::CACHE_TTL && (int) get_option( self::OPTION_ATTEMPT, 0 ) <= time() - self::RETRY_DELAY;
	}

	/**
	 * Returns last successful refresh time; zero means no verified cache.
	 *
	 * @return int
	 */
	public function refreshed_at() {
		$cache = get_option( self::OPTION, array() );
		$at    = is_array( $cache ) ? ( $cache['refreshed_at'] ?? 0 ) : 0;
		return is_int( $at ) && $at > 0 && $at <= time() && ! is_wp_error( $this->verified_entries( $cache['libraries'] ?? null ) ) ? $at : 0;
	}

	/**
	 * Refreshes metadata only after administrator consent. Failures keep old cache.
	 *
	 * @return array|\WP_Error
	 */
	public function refresh() {
		$raw = $this->fetch( self::INDEX_URL, self::MAX_INDEX );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		$index = json_decode( $raw, true, 16 );
		if ( ! is_array( $index ) || 1 !== ( $index['schema_version'] ?? null ) || ! is_array( $index['libraries'] ?? null ) || count( $index['libraries'] ) > 100 ) {
			return $this->error( 'catalog_invalid', __( 'The GitHub catalog is invalid. The last saved catalog is still available.', 'aculect-icon-library' ) );
		}
		$entries = $this->verified_entries( $index['libraries'] );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		update_option(
			self::OPTION,
			array(
				'libraries'    => $entries,
				'refreshed_at' => time(),
			),
			false
		);
		delete_option( self::OPTION_ERROR );
		return $entries;
	}

	/**
	 * Fetches and sanitizes at most twelve sample SVGs, never a full package.
	 *
	 * @param string $library Library identifier.
	 * @param string $style Style identifier.
	 * @param string $version Release version.
	 * @param bool   $download Whether an explicit preview action permits network access.
	 * @return array|\WP_Error
	 */
	public function preview( $library, $style, $version, $download = true ) {
		$entry = $this->find( $library, $style, $version );
		if ( ! $entry || ! $this->valid_preview_pin( $entry ) ) {
			return $this->error( 'preview_unavailable', __( 'A reviewed preview is not available for this release.', 'aculect-icon-library' ) );
		}
		$key    = 'icon_library_preview_' . $entry['preview_sha256'];
		$cached = get_option( $key, null );
		if ( ! is_string( $cached ) && ! $download ) {
			return $this->error( 'preview_unavailable', __( 'Select Preview to download a small sample.', 'aculect-icon-library' ) );
		}
		$cache_valid = is_string( $cached ) && strlen( $cached ) === $entry['preview_bytes'] && hash_equals( $entry['preview_sha256'], hash( 'sha256', $cached ) );
		$raw         = $cache_valid || ! $download ? $cached : $this->fetch( $this->preview_url( $entry ), self::MAX_PREVIEW );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}
		if ( strlen( $raw ) !== $entry['preview_bytes'] || ! hash_equals( $entry['preview_sha256'], hash( 'sha256', $raw ) ) ) {
			return $this->error( 'preview_integrity', __( 'The preview failed its integrity check.', 'aculect-icon-library' ) );
		}
		$data = json_decode( $raw, true, 16 );
		if ( ! is_array( $data ) || 1 !== ( $data['schema_version'] ?? null ) || ( $data['library_id'] ?? null ) !== $library || ( $data['style_id'] ?? null ) !== $style || ( $data['release_version'] ?? null ) !== $version || ! is_array( $data['samples'] ?? null ) || ! $data['samples'] || count( $data['samples'] ) > self::MAX_SAMPLES ) {
			return $this->error( 'preview_invalid', __( 'The preview data is invalid.', 'aculect-icon-library' ) );
		}
		$sanitizer = new SvgSanitizer();
		$samples   = array();
		foreach ( $data['samples'] as $sample ) {
			if ( ! is_array( $sample ) || ! is_string( $sample['label'] ?? null ) || strlen( $sample['label'] ) > 200 || ! is_string( $sample['svg'] ?? null ) || strlen( $sample['svg'] ) > SvgSanitizer::MAX_FILE_SIZE ) {
				return $this->error( 'preview_invalid', __( 'The preview data is invalid.', 'aculect-icon-library' ) );
			}
			$svg = $sanitizer->sanitize_custom( $sample['svg'] );
			if ( is_wp_error( $svg ) ) {
				return $this->error( 'preview_unsafe', __( 'The preview contains unsupported SVG data.', 'aculect-icon-library' ) );
			}
			$samples[] = array(
				'label' => sanitize_text_field( $sample['label'] ),
				'svg'   => $svg,
			);
		}
		if ( ! $cache_valid ) {
			update_option( $key, $raw, false );
		}
		return $samples;
	}

	/**
	 * Derives the sample URL from the same repository/release identity as the ZIP.
	 *
	 * @param array $entry Reviewed release descriptor.
	 * @return string
	 */
	public function preview_url( $entry ) {
		$tag = $entry['library_id'] . '-' . $entry['style_id'] . '-' . $entry['release_version'];
		if ( isset( $entry['preview_revision'] ) ) {
			return 'https://raw.githubusercontent.com/mehul0810/aculect-icon-libraries/' . $entry['preview_revision'] . '/data/previews/' . rawurlencode( $tag . '.preview.json' );
		}
		return self::RELEASE_BASE . rawurlencode( $tag ) . '/' . rawurlencode( $tag . '.preview.json' );
	}

	/** Returns preserved attribution from verified cached sample data without fetching.
	 *
	 * @param string $library Library identifier.
	 * @param string $style Style identifier.
	 * @param string $version Release version.
	 * @return string
	 */
	public function preview_license( $library, $style, $version ) {
		$entry = $this->find( $library, $style, $version );
		if ( ! $entry || ! $this->valid_preview_pin( $entry ) ) {
			return ''; }
		$raw = get_option( 'icon_library_preview_' . $entry['preview_sha256'], null );
		if ( ! is_string( $raw ) || strlen( $raw ) !== $entry['preview_bytes'] || ! hash_equals( $entry['preview_sha256'], hash( 'sha256', $raw ) ) ) {
			return ''; }
		$data = json_decode( $raw, true, 16 );
		return is_array( $data ) && is_string( $data['license'] ?? null ) && strlen( $data['license'] ) <= LibraryPackageValidator::MAX_LICENSE ? $data['license'] : '';
	}

	/**
	 * Checks remote descriptors against immutable local pins, including previews.
	 *
	 * @param mixed $remote GitHub catalog descriptors.
	 * @return array
	 */
	private function match_entries( $remote ) {
		if ( ! is_array( $remote ) || count( $remote ) > 100 ) {
			return array();
		}
		$matches = array();
		foreach ( parent::get_entries() as $local ) {
			foreach ( $remote as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}
				$valid = true;
				foreach ( array( 'library_id', 'style_id', 'release_version', 'package_sha256', 'manifest_sha256', 'package_bytes', 'preview_sha256', 'preview_bytes', 'preview_revision' ) as $key ) {
					if ( ( $local[ $key ] ?? null ) !== ( $candidate[ $key ] ?? null ) ) {
						$valid = false;
					}
				}
				$availability = $candidate['availability'] ?? 'available';
				if ( $valid && in_array( $availability, array( 'available', 'pending-publication' ), true ) ) {
					$local['availability'] = $availability;
					$matches[]             = $local;
					break;
				}
			}
		}
		return $matches;
	}

	/** Rejects malformed or altered known entries before replacing a valid cache.
	 * Unknown valid releases never gain local installation authority. An explicitly
	 * empty valid index is authoritative and does not revive withdrawn entries.
	 *
	 * @param mixed $remote Catalog descriptors.
	 * @return array|\WP_Error
	 */
	private function verified_entries( $remote ) {
		if ( ! is_array( $remote ) || count( $remote ) > 100 || array_values( $remote ) !== $remote ) {
			return $this->error( 'catalog_invalid', __( 'The GitHub catalog is invalid. The last saved catalog is still available.', 'aculect-icon-library' ) );
		}
		$seen    = array();
		$matches = array();
		$known   = array();
		foreach ( parent::get_entries() as $entry ) {
			$known[ $entry['library_id'] . '/' . $entry['style_id'] . '/' . $entry['release_version'] ] = true;
		}
		foreach ( $remote as $candidate ) {
			$validated = is_array( $candidate ) ? ( new TrustedLibraryCatalog( array( $candidate ) ) )->get_entries() : array();
			if ( ! $validated || ! $this->valid_preview_pin( $candidate ) ) {
				return $this->error( 'catalog_invalid', __( 'The GitHub catalog is invalid. The last saved catalog is still available.', 'aculect-icon-library' ) );
			}
			$key = $candidate['library_id'] . '/' . $candidate['style_id'] . '/' . $candidate['release_version'];
			if ( isset( $seen[ $key ] ) ) {
				return $this->error( 'catalog_invalid', __( 'The GitHub catalog contains duplicate releases. The last saved catalog is still available.', 'aculect-icon-library' ) );
			}
			$seen[ $key ] = true;
			$matched      = $this->match_entries( array( $candidate ) );
			if ( ! $matched && isset( $known[ $key ] ) ) {
				return $this->error( 'catalog_integrity', __( 'The GitHub catalog failed its integrity check. The last saved catalog is still available.', 'aculect-icon-library' ) );
			}
			$matches = array_merge( $matches, $matched );
		}
		if ( $remote && ! $matches ) {
			return $this->error( 'catalog_unreviewed', __( 'The GitHub catalog has no reviewed releases. The last saved catalog is still available.', 'aculect-icon-library' ) );
		}
		return $matches;
	}

	/**
	 * Checks bounded preview pins independently from ZIP integrity.
	 *
	 * @param array $entry Reviewed descriptor.
	 * @return bool
	 */
	private function valid_preview_pin( $entry ) {
		return ( ! isset( $entry['preview_revision'] ) || ( is_string( $entry['preview_revision'] ) && 1 === preg_match( '/^[a-f0-9]{40}$/', $entry['preview_revision'] ) ) ) && is_string( $entry['preview_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $entry['preview_sha256'] ) && is_int( $entry['preview_bytes'] ?? null ) && $entry['preview_bytes'] > 0 && $entry['preview_bytes'] <= self::MAX_PREVIEW;
	}

	/**
	 * Fetches bounded JSON with TLS and a narrowly limited GitHub redirect chain.
	 *
	 * @param string $url Fixed metadata URL.
	 * @param int    $limit Maximum response bytes.
	 * @return string|\WP_Error
	 */
	private function fetch( $url, $limit ) {
		$initial = $url;
		for ( $hop = 0; $hop <= 3; ++$hop ) {
			$options  = array(
				'timeout'             => self::INDEX_URL === $initial ? 5 : 15,
				'redirection'         => 0,
				'limit_response_size' => $limit + 1,
				'sslverify'           => true,
			);
			$response = $this->transport ? call_user_func( $this->transport, $url, $options ) : wp_safe_remote_get( $url, $options );
			if ( is_wp_error( $response ) ) {
				return $this->error( 'discovery_network', __( 'GitHub could not be reached. Cached catalog and previews remain available.', 'aculect-icon-library' ) );
			}
			$status = wp_remote_retrieve_response_code( $response );
			if ( 200 === $status ) {
				$body = wp_remote_retrieve_body( $response );
				return is_string( $body ) && strlen( $body ) <= $limit ? $body : $this->error( 'discovery_size', __( 'The GitHub response exceeds the allowed size.', 'aculect-icon-library' ) );
			}
			$location = wp_remote_retrieve_header( $response, 'location' );
			$parts    = is_string( $location ) ? wp_parse_url( $location ) : false;
			if ( self::INDEX_URL === $initial || ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) || ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'release-assets.githubusercontent.com' !== ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) || strlen( $location ) > 8192 ) {
				break;
			}
			$url = $location;
		}
		return $this->error( 'discovery_http', __( 'GitHub did not return the requested metadata. Cached data remains available.', 'aculect-icon-library' ) );
	}

	/**
	 * Creates a public-safe error.
	 *
	 * @param string $code Error code suffix.
	 * @param string $message Translated message.
	 * @return \WP_Error
	 */
	private function error( $code, $message ) {
		return new \WP_Error( 'icon_library_' . $code, $message, array( 'status' => 400 ) );
	}
}
