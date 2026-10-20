<?php
/**
 * Fails closed on findings from the pinned Plugin Check CLI.
 *
 * @package IconLibrary
 */

if ( PHP_SAPI !== 'cli' || 2 !== $argc || ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php scripts/validate-plugin-check.php <strict-json-report>\n" );
	exit( 1 );
}

$report = trim( file_get_contents( $argv[1] ) );
// Plugin Check 2.1.0 emits this message instead of JSON when there are no findings.
if ( 'Success: Checks complete. No errors found.' === $report ) {
	echo "Plugin Check passed without findings.\n";
	exit( 0 );
}

$findings = json_decode( $report );
if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $findings ) ) {
	fwrite( STDERR, "Plugin Check output is missing or unrecognized; verification failed.\n" );
	exit( 1 );
}
if ( count( $findings ) ) {
	fwrite( STDERR, sprintf( "Plugin Check reported %d finding(s); review is required.\n%s\n", count( $findings ), $report ) );
	exit( 1 );
}

echo "Plugin Check passed without findings.\n";
