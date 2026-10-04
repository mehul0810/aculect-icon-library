<?php
/** Validates trusted versioned library ZIP packages before extraction.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Enforces the bounded format-v1 archive contract. */
class LibraryPackageValidator {
	const MAX_ARCHIVE  = 33554432;
	const MAX_MANIFEST = 8388608;
	const MAX_LICENSE  = 131072;
	const MAX_SVG      = 65536;
	const MAX_TOTAL    = 134217728;
	const MAX_ICONS    = 10000;
	/** SVG sanitizer for defense in depth.
	 *
	 * @var SvgSanitizer
	 */
	private $sanitizer;
	/** Whether synthetic fixture packages are allowed.
	 *
	 * @var bool
	 */
	private $allow_test_fixture;

	/** Creates a package validator.
	 *
	 * @param SvgSanitizer $sanitizer SVG sanitizer.
	 * @param bool         $allow_test_fixture Allow synthetic test packages.
	 */
	public function __construct( SvgSanitizer $sanitizer, $allow_test_fixture = false ) {
		$this->sanitizer          = $sanitizer;
		$this->allow_test_fixture = true === $allow_test_fixture;
	}

	/**
	 * Validates and optionally streams validated members to a private directory.
	 * At most the manifest and one bounded member are held in memory at a time.
	 *
	 * @param string $path Archive path.
	 * @param array  $trusted Trusted catalog descriptor.
	 * @param string $extraction_dir Optional unique, empty private directory.
	 * @return array|WP_Error
	 */
	public function validate( $path, $trusted, $extraction_dir = '' ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || filesize( $path ) > self::MAX_ARCHIVE || ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'icon_library_package_invalid', __( 'The library package is unavailable or exceeds the size limit.', 'aculect-icon-library' ) );
		}
		if ( filesize( $path ) !== ( $trusted['package_bytes'] ?? null ) || hash_file( 'sha256', $path ) !== ( $trusted['package_sha256'] ?? null ) ) {
			return new WP_Error( 'icon_library_package_digest', __( 'The downloaded package does not match its trusted catalog digest.', 'aculect-icon-library' ) );
		}
		if ( $extraction_dir && ( ! is_dir( $extraction_dir ) || is_link( $extraction_dir ) || count( array_diff( scandir( $extraction_dir ), array( '.', '..' ) ) ) ) ) {
			return new WP_Error( 'icon_library_package_staging', __( 'The private package staging directory is not empty and safe.', 'aculect-icon-library' ) );
		}
		$zip = new \ZipArchive();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes numFiles as part of its PHP API.
		if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) || $zip->numFiles < 3 || $zip->numFiles > self::MAX_ICONS + 2 ) {
			return new WP_Error( 'icon_library_package_zip', __( 'The library package is not a valid supported ZIP archive.', 'aculect-icon-library' ) );
		}
		try {
			$archive_members = array();
			$total           = 0;
			$manifest_index  = null;
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes numFiles as part of its PHP API.
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i );
				$name = is_array( $stat ) ? ( $stat['name'] ?? '' ) : '';
				if ( ! is_string( $name ) || ! $this->valid_path( $name ) || isset( $archive_members[ strtolower( $name ) ] ) || ( isset( $stat['comp_method'] ) && ! in_array( $stat['comp_method'], array( 0, 8 ), true ) ) ) {
					return new WP_Error( 'icon_library_package_member', __( 'The package contains an unsafe or duplicate member path.', 'aculect-icon-library' ) ); }
				$opsys = 0;
				$attrs = 0;
				if ( method_exists( $zip, 'getExternalAttributesIndex' ) && $zip->getExternalAttributesIndex( $i, $opsys, $attrs ) ) {
					$mode = ( $attrs >> 16 ) & 0170000;
					if ( ( $mode && 0100000 !== $mode ) || ( ( $attrs >> 16 ) & 0111 ) ) {
						return new WP_Error( 'icon_library_package_member', __( 'The package contains a non-regular or executable member.', 'aculect-icon-library' ) ); }
				}
				$limit = 'manifest.json' === $name ? self::MAX_MANIFEST : ( 0 === strpos( $name, 'licenses/' ) && preg_match( '/\.txt$/i', $name ) ? self::MAX_LICENSE : ( 0 === strpos( $name, 'icons/' ) && preg_match( '/\.svg$/i', $name ) ? self::MAX_SVG : 0 ) );
				if ( ! $limit || ! isset( $stat['size'] ) || $stat['size'] > $limit || $total + $stat['size'] > self::MAX_TOTAL ) {
					return new WP_Error( 'icon_library_package_member', __( 'The package member exceeds limits or has an unsupported path.', 'aculect-icon-library' ) ); }
				$total                                 += $stat['size'];
				$archive_members[ strtolower( $name ) ] = array(
					'name'  => $name,
					'index' => $i,
					'size'  => $stat['size'],
					'crc'   => $stat['crc'] ?? null,
					'limit' => $limit,
				);
				if ( 'manifest.json' === $name ) {
					$manifest_index = $i; }
			}
			if ( null === $manifest_index ) {
				return new WP_Error( 'icon_library_package_manifest', __( 'The package manifest is missing.', 'aculect-icon-library' ) ); }
			$manifest_bytes = $this->read_member( $zip, $archive_members['manifest.json'] );
			if ( is_wp_error( $manifest_bytes ) || hash( 'sha256', $manifest_bytes ) !== ( $trusted['manifest_sha256'] ?? null ) ) {
				return new WP_Error( 'icon_library_package_manifest_digest', __( 'The package manifest does not match the trusted catalog.', 'aculect-icon-library' ) ); }
			$manifest = json_decode( $manifest_bytes, true );
			if ( ! is_array( $manifest ) || ( $manifest['schema_version'] ?? null ) !== 1 || ( $manifest['library_id'] ?? null ) !== ( $trusted['library_id'] ?? null ) || ( $manifest['style_id'] ?? null ) !== ( $trusted['style_id'] ?? null ) || ( $manifest['release_version'] ?? null ) !== ( $trusted['release_version'] ?? null ) || ! is_array( $manifest['icons'] ?? null ) || count( $manifest['icons'] ) < 1 || count( $manifest['icons'] ) > self::MAX_ICONS ) {
				return new WP_Error( 'icon_library_package_manifest', __( 'The package identity or manifest schema is invalid.', 'aculect-icon-library' ) ); }
			if ( ! $this->valid_provenance( $manifest ) ) {
				return new WP_Error( 'icon_library_package_provenance', __( 'The package provenance record is invalid or mutable.', 'aculect-icon-library' ) ); }
			$license = $manifest['license'] ?? null;
			if ( ! is_array( $license ) || ! is_string( $license['path'] ?? null ) || ! $this->valid_path( $license['path'] ) || ! preg_match( '/\.txt$/i', $license['path'] ) || 0 !== strpos( $license['path'], 'licenses/' ) || ! is_string( $license['sha256'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $license['sha256'] ) ) {
				return new WP_Error( 'icon_library_package_license', __( 'The package license declaration is invalid.', 'aculect-icon-library' ) ); }
			$expected = array( strtolower( $license['path'] ) => $license['path'] );
			$ids      = array();
			$names    = array();
			$paths    = array();
			foreach ( $manifest['icons'] as $icon ) {
				if ( ! is_array( $icon ) || ! $this->valid_id( $icon['id'] ?? null ) || ! $this->valid_id( $icon['core_icon_name'] ?? null ) || ! is_string( $icon['label'] ?? null ) || ! preg_match( '//u', $icon['label'] ) || '' === trim( $icon['label'] ) || strlen( $icon['label'] ) > 120 || ! is_array( $icon['keywords'] ?? null ) || ! is_string( $icon['path'] ?? null ) || ! $this->valid_path( $icon['path'] ) || 0 !== strpos( $icon['path'], 'icons/' ) || ! preg_match( '/\.svg$/i', $icon['path'] ) || ! is_string( $icon['sha256'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{64}$/', $icon['sha256'] ) ) {
					return new WP_Error( 'icon_library_package_icon', __( 'The package contains an invalid icon record.', 'aculect-icon-library' ) ); }
				foreach ( $icon['keywords'] as $keyword ) {
					if ( ! is_string( $keyword ) || strlen( $keyword ) > 40 || ! preg_match( '//u', $keyword ) ) {
						return new WP_Error( 'icon_library_package_icon', __( 'The package contains invalid icon keywords.', 'aculect-icon-library' ) ); }
				}
				$folded_path = strtolower( $icon['path'] );
				if ( isset( $ids[ $icon['id'] ] ) || isset( $names[ $icon['core_icon_name'] ] ) || isset( $paths[ $folded_path ] ) || isset( $expected[ $folded_path ] ) ) {
					return new WP_Error( 'icon_library_package_icon', __( 'The package contains duplicate icon identities or paths.', 'aculect-icon-library' ) ); }
				$ids[ $icon['id'] ]               = true;
				$names[ $icon['core_icon_name'] ] = true;
				$paths[ $folded_path ]            = true;
				$expected[ $folded_path ]         = $icon['path'];
			}
			if ( count( $expected ) + 1 !== count( $archive_members ) || ! isset( $archive_members[ strtolower( $license['path'] ) ] ) ) {
				return new WP_Error( 'icon_library_package_members', __( 'The package has missing or undeclared members.', 'aculect-icon-library' ) ); }
			foreach ( $expected as $key => $name ) {
				if ( ! isset( $archive_members[ $key ] ) || $archive_members[ $key ]['name'] !== $name ) {
					return new WP_Error( 'icon_library_package_members', __( 'The package member paths do not match the manifest.', 'aculect-icon-library' ) ); }
			}
			if ( $extraction_dir && ! $this->write_member( $extraction_dir, 'manifest.json', $manifest_bytes ) ) {
				return new WP_Error( 'icon_library_package_staging', __( 'The package manifest could not be staged.', 'aculect-icon-library' ) ); }
			$license_bytes = $this->read_member( $zip, $archive_members[ strtolower( $license['path'] ) ] );
			if ( is_wp_error( $license_bytes ) || hash( 'sha256', $license_bytes ) !== $license['sha256'] || ! preg_match( '//u', $license_bytes ) || ! trim( $license_bytes ) || ( $extraction_dir && ! $this->write_member( $extraction_dir, $license['path'], $license_bytes ) ) ) {
				return new WP_Error( 'icon_library_package_license', __( 'The package license is missing, corrupt, or cannot be staged.', 'aculect-icon-library' ) ); }
			foreach ( $manifest['icons'] as &$icon ) {
				$svg = $this->read_member( $zip, $archive_members[ strtolower( $icon['path'] ) ] );
				if ( is_wp_error( $svg ) || hash( 'sha256', $svg ) !== $icon['sha256'] ) {
					unset( $icon );
					return new WP_Error( 'icon_library_package_icon', __( 'An icon file is missing or does not match its digest.', 'aculect-icon-library' ) ); }
				$svg_valid = $this->validate_svg( $svg );
				if ( is_wp_error( $svg_valid ) || ( $extraction_dir && ! $this->write_member( $extraction_dir, $icon['path'], $svg ) ) ) {
					unset( $icon );
					return is_wp_error( $svg_valid ) ? $svg_valid : new WP_Error( 'icon_library_package_staging', __( 'An icon file could not be staged.', 'aculect-icon-library' ) ); }
				$icon['variant']      = $manifest['style_id'];
				$icon['coreIconName'] = $manifest['library_id'] . '/' . $icon['core_icon_name'];
			}
			unset( $icon );
			$manifest['slug']     = $manifest['library_id'];
			$manifest['name']     = isset( $manifest['name'] ) && is_string( $manifest['name'] ) ? sanitize_text_field( $manifest['name'] ) : ucwords( str_replace( '-', ' ', $manifest['library_id'] ) );
			$manifest['version']  = $manifest['release_version'];
			$manifest['variants'] = array(
				array(
					'slug'           => $manifest['style_id'],
					'label'          => ucwords( str_replace( '-', ' ', $manifest['style_id'] ) ),
					'defaultEnabled' => false,
				),
			);
			$manifest['icons']    = array_values( $manifest['icons'] );
			return array(
				'manifest' => $manifest,
				'members'  => array_values(
					array_map(
						static function ( $item ) {
							return $item['name'];
						},
						$archive_members
					)
				),
			);
		} finally {
			$zip->close();
		}
	}

	/** Reads one bounded ZIP member and verifies its size and CRC.
	 *
	 * @param \ZipArchive $zip Open package archive.
	 * @param array       $entry Validated archive member metadata.
	 * @return string|WP_Error
	 */
	private function read_member( $zip, $entry ) {
		$stream = $zip->getStream( $entry['name'] );
		if ( ! $stream ) {
			return new WP_Error( 'icon_library_package_member', __( 'A package member could not be read.', 'aculect-icon-library' ) ); }
		$data = '';
		while ( ! feof( $stream ) && strlen( $data ) <= $entry['limit'] ) {
			$chunk = fread( $stream, min( 65536, $entry['limit'] + 1 - strlen( $data ) ) );
			if ( false === $chunk || '' === $chunk ) {
				break; }
			$data .= $chunk;
		}
		fclose( $stream );
		if ( strlen( $data ) !== $entry['size'] || strlen( $data ) > $entry['limit'] || ( null !== $entry['crc'] && strtolower( hash( 'crc32b', $data ) ) !== sprintf( '%08x', $entry['crc'] ) ) ) {
			return new WP_Error( 'icon_library_package_member', __( 'A package member is truncated, corrupt, or exceeds its declared limit.', 'aculect-icon-library' ) ); }
		return $data;
	}

	/** Writes validated package bytes below the private staging directory.
	 *
	 * @param string $directory Staging root.
	 * @param string $relative_path Member path.
	 * @param string $bytes Member bytes.
	 * @return bool
	 */
	private function write_member( $directory, $relative_path, $bytes ) {
		if ( ! $this->valid_path( $relative_path ) || is_link( $directory ) ) {
			return false; }
		$path = $directory . '/' . $relative_path;
		if ( ! wp_mkdir_p( dirname( $path ) ) || is_link( dirname( $path ) ) || is_link( $path ) ) {
			return false; }
		$written = file_put_contents( $path, $bytes, LOCK_EX );
		return strlen( $bytes ) === $written;
	}

	/** Verifies package upstream and conversion provenance fields.
	 *
	 * @param array $manifest Package manifest.
	 * @return bool
	 */
	private function valid_provenance( $manifest ) {
		if ( ! $this->allow_test_fixture && ! empty( $manifest['test_fixture'] ) ) {
			return false; }
		foreach ( array( 'upstream', 'conversion' ) as $key ) {
			$source   = $manifest[ $key ] ?? null;
			$identity = 'upstream' === $key ? 'name' : 'tool';
			if ( ! is_array( $source ) || ! is_string( $source[ $identity ] ?? null ) || '' === trim( $source[ $identity ] ) || ! is_string( $source['revision'] ?? null ) ) {
				return false; }
			$fixture = $this->allow_test_fixture && true === ( $manifest['test_fixture'] ?? false ) && 'synthetic-test' === ( $manifest['library_id'] ?? null ) && 0 === strpos( $source['revision'], 'synthetic-test:' );
			if ( ! $fixture && 1 !== preg_match( '/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/', $source['revision'] ) ) {
				return false; }
		}
		return true;
	}

	/** Accepts only the supported static SVG path subset.
	 *
	 * @param string $svg SVG source.
	 * @return true|WP_Error
	 */
	private function validate_svg( $svg ) {
		if ( ! is_string( $svg ) || strlen( $svg ) > self::MAX_SVG || false !== stripos( $svg, '<!doctype' ) || false !== stripos( $svg, '<!entity' ) || false !== strpos( $svg, "\0" ) || ! preg_match( '//u', $svg ) || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $svg ) ) {
			return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG has an unsafe declaration or encoding.', 'aculect-icon-library' ) ); }
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument property names are defined by the PHP XML API.
		$root = $document->documentElement;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement property names are defined by the PHP XML API.
		if ( ! $loaded || ! $root instanceof \DOMElement || 'svg' !== $root->localName || 'http://www.w3.org/2000/svg' !== $root->namespaceURI ) {
			return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG does not have the required SVG root.', 'aculect-icon-library' ) ); }
		$instructions = ( new \DOMXPath( $document ) )->query( '//processing-instruction()' );
		if ( $instructions && $instructions->length ) {
			return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG contains unsupported processing instructions.', 'aculect-icon-library' ) ); }
		foreach ( iterator_to_array( $root->attributes ) as $attribute ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMAttr exposes nodeName as part of the PHP XML API.
			if ( ! in_array( $attribute->nodeName, array( 'xmlns', 'width', 'height', 'viewBox' ), true ) ) {
				return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG uses unsupported root attributes.', 'aculect-icon-library' ) ); }
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMAttr exposes nodeName as part of the PHP XML API.
			if ( 'xmlns' === $attribute->nodeName && 'http://www.w3.org/2000/svg' !== $attribute->value ) {
				return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG declares an unsupported namespace.', 'aculect-icon-library' ) ); }
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMAttr exposes nodeName as part of the PHP XML API.
			if ( in_array( $attribute->nodeName, array( 'width', 'height' ), true ) && ! preg_match( '/^(?:\d+(?:\.\d*)?|\.\d+)(?:px)?$/', $attribute->value ) ) {
				return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG has invalid dimensions.', 'aculect-icon-library' ) ); }
		}
		$view_box = $root->getAttribute( 'viewBox' );
		if ( '' !== $view_box ) {
			$coordinates = preg_split( '/[\s,]+/', trim( $view_box ) );
			if ( 4 !== count( $coordinates ) ) {
				return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG has an invalid viewBox.', 'aculect-icon-library' ) ); }
			foreach ( $coordinates as $coordinate ) {
				if ( ! is_numeric( $coordinate ) || ! is_finite( (float) $coordinate ) ) {
					return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG has an invalid viewBox.', 'aculect-icon-library' ) ); }
			}
			if ( (float) $coordinates[2] <= 0 || (float) $coordinates[3] <= 0 ) {
				return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG has non-positive viewBox dimensions.', 'aculect-icon-library' ) ); }
		} elseif ( ! $root->hasAttribute( 'width' ) || ! $root->hasAttribute( 'height' ) ) {
			return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG must declare viewBox or numeric width and height.', 'aculect-icon-library' ) ); }
		$paths = 0;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes childNodes as part of the PHP XML API.
		foreach ( $root->childNodes as $child ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes nodeType and nodeValue as part of the PHP XML API.
			if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->nodeValue ) ) {
				continue; }
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMElement exposes these properties as part of the PHP XML API.
			if ( ! $child instanceof \DOMElement || 'path' !== $child->localName || 'http://www.w3.org/2000/svg' !== $child->namespaceURI ) {
				return new WP_Error( 'icon_library_package_svg', __( 'Only SVG path geometry is supported in library packages.', 'aculect-icon-library' ) ); }
			++$paths;
			$attributes = array();
			foreach ( iterator_to_array( $child->attributes ) as $attribute ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMAttr exposes nodeName as part of the PHP XML API.
				$attributes[ $attribute->nodeName ] = $attribute->value; }
			if ( ! isset( $attributes['d'] ) || array_diff( array_keys( $attributes ), array( 'd', 'fill-rule' ) ) || ( isset( $attributes['fill-rule'] ) && ! in_array( $attributes['fill-rule'], array( 'nonzero', 'evenodd' ), true ) ) || ! $this->valid_path_data( $attributes['d'] ) || $child->hasChildNodes() ) {
				return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG path is malformed or uses unsupported features.', 'aculect-icon-library' ) ); }
		}
		$core_allowed    = array(
			'svg'     => array(
				'class'       => true,
				'xmlns'       => true,
				'width'       => true,
				'height'      => true,
				'viewbox'     => true,
				'aria-hidden' => true,
				'role'        => true,
				'focusable'   => true,
			),
			'path'    => array(
				'fill'      => true,
				'fill-rule' => true,
				'd'         => true,
				'transform' => true,
			),
			'polygon' => array(
				'fill'      => true,
				'fill-rule' => true,
				'points'    => true,
				'transform' => true,
				'focusable' => true,
			),
		);
		$core_compatible = function_exists( 'wp_kses' ) ? wp_kses( $svg, $core_allowed ) : '';
		if ( ! $paths || '' === trim( $core_compatible ) ) {
			return new WP_Error( 'icon_library_package_svg', __( 'An icon SVG is empty or incompatible with WordPress Core sanitization.', 'aculect-icon-library' ) ); }
		$sanitized = $this->sanitizer->sanitize_custom( $svg );
		return is_wp_error( $sanitized ) ? new WP_Error( 'icon_library_package_svg', __( 'An icon SVG does not satisfy the existing plugin sanitizer contract.', 'aculect-icon-library' ) ) : true;
	}

	/** Checks SVG path data against the bounded grammar.
	 *
	 * @param string $path Path data.
	 * @return bool
	 */
	private function valid_path_data( $path ) {
		if ( ! is_string( $path ) || '' === trim( $path ) || strlen( $path ) > self::MAX_SVG ) {
			return false; }
		$arities        = array(
			'M' => 2,
			'L' => 2,
			'H' => 1,
			'V' => 1,
			'C' => 6,
			'S' => 4,
			'Q' => 4,
			'T' => 2,
			'A' => 7,
			'Z' => 0,
		);
		$position       = 0;
		$first_command  = true;
		$length         = strlen( $path );
		$number_pattern = '/\G[+\-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+\-]?[0-9]+)?/';
		while ( true ) {
			while ( $position < $length && false !== strpos( " \t\r\n", $path[ $position ] ) ) {
				++$position; }
			if ( $position === $length ) {
				return ! $first_command; }
			$letter  = $path[ $position ];
			$command = strtoupper( $letter );
			if ( false === strpos( 'MmLlHhVvCcSsQqTtAaZz', $letter ) || ( $first_command && 'M' !== $command ) ) {
				return false; }
			$first_command = false;
			++$position;
			$arity = $arities[ $command ];
			if ( 0 === $arity ) {
				continue; }
			$repeated = false;
			while ( true ) {
				for ( $parameter = 0; $parameter < $arity; ++$parameter ) {
					$start = $position;
					while ( $position < $length && false !== strpos( " \t\r\n", $path[ $position ] ) ) {
						++$position; }
					if ( $position < $length && ',' === $path[ $position ] ) {
						if ( 0 === $parameter && ! $repeated ) {
							return false; }
						++$position;
						while ( $position < $length && false !== strpos( " \t\r\n", $path[ $position ] ) ) {
							++$position; }
					}
					if ( 'A' === $command && 3 === $parameter && $start === $position ) {
						return false; }
					if ( 'A' === $command && in_array( $parameter, array( 3, 4 ), true ) ) {
						if ( $position >= $length || ! in_array( $path[ $position ], array( '0', '1' ), true ) ) {
							return false; }
						++$position;
					} else {
						if ( ! preg_match( $number_pattern, $path, $number, 0, $position ) || ! is_finite( (float) $number[0] ) ) {
							return false; }
						if ( 'A' === $command && in_array( $parameter, array( 0, 1 ), true ) && ( '+' === $number[0][0] || '-' === $number[0][0] ) ) {
							return false; }
						$position += strlen( $number[0] );
					}
				}
				while ( $position < $length && false !== strpos( " \t\r\n", $path[ $position ] ) ) {
					++$position; }
				if ( $position === $length || ctype_alpha( $path[ $position ] ) ) {
					break; }
				$repeated = true;
			}
		}
	}

	/** Checks a package icon identifier.
	 *
	 * @param string $value Identifier.
	 * @return bool
	 */
	private function valid_id( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value ); }
	/** Checks relative package member path grammar.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private function valid_path( $path ) {
		return is_string( $path ) && '' !== $path && strlen( $path ) <= 240 && false === strpos( $path, "\0" ) && false === strpos( $path, '\\' ) && '/' !== $path[0] && false === strpos( $path, '//' ) && ! preg_match( '/(?:^|\/)\.{1,2}(?:\/|$)|[^A-Za-z0-9._\/-]/', $path ) && substr( $path, -1 ) !== '/'; }
}
