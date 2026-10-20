<?php
/**
 * Imports the pinned, fully filled-path subset of Iconoir Solid.
 *
 * Usage: php scripts/import-iconoir.php /path/to/iconoir-v7.12.1
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

require_once __DIR__ . '/lib/CollectionBuild.php';

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$source   = isset( $argv[1] ) ? realpath( $argv[1] ) : false;
$revision = 'd7dfa4d0341df0670bfed9fc24221c9d7ef2112e';
$version  = '7.12.1';
$upstream = 'https://github.com/iconoir-icons/iconoir';
$target   = dirname( __DIR__ ) . '/assets/icons/iconoir';
$build    = $target . '.tmp-' . getmypid();

try {
	if ( false === $source ) {
		throw new RuntimeException( 'Provide the pinned official Iconoir checkout.' );
	}
	CollectionBuild::validate_source_checkout( $source, $upstream, 'LICENSE' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Reads the immutable upstream source revision at build time.
	$actual  = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source ) . ' rev-parse HEAD' ) );
	$package = json_decode( file_get_contents( CollectionBuild::get_contained_source_file( $source, 'package.json' ) ), true );
	if ( $revision !== $actual || $version !== $package['version'] ) {
		throw new RuntimeException( 'Unexpected Iconoir version or revision.' );
	}
	if ( file_exists( $build ) || is_link( $target ) ) {
		throw new RuntimeException( 'Unsafe generated target.' );
	}
	if ( ! mkdir( $build . '/solid', 0755, true ) ) {
		throw new RuntimeException( 'Could not create generated directory.' );
	}
	$remove_generated = static function ( $directory ) {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $entry ) {
			if ( $entry->isLink() || $entry->isFile() ) {
				unlink( $entry->getPathname() );
			} else {
				rmdir( $entry->getPathname() );
			}
		}
		rmdir( $directory );
	};
	$finalized        = false;
	CollectionBuild::register_shutdown_cleanup( $finalized, $build, $remove_generated );
	if ( ! copy( CollectionBuild::get_contained_source_file( $source, 'LICENSE' ), $build . '/LICENSE' ) ) {
		throw new RuntimeException( 'Could not preserve license.' );
	}
	$icons      = array();
	$exclusions = array();
	foreach ( array(
		'regular' => 1383,
		'solid'   => 288,
	) as $variant => $expected ) {
		$files = glob( $source . '/icons/' . $variant . '/*.svg' );
		sort( $files );
		if ( count( $files ) !== $expected ) {
			throw new RuntimeException( 'Unexpected pinned source count.' );
		}
		foreach ( $files as $file ) {
			$slug = basename( $file, '.svg' );
			$file = CollectionBuild::get_contained_source_file( $source, 'icons/' . $variant . '/' . $slug . '.svg' );
			try {
				if ( 'regular' === $variant ) {
					throw new RuntimeException( 'Regular stroke geometry requires a separately verified stroke-to-path converter.' );
				}
				$document = new DOMDocument();
				if ( ! $document->loadXML( file_get_contents( $file ), LIBXML_NONET | LIBXML_NOBLANKS ) ) {
					throw new RuntimeException( 'Invalid SVG document.' );
				}
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
				$root = $document->documentElement;
				foreach ( $document->getElementsByTagName( '*' ) as $element ) {
					if ( $element === $root ) {
						continue;
					}
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
					if ( ! in_array( $element->tagName, array( 'path', 'polygon' ), true ) || 'currentColor' !== $element->getAttribute( 'fill' ) ) {
						throw new RuntimeException( 'Mixed stroke, clipping, or non-filled-path geometry requires faithful build-time conversion.' );
					}
				}
				// Every child explicitly supplies its fill and no accepted child can
				// carry stroke. These two unused root hints have no visible effect.
				$root->removeAttribute( 'fill' );
				$root->removeAttribute( 'stroke-width' );
				$svg = CollectionBuild::normalize_svg( $document->saveXML( $root ) );
			} catch ( RuntimeException $exception ) {
				$exclusions[] = array(
					'slug'    => $slug,
					'variant' => $variant,
					'reason'  => $exception->getMessage(),
				);
				continue;
			}
			$relative = 'solid/' . $slug . '.svg';
			if ( false === file_put_contents( $build . '/' . $relative, $svg . "\n" ) ) {
				throw new RuntimeException( 'Could not write icon.' );
			}
			$icons[] = array(
				'id'           => 'iconoir/solid/' . $slug,
				'coreIconName' => 'iconoir/' . $slug . '-solid',
				'label'        => ucwords( str_replace( '-', ' ', $slug ) ),
				'variant'      => 'solid',
				'categories'   => array( 'general' ),
				'keywords'     => array_values( array_unique( explode( '-', $slug ) ) ),
				'path'         => $relative,
				'sha256'       => hash_file( 'sha256', $build . '/' . $relative ),
			);
		}
	}
	if ( 210 !== count( $icons ) || 1461 !== count( $exclusions ) ) {
		throw new RuntimeException( 'Compatibility scope changed unexpectedly.' );
	}
	$manifest = array(
		'schemaVersion' => CollectionBuild::SCHEMA_VERSION,
		'slug'          => 'iconoir',
		'name'          => 'Iconoir',
		'description'   => 'The compatible filled-path subset of Iconoir Solid. Regular and mixed-stroke icons are deferred.',
		'version'       => $version,
		'license'       => array(
			'name' => 'MIT',
			'url'  => $upstream . '/blob/' . $revision . '/LICENSE',
		),
		'source'        => array(
			'name'     => 'iconoir-icons/iconoir',
			'url'      => $upstream,
			'revision' => $revision,
		),
		'variants'      => array(
			array(
				'slug'           => 'solid',
				'label'          => 'Solid',
				'coreCompatible' => true,
				'defaultEnabled' => false,
				'iconCount'      => count( $icons ),
			),
		),
		'categories'    => array(
			array(
				'slug'      => 'general',
				'label'     => 'General',
				'iconCount' => count( $icons ),
			),
		),
		'icons'         => $icons,
	);
	$errors   = CollectionBuild::validate_manifest( $manifest, $build );
	if ( $errors ) {
		throw new RuntimeException( implode( "\n", $errors ) );
	}
	$report = array(
		'schemaVersion'     => 1,
		'slug'              => 'iconoir',
		'version'           => $version,
		'sourceRevision'    => $revision,
		'includedIconCount' => 210,
		'excludedIconCount' => 1461,
		'exclusions'        => $exclusions,
	);
	foreach ( array(
		'manifest.json'   => $manifest,
		'exclusions.json' => $report,
	) as $name => $data ) {
		if ( false === file_put_contents( $build . '/' . $name, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
			throw new RuntimeException( 'Could not write generated metadata.' );
		}
	}
	$backup = $build . '.previous';
	if ( is_dir( $target ) && ! rename( $target, $backup ) ) {
		throw new RuntimeException( 'Could not preserve previous collection.' );
	}
	if ( ! rename( $build, $target ) ) {
		if ( is_dir( $backup ) ) {
			rename( $backup, $target );
		}
		throw new RuntimeException( 'Could not finalize collection.' );
	}
	$finalized = true;
	if ( is_dir( $backup ) ) {
		$remove_generated( $backup );
	}
	printf( "Imported %d Iconoir Solid icons; recorded %d compatibility exclusions.\n", count( $icons ), count( $exclusions ) );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
