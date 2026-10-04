<?php
/**
 * Authorized optional-library installation endpoints.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps browser requests separate from trusted package and storage inputs. */
class LibraryAdminController {
	/**
	 * Installation service.
	 *
	 * @var LibraryInstaller
	 */
	private $installer;

	/**
	 * Constructor.
	 *
	 * @param LibraryInstaller $installer Installation service.
	 */
	public function __construct( LibraryInstaller $installer ) {
		$this->installer = $installer;
	}

	/** Registers REST and non-JavaScript form handlers. */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_icon_library_install_package', array( $this, 'submit_form' ) );
	}

	/** Registers explicit installation and read-only recovery endpoints. */
	public function register_routes() {
		$route = '/library-packages/(?P<library>[a-z0-9]+(?:-[a-z0-9]+)*)/jobs';
		register_rest_route(
			Plugin::REST_NAMESPACE,
			$route,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_job' ),
					'permission_callback' => array( $this, 'can_install' ),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NAMESPACE,
			$route . '/(?P<job_id>[a-zA-Z0-9-]+)/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'run_job' ),
				'permission_callback' => array( $this, 'can_install' ),
			)
		);
	}

	/**
	 * Checks access to installation state without changing it.
	 *
	 * @return bool
	 */
	public function can_read() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Checks installation authority and file-modification policy.
	 *
	 * @return true|\WP_Error
	 */
	public static function installation_permission() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'install_plugins' ) || ( is_multisite() && ! is_super_admin() ) ) {
			return new \WP_Error( 'icon_library_install_forbidden', __( 'You do not have permission to install icon libraries on this site.', 'aculect-icon-library' ), array( 'status' => 403 ) );
		}
		if ( ! wp_is_file_mod_allowed( 'icon_library_install' ) ) {
			return new \WP_Error( 'icon_library_file_modifications_disabled', __( 'File modifications are disabled for this site.', 'aculect-icon-library' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Authorizes each REST mutation, including retries and resumptions.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public function can_install( $request ) {
		$permission = self::installation_permission();
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) || 'POST' !== $request->get_method() ) {
			return new \WP_Error( 'icon_library_install_method', __( 'Installation requires a form submission.', 'aculect-icon-library' ), array( 'status' => 405 ) );
		}
		$nonce = is_object( $request ) && method_exists( $request, 'get_header' ) ? $request->get_header( 'X-WP-Nonce' ) : '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'icon_library_install_nonce', __( 'Your session has expired. Reload this page before installing a library.', 'aculect-icon-library' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Returns durable state for page reloads and progress checks.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		if ( ! $this->can_read() ) {
			return new \WP_Error( 'icon_library_install_forbidden', __( 'You do not have permission to view icon library jobs.', 'aculect-icon-library' ), array( 'status' => 403 ) );
		}
		$library = $request->get_param( 'library' );
		if ( ! $this->valid_identifier( $library ) ) {
			return $this->invalid_request();
		}
		return rest_ensure_response(
			array(
				'job'       => $this->installer->get_job( $library ),
				'installed' => $this->installer->get_installed( $library ),
			)
		);
	}

	/**
	 * Queues an exact trusted catalog entry after explicit user consent.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_job( $request ) {
		$permission = $this->can_install( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$library = $request->get_param( 'library' );
		$style   = $request->get_param( 'style' );
		$version = $request->get_param( 'version' );
		if ( ! $this->valid_identifier( $library ) || ! $this->valid_identifier( $style ) || ! ( new TrustedLibraryCatalog() )->is_valid_version( $version ) ) {
			return $this->invalid_request();
		}
		$result = $this->installer->request_install( $library, $style, $version );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Runs or resumes only the server-owned job selected by its identifier.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function run_job( $request ) {
		$permission = $this->can_install( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$library = $request->get_param( 'library' );
		$job_id  = $request->get_param( 'job_id' );
		if ( ! $this->valid_identifier( $library ) || ! is_string( $job_id ) || strlen( $job_id ) > 128 || 1 !== preg_match( '/^[a-zA-Z0-9-]+$/D', $job_id ) ) {
			return $this->invalid_request();
		}
		$result = $this->installer->run( $library, $job_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Handles the same workflow when JavaScript is unavailable. */
	public function submit_form() {
		$permission = self::installation_permission();
		if ( is_wp_error( $permission ) ) {
			wp_die( esc_html( $permission->get_error_message() ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			wp_die( esc_html__( 'Installation requires a form submission.', 'aculect-icon-library' ), '', array( 'response' => 405 ) );
		}
		check_admin_referer( 'icon_library_install_package' );
		// Preserve exact identifiers so invalid input cannot become a trusted selection.
		$library = isset( $_POST['library'] ) && is_string( $_POST['library'] ) ? wp_unslash( $_POST['library'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strictly validated below.
		$style   = isset( $_POST['style'] ) && is_string( $_POST['style'] ) ? wp_unslash( $_POST['style'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strictly validated below.
		$version = isset( $_POST['version'] ) && is_string( $_POST['version'] ) ? wp_unslash( $_POST['version'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strictly validated below.
		$job_id  = isset( $_POST['job_id'] ) && is_string( $_POST['job_id'] ) ? wp_unslash( $_POST['job_id'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strictly validated below.
		$result  = $this->invalid_request();
		if ( $this->valid_identifier( $library ) && $this->valid_identifier( $style ) && ( new TrustedLibraryCatalog() )->is_valid_version( $version ) ) {
			if ( '' === $job_id ) {
				$result = $this->installer->request_install( $library, $style, $version );
				$job_id = ! is_wp_error( $result ) && is_array( $result ) && isset( $result['job_id'] ) ? $result['job_id'] : '';
			}
			$complete = ! is_wp_error( $result ) && is_array( $result ) && 'succeeded' === ( $result['status'] ?? '' );
			if ( ! $complete && is_string( $job_id ) && '' !== $job_id && strlen( $job_id ) <= 128 && 1 === preg_match( '/^[a-zA-Z0-9-]+$/D', $job_id ) ) {
				$result = $this->installer->run( $library, $job_id );
			}
		}
		$args = array(
			'page' => AdminPage::MENU_SLUG,
			'tab'  => 'packages',
		);
		if ( is_wp_error( $result ) ) {
			$args['package-error'] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'themes.php' ) ) );
		exit;
	}

	/**
	 * Validates an exact catalog identifier rather than accepting a URL or path.
	 *
	 * @param mixed $value Candidate identifier.
	 * @return bool
	 */
	private function valid_identifier( $value ) {
		return is_string( $value ) && strlen( $value ) <= 100 && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value );
	}

	/**
	 * Returns a bounded error without reflecting input or filesystem details.
	 *
	 * @return \WP_Error
	 */
	private function invalid_request() {
		return new \WP_Error( 'icon_library_install_request', __( 'Select a valid library package and version.', 'aculect-icon-library' ), array( 'status' => 400 ) );
	}
}
