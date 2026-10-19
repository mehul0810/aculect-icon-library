<?php
/**
 * Serialized post-content updates for automated block edits.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Preserves Core update hooks while preventing a stale content overwrite. */
class PostContentWriter {
	/**
	 * Saves against the exact snapshot used to compute an edit.
	 *
	 * @param object $original Original post snapshot.
	 * @param string $content  New serialized block content.
	 * @return true|WP_Error
	 */
	public function save( $original, $content ) {
		global $wpdb;
		$transaction = new DatabaseTransaction();
		if ( ! isset( $wpdb->posts, $wpdb->postmeta ) || ! $transaction->begin( array( $wpdb->posts, $wpdb->postmeta ) ) ) {
			return new WP_Error( 'icon_library_ability_transaction_unavailable', __( 'The database cannot safely lock this post for an automated edit. Try again outside an existing transaction, or contact your administrator.', 'aculect-icon-library' ), array( 'status' => 503 ) );
		}

		try {
			// Lock before reading: an ordinary editor save also takes this row lock.
			// SQLite uses BEGIN IMMEDIATE and strips the MySQL FOR UPDATE clause.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conflict detection requires an authoritative row lock, not cached post data.
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", (int) $original->ID ) );
			if ( ! is_object( $current ) ) {
				return new WP_Error( 'icon_library_ability_post_unavailable', __( 'The post could not be locked and read. Refresh the block list and try again.', 'aculect-icon-library' ), array( 'status' => 409 ) );
			}
			if ( $original->post_content !== $current->post_content || (string) ( $original->post_modified_gmt ?? '' ) !== (string) $current->post_modified_gmt ) {
				return new WP_Error( 'icon_library_ability_stale_post', __( 'The post changed after it was read. Refresh the block list and try again.', 'aculect-icon-library' ), array( 'status' => 409 ) );
			}

			clean_post_cache( (int) $original->ID );
			$updated = wp_update_post(
				array(
					'ID'           => (int) $original->ID,
					'post_content' => wp_slash( $content ),
				),
				true
			);
			if ( is_wp_error( $updated ) || ! $updated ) {
				return is_wp_error( $updated ) ? $updated : new WP_Error( 'icon_library_ability_update_failed', __( 'The post could not be updated.', 'aculect-icon-library' ), array( 'status' => 500 ) );
			}
			if ( ! $transaction->commit() ) {
				return new WP_Error( 'icon_library_ability_update_failed', __( 'The post update could not be committed. Refresh the block list before retrying.', 'aculect-icon-library' ), array( 'status' => 500 ) );
			}
			return true;
		} finally {
			$transaction->rollback();
			// Core may have cached an uncommitted value, including on a failed save.
			clean_post_cache( (int) $original->ID );
		}
	}
}
