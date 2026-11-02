<?php
/**
 * Bundled admin page regression tests.
 *
 * @package IconLibrary
 */

use IconLibrary\AdminPage;
use IconLibrary\CollectionRegistry;
use IconLibrary\SvgSanitizer;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_attr' ) ) {
	/** Escape test attributes.
	 *
	 * @param string $value Attribute value.
	 */
	function esc_attr( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	/** Escape test text.
	 *
	 * @param string $value Text value.
	 */
	function esc_html( $value ) {
		return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	/** Echo escaped test text.
	 *
	 * @param string $value Text value.
	 */
	function esc_html_e( $value ) {
		echo esc_html( $value );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	/** Return escaped test text.
	 *
	 * @param string $value Text value.
	 */
	function esc_html__( $value ) {
		return esc_html( $value );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	/** Return local test URL.
	 *
	 * @param string $value URL value.
	 */
	function esc_url( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	/** Build local test admin URL.
	 *
	 * @param string $path Admin path.
	 */
	function admin_url( $path ) {
		return '/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	/** Render a test nonce.
	 *
	 * @param string $action Nonce action.
	 */
	function wp_nonce_field( $action ) {
		echo '<input name="_wpnonce" value="' . esc_attr( $action ) . '" />';
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	/** Echo escaped test attributes.
	 *
	 * @param string $value Attribute value.
	 */
	function esc_attr_e( $value ) {
		echo esc_attr( $value );
	}
}
if ( ! function_exists( '_x' ) ) {
	/** Return translated test text.
	 *
	 * @param string $value Text value.
	 */
	function _x( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/** Return unslashed test text.
	 *
	 * @param string $value Text value.
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/** Build test query URLs.
	 *
	 * @param array  $args Query arguments.
	 * @param string $url  Base URL.
	 */
	function add_query_arg( $args, $url = '' ) {
		return $url . '?' . http_build_query( $args );
	}
}
if ( ! function_exists( 'submit_button' ) ) {
	/** Render the filter submit control in isolated admin tests.
	 *
	 * @param string $label Button text.
	 */
	function submit_button( $label ) {
		echo '<button type="submit">' . esc_html( $label ) . '</button>';
	}
}

/** Verifies the bundled release admin surface. */
class BundledAdminPageTest extends TestCase {
	/** Set admin permissions and clear request state. */
	protected function setUp(): void {
		$GLOBALS['icon_library_test_capabilities'] = array( 'manage_options' => true );
		$_GET                                      = array();
	}

	/** Clear request state after each case. */
	protected function tearDown(): void {
		$GLOBALS['icon_library_test_capabilities'] = array();
		$_GET                                      = array();
	}

	/** Construct a page with an explicit collection inventory.
	 *
	 * @param array $collections Collection summaries.
	 */
	private function page( $collections ) {
		$registry = $this->getMockBuilder( CollectionRegistry::class )->disableOriginalConstructor()->onlyMethods( array( 'get_collections', 'get_bundled_collection_slugs', 'query_icons', 'count_icons_by_category', 'get_manifest' ) )->getMock();
		$registry->method( 'get_collections' )->willReturn( $collections );
		$registry->method( 'get_bundled_collection_slugs' )->willReturn( array_keys( $collections ) );
		$registry->method( 'query_icons' )->willReturn(
			array(
				'total'          => 0,
				'items'          => array(),
				'variant_counts' => array(),
			)
		);
		$registry->method( 'count_icons_by_category' )->willReturn( array() );
		$registry->method( 'get_manifest' )->willReturn( null );
		$sanitizer = $this->getMockBuilder( SvgSanitizer::class )->disableOriginalConstructor()->getMock();
		return new AdminPage( $registry, $sanitizer );
	}

	/** Render the page to a string.
	 *
	 * @param AdminPage $page Admin page.
	 */
	private function render( $page ) {
		ob_start();
		$page->render();
		return ob_get_clean();
	}

	/** All ten bundled families appear in local installation choices. */
	public function test_install_tab_lists_all_bundled_families() {
		$_GET['tab'] = 'install';
		$collections = array();
		foreach ( array( 'rune', 'iconoir', 'tabler-icons', 'ionicons', 'material-icons', 'bootstrap-icons', 'heroicons', 'phosphor', 'radix', 'font-awesome' ) as $slug ) {
			$collections[ $slug ] = array(
				'slug'     => $slug,
				'name'     => $slug,
				'enabled'  => false,
				'variants' => array(
					array(
						'slug'    => 'regular',
						'label'   => 'Regular',
						'enabled' => false,
					),
				),
			);
		}
		$html = $this->render( $this->page( $collections ) );
		foreach ( array_keys( $collections ) as $slug ) {
			$this->assertStringContainsString( '<h3>' . $slug . '</h3>', $html );
		}
		$this->assertSame( 10, substr_count( $html, 'class="icon-library-collection-row"' ) );
		$this->assertSame( 3, substr_count( $html, 'class="icon-library-tab ' ) );
		$this->assertStringContainsString( 'Install Library', $html );
		$this->assertStringNotContainsString( 'icon_library_install_package', $html );
	}

	/** Old remote-installer URLs fall back to the Library view. */
	public function test_stale_installer_tabs_show_library_without_package_or_migration_actions() {
		foreach ( array( 'browse', 'packages' ) as $tab ) {
			$_GET = array(
				'tab'                 => $tab,
				'package-error'       => '1',
				'package-uninstalled' => '1',
				'preview_library'     => 'iconoir',
			);
			$html = $this->render( $this->page( array() ) );
			$this->assertStringContainsString( 'No libraries installed yet.', $html );
			$this->assertStringNotContainsString( 'Available Libraries', $html );
			$this->assertStringNotContainsString( 'Package installation', $html );
			$this->assertStringNotContainsString( 'icon_library_preserve_legacy', $html );
			$this->assertStringNotContainsString( 'icon_library_discover', $html );
		}
	}

	/** Unselected bundles do not appear as installed library settings. */
	public function test_library_shows_only_selected_collections() {
		$html = $this->render(
			$this->page(
				array(
					'rune' => array(
						'slug'      => 'rune',
						'name'      => 'Rune',
						'installed' => false,
						'enabled'   => false,
						'variants'  => array(),
					),
				)
			)
		);
		$this->assertStringContainsString( 'No libraries installed yet.', $html );
		$this->assertStringNotContainsString( '<h3>Rune</h3>', $html );
	}

	/** Submitted preview filters retain the Install Library tab and collection. */
	public function test_install_preview_filter_submits_to_install_tab() {
		$_GET = array(
			'tab'        => 'install',
			'collection' => 'rune',
			'search'     => 'arrow',
		);
		$html = $this->render(
			$this->page(
				array(
					'rune' => array(
						'slug'       => 'rune',
						'name'       => 'Rune',
						'installed'  => false,
						'enabled'    => false,
						'variants'   => array(),
						'categories' => array(),
					),
				)
			)
		);
		$this->assertStringContainsString( 'class="icon-library-filters"', $html );
		$this->assertStringContainsString( 'name="tab" value="install"', $html );
		$this->assertStringContainsString( 'name="collection" value="rune"', $html );
		$this->assertStringContainsString( 'name="search" value="arrow"', $html );
		$this->assertStringNotContainsString( 'name="tab" value="browse"', $html );
	}

	/** Collection and variant controls remain available. */
	public function test_bundled_library_detail_has_collection_and_variant_controls() {
		$_GET = array(
			'tab'        => 'library',
			'collection' => 'rune',
		);
		$html = $this->render(
			$this->page(
				array(
					'rune' => array(
						'slug'      => 'rune',
						'name'      => 'Rune',
						'enabled'   => true,
						'installed' => true,
						'variants'  => array(
							array(
								'slug'    => 'regular',
								'label'   => 'Regular',
								'enabled' => true,
							),
						),
					),
				)
			)
		);
		$this->assertStringContainsString( 'name="action" value="icon_library_toggle_collection"', $html );
		$this->assertStringContainsString( 'name="action" value="icon_library_toggle_variant"', $html );
		$this->assertStringNotContainsString( 'icon_library_uninstall_package', $html );
	}

	/** The static Upload screen remains truthful when its script never runs. */
	public function test_upload_screen_has_static_fallback() {
		$_GET['tab'] = 'custom';
		$html        = $this->render( $this->page( array() ) );
		$this->assertStringContainsString( 'Uploaded icons cannot be listed or managed until this page', $html );
		$this->assertStringContainsString( 'name="action" value="icon_library_upload_custom_icon"', $html );
		$this->assertStringNotContainsString( 'Loading uploaded icons', $html );
	}

	/** Upload failures use a bounded message, never URL-supplied text or settings feedback. */
	public function test_upload_failure_notice_is_actionable_and_escaped() {
		$_GET = array(
			'tab'                       => 'custom',
			'icon-library-upload-error' => 'icon_library_custom_duplicate',
		);
		$html = $this->render( $this->page( array() ) );
		$this->assertStringContainsString( 'Choose a different name.', $html );
		$this->assertStringNotContainsString( 'Library settings could not be updated.', $html );

		$_GET['icon-library-upload-error'] = 'unsafe-message-from-url';
		$html                              = $this->render( $this->page( array() ) );
		$this->assertStringContainsString( 'The icon could not be stored.', $html );
		$this->assertStringNotContainsString( 'unsafe-message-from-url', $html );
	}

	/** Failed cleanup must disclose retained artwork rather than suggest another upload. */
	public function test_failed_upload_cleanup_notice_requires_administrator_action() {
		$_GET = array(
			'tab'                       => 'custom',
			'icon-library-upload-error' => 'icon_library_custom_cleanup_failed',
		);
		$html = $this->render( $this->page( array() ) );
		$this->assertStringContainsString( 'its unpublished SVG could not be removed', $html );
		$this->assertStringContainsString( 'Ask your site administrator to check the uploads directory before retrying.', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringNotContainsString( 'then try again', $html );
	}

	/** A shared metadata failure code must not claim an uncertain commit was rolled back. */
	public function test_metadata_failure_notice_requires_refresh_before_retry() {
		$_GET = array(
			'tab'                       => 'custom',
			'icon-library-upload-error' => 'icon_library_custom_metadata_failed',
		);
		$html = $this->render( $this->page( array() ) );
		$this->assertStringContainsString( 'The icon change could not be confirmed.', $html );
		$this->assertStringContainsString( 'Refresh the icon list before retrying.', $html );
		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringNotContainsString( 'No icon was added', $html );
		$this->assertStringNotContainsString( 'then try again', $html );
	}

	/** Administrators without the required capability see nothing. */
	public function test_user_without_manage_options_cannot_render_page() {
		$GLOBALS['icon_library_test_capabilities'] = array();
		$this->assertSame( '', $this->render( $this->page( array() ) ) );
	}
}
