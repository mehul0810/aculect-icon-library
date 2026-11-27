<?php
/**
 * Checks the Abilities REST contract on a disposable, installed WordPress site.
 *
 * @package IconLibrary
 */

if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'ICON_LIBRARY_ALLOW_TEST_DATA' ) ) {
	fwrite( STDERR, "Use a disposable site and set ICON_LIBRARY_ALLOW_TEST_DATA=1.\n" );
	exit( 1 );
}

$wp_load = getenv( 'ICON_LIBRARY_WP_LOAD' );
if ( ! $wp_load || ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "Set ICON_LIBRARY_WP_LOAD to the disposable site's wp-load.php.\n" );
	exit( 1 );
}

require $wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

/**
 * Fail the smoke test without including response contents in logs.
 *
 * @param bool   $condition Expected condition.
 * @param string $message   Failure description.
 * @throws RuntimeException When the assertion fails.
 * @return void
 */
function icon_library_abilities_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Dispatch a read-only ability through the real WordPress REST controller.
 *
 * @param string $name  Registered ability name.
 * @param array  $input Request input.
 * @return WP_REST_Response
 */
function icon_library_abilities_request( $name, $input ) {
	$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/icon-library/' . $name . '/run' );
	$request->set_param( 'input', $input );
	return rest_do_request( $request );
}

$users         = array();
$posts         = array();
$original_user = get_current_user_id();
$failure       = '';

try {
	icon_library_abilities_assert( defined( 'ICON_LIBRARY_VERSION' ) && function_exists( 'wp_get_ability' ), 'The plugin and WordPress Abilities API must be active.' );
	foreach ( array( 'search-icons', 'get-icon', 'list-icon-blocks', 'insert-icon-block', 'replace-icon-block', 'remove-icon-block' ) as $name ) {
		icon_library_abilities_assert( null !== wp_get_ability( 'icon-library/' . $name ), 'A required ability is not registered.' );
	}

	$suffix = str_replace( '-', '', wp_generate_uuid4() );
	foreach ( array( 'one', 'two' ) as $owner ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'ail-smoke-' . $owner . '-' . $suffix,
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => 'ail-smoke-' . $owner . '-' . $suffix . '@example.test',
				'role'       => 'author',
			)
		);
		icon_library_abilities_assert( ! is_wp_error( $user_id ), 'Could not create a temporary author.' );
		$users[] = $user_id;
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Aculect abilities smoke',
				'post_status'  => 'draft',
				'post_author'  => $user_id,
				'post_content' => '<!-- wp:icon {"icon":"test/icon"} /-->',
			),
			true
		);
		icon_library_abilities_assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create a temporary draft.' );
		$posts[] = $post_id;
	}

	wp_set_current_user( $users[0] );
	$response = icon_library_abilities_request( 'list-icon-blocks', array( 'post_id' => (string) $posts[0] ) );
	$data     = json_decode( wp_json_encode( $response->get_data() ) );
	icon_library_abilities_assert( 200 === $response->get_status() && is_object( $data ), 'An author could not list their own draft.' );
	icon_library_abilities_assert( $posts[0] === $data->post_id && 1 === $data->count && 'test/icon' === $data->items[0]->icon, 'The list response did not retain the requested post and icon.' );
	// WordPress 7.2 prepares empty object-schema output for JSON serialization.
	if ( version_compare( get_bloginfo( 'version' ), '7.2-alpha', '>=' ) ) {
		icon_library_abilities_assert( is_object( $data->items[0]->attributes ), 'Empty block attributes must be a JSON object on WordPress 7.2.' );
	} else {
		icon_library_abilities_assert( array() === $data->items[0]->attributes, 'Unexpected empty block attributes on WordPress 7.1.' );
	}

	$denied = icon_library_abilities_request( 'list-icon-blocks', array( 'post_id' => (string) $posts[1] ) );
	icon_library_abilities_assert( 403 === $denied->get_status(), 'An author could list another author\'s draft.' );
	icon_library_abilities_assert( '<!-- wp:icon {"icon":"test/icon"} /-->' === get_post( $posts[1] )->post_content, 'A denied request changed another author\'s content.' );

	$search = icon_library_abilities_request(
		'search-icons',
		array(
			'search'   => 'ail-no-such-icon-' . $suffix,
			'page'     => '2',
			'per_page' => '3',
		)
	);
	$result = json_decode( wp_json_encode( $search->get_data() ) );
	icon_library_abilities_assert( 200 === $search->get_status() && is_object( $result ), 'Search did not return an expected response.' );
	icon_library_abilities_assert( 2 === $result->page && 3 === $result->per_page && 0 === $result->total && array() === $result->items, 'Search input or pagination was not prepared as expected.' );
	if ( version_compare( get_bloginfo( 'version' ), '7.2-alpha', '>=' ) ) {
		icon_library_abilities_assert( is_object( $result->variant_counts ), 'Empty variant counts must be a JSON object on WordPress 7.2.' );
		$replace  = wp_get_ability( 'icon-library/replace-icon-block' );
		$prepared = $replace->prepare_input(
			array(
				'post_id'    => (string) $posts[0],
				'path'       => array( '0' ),
				'icon'       => 'test/icon',
				'attributes' => array(
					'flipHorizontal' => 'false',
					'rotation'       => '90',
				),
			)
		);
		icon_library_abilities_assert(
			is_array( $prepared ) && $posts[0] === $prepared['post_id'] && array( 0 ) === $prepared['path'] &&
			false === $prepared['attributes']['flipHorizontal'] && 90 === $prepared['attributes']['rotation'],
			'Nested ability input was not prepared as expected.'
		);
		$prepared['attributes']['flipHorizontal'] = 'invalid';
		$invalid                                  = $replace->prepare_input( $prepared );
		icon_library_abilities_assert( is_wp_error( $invalid ), 'Invalid nested ability input was not rejected.' );
	} else {
		icon_library_abilities_assert( array() === $result->variant_counts, 'Unexpected empty variant counts on WordPress 7.1.' );
	}

	$bad = icon_library_abilities_request( 'list-icon-blocks', array( 'post_id' => 'not-a-number' ) );
	icon_library_abilities_assert( 400 === $bad->get_status(), 'Invalid post input was not rejected.' );
	wp_set_current_user( 0 );
	$anonymous = icon_library_abilities_request( 'search-icons', array() );
	icon_library_abilities_assert( 401 === $anonymous->get_status(), 'Anonymous catalog access was not rejected.' );
} catch ( Throwable $error ) {
	$failure = $error->getMessage();
} finally {
	wp_set_current_user( $original_user );
	foreach ( $posts as $post_id ) {
		if ( ! wp_delete_post( $post_id, true ) ) {
			$failure = 'Could not remove a temporary draft.';
		}
	}
	foreach ( $users as $user_id ) {
		if ( ! wp_delete_user( $user_id ) ) {
			$failure = 'Could not remove a temporary author.';
		}
	}
}

if ( '' !== $failure ) {
	fwrite( STDERR, $failure . "\n" );
	exit( 1 );
}

echo "Abilities REST contract smoke passed.\n";
