<?php
/**
 * Traffic Log: lightweight endpoint the tracking script posts to.
 *
 * Loads WordPress with SHORTINIT, so plugins, the theme and hooks don't run,
 * which keeps each hit cheap. If this URL can't be reached (some hosts block
 * direct PHP files in plugins), the tracking script falls back to admin-ajax.
 *
 * @package OLC_Toolkit
 */

// phpcs:disable WordPress.Security.NonceVerification -- signed token checked below.

define( 'SHORTINIT', true );

/**
 * Find wp-load.php: the document root first, then each parent folder.
 *
 * @return string Path, or '' if not found.
 */
function olctk_traffic_log_find_wp_load(): string {
	$candidates = array();
	if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
		$candidates[] = rtrim( (string) $_SERVER['DOCUMENT_ROOT'], '/\\' ) . '/wp-load.php';
	}
	$dir = __DIR__;
	for ( $i = 0; $i < 8; $i++ ) {
		$dir          = dirname( $dir );
		$candidates[] = $dir . '/wp-load.php';
	}
	foreach ( $candidates as $file ) {
		if ( is_file( $file ) ) {
			return $file;
		}
	}
	return '';
}

$olctk_wp_load = olctk_traffic_log_find_wp_load();
if ( '' === $olctk_wp_load ) {
	http_response_code( 500 );
	header( 'Content-Type: application/json' );
	echo '{"type":"error"}';
	exit;
}
require_once $olctk_wp_load;

// SHORTINIT loads very little; add only what the handler needs.
require_once ABSPATH . WPINC . '/formatting.php';   // sanitize_text_field(), esc_url_raw().
require_once ABSPATH . WPINC . '/kses.php';         // Used by esc_url_raw().
require_once ABSPATH . WPINC . '/class-wp-post.php'; // get_post().
require_once ABSPATH . WPINC . '/meta.php';         // get_metadata(), update_metadata().
require_once ABSPATH . WPINC . '/post.php';         // get_post_meta(), update_post_meta().
require_once ABSPATH . WPINC . '/revision.php';     // wp_is_post_revision(), used by update_post_meta().

require_once __DIR__ . '/token.php';
require_once __DIR__ . '/handler.php';

header( 'Content-Type: application/json' );

if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
	http_response_code( 405 );
	echo '{"type":"invalid"}';
	exit;
}

// Do nothing while the add-on is switched off.
if ( ! in_array( 'traffic-log', (array) get_option( 'olctk_enabled_addons', array() ), true ) ) {
	http_response_code( 403 );
	echo '{"type":"disabled"}';
	exit;
}

$olctk_token = isset( $_POST['token'] ) ? (string) $_POST['token'] : '';
$olctk_tick  = isset( $_POST['tick'] ) ? (string) $_POST['tick'] : '';

if ( ! olctk_traffic_log_verify_token( $olctk_token, $olctk_tick ) ) {
	http_response_code( 403 );
	echo '{"type":"invalid"}';
	exit;
}

// SHORTINIT stops before WordPress adds slashes to $_POST, so values are used as sent.
echo wp_json_encode(
	olctk_traffic_log_process(
		isset( $_POST['post_id'] ) ? $_POST['post_id'] : '',
		isset( $_POST['session_id'] ) ? $_POST['session_id'] : '',
		isset( $_POST['user_id'] ) ? $_POST['user_id'] : '',
		isset( $_POST['url'] ) ? $_POST['url'] : '',
		isset( $_POST['label'] ) ? $_POST['label'] : ''
	)
);
exit;
