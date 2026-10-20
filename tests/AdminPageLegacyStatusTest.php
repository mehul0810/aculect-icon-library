<?php
/**
 * Ensures removed admin flows stay outside the bundled-only screen.
 *
 * @package IconLibrary
 */

use IconLibrary\AdminPage;
use PHPUnit\Framework\TestCase;

/** The bundled-only admin page has no remote or migration UI. */
class AdminPageLegacyStatusTest extends TestCase {
	/** Remote installation and legacy preservation have no active UI entrypoint. */
	public function test_admin_page_has_only_local_dependencies() {
		$constructor = new ReflectionMethod( AdminPage::class, '__construct' );
		$this->assertSame( 2, $constructor->getNumberOfParameters() );
		foreach ( array( 'render_packages_tab', 'render_discovery', 'render_package_form', 'render_legacy_status', 'suppress_unrelated_notices' ) as $method ) {
			$this->assertFalse( method_exists( AdminPage::class, $method ), $method );
		}
	}
}
