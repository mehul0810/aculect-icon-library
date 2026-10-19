<?php
/** Real Core registry subprocess fixture for bounded picker hydration. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'ICON_LIBRARY_DIR', dirname( __DIR__, 2 ) . '/' );

function __( $text ) { return $text; }
function add_action() {}
function wp_kses( $content ) { return $content; }
function sanitize_text_field( $text ) { return $text; }
function remove_accents( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_trigger_error( $function_name, $message ) { throw new RuntimeException( $function_name . ': ' . $message ); }
class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
class WP_REST_Request {
	public function __construct( $method, $route ) { $this->method = $method; $this->route = $route; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	private $method;
	private $route;
}
class WP_REST_Response {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
	public function set_data( $data ) { $this->data = $data; }
}

$core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $core_dir ) { throw new RuntimeException( 'WP_CORE_DIR is required.' ); }
if ( ! is_file( $core_dir . '/wp-includes/compat.php' ) ) { throw new RuntimeException( 'WP_CORE_DIR must contain wp-includes/compat.php.' ); }
require_once $core_dir . '/wp-includes/compat.php';
require_once $core_dir . '/wp-includes/class-wp-icon-collections-registry.php';
require_once $core_dir . '/wp-includes/class-wp-icons-registry.php';
spl_autoload_register( static function ( $class ) {
	$prefix = 'IconLibrary\\';
	if ( 0 === strpos( $class, $prefix ) ) {
		require_once ICON_LIBRARY_DIR . 'src/' . substr( $class, strlen( $prefix ) ) . '.php';
	}
} );
function wp_register_icon_collection( $name, $args ) { return WP_Icon_Collections_Registry::get_instance()->register( $name, $args ); }
function wp_register_icon( $name, $args ) { return WP_Icons_Registry::get_instance()->register( $name, $args ); }

class EmptyCollectionRegistry extends IconLibrary\CollectionRegistry {
	public function __construct() {}
	public function get_enabled_collection_slugs() { return array(); }
	public function get_available_collection_slugs() { return array(); }
}

function check( $truth, $message ) {
	if ( ! $truth ) { throw new RuntimeException( $message ); }
}
function hydrated_count( $prefix ) {
	$count = 0;
	foreach ( IconLibrary\CoreIconMetadataReader::get_registered_metadata() as $icon ) {
		if ( 0 === strpos( $icon['name'], $prefix ) && isset( $icon['content'] ) ) { ++$count; }
	}
	return $count;
}

wp_register_icon_collection( 'external-lazy', array( 'label' => 'External lazy' ) );
wp_register_icon_collection( 'external-target', array( 'label' => 'External target' ) );
$svg = ICON_LIBRARY_DIR . 'assets/icons/rune/pixelated/indicators-square-check.svg';
for ( $i = 0; $i < 2000; ++$i ) {
	$name = sprintf( 'external-lazy/item-%04d', $i );
	check( wp_register_icon( $name, array( 'label' => 1500 === $i ? 'Needle in catalog' : 'Icon ' . $i, 'file_path' => $svg ) ), 'registration failed: ' . $name );
}
check( wp_register_icon( 'external-target/one', array( 'label' => 'Target', 'content' => '<svg><path d="M0 0"/></svg>' ) ), 'target registration failed' );
$registry = WP_Icons_Registry::get_instance();
$identity = spl_object_id( $registry );
$picker   = new IconLibrary\CoreIconRegistrar( new EmptyCollectionRegistry() );

$other = $picker->get_picker_page( 'external-target' );
check( 1 === $other['total'] && 'external-target/one' === $other['items'][0]['name'], 'other collection was not preserved' );
check( 0 === hydrated_count( 'external-lazy/' ), 'unrelated external artwork was hydrated' );

$all = $picker->get_picker_page( '', '', 1, 48 );
check( 2001 === $all['total'] && 48 === count( $all['items'] ), 'All collection pagination failed' );
check( 48 === hydrated_count( 'external-lazy/' ), 'All hydrated beyond selected page' );

$late = $picker->get_picker_page( 'external-lazy', '', 42, 48 );
check( 2000 === $late['total'] && 32 === count( $late['items'] ), 'late page failed' );
check( 80 === hydrated_count( 'external-lazy/' ), 'late page hydrated beyond selected page' );

$search = $picker->get_picker_page( 'external-lazy', 'Needle' );
check( 1 === $search['total'] && 'external-lazy/item-1500' === $search['items'][0]['name'], 'label search failed' );
check( 81 === hydrated_count( 'external-lazy/' ), 'search hydrated unrelated artwork' );
check( $identity === spl_object_id( WP_Icons_Registry::get_instance() ), 'Core registry singleton changed' );
check( 2001 === count( IconLibrary\CoreIconMetadataReader::get_registered_metadata() ), 'Core registrations changed' );
echo "Real Core picker metadata remains lazy and bounded.\n";
