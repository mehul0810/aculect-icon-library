<?php
/** Trusted optional-library releases.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Provides only locally configured, pinned package descriptors. */
class TrustedLibraryCatalog {
	const RELEASE_BASE = 'https://github.com/mehul0810/aculect-icon-libraries/releases/download/';
	/**
	 * Locally trusted release descriptors.
	 *
	 * @var array
	 */
	private $entries;

	/**
	 * Loads explicit trusted entries or the shipped catalog.
	 *
	 * @param array|null $entries Explicitly trusted entries, or null for the shipped catalog.
	 */
	public function __construct( $entries = null ) {
		if ( null === $entries && defined( 'ICON_LIBRARY_DIR' ) && is_readable( ICON_LIBRARY_DIR . 'data/library-catalog.json' ) ) {
			$raw     = file_get_contents( ICON_LIBRARY_DIR . 'data/library-catalog.json' );
			$catalog = is_string( $raw ) && strlen( $raw ) <= 1048576 ? json_decode( $raw, true ) : null;
			$entries = is_array( $catalog ) && 1 === ( $catalog['schema_version'] ?? null ) && is_array( $catalog['libraries'] ?? null ) ? $catalog['libraries'] : array();
		}
		$this->entries = is_array( $entries ) ? $entries : array();
	}

	/**
	 * Returns valid descriptors with canonical release URLs.
	 *
	 * @return array
	 */
	public function get_entries() {
		$entries    = array();
		$namespaces = array();
		foreach ( $this->entries as $entry ) {
			if ( ! is_array( $entry ) || ! $this->is_valid( $entry ) ) {
				continue; }
			$key               = $entry['library_id'] . '/' . $entry['style_id'] . '/' . $entry['release_version'];
			$library_namespace = $entry['library_id'];
			$style_namespace   = $entry['library_id'] . '-' . $entry['style_id'];
			if ( ( isset( $namespaces[ $library_namespace ] ) && $namespaces[ $library_namespace ] !== $library_namespace ) || ( isset( $namespaces[ $style_namespace ] ) && $namespaces[ $style_namespace ] !== $library_namespace ) ) {
				continue; }
			$namespaces[ $library_namespace ] = $library_namespace;
			$namespaces[ $style_namespace ]   = $library_namespace;
			$entry['url']                     = self::RELEASE_BASE . rawurlencode( $entry['library_id'] . '-' . $entry['style_id'] . '-' . $entry['release_version'] ) . '/' . rawurlencode( $entry['library_id'] . '-' . $entry['style_id'] . '-' . $entry['release_version'] . '.zip' );
			$entries[ $key ]                  = $entry;
		}
		return array_values( $entries );
	}

	/**
	 * Finds one exact trusted release.
	 *
	 * @param string $library_id Library identifier.
	 * @param string $style_id Style identifier.
	 * @param string $version Exact release version.
	 * @return array|null
	 */
	public function find( $library_id, $style_id, $version ) {
		foreach ( $this->get_entries() as $entry ) {
			if ( $entry['library_id'] === $library_id && $entry['style_id'] === $style_id && $entry['release_version'] === $version ) {
				return $entry; }
		}
		return null;
	}

	/**
	 * Checks descriptor shape and canonical provenance location.
	 *
	 * @param array $entry Candidate descriptor.
	 * @return bool
	 */
	private function is_valid( $entry ) {
		foreach ( array( 'library_id', 'style_id' ) as $key ) {
			if ( ! isset( $entry[ $key ] ) || ! is_string( $entry[ $key ] ) || strlen( $entry[ $key ] ) > 100 || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry[ $key ] ) ) {
				return false; }
		}
		if ( ! isset( $entry['release_version'], $entry['package_sha256'], $entry['manifest_sha256'], $entry['package_bytes'] ) || ! $this->is_valid_version( $entry['release_version'] ) || ! is_string( $entry['package_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $entry['package_sha256'] ) || ! is_string( $entry['manifest_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $entry['manifest_sha256'] ) || ! is_int( $entry['package_bytes'] ) || $entry['package_bytes'] < 1 || $entry['package_bytes'] > LibraryPackageValidator::MAX_ARCHIVE ) {
			return false; }
		$url = self::RELEASE_BASE . rawurlencode( $entry['library_id'] . '-' . $entry['style_id'] . '-' . $entry['release_version'] ) . '/' . rawurlencode( $entry['library_id'] . '-' . $entry['style_id'] . '-' . $entry['release_version'] . '.zip' );
		return ! isset( $entry['url'] ) || $url === $entry['url'];
	}

	/**
	 * Validates bounded semantic-version syntax.
	 *
	 * @param mixed $version Candidate version.
	 * @return bool
	 */
	public function is_valid_version( $version ) {
		if ( ! is_string( $version ) || strlen( $version ) > 64 || ! preg_match( '/^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})(?:-([0-9A-Za-z.-]+))?(?:\+([0-9A-Za-z.-]+))?$/', $version, $parts ) ) {
			return false; }
		foreach ( array( 4, 5 ) as $index ) {
			if ( empty( $parts[ $index ] ) ) {
				continue; }
			foreach ( explode( '.', $parts[ $index ] ) as $identifier ) {
				if ( '' === $identifier || ( 4 === $index && ctype_digit( $identifier ) && strlen( $identifier ) > 1 && '0' === $identifier[0] ) ) {
					return false; }
			}
		}
		return true;
	}

	/**
	 * Returns SemVer precedence; build metadata does not affect ordering.
	 *
	 * @param string $left First version.
	 * @param string $right Second version.
	 * @return int|false
	 */
	public function compare_versions( $left, $right ) {
		if ( ! $this->is_valid_version( $left ) || ! $this->is_valid_version( $right ) ) {
			return false; }
		preg_match( '/^(\d+)\.(\d+)\.(\d+)(?:-([^+]+))?(?:\+(.+))?$/', $left, $a );
		preg_match( '/^(\d+)\.(\d+)\.(\d+)(?:-([^+]+))?(?:\+(.+))?$/', $right, $b );
		for ( $i = 1; $i <= 3; ++$i ) {
			$delta = (int) $a[ $i ] <=> (int) $b[ $i ];
			if ( 0 !== $delta ) {
				return $delta; }
		}
		$left_pre  = isset( $a[4] ) ? explode( '.', $a[4] ) : array();
		$right_pre = isset( $b[4] ) ? explode( '.', $b[4] ) : array();
		if ( ! $left_pre || ! $right_pre ) {
			return ! $left_pre && ! $right_pre ? 0 : ( ! $left_pre ? 1 : -1 ); }
		$limit = min( count( $left_pre ), count( $right_pre ) );
		for ( $i = 0; $i < $limit; ++$i ) {
			$l_numeric = ctype_digit( $left_pre[ $i ] );
			$r_numeric = ctype_digit( $right_pre[ $i ] );
			if ( $l_numeric && $r_numeric ) {
				$delta = strlen( $left_pre[ $i ] ) <=> strlen( $right_pre[ $i ] );
				if ( 0 === $delta ) {
					$delta = strcmp( $left_pre[ $i ], $right_pre[ $i ] ) <=> 0; }
			} elseif ( $l_numeric !== $r_numeric ) {
				$delta = $l_numeric ? -1 : 1; } else {
				$delta = strcmp( $left_pre[ $i ], $right_pre[ $i ] ) <=> 0; }
				if ( 0 !== $delta ) {
					return $delta; }
		}
		return count( $left_pre ) <=> count( $right_pre );
	}
}
