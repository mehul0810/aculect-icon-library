<?php
/**
 * Directory asset validator regression tests.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

/**
 * Validates the WordPress.org directory assets gate.
 */
class DirectoryAssetsValidationTest extends TestCase {
	/**
	 * Isolated fixture root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Sets up an isolated validator fixture.
	 */
	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/icon-library-directory-assets-' . uniqid( '', true );
		mkdir( $this->root . '/scripts', 0777, true );
		mkdir( $this->root . '/.wordpress-org/blueprints', 0777, true );
		mkdir( $this->root . '/assets', 0777, true );

		$source = dirname( __DIR__ );
		foreach ( array( 'banner-772x250.png', 'banner-1544x500.png', 'icon-128x128.png', 'icon-256x256.png', 'icon.svg' ) as $file ) {
			copy( $source . '/.wordpress-org/' . $file, $this->root . '/.wordpress-org/' . $file );
		}
		copy( $source . '/.wordpress-org/blueprints/blueprint.json', $this->root . '/.wordpress-org/blueprints/blueprint.json' );
		copy( $source . '/assets/aculect-icon.svg', $this->root . '/assets/aculect-icon.svg' );
		copy( $source . '/aculect-icon-library.php', $this->root . '/aculect-icon-library.php' );
		copy( $source . '/scripts/validate-directory-assets.php', $this->root . '/scripts/validate-directory-assets.php' );
	}

	/**
	 * Removes the isolated validator fixture.
	 */
	protected function tearDown(): void {
		$this->remove_directory( $this->root );
	}

	/**
	 * Accepts the published Blueprint fixture.
	 */
	public function test_validator_accepts_the_published_blueprint_fixture() {
		$result = $this->run_validator();

		$this->assertSame( 0, $result['status'], $result['output'] );
		$this->assertStringContainsString( 'WordPress.org directory assets valid.', $result['output'] );
	}

	/**
	 * Rejects a version-pinned Blueprint ZIP URL.
	 */
	public function test_validator_rejects_a_version_pinned_blueprint_zip_url() {
		$this->replace_blueprint_url( 'https://downloads.wordpress.org/plugin/aculect-icon-library.1.0.1.zip' );

		$result = $this->run_validator();

		$this->assertSame( 1, $result['status'] );
		$this->assertStringContainsString( 'Invalid WordPress.org preview Blueprint configuration.', $result['output'] );
	}

	/**
	 * Rejects an unrelated Blueprint ZIP URL.
	 */
	public function test_validator_rejects_an_unrelated_blueprint_zip_url() {
		$this->replace_blueprint_url( 'https://example.com/aculect-icon-library.latest-stable.zip' );

		$result = $this->run_validator();

		$this->assertSame( 1, $result['status'] );
		$this->assertStringContainsString( 'Invalid WordPress.org preview Blueprint configuration.', $result['output'] );
	}

	/**
	 * Replaces the plugin ZIP URL in the fixture.
	 *
	 * @param string $url Plugin ZIP URL.
	 */
	private function replace_blueprint_url( $url ) {
		$blueprint = $this->root . '/.wordpress-org/blueprints/blueprint.json';
		$contents  = json_decode( file_get_contents( $blueprint ), true );

		$this->assertIsArray( $contents );
		$contents['steps'][1]['pluginData']['url'] = $url;
		file_put_contents( $blueprint, wp_json_encode( $contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	}

	/**
	 * Runs the validator for the isolated fixture.
	 *
	 * @return array<string, int|string> Validator status and output.
	 */
	private function run_validator() {
		$output = array();
		$status = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Runs the isolated validator subprocess.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->root . '/scripts/validate-directory-assets.php' ) . ' 2>&1', $output, $status );

		return array(
			'status' => $status,
			'output' => implode( "\n", $output ),
		);
	}

	/**
	 * Removes an isolated fixture directory.
	 *
	 * @param string $directory Fixture directory.
	 */
	private function remove_directory( $directory ) {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}

		rmdir( $directory );
	}
}
