<?php
/**
 * Administrator consent for catalog and sample requests.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Uses nonce-protected forms; page views never trigger remote downloads. */
class LibraryDiscoveryController {
	/**
	 * Catalog and preview service.
	 *
	 * @var LibraryDiscoveryCatalog
	 */
	private $catalog;

	/**
	 * Constructor.
	 *
	 * @param LibraryDiscoveryCatalog $catalog Catalog service.
	 */
	public function __construct( LibraryDiscoveryCatalog $catalog ) {
		$this->catalog = $catalog;
	}

	/** Registers explicit consent actions. */
	public function register() {
		add_action( 'admin_post_icon_library_discover', array( $this, 'submit' ) );
		add_action( LibraryDiscoveryCatalog::UPDATE_HOOK, array( $this->catalog, 'run_scheduled_update' ) );
	}

	/** Performs a bounded metadata request after nonce and capability checks. */
	public function submit() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Use the catalog form to change these settings.', 'aculect-icon-library' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to browse icon libraries.', 'aculect-icon-library' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'icon_library_discover' );
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$args = array(
			'page' => AdminPage::MENU_SLUG,
			'tab'  => 'browse',
		);
		if ( 'updates' === $mode ) {
			$this->catalog->set_updates_enabled( isset( $_POST['updates'] ) && '1' === $_POST['updates'] );
			$result                   = true;
			$args['discovery_result'] = 'settings';
		} elseif ( 'refresh' === $mode ) {
			$result = $this->catalog->refresh();
		} elseif ( 'preview' === $mode ) {
			$library = isset( $_POST['library'] ) && is_string( $_POST['library'] ) ? sanitize_key( wp_unslash( $_POST['library'] ) ) : '';
			$style   = isset( $_POST['style'] ) && is_string( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : '';
			$version = isset( $_POST['version'] ) && is_string( $_POST['version'] ) ? wp_unslash( $_POST['version'] ) : '';
			$result  = $this->catalog->preview( $library, $style, $version );
			if ( ! is_wp_error( $result ) ) {
				$args['preview_library'] = $library;
				$args['preview_style']   = $style;
				$args['preview_version'] = $version;
			}
		} else {
			$result = new \WP_Error( 'invalid_action' );
		}
		if ( ! isset( $args['discovery_result'] ) ) {
			$args['discovery_result'] = is_wp_error( $result ) ? 'failed' : 'success';
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'themes.php' ) ) );
		exit;
	}
}
