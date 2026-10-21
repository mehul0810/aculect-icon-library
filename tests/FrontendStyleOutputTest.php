<?php
/** @package IconLibrary */

use PHPUnit\Framework\TestCase;

class FrontendStyleOutputTest extends TestCase {
	/** @dataProvider frontend_cases */
	public function test_frontend_head_emits_one_stylesheet_before_saved_or_cached_output( $case ) {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/frontend-style-output.php' ) . ' ' . escapeshellarg( $case );
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		$this->assertSame( 1, substr_count( $result['head'], "id='icon-library-icons-css'" ) );
		$this->assertStringContainsString( 'assets/icons.css', $result['head'] );
		$this->assertSame( $result['original_body'], $result['body'], 'Cached markup and unrelated attributes must remain intact.' );
		$this->assertSame( '', $result['late_styles'], 'Repeated rendering must not duplicate the already emitted stylesheet.' );
	}

	public function frontend_cases() {
		return array(
			'saved outline'   => array( 'outline' ),
			'cached parent'   => array( 'cached' ),
			'cached template' => array( 'template' ),
			'repeated icons'  => array( 'repeated' ),
			'solid control'   => array( 'solid' ),
			'no icon control' => array( 'none' ),
		);
	}
}
