<?php
/**
 * Tests the single bundled release package.
 *
 * @package IconLibrary
 */

use PHPUnit\Framework\TestCase;

/** Tests bundled package contents, exclusions and reproducibility. */
class LeanPackageTest extends TestCase {
	/**
	 * Temporary project root.
	 *
	 * @var string
	 */
	private $root;
	/**
	 * Source checkout root.
	 *
	 * @var string
	 */
	private $source_root;
	/**
	 * Plugin version from the fixture.
	 *
	 * @var string
	 */
	private $version;

	/** Creates an isolated source fixture for package-builder subprocesses. */
	protected function setUp(): void {
		$this->root        = sys_get_temp_dir() . '/icon-library-package-' . wp_generate_password( 12, false, false );
		$this->source_root = ICON_LIBRARY_DIR;
		mkdir( $this->root, 0700, true );
		$this->copy_fixture();
		$source = file_get_contents( $this->root . '/aculect-icon-library.php' );
		$this->assertMatchesRegularExpression( '/^[ \t*#@]*Version:\s*(\d+\.\d+\.\d+)/mi', $source );
		preg_match( '/^[ \t*#@]*Version:\s*(\d+\.\d+\.\d+)/mi', $source, $matches );
		$this->version = $matches[1];
	}

	/** Removes only the temporary fixture created by this test. */
	protected function tearDown(): void {
		$this->remove_tree( $this->root );
	}

	/** Checks bundled assets, the deferred runtime boundary and determinism. */
	public function test_bundled_artifact_is_complete_deterministic_and_under_size_limit() {
		$this->assertSame( 0, $this->run_packager( array() ) );
		$archive = $this->root . '/build/aculect-icon-library.' . $this->version . '.zip';
		$this->assertFileExists( $archive );
		$this->assertLessThan( 10000000, filesize( $archive ) );
		$hash = hash_file( 'sha256', $archive );
		$this->assertSame( 0, $this->run_packager( array() ) );
		$this->assertSame( $hash, hash_file( 'sha256', $archive ) );
		$this->assertSame( 1, $this->run_packager( array( '--bridge' ), 1 ) );
		$this->assertFileDoesNotExist( $this->root . '/build/aculect-icon-library.' . $this->version . '-bridge.zip' );

		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $archive ) );
		$names          = $this->zip_names( $zip );
		$families       = array();
		$expected_icons = array();
		foreach ( glob( $this->root . '/assets/icons/*/manifest.json' ) as $manifest_path ) {
			$family     = basename( dirname( $manifest_path ) );
			$families[] = $family;
			$manifest   = json_decode( file_get_contents( $manifest_path ), true );
			$this->assertContains( 'aculect-icon-library/assets/icons/' . $family . '/LICENSE', $names );
			$this->assertContains( 'aculect-icon-library/assets/icons/' . $family . '/manifest.json', $names );
			$this->assertContains( 'aculect-icon-library/assets/icons/' . $family . '/metadata.json', $names );
			if ( is_file( dirname( $manifest_path ) . '/exclusions.json' ) ) {
				$this->assertContains( 'aculect-icon-library/assets/icons/' . $family . '/exclusions.json', $names );
			}
			foreach ( $manifest['icons'] as $icon ) {
				$expected_icons[] = 'aculect-icon-library/assets/icons/' . $family . '/' . $icon['path'];
			}
		}
		foreach ( array( '16-solid', '20-solid', '24-solid' ) as $legacy_variant ) {
			foreach ( glob( $this->root . '/assets/icons/heroicons/' . $legacy_variant . '/*.svg' ) as $legacy_file ) {
				$expected_icons[] = 'aculect-icon-library/assets/icons/heroicons/' . $legacy_variant . '/' . basename( $legacy_file );
			}
		}
		sort( $families );
		$this->assertCount( 10, $families );
		$actual_icons = array_values(
			array_filter(
				$names,
				static function ( $name ) {
					return 0 === strpos( $name, 'aculect-icon-library/assets/icons/' ) && 'svg' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				}
			)
		);
		sort( $expected_icons );
		sort( $actual_icons );
		$this->assertSame( $expected_icons, $actual_icons );
		foreach ( $expected_icons as $icon_path ) {
			$this->assertContains( $icon_path, $names );
		}
		$this->assertContains( 'aculect-icon-library/assets/icons/heroicons/16-solid/academic-cap.svg', $names );
		$this->assertContains( 'aculect-icon-library/assets/icons/heroicons/20-solid/academic-cap.svg', $names );
		$this->assertContains( 'aculect-icon-library/assets/icons/heroicons/24-solid/academic-cap.svg', $names );
		$this->assertContains( 'aculect-icon-library/src/InstalledLibraryRepository.php', $names );
		$this->assertContains( 'aculect-icon-library/src/LibraryJobStore.php', $names );
		$this->assertContains( 'aculect-icon-library/src/CoreIconMetadataReader.php', $names );
		$profile = json_decode( $zip->getFromName( 'aculect-icon-library/data/package-profile.json' ), true );
		$this->assertSame( 'bundled', $profile['profile'] ?? null );
		$this->assertSame( $families, $profile['icon_families'] ?? null );
		foreach ( array(
			'aculect-icon-library/assets/library-installer.js',
			'aculect-icon-library/data/library-catalog.json',
			'aculect-icon-library/src/LibraryInstaller.php',
			'aculect-icon-library/src/LibraryAdminController.php',
			'aculect-icon-library/src/LibraryPackageValidator.php',
			'aculect-icon-library/src/TrustedLibraryCatalog.php',
			'aculect-icon-library/src/LibraryDiscoveryCatalog.php',
			'aculect-icon-library/src/LibraryDiscoveryController.php',
			'aculect-icon-library/src/LegacyAssetStore.php',
			'aculect-icon-library/src/LegacyMigration.php',
		) as $excluded ) {
			$this->assertNotContains( $excluded, $names );
		}
		$zip->close();
	}

	/**
	 * Runs the packager in the isolated fixture.
	 *
	 * @param string[] $arguments Command-line arguments.
	 * @param int      $expected_status Expected process exit status.
	 * @return int Exit status.
	 */
	private function run_packager( $arguments, $expected_status = 0 ) {
		$build_php = getenv( 'ICON_LIBRARY_BUILD_PHP' );
		if ( false === $build_php || '' === $build_php ) {
			$build_php = PHP_BINARY;
		} else {
			$this->assertTrue( is_file( $build_php ) && is_executable( $build_php ), 'ICON_LIBRARY_BUILD_PHP must name an executable PHP binary.' );
		}
		$command = array_merge( array( $build_php, $this->root . '/scripts/package-release.php' ), $arguments );
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		$this->assertSame( $expected_status, $status, $output );
		return $status;
	}

	/**
	 * Reads archive member names.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return string[]
	 */
	private function zip_names( ZipArchive $zip ) {
		$names = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this standard property name.
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$names[] = $zip->getNameIndex( $index );
		}
		return $names;
	}

	/** Copies only package inputs into the temporary fixture. */
	private function copy_fixture() {
		foreach ( array( 'aculect-icon-library.php', 'uninstall.php', 'readme.txt', 'LICENSE.md' ) as $file ) {
			copy( $this->source_root . '/' . $file, $this->root . '/' . $file );
		}
		foreach ( array( 'src', 'assets/icons', 'scripts/lib', 'data' ) as $directory ) {
			$this->copy_tree( $this->source_root . '/' . $directory, $this->root . '/' . $directory );
		}
		copy( $this->source_root . '/scripts/package-release.php', $this->root . '/scripts/package-release.php' );
		foreach ( array( 'assets/admin.css', 'assets/aculect-icon.svg', 'assets/admin.js', 'assets/library-installer.js', 'assets/build/custom-icons-dataviews.asset.php', 'assets/build/custom-icons-dataviews.js', 'assets/src/custom-icons-dataviews.js', 'assets/icons.css', 'assets/picker-compat.css', 'assets/bounded-picker.js', 'assets/bounded-picker.css' ) as $file ) {
			$destination = $this->root . '/' . $file;
			if ( ! is_dir( dirname( $destination ) ) ) {
				mkdir( dirname( $destination ), 0700, true );
			}
			copy( $this->source_root . '/' . $file, $destination );
		}
	}

	/**
	 * Recursively copies a source directory for isolated build testing.
	 *
	 * @param string $source      Source path.
	 * @param string $destination Destination path.
	 */
	private function copy_tree( $source, $destination ) {
		if ( is_dir( $source ) ) {
			mkdir( $destination, 0700, true );
			foreach ( scandir( $source ) as $item ) {
				if ( '.' !== $item && '..' !== $item ) {
					$this->copy_tree( $source . '/' . $item, $destination . '/' . $item );
				}
			}
			return;
		}
		copy( $source, $destination );
	}

	/**
	 * Removes a path from the test-owned temporary fixture.
	 *
	 * @param string $path Path to remove.
	 */
	private function remove_tree( $path ) {
		if ( ! is_dir( $path ) || is_link( $path ) ) {
			if ( file_exists( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
			return;
		}
		foreach ( scandir( $path ) as $item ) {
			if ( '.' !== $item && '..' !== $item ) {
				$this->remove_tree( $path . '/' . $item );
			}
		}
		rmdir( $path );
	}
}
