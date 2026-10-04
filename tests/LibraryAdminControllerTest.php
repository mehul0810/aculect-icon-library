<?php
/** @package IconLibrary */

use IconLibrary\LibraryAdminController;
use IconLibrary\LibraryInstaller;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return ! empty( $GLOBALS['icon_library_test_multisite'] );
	}
}
if ( ! function_exists( 'is_super_admin' ) ) {
	function is_super_admin() {
		return ! empty( $GLOBALS['icon_library_test_super_admin'] );
	}
}
if ( ! function_exists( 'wp_is_file_mod_allowed' ) ) {
	function wp_is_file_mod_allowed( $context ) {
		return 'icon_library_install' === $context && ! empty( $GLOBALS['icon_library_test_file_mods'] );
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return 'wp_rest' === $action && 'test-valid-rest' === $nonce;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args ) {
		$GLOBALS['icon_library_test_rest_routes'][ $namespace . $route ] = $args;
	}
}

class LibraryAdminTestRequest extends WP_REST_Request {
	private $test_nonce;
	public function __construct( $params = array(), $nonce = 'test-valid-rest', $method = 'POST' ) {
		parent::__construct( $method, '', $params );
		$this->test_nonce = $nonce;
	}
	public function get_header( $name ) {
		return 'X-WP-Nonce' === $name ? $this->test_nonce : '';
	}
}

class LibraryAdminControllerTest extends TestCase {
	private $installer;
	private $controller;

	protected function setUp(): void {
		$GLOBALS['icon_library_test_capabilities'] = array(
			'manage_options'  => true,
			'install_plugins' => true,
		);
		$GLOBALS['icon_library_test_multisite']    = false;
		$GLOBALS['icon_library_test_super_admin']  = false;
		$GLOBALS['icon_library_test_file_mods']    = true;
		$GLOBALS['icon_library_test_rest_routes']  = array();
		$this->installer                           = $this->getMockBuilder( LibraryInstaller::class )->disableOriginalConstructor()->getMock();
		$this->controller                          = new LibraryAdminController( $this->installer );
	}

	protected function tearDown(): void {
		$GLOBALS['icon_library_test_capabilities'] = array();
		$GLOBALS['icon_library_test_multisite']    = false;
		$GLOBALS['icon_library_test_super_admin']  = false;
		$GLOBALS['icon_library_test_file_mods']    = true;
	}

	private function request( $extra = array(), $nonce = 'test-valid-rest', $method = 'POST' ) {
		return new LibraryAdminTestRequest(
			array_merge(
				array(
					'library' => 'synthetic-test',
					'style'   => 'outline',
					'version' => '1.0.0',
					'job_id'  => 'job-123',
				),
				$extra
			),
			$nonce,
			$method
		);
	}

	public function test_install_and_resume_require_both_capabilities() {
		$this->installer->expects( $this->never() )->method( 'request_install' );
		$this->installer->expects( $this->never() )->method( 'run' );
		foreach ( array( 'manage_options', 'install_plugins' ) as $missing ) {
			$GLOBALS['icon_library_test_capabilities'] = array(
				'manage_options'  => true,
				'install_plugins' => true,
				$missing          => false,
			);
			$this->assertSame( 'icon_library_install_forbidden', $this->controller->create_job( $this->request() )->get_error_code() );
			$this->assertSame( 'icon_library_install_forbidden', $this->controller->run_job( $this->request() )->get_error_code() );
		}
	}

	public function test_explicit_nonce_and_post_are_required_for_every_mutation() {
		$this->installer->expects( $this->never() )->method( 'request_install' );
		$this->installer->expects( $this->never() )->method( 'run' );
		foreach ( array( 'create_job', 'run_job' ) as $method ) {
			$this->assertSame( 'icon_library_install_nonce', $this->controller->$method( $this->request( array(), '' ) )->get_error_code() );
			$this->assertSame( 'icon_library_install_nonce', $this->controller->$method( $this->request( array(), 'expired' ) )->get_error_code() );
			$this->assertSame( 'icon_library_install_method', $this->controller->$method( $this->request( array(), 'test-valid-rest', 'GET' ) )->get_error_code() );
		}
	}

	public function test_file_policy_and_multisite_authority_fail_closed() {
		$this->installer->expects( $this->never() )->method( 'request_install' );
		$GLOBALS['icon_library_test_file_mods'] = false;
		$this->assertSame( 'icon_library_file_modifications_disabled', $this->controller->create_job( $this->request() )->get_error_code() );
		$GLOBALS['icon_library_test_file_mods'] = true;
		$GLOBALS['icon_library_test_multisite'] = true;
		$this->assertSame( 'icon_library_install_forbidden', $this->controller->create_job( $this->request() )->get_error_code() );
		$GLOBALS['icon_library_test_super_admin'] = true;
		$this->assertTrue( $this->controller->can_install( $this->request() ) );
	}

	public function test_only_exact_catalog_identity_is_forwarded() {
		$job = array(
			'job_id' => 'job-123',
			'status' => 'queued',
		);
		$this->installer->expects( $this->once() )->method( 'request_install' )->with( 'synthetic-test', 'outline', '1.0.0-rc.1+build' )->willReturn( $job );
		$result = $this->controller->create_job(
			$this->request(
				array(
					'version'            => '1.0.0-rc.1+build',
					'url'                => 'https://attacker.invalid/archive.zip',
					'lease_token'        => 'attacker',
					'allow_test_fixture' => true,
				)
			)
		);
		$this->assertSame( $job, $result->get_data() );
	}

	public function test_bad_shapes_and_paths_are_rejected_before_service_calls() {
		$this->installer->expects( $this->never() )->method( 'request_install' );
		$this->installer->expects( $this->never() )->method( 'run' );
		foreach ( array( array( 'library' => '../escape' ), array( 'library' => array() ), array( 'style' => '/tmp/icon' ), array( 'version' => '1.0.0/' ), array( 'version' => str_repeat( '9', 100 ) . '.0.0' ) ) as $input ) {
			$this->assertSame( 'icon_library_install_request', $this->controller->create_job( $this->request( $input ) )->get_error_code() );
		}
		$this->assertSame( 'icon_library_install_request', $this->controller->run_job( $this->request( array( 'job_id' => '../escape' ) ) )->get_error_code() );
	}

	public function test_resume_uses_only_server_owned_job_identifier() {
		$job = array(
			'job_id' => 'job-123',
			'status' => 'succeeded',
		);
		$this->installer->expects( $this->once() )->method( 'run' )->with( 'synthetic-test', 'job-123' )->willReturn( $job );
		$this->assertSame(
			$job,
			$this->controller->run_job(
				$this->request(
					array(
						'lease_token' => 'untrusted',
						'generation'  => 900,
					)
				)
			)->get_data()
		);
	}

	public function test_status_is_read_only_and_authorized() {
		$this->installer->expects( $this->never() )->method( 'request_install' );
		$this->installer->expects( $this->never() )->method( 'run' );
		$this->installer->expects( $this->once() )->method( 'get_job' )->with( 'synthetic-test' )->willReturn( array( 'status' => 'running' ) );
		$this->installer->expects( $this->once() )->method( 'get_installed' )->with( 'synthetic-test' )->willReturn( null );
		$result = $this->controller->get_status( $this->request( array(), '', 'GET' ) );
		$this->assertSame(
			array(
				'job'       => array( 'status' => 'running' ),
				'installed' => null,
			),
			$result->get_data()
		);
		$GLOBALS['icon_library_test_capabilities'] = array();
		$this->assertSame( 'icon_library_install_forbidden', $this->controller->get_status( $this->request() )->get_error_code() );
	}

	public function test_failure_is_not_reported_as_success() {
		$error = new WP_Error( 'install_failed', 'A validation check failed.' );
		$this->installer->method( 'request_install' )->willReturn( $error );
		$this->assertSame( $error, $this->controller->create_job( $this->request() ) );
	}

	public function test_untrusted_release_error_preserves_bad_request_status() {
		$error = new WP_Error( 'icon_library_release_untrusted', 'That exact library version is not in the trusted catalog.', array( 'status' => 400 ) );
		$this->installer->expects( $this->once() )->method( 'request_install' )->with( 'lucide', 'outline', '9.9.9' )->willReturn( $error );
		$this->installer->expects( $this->never() )->method( 'run' );

		$result = $this->controller->create_job( $this->request( array( 'library' => 'lucide', 'style' => 'outline', 'version' => '9.9.9' ) ) );
		$this->assertSame( 'icon_library_release_untrusted', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}
}
