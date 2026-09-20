<?php
/**
 * Uninstall cleanup.
 *
 * @package IconLibrary
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'icon_library_enabled_collections' );
delete_option( 'icon_library_enabled_variants' );
delete_option( 'icon_library_custom_icons' );
delete_option( 'icon_library_custom_icons_lock' );
delete_option( 'icon_library_state_lock' );

$icon_library_uploads   = wp_upload_dir();
$icon_library_base      = empty( $icon_library_uploads['error'] ) ? realpath( $icon_library_uploads['basedir'] ) : false;
$icon_library_directory = false !== $icon_library_base ? $icon_library_base . '/icon-library/custom-icons' : '';
$icon_library_resolved  = $icon_library_directory ? realpath( $icon_library_directory ) : false;

// realpath()/is_link()/prefix-of-$icon_library_base checks guard against deleting
// outside the uploads directory if custom-icons (or uploads itself) were ever replaced
// with a symlink between install and uninstall; wp_delete_file()/rmdir() below only
// ever touch $icon_library_directory once it has passed all of these.
if ( $icon_library_directory && false !== $icon_library_resolved && ! is_link( $icon_library_directory ) && 0 === strpos( $icon_library_resolved, $icon_library_base . DIRECTORY_SEPARATOR ) && is_dir( $icon_library_resolved ) ) {
	$icon_library_files = glob( $icon_library_directory . '/*.svg' );
	if ( is_array( $icon_library_files ) ) {
		foreach ( $icon_library_files as $icon_library_file ) {
			wp_delete_file( $icon_library_file );
		}
	}
	// Remove only the now-empty plugin-owned directories.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $icon_library_directory );
	$icon_library_parent = dirname( $icon_library_directory );
	if ( is_dir( $icon_library_parent ) && array( '.', '..' ) === scandir( $icon_library_parent ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $icon_library_parent );
	}
}
