<?php
/**
 * Explicit GitHub discovery and bounded sample previews.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Remote visibility cannot change the locally reviewed package trust anchors. */
class LibraryDiscoveryCatalog extends TrustedLibraryCatalog {
	const INDEX_URL   = 'https://raw.githubusercontent.com/mehul0810/aculect-icon-libraries/main/data/catalog.json';
	const OPTION      = 'icon_library_discovery_catalog';
	const MAX_INDEX   = 1048576;
	const MAX_PREVIEW = 262144;
	const MAX_SAMPLES = 12;

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
	 * Returns only cached GitHub entries matching local trust anchors.
	 *
	 * @return array
	 */
	public function get_entries() {
		$cache = get_option( self::OPTION, array() );
		return $this->match_entries( is_array( $cache ) ? ( $cache['libraries'] ?? array() ) : array() );
	}

	/**
	 * Returns last successful refresh time; zero means no verified cache.
	 *
	 * @return int
	 */
	public function refreshed_at() {
		$cache = get_option( self::OPTION, array() );
		return is_array( $cache ) ? (int) ( $cache['refreshed_at'] ?? 0 ) : 0;
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
		$entries = $this->match_entries( $index['libraries'] );
		update_option(
			self::OPTION,
			array(
				'libraries'    => $entries,
				'refreshed_at' => time(),
			),
			false
		);
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
		return self::RELEASE_BASE . rawurlencode( $tag ) . '/' . rawurlencode( $tag . '.preview.json' );
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
				foreach ( array( 'library_id', 'style_id', 'release_version', 'package_sha256', 'manifest_sha256', 'package_bytes', 'preview_sha256', 'preview_bytes' ) as $key ) {
					if ( ( $local[ $key ] ?? null ) !== ( $candidate[ $key ] ?? null ) ) {
						$valid = false;
					}
				}
				if ( $valid ) {
					$matches[] = $local;
					break;
				}
			}
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
		return is_string( $entry['preview_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $entry['preview_sha256'] ) && is_int( $entry['preview_bytes'] ?? null ) && $entry['preview_bytes'] > 0 && $entry['preview_bytes'] <= self::MAX_PREVIEW;
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
				'timeout'             => 15,
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
