<?php
/** Real Core registry subprocess fixture for bounded picker hydration. */

$core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $core_dir ) { throw new RuntimeException( 'WP_CORE_DIR is required.' ); }
define( 'ABSPATH', rtrim( $core_dir, '/' ) . '/' );
if ( ! defined( 'WPINC' ) ) { define( 'WPINC', 'wp-includes' ); }
define( 'ICON_LIBRARY_DIR', dirname( __DIR__, 2 ) . '/' );

function __( $text ) { return $text; }
function _x( $text ) { return $text; }
function get_bloginfo( $name ) { return 'version' === $name ? $GLOBALS['wp_version'] : ''; }
function add_action() {}
function wp_kses( $content ) { return $content; }
function sanitize_text_field( $text ) { return $text; }
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $text ) ); }
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

if ( ! is_file( $core_dir . '/wp-includes/compat.php' ) ) { throw new RuntimeException( 'WP_CORE_DIR must contain wp-includes/compat.php.' ); }
require $core_dir . '/wp-includes/version.php';
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
	public function query_icons( $args = array() ) { return array( 'items' => array(), 'total' => 0, 'variant_counts' => array() ); }
	public function get_icon_by_core_name( $name, $enabled_only = true ) { return null; }
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
if ( version_compare( get_bloginfo( 'version' ), '7.2-alpha', '>=' ) ) {
	check( wp_register_icon_collection( 'private-fixture', array( 'label' => 'Private fixture', 'public' => false ) ), 'private collection registration failed' );
	check( wp_register_icon( 'private-fixture/one', array( 'label' => 'Private fixture icon', 'file_path' => $svg ) ), 'private icon registration failed' );
	$all = $picker->get_picker_page( '', '', 1, 48 );
	check( 2001 === $all['total'], 'private collection affected picker total' );
	$private = $picker->get_picker_page( 'private-fixture' );
	check( 0 === $private['total'] && 0 === hydrated_count( 'private-fixture/' ), 'private collection was selected or hydrated' );
}
check( wp_register_icon_collection( 'core', array( 'label' => 'Core' ) ), 'Core collection registration failed' );
$core_properties = array( 'label' => 'Envelope', 'file_path' => ABSPATH . WPINC . '/images/icon-library/envelope.svg' );
if ( version_compare( get_bloginfo( 'version' ), '7.2-alpha', '>=' ) ) {
	$core_properties['keywords'] = array( 'letter' );
}
check( wp_register_icon( 'core/envelope', $core_properties ), 'Core icon registration failed' );
$ability = new IconLibrary\IconBlockService( new EmptyCollectionRegistry() );
$result  = $ability->search_icons( array( 'search' => isset( $core_properties['keywords'] ) ? 'letter' : 'envelope' ) );
check( 1 === $result['total'] && 'core/envelope' === $result['items'][0]['name'], 'ability keyword search failed' );
check( 'Envelope' === $result['items'][0]['label'] && ( $core_properties['keywords'] ?? array() ) === $result['items'][0]['keywords'], 'ability metadata differs from Core registration' );
check( $result['items'][0] === $ability->get_icon( array( 'icon' => 'core/envelope' ) ), 'ability exact lookup differs from search' );
check( 0 === hydrated_count( 'core/' ), 'ability discovery hydrated Core artwork' );
echo "Real Core picker metadata remains lazy and bounded.\n";
