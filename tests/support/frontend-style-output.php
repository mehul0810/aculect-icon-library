<?php
/**
 * Isolated frontend head/content lifecycle using WordPress's real style engine.
 * The small shims below replace site configuration, not stylesheet output.
 *
 * @package IconLibrary
 */

$core = getenv( 'WP_CORE_DIR' );
define( 'ABSPATH', rtrim( $core, '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'ICON_LIBRARY_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'ICON_LIBRARY_URL', 'https://example.test/wp-content/plugins/aculect-icon-library/' );
define( 'ICON_LIBRARY_VERSION', '1.2.0' );

require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/class-wp-dependency.php';
require ABSPATH . WPINC . '/class-wp-dependencies.php';
require ABSPATH . WPINC . '/class-wp-styles.php';
require ABSPATH . WPINC . '/functions.wp-styles.php';
require ABSPATH . WPINC . '/class-wp-token-map.php';
foreach ( array( 'html5-named-character-references.php', 'class-wp-html-attribute-token.php', 'class-wp-html-span.php', 'class-wp-html-doctype-info.php', 'class-wp-html-text-replacement.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $file ) {
	require ABSPATH . WPINC . '/html-api/' . $file;
}

function is_admin() { return ! empty( $GLOBALS['icon_style_test_admin'] ); }
function get_option( $name, $default = false ) { return 'blog_charset' === $name ? 'UTF-8' : $default; }
function is_utf8_charset( $charset = null ) { return null === $charset || 'utf-8' === strtolower( $charset ); }
function wp_allowed_protocols() { return array( 'http', 'https' ); }
function _wp_scripts_maybe_doing_it_wrong( $function, $handle = '' ) {}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 === strpos( $class, 'IconLibrary\\' ) ) {
			require ICON_LIBRARY_DIR . 'src/' . substr( $class, strlen( 'IconLibrary\\' ) ) . '.php';
		}
	}
);
( new IconLibrary\Plugin() )->register();

// The iframe has its own style registry; frontend and editor requests must both
// receive the rules even after another registry has already printed them.
$editor = 'editor' === $argv[1];
if ( $editor ) {
	do_action( 'wp_enqueue_scripts' );
	ob_start();
	wp_print_styles();
	ob_end_clean();
	$GLOBALS['wp_styles'] = new WP_Styles();
	$GLOBALS['icon_style_test_admin'] = true;
}
$hook = $editor ? 'enqueue_block_assets' : 'wp_enqueue_scripts';
do_action( $hook );
do_action( $hook );
ob_start();
wp_print_styles();
$head = ob_get_clean();

$outline = '<svg class="icon-library-stroked other-class" viewBox="0 0 24 24" aria-hidden="true"><path d="M0 0h1v1z" /></svg>';
$cases   = array(
	'outline'  => $outline,
	'cached'   => '<section data-cache="yes">' . $outline . '</section>',
	'template' => '<footer><link rel="stylesheet" id="unrelated-css" href="/theme.css">' . $outline . '</footer>',
	'repeated' => $outline . $outline,
	'solid'    => '<svg viewBox="0 0 24 24"><path d="M0 0h1v1z" /></svg>',
	'custom'   => '<svg viewBox="0 0 24 24"><path fill="#dd5500" d="M0 0h1v1z" /></svg>',
	'editor'   => $outline,
	'none'     => '<p>No plugin icons.</p>',
);
$original = $cases[ $argv[1] ];
$cached   = in_array( $argv[1], array( 'cached', 'template' ), true );
// A cached fragment/template can bypass every block rendering callback. Do not
// accidentally make that case pass by invoking the late render_block fallback.
$body     = $cached ? $original : apply_filters( 'render_block', $original, array( 'blockName' => 'core/icon' ), null );
$body    .= 'repeated' === $argv[1] ? $original : '';
$original .= 'repeated' === $argv[1] ? $original : '';
ob_start();
wp_print_styles();
$late = ob_get_clean();
echo json_encode( array( 'head' => $head, 'body' => $body, 'original_body' => $original, 'late_styles' => $late, 'render_callback_used' => ! $cached ) );
