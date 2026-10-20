<?php
/**
 * Tests the release gate independently of Plugin Check's command exit status.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

/** Rejects findings and unknown reports, including empty command output. */
class PluginCheckGateTest extends TestCase {
	/**
	 * @dataProvider reports
	 * @param string $report Captured command output.
	 * @param int    $expected Expected gate exit code.
	 */
	public function test_report_gate( $report, $expected ) {
		$path = tempnam( sys_get_temp_dir(), 'ail-plugin-check-' );
		file_put_contents( $path, $report );
		try {
			$process = proc_open(
				array( PHP_BINARY, ICON_LIBRARY_DIR . 'scripts/validate-plugin-check.php', $path ),
				array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
				$pipes
			);
			$this->assertIsResource( $process );
			$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->assertSame( $expected, proc_close( $process ), $output );
		} finally {
			unlink( $path );
		}
	}

	/** @return array<string,array> Accepted and rejected report shapes. */
	public function reports() {
		return array(
			'official success' => array( "Success: Checks complete. No errors found.\n", 0 ),
			'empty findings'   => array( '[]', 0 ),
			'error'            => array( '[{"type":"ERROR","code":"fixture"}]', 1 ),
			'warning'          => array( '[{"type":"WARNING","code":"fixture"}]', 1 ),
			'empty output'     => array( '', 1 ),
			'object'           => array( '{}', 1 ),
			'null'             => array( 'null', 1 ),
			'invalid JSON'     => array( '[', 1 ),
			'noisy success'    => array( "Unexpected warning\nSuccess: Checks complete. No errors found.", 1 ),
		);
	}
}
