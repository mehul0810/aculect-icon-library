<?php
/**
 * Constrained normalization of exported SVG artwork.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use DOMDocument;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts inert export scaffolding, never executable SVG, to Core geometry.
 */
class CustomSvgNormalizer {
	/**
	 * Normalize an already parsed, size-limited document before final validation.
	 *
	 * @param DOMDocument $source Source document.
	 * @return DOMDocument|WP_Error Normalized document or unsupported input error.
	 */
	public function normalize( DOMDocument $source ) {
		$rules = array();
		foreach ( $source->getElementsByTagName( '*' ) as $element ) {
			if ( $element->prefix || ( $element->namespaceURI && 'http://www.w3.org/2000/svg' !== $element->namespaceURI ) ) {
				return new WP_Error( 'icon_library_svg_namespace', __( 'The SVG namespace is not supported.', 'aculect-icon-library' ) );
			}
			if ( 'style' === $element->tagName ) {
				if ( $element->parentNode !== $source->documentElement || $element->getElementsByTagName( '*' )->length > 0 || ( $element->attributes->length && ( 1 !== $element->attributes->length || 'text/css' !== $element->getAttribute( 'type' ) ) ) ) {
					return $this->unsupported();
				}
				$parsed = $this->parse_styles( $element->textContent );
				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}
				$rules = array_merge( $rules, $parsed );
			}
		}

		$output = new DOMDocument();
		$root   = $output->createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		$output->appendChild( $root );
		foreach ( $source->getElementsByTagName( '*' ) as $element ) {
			$tag = $element->tagName;
			if ( $element !== $source->documentElement && ! in_array( $element->parentNode->nodeName, array( 'svg', 'g' ), true ) ) {
				return $this->unsupported();
			}
			if ( 'style' === $tag ) {
				continue;
			}
			if ( 'title' === $tag && 0 === $element->attributes->length && '' === trim( $element->textContent ) && 0 === $element->getElementsByTagName( '*' )->length ) {
				continue;
			}
			if ( ! in_array( $tag, array( 'svg', 'g', 'path', 'polygon' ), true ) || ( 'svg' === $tag && $element !== $source->documentElement ) ) {
				/* translators: %s: SVG element name. */
				return new WP_Error( 'icon_library_svg_element', sprintf( __( 'Unsupported SVG element: %s.', 'aculect-icon-library' ), $tag ) );
			}
			foreach ( $element->childNodes as $child ) {
				if ( ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) && '' !== trim( $child->textContent ) ) {
					return $this->unsupported();
				}
			}
			$target = 'svg' === $tag ? $root : $output->createElementNS( 'http://www.w3.org/2000/svg', $tag );
			foreach ( $element->attributes as $attribute ) {
				$name = $attribute->nodeName;
				if ( ( 'id' === $name && ! $attribute->namespaceURI ) || ( 'svg' === $tag && ( 'version' === $name || ( 'xml:space' === $name && 'http://www.w3.org/XML/1998/namespace' === $attribute->namespaceURI ) ) ) ) {
					continue;
				}
				if ( 'g' === $tag || ( 'class' === $name && 'svg' === $tag && ! empty( $rules ) ) ) {
					return $this->unsupported();
				}
				if ( 'class' === $name && in_array( $tag, array( 'path', 'polygon' ), true ) ) {
					if ( $attribute->namespaceURI || ! preg_match( '/\A\s*[a-zA-Z_][a-zA-Z0-9_-]*(?:\s+[a-zA-Z_][a-zA-Z0-9_-]*)*\s*\z/', $attribute->value ) ) {
						return $this->unsupported();
					}
					continue;
				}
				if ( $attribute->namespaceURI ) {
					return new WP_Error( 'icon_library_svg_namespace', __( 'Namespaced SVG attributes are not supported.', 'aculect-icon-library' ) );
				}
				if ( false !== strpos( $attribute->value, '\\' ) || 1 === preg_match( '/(?:url\s*\(|javascript:|data:|https?:|\/\/)/i', $attribute->value ) ) {
					return new WP_Error( 'icon_library_svg_reference', __( 'External or executable SVG references are not allowed.', 'aculect-icon-library' ) );
				}
				$target->setAttribute( $name, $attribute->value );
			}
			if ( in_array( $tag, array( 'path', 'polygon' ), true ) ) {
				$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
				$matched = array();
				// Equal-specificity class rules override presentation attributes in source order.
				foreach ( $rules as $rule ) {
					if ( in_array( $rule['class'], $classes, true ) ) {
						$matched[] = $rule['class'];
						foreach ( $rule['properties'] as $name => $value ) {
							$target->setAttribute( $name, $value );
						}
					}
				}
				if ( array_diff( $classes, $matched ) ) {
					return $this->unsupported();
				}
				$root->appendChild( $target );
			}
		}
		return $output;
	}

	/**
	 * Parse only a deliberately small export grammar, not general CSS.
	 *
	 * @param string $css Stylesheet text.
	 * @return array|WP_Error Ordered single-class fill rules or an error.
	 */
	private function parse_styles( $css ) {
		$rules = array();
		while ( '' !== trim( $css ) ) {
			if ( ! preg_match( '/\A\s*\.([a-zA-Z_][a-zA-Z0-9_-]*)\s*\{([^{}]*)\}\s*/', $css, $match ) ) {
				return $this->unsupported();
			}
			$properties = array();
			foreach ( explode( ';', $match[2] ) as $declaration ) {
				if ( '' === trim( $declaration ) ) {
					continue;
				}
				if ( ! preg_match( '/\A\s*(fill|fill-rule|clip-rule)\s*:\s*(.*?)\s*\z/i', $declaration, $parts ) ) {
					return $this->unsupported();
				}
				$name  = strtolower( $parts[1] );
				$value = $parts[2];
				$valid = 'fill' === $name ? preg_match( '/\A(?:#[a-f0-9]{3}|#[a-f0-9]{6}|none|currentColor)\z/i', $value ) : preg_match( '/\A(?:evenodd|nonzero)\z/i', $value );
				if ( ! $valid ) {
					return $this->unsupported();
				}
				// Clipping is unsupported; clip-rule has no effect without a clipPath.
				if ( 'clip-rule' !== $name ) {
					$properties[ $name ] = $value;
				}
			}
			$rules[] = array(
				'class'      => $match[1],
				'properties' => $properties,
			);
			$css     = substr( $css, strlen( $match[0] ) );
		}
		return $rules;
	}

	/**
	 * Explain unsupported export features without silently altering artwork.
	 *
	 * @return WP_Error Import error.
	 */
	private function unsupported() {
		return new WP_Error( 'icon_library_svg_export', __( 'This SVG uses unsupported styling or grouping. Export paths with explicit fills, without references, effects, or complex CSS.', 'aculect-icon-library' ) );
	}
}
