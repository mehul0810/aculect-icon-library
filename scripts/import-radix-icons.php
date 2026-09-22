<?php
/**
 * Imports the pinned Radix Icons collection.
 *
 * Usage: php scripts/import-radix-icons.php /path/to/radix-icons-checkout
 *
 * @package IconLibrary
 */

use IconLibrary\Build\CollectionBuild;

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/CollectionBuild.php';

$source_dir = isset( $argv[1] ) ? rtrim( $argv[1], DIRECTORY_SEPARATOR ) : '';
$plugin_dir = dirname( __DIR__ );
$target_dir = $plugin_dir . '/assets/icons/radix';

const RADIX_EXPECTED_SOURCE_COUNT  = 318;
const RADIX_EXPECTED_LOGO_COUNT    = 14;
const RADIX_EXPECTED_OPACITY_COUNT = 5;
const RADIX_EXPECTED_VERSION       = '1.3.2';
const RADIX_EXPECTED_REVISION      = 'bde33b13aa5848555f5512ac12155930fb4beb7d';
const RADIX_TAG_OBJECT             = 'f3c1e3c9c219b0dad91cb3137f9bd1e68aafec03';

$logo_exclusions = array(
	'codesandbox-logo',
	'discord-logo',
	'figma-logo',
	'framer-logo',
	'github-logo',
	'iconjar-logo',
	'instagram-logo',
	'linkedin-logo',
	'modulz-logo',
	'notion-logo',
	'sketch-logo',
	'stitches-logo',
	'twitter-logo',
	'vercel-logo',
);

$opacity_exclusions = array(
	'shadow',
	'shadow-inner',
	'shadow-none',
	'shadow-outer',
	'transparency-grid',
);

if ( ! is_dir( $source_dir ) ) {
	fwrite( STDERR, "Provide the official Radix Icons source checkout.\n" );
	exit( 1 );
}

$package_path = $source_dir . '/packages/radix-icons/package.json';
$package      = json_decode( (string) @file_get_contents( $package_path ), true );
$version      = is_array( $package ) && isset( $package['version'] ) ? (string) $package['version'] : '';
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Reads the pinned revision from a pre-validated local checkout.
$revision = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $source_dir ) . ' rev-parse HEAD 2>/dev/null' ) );

if ( RADIX_EXPECTED_VERSION !== $version || RADIX_EXPECTED_REVISION !== $revision ) {
	fwrite( STDERR, "Source checkout does not match the pinned Radix Icons release.\n" );
	exit( 1 );
}

try {
	CollectionBuild::validate_source_checkout( $source_dir, 'https://github.com/radix-ui/icons', 'LICENSE' );
	$license_source = CollectionBuild::get_contained_source_file( $source_dir, 'LICENSE' );
	$website_source = CollectionBuild::get_contained_source_file( $source_dir, 'packages/website/components/AllIcons.tsx' );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

$source_files = glob( $source_dir . '/packages/radix-icons/icons/*.svg' );
sort( $source_files );
if ( RADIX_EXPECTED_SOURCE_COUNT !== count( $source_files ) ) {
	fwrite( STDERR, sprintf( "Expected %d pinned Radix icons; found %d.\n", RADIX_EXPECTED_SOURCE_COUNT, count( $source_files ) ) );
	exit( 1 );
}

/**
 * Removes a previous generated collection without following symlinks.
 *
 * @param string $directory Generated collection directory.
 * @return void
 */
$reset_directory = static function ( $directory ) use ( $plugin_dir ) {
	$parent = realpath( dirname( $directory ) );
	$root   = realpath( $plugin_dir . '/assets/icons' );
	if ( false === $parent || false === $root || $parent !== $root || is_link( $directory ) ) {
		throw new RuntimeException( 'Generated collection path is unsafe.' );
	}
	if ( ! is_dir( $directory ) ) {
		return;
	}
	$entries = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $entries as $entry ) {
		$path = $entry->getPathname();
		if ( $entry->isLink() || $entry->isFile() ) {
			unlink( $path );
		} elseif ( $entry->isDir() ) {
			rmdir( $path );
		}
	}
	rmdir( $directory );
};

$build_dir = $target_dir . '.tmp-' . getmypid();
try {
	$reset_directory( $build_dir );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

if ( ! mkdir( $build_dir . '/default', 0755, true ) ) {
	fwrite( STDERR, "Could not create the Radix Icons target directory.\n" );
	exit( 1 );
}

if ( ! copy( $license_source, $build_dir . '/LICENSE' ) ) {
	fwrite( STDERR, "Could not copy the Radix Icons license.\n" );
	exit( 1 );
}

$build_finalized = false;
register_shutdown_function(
	static function () use ( &$build_finalized, $build_dir, $reset_directory ) {
		if ( $build_finalized || ! is_dir( $build_dir ) ) {
			return;
		}
		try {
			$reset_directory( $build_dir );
		} catch ( RuntimeException $exception ) {
			// A failed import already reports its primary error; never mask it here.
		}
	}
);

$slugify = static function ( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
	return trim( (string) $value, '-' );
};

/**
 * Maps upstream website groupings to source SVG slugs.
 *
 * The website is the upstream source of category labels. Component names and
 * SVG slugs are matched after removing punctuation, avoiding fragile acronym
 * conversions such as ID, GitHub, or CodeSandbox.
 *
 * @param string[] $source_files    Pinned SVG paths.
 * @param string   $website_source Upstream website component path.
 * @return array<string,array{category:string,label:string}>
 */
$read_categories = static function ( $source_files, $website_source ) {
	$slug_by_key = array();
	foreach ( $source_files as $file ) {
		$slug = basename( $file, '.svg' );
		$key  = preg_replace( '/[^a-z0-9]/', '', strtolower( $slug ) );
		if ( isset( $slug_by_key[ $key ] ) ) {
			throw new RuntimeException( 'Radix source contains an ambiguous normalized icon name.' );
		}
		$slug_by_key[ $key ] = $slug;
	}

	$source         = (string) file_get_contents( $website_source );
	$category_names = array( 'Typography', 'Music', 'Abstract', 'Arrows', 'Objects', 'Design', 'Components', 'Borders', 'Alignment' );
	$mapping        = array();
	foreach ( $category_names as $category_name ) {
		$pattern = '/const\s+' . preg_quote( $category_name, '/' ) . '\s*=\s*\(\)\s*=>\s*\{(.*?)(?=\nconst\s+[A-Z][A-Za-z0-9]+\s*=\s*\(\)\s*=>|\z)/s';
		if ( ! preg_match( $pattern, $source, $section_match ) ) {
			throw new RuntimeException( 'Could not read the upstream ' . $category_name . ' category.' );
		}
		if ( ! preg_match_all( '/<Icons\.([A-Za-z0-9]+)Icon\b/', $section_match[1], $icon_matches, PREG_SET_ORDER ) ) {
			throw new RuntimeException( 'The upstream ' . $category_name . ' category is empty.' );
		}
		foreach ( $icon_matches as $icon_match ) {
			$key = preg_replace( '/[^a-z0-9]/', '', strtolower( $icon_match[1] ) );
			if ( ! isset( $slug_by_key[ $key ] ) ) {
				throw new RuntimeException( 'Could not match upstream component to an SVG: ' . $icon_match[1] . '.' );
			}
			$slug = $slug_by_key[ $key ];
			if ( isset( $mapping[ $slug ] ) ) {
				throw new RuntimeException( 'Upstream website assigns an icon to multiple categories: ' . $slug . '.' );
			}
			$mapping[ $slug ] = array(
				'category' => 'Borders' === $category_name ? 'Borders and corners' : $category_name,
				'label'    => ucwords( str_replace( '-', ' ', $slug ) ),
			);
		}
	}

	if ( 303 !== count( $mapping ) ) {
		throw new RuntimeException( sprintf( 'Expected 303 categorized non-logo upstream icons; found %d.', count( $mapping ) ) );
	}

	return $mapping;
};

/**
 * Formats a finite decimal without locale-dependent output or trailing zeros.
 *
 * @param float $number Numeric path coordinate.
 * @return string
 */
$format_number = static function ( $number ) {
	$formatted = rtrim( rtrim( sprintf( '%.6F', $number ), '0' ), '.' );
	return '-0' === $formatted ? '0' : $formatted;
};

/**
 * Converts Core-incompatible circle and rectangle elements to exact paths.
 *
 * @param string $svg Upstream SVG markup.
 * @return string
 */
$convert_geometry = static function ( $svg ) use ( $format_number ) {
	$previous = libxml_use_internal_errors( true );
	$document = new DOMDocument();
	$loaded   = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument exposes this standard property name.
	$document_element = $document->documentElement;
	if ( ! $loaded || ! $document_element instanceof DOMElement ) {
		throw new RuntimeException( 'Radix SVG is not valid XML.' );
	}

	$root  = $document_element;
	$xpath = new DOMXPath( $document );
	if ( 'none' === strtolower( $root->getAttribute( 'fill' ) ) ) {
		$geometry = $xpath->query( '//*[local-name()="path" or local-name()="polygon" or local-name()="rect" or local-name()="circle"]' );
		foreach ( $geometry as $element ) {
			if ( ! $element instanceof DOMElement || 'currentcolor' !== strtolower( $element->getAttribute( 'fill' ) ) ) {
				throw new RuntimeException( 'Root fill="none" is not redundant.' );
			}
		}
		$root->removeAttribute( 'fill' );
	}

	$copy_path_attributes = static function ( DOMElement $source, DOMElement $path ) {
		foreach ( array( 'fill', 'fill-rule', 'transform', 'focusable' ) as $attribute ) {
			if ( $source->hasAttribute( $attribute ) ) {
				$path->setAttribute( $attribute, $source->getAttribute( $attribute ) );
			}
		}
	};

	foreach ( iterator_to_array( $document->getElementsByTagName( 'circle' ) ) as $circle ) {
		$cx = (float) $circle->getAttribute( 'cx' );
		$cy = (float) $circle->getAttribute( 'cy' );
		$r  = (float) $circle->getAttribute( 'r' );
		if ( $r <= 0 ) {
			throw new RuntimeException( 'Circle radius must be positive.' );
		}
		$d    = sprintf(
			'M %1$s %2$s a %3$s %3$s 0 1 0 -%4$s 0 a %3$s %3$s 0 1 0 %4$s 0',
			$format_number( $cx + $r ),
			$format_number( $cy ),
			$format_number( $r ),
			$format_number( 2 * $r )
		);
		$path = $document->createElement( 'path' );
		$path->setAttribute( 'd', $d );
		$copy_path_attributes( $circle, $path );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes this standard property name.
		$circle->parentNode->replaceChild( $path, $circle );
	}

	foreach ( iterator_to_array( $document->getElementsByTagName( 'rect' ) ) as $rect ) {
		$x_attribute  = $rect->getAttribute( 'x' );
		$y_attribute  = $rect->getAttribute( 'y' );
		$rx_attribute = $rect->getAttribute( 'rx' );
		$ry_attribute = $rect->getAttribute( 'ry' );
		$x            = (float) ( '' === $x_attribute ? 0 : $x_attribute );
		$y            = (float) ( '' === $y_attribute ? 0 : $y_attribute );
		$w            = (float) $rect->getAttribute( 'width' );
		$h            = (float) $rect->getAttribute( 'height' );
		$rx           = (float) ( '' === $rx_attribute ? 0 : $rx_attribute );
		$ry           = (float) ( '' === $ry_attribute ? $rx : $ry_attribute );
		if ( $w <= 0 || $h <= 0 || $rx < 0 || $ry < 0 ) {
			throw new RuntimeException( 'Rectangle geometry is invalid.' );
		}
		$rx = min( $rx, $w / 2 );
		$ry = min( $ry, $h / 2 );
		if ( $rx > 0 || $ry > 0 ) {
			$d = sprintf(
				'M %1$s %2$s H %3$s A %4$s %5$s 0 0 1 %6$s %7$s V %8$s A %4$s %5$s 0 0 1 %3$s %9$s H %1$s A %4$s %5$s 0 0 1 %10$s %8$s V %7$s A %4$s %5$s 0 0 1 %1$s %2$s Z',
				$format_number( $x + $rx ),
				$format_number( $y ),
				$format_number( $x + $w - $rx ),
				$format_number( $rx ),
				$format_number( $ry ),
				$format_number( $x + $w ),
				$format_number( $y + $ry ),
				$format_number( $y + $h - $ry ),
				$format_number( $y + $h ),
				$format_number( $x )
			);
		} else {
			$d = sprintf(
				'M %1$s %2$s H %3$s V %4$s H %1$s Z',
				$format_number( $x ),
				$format_number( $y ),
				$format_number( $x + $w ),
				$format_number( $y + $h )
			);
		}
		$path = $document->createElement( 'path' );
		$path->setAttribute( 'd', $d );
		$copy_path_attributes( $rect, $path );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes this standard property name.
		$rect->parentNode->replaceChild( $path, $rect );
	}

	$normalized_source = $document->saveXML( $root );
	if ( ! is_string( $normalized_source ) ) {
		throw new RuntimeException( 'Radix SVG conversion failed.' );
	}
	return CollectionBuild::normalize_svg( $normalized_source );
};

try {
	$category_map = $read_categories( $source_files, $website_source );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}

$icons            = array();
$exclusions       = array();
$category_members = array();

foreach ( $source_files as $file ) {
	$slug = basename( $file, '.svg' );
	if ( in_array( $slug, $logo_exclusions, true ) ) {
		$exclusions[] = array(
			'slug'   => $slug,
			'type'   => 'trademark',
			'reason' => 'Third-party logo excluded from the non-brand 1.1.0 collection scope.',
		);
		continue;
	}
	if ( in_array( $slug, $opacity_exclusions, true ) ) {
		$exclusions[] = array(
			'slug'   => $slug,
			'type'   => 'compatibility',
			'reason' => 'Per-element opacity semantics are not preserved by the WordPress 7.1 Icon API.',
		);
		continue;
	}

	try {
		$source = CollectionBuild::get_contained_source_file( $source_dir, 'packages/radix-icons/icons/' . $slug . '.svg' );
		$svg    = (string) file_get_contents( $source );
		if ( preg_match( '/\sopacity\s*=/i', $svg ) ) {
			throw new RuntimeException( 'Unexpected opacity attribute outside the approved exclusion set.' );
		}
		$svg = $convert_geometry( $svg );
	} catch ( RuntimeException $exception ) {
		fwrite( STDERR, $slug . ': ' . $exception->getMessage() . "\n" );
		exit( 1 );
	}

	$metadata = $category_map[ $slug ] ?? null;
	if ( null === $metadata && 'border-width' !== $slug ) {
		fwrite( STDERR, $slug . ": icon is missing from the pinned upstream categories.\n" );
		exit( 1 );
	}
	$category_label = null === $metadata ? 'General' : $metadata['category'];
	$category_slug  = $slugify( $category_label );
	$label          = null === $metadata ? ucwords( str_replace( '-', ' ', $slug ) ) : $metadata['label'];

	$relative = 'default/' . $slug . '.svg';
	$target   = $build_dir . '/' . $relative;
	if ( false === file_put_contents( $target, $svg . "\n" ) ) {
		fwrite( STDERR, "Could not write generated SVG: $relative.\n" );
		exit( 1 );
	}

	$category_members[ $category_slug ]['label']          = $category_label;
	$category_members[ $category_slug ]['icons'][ $slug ] = true;
	$icons[] = array(
		'id'           => 'radix/default/' . $slug,
		'coreIconName' => 'radix/' . $slug . '-default',
		'label'        => $label,
		'variant'      => 'default',
		'categories'   => array( $category_slug ),
		'keywords'     => array_values( array_unique( explode( '-', $slug ) ) ),
		'path'         => $relative,
		'sha256'       => hash_file( 'sha256', $target ),
	);
}

$logo_count    = count( array_filter( $exclusions, static fn( $item ) => 'trademark' === $item['type'] ) );
$opacity_count = count( array_filter( $exclusions, static fn( $item ) => 'compatibility' === $item['type'] ) );
if ( RADIX_EXPECTED_LOGO_COUNT !== $logo_count || RADIX_EXPECTED_OPACITY_COUNT !== $opacity_count || 299 !== count( $icons ) ) {
	fwrite( STDERR, "Radix inclusion and exclusion counts do not match the approved scope.\n" );
	exit( 1 );
}

$category_order = array( 'typography', 'music', 'abstract', 'arrows', 'objects', 'design', 'components', 'borders-and-corners', 'alignment', 'general' );
$categories     = array();
foreach ( $category_order as $category_slug ) {
	if ( ! isset( $category_members[ $category_slug ] ) ) {
		continue;
	}
	$categories[] = array(
		'slug'      => $category_slug,
		'label'     => $category_members[ $category_slug ]['label'],
		'iconCount' => count( $category_members[ $category_slug ]['icons'] ),
	);
}

$manifest = array(
	'schemaVersion' => CollectionBuild::SCHEMA_VERSION,
	'slug'          => 'radix',
	'name'          => 'Radix Icons',
	'description'   => 'A non-logo collection of crisp 15-pixel icons from Radix Icons.',
	'version'       => $version,
	'license'       => array(
		'name' => 'MIT',
		'url'  => 'https://github.com/radix-ui/icons/blob/' . RADIX_EXPECTED_REVISION . '/LICENSE',
	),
	'source'        => array(
		'name'     => 'radix-ui/icons',
		'url'      => 'https://github.com/radix-ui/icons',
		'revision' => $revision,
	),
	'variants'      => array(
		array(
			'slug'           => 'default',
			'label'          => 'Default',
			'coreCompatible' => true,
			'defaultEnabled' => true,
			'iconCount'      => count( $icons ),
		),
	),
	'categories'    => $categories,
	'icons'         => $icons,
);

$errors = CollectionBuild::validate_manifest( $manifest, $build_dir );
if ( $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

$exclusion_report = array(
	'schemaVersion'     => 1,
	'slug'              => 'radix',
	'version'           => $version,
	'sourceRevision'    => $revision,
	'sourceTagObject'   => RADIX_TAG_OBJECT,
	'includedIconCount' => count( $icons ),
	'excludedIconCount' => count( $exclusions ),
	'exclusionRule'     => 'Exclude third-party logos and icons whose opacity semantics cannot be preserved by the WordPress 7.1 Icon API.',
	'exclusions'        => $exclusions,
);

foreach ( array(
	'manifest.json'   => $manifest,
	'exclusions.json' => $exclusion_report,
) as $filename => $data ) {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	if ( false === file_put_contents( $build_dir . '/' . $filename, $json ) ) {
		fwrite( STDERR, "Could not write $filename.\n" );
		exit( 1 );
	}
}

try {
	$reset_directory( $target_dir );
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, $exception->getMessage() . "\n" );
	exit( 1 );
}
if ( ! rename( $build_dir, $target_dir ) ) {
	fwrite( STDERR, "Could not finalize the generated Radix Icons collection.\n" );
	exit( 1 );
}
$build_finalized = true;

printf( "Imported %d Radix icons and recorded %d exclusions.\n", count( $icons ), count( $exclusions ) );
