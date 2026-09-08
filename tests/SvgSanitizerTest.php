<?php
/** @package IconLibrary */

use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

class SvgSanitizerTest extends TestCase {
	/** @dataProvider unsafe_svg_provider */
	public function test_rejects_unsafe_svg( $svg, $code ) {
		$result = ( new SvgSanitizer() )->sanitize_custom( $svg );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function unsafe_svg_provider() {
		return array(
			'script'                 => array( '<svg><script>alert(1)</script><path d="M0 0"/></svg>', 'icon_library_svg_element' ),
			'event'                  => array( '<svg onload="alert(1)"><path d="M0 0"/></svg>', 'icon_library_svg_attribute' ),
			'style'                  => array( '<svg><path style="fill:red" d="M0 0"/></svg>', 'icon_library_svg_attribute' ),
			'url'                    => array( '<svg><path fill="url(https://example.com/x)" d="M0 0"/></svg>', 'icon_library_svg_reference' ),
			'css_escape'             => array( '<svg><path fill="j\\61vascript:alert(1)" d="M0 0"/></svg>', 'icon_library_svg_reference' ),
			'declaration'            => array( '<!DOCTYPE svg><svg><path d="M0 0"/></svg>', 'icon_library_svg_declaration' ),
			'processing_instruction' => array( '<?xml-stylesheet href="https://example.com/x.css"?><svg><path d="M0 0"/></svg>', 'icon_library_svg_declaration' ),
		);
	}

	public function test_accepts_core_compatible_svg() {
		$result = ( new SvgSanitizer() )->sanitize_custom( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h24v24z"/></svg>' );
		$this->assertIsString( $result );
	}

	public function test_normalizes_export_scaffolding_without_losing_fills_or_geometry() {
		$svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" id="Layer_1" version="1.1" xml:space="preserve" width="800px" height="800px" viewBox="0 0 100 100"><!-- export --><style type="text/css">.st0{fill-rule:evenodd;clip-rule:evenodd;fill:#FDB913;}.st1{fill:#EFE7D2;}.st2{fill:none;}</style><title/><g id="fill"><g><path id="shape" class="st0" d="M0 0h20v20z"/><polygon class="st1" points="0,0 20,0 20,20"/><path class="st2" d="M5 5h10v10z"/><path d="M1 1h2v2z"/></g></g></svg>';
		$result = ( new SvgSanitizer() )->sanitize_custom( $svg );
		$this->assertIsString( $result );
		$document = new DOMDocument();
		$document->loadXML( $result );
		$this->assertSame( '0 0 100 100', $document->documentElement->getAttribute( 'viewBox' ) );
		$this->assertSame( '800px', $document->documentElement->getAttribute( 'width' ) );
		$this->assertSame( 5, $document->getElementsByTagName( '*' )->length );
		$paths = $document->getElementsByTagName( 'path' );
		$this->assertSame( '#FDB913', $paths->item( 0 )->getAttribute( 'fill' ) );
		$this->assertSame( 'evenodd', $paths->item( 0 )->getAttribute( 'fill-rule' ) );
		$this->assertSame( 'M0 0h20v20z', $paths->item( 0 )->getAttribute( 'd' ) );
		$this->assertSame( '#EFE7D2', $document->getElementsByTagName( 'polygon' )->item( 0 )->getAttribute( 'fill' ) );
		$this->assertSame( 'none', $paths->item( 1 )->getAttribute( 'fill' ) );
		$this->assertFalse( $paths->item( 2 )->hasAttribute( 'fill' ) );
		$this->assertDoesNotMatchRegularExpression( '/\b(?:id|class|version|xml:space|xmlns:xlink)=|<style|<g[ >]|<title|<!--/', $result );
		$this->assertSame( $result, ( new SvgSanitizer() )->sanitize_custom( $result ) );
	}

	public function test_class_cascade_uses_stylesheet_order_not_class_order() {
		$result = ( new SvgSanitizer() )->sanitize_custom( '<svg><style>.a{fill:#123;fill:#456;}.b{fill:#789;}</style><style>.a{fill-rule:evenodd;}</style><path class="b a" fill="#000" d="M0 0"/></svg>' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'fill="#789"', $result );
		$this->assertStringContainsString( 'fill-rule="evenodd"', $result );
	}

	public function test_rejects_normalization_that_expands_beyond_file_size_limit() {
		$svg = '<svg><style>.a{fill:#123456;fill-rule:evenodd}</style>' . str_repeat( '<path class="a" d="M0 0"/>', 2000 ) . '</svg>';
		$this->assertLessThan( SvgSanitizer::MAX_FILE_SIZE, strlen( $svg ) );
		$result = ( new SvgSanitizer() )->sanitize_custom( $svg );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'icon_library_svg_too_large', $result->get_error_code() );
	}

	/** @dataProvider unsupported_export_provider */
	public function test_rejects_unsupported_exports( $svg ) {
		$this->assertInstanceOf( WP_Error::class, ( new SvgSanitizer() )->sanitize_custom( $svg ) );
	}

	public function unsupported_export_provider() {
		return array(
			'foreign geometry' => array( '<svg><path xmlns="urn:foreign" d="M0 0"/></svg>' ),
			'foreign attribute' => array( '<svg xmlns:x="urn:foreign"><path x:d="M0 0"/></svg>' ),
			'nested svg' => array( '<svg><svg><path d="M0 0"/></svg></svg>' ),
			'nested geometry' => array( '<svg><path d="M0 0"><path d="M1 1"/></path></svg>' ),
			'group event' => array( '<svg><g id="x" onload="alert(1)"><path d="M0 0"/></g></svg>' ),
			'group transform' => array( '<svg><g transform="translate(2)"><path d="M0 0"/></g></svg>' ),
			'group fill' => array( '<svg><g fill="#123"><path d="M0 0"/></g></svg>' ),
			'group class' => array( '<svg><style>.a{fill:none}</style><g class="a"><path d="M0 0"/></g></svg>' ),
			'root class cascade' => array( '<svg class="a"><style>.a{fill:none}</style><path d="M0 0"/></svg>' ),
			'undefined class' => array( '<svg><path class="missing" d="M0 0"/></svg>' ),
			'css url' => array( '<svg><style>.a{fill:url(#x)}</style><path class="a" d="M0 0"/></svg>' ),
			'css escape' => array( '<svg><style>.a{fill:\\6eone}</style><path class="a" d="M0 0"/></svg>' ),
			'css import' => array( '<svg><style>@import "https://example.com";</style><path d="M0 0"/></svg>' ),
			'css selector' => array( '<svg><style>#x{fill:none}</style><path id="x" d="M0 0"/></svg>' ),
			'css important' => array( '<svg><style>.a{fill:none!important}</style><path class="a" d="M0 0"/></svg>' ),
			'css unsupported property' => array( '<svg><style>.a{opacity:0}</style><path class="a" d="M0 0"/></svg>' ),
			'overridden unsafe attribute' => array( '<svg><style>.a{fill:none}</style><path class="a" fill="url(#x)" d="M0 0"/></svg>' ),
			'conditional stylesheet' => array( '<svg><style media="print">.a{fill:none}</style><path class="a" d="M0 0"/></svg>' ),
			'style event' => array( '<svg><style type="text/css" onload="alert(1)">.a{fill:none}</style><path d="M0 0"/></svg>' ),
			'style child' => array( '<svg><style><script/></style><path d="M0 0"/></svg>' ),
			'title child' => array( '<svg><title><path d="M0 0"/></title></svg>' ),
			'title event' => array( '<svg><title onload="alert(1)"/><path d="M0 0"/></svg>' ),
			'reference' => array( '<svg><path id="x" d="M0 0"/><use href="#x"/></svg>' ),
			'foreign object' => array( '<svg><foreignObject/><path d="M0 0"/></svg>' ),
		);
	}
}
