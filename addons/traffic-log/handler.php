<?php
/**
 * Traffic Log: records one hit (page view or link click).
 *
 * Used by both the lightweight endpoint (endpoint.php) and the admin-ajax
 * fallback, so both store data the same way.
 *
 * Storage (unchanged from the child-theme version, so old data still shows):
 *   post meta key  "olct-<Ymd>-<visitor id>-<session id>"
 *   value          array( 'count' => page views, 'urls' => array( md5(url) => array( url, label, count ) ) )
 *
 * Only needs get_option(), get_post() and the post-meta functions, which
 * the endpoint loads under SHORTINIT.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/** Option names (kept from the child-theme version so settings carry over). */
if ( ! defined( 'OLCTK_TRAFFIC_LOG_TYPES_OPTION' ) ) {
	define( 'OLCTK_TRAFFIC_LOG_TYPES_OPTION', 'olc_traffic_log_cpts' );
	define( 'OLCTK_TRAFFIC_LOG_DATES_OPTION', 'olc_traffic_log_dates' );
}

/**
 * Post types selected for tracking.
 *
 * @return string[]
 */
function olctk_traffic_log_post_types(): array {
	return array_values( (array) get_option( OLCTK_TRAFFIC_LOG_TYPES_OPTION, array() ) );
}

/**
 * Record a hit.
 *
 * @param mixed $post_id    Post the visitor is on.
 * @param mixed $session_id Session id from the browser.
 * @param mixed $user_id    Visitor id from the browser.
 * @param mixed $url        Clicked link, or '' for a page view.
 * @param mixed $label      Clicked link text.
 * @return array Result sent back to the browser.
 */
function olctk_traffic_log_process( $post_id, $session_id, $user_id, $url, $label ): array {
	$post_id    = (int) $post_id;
	$session_id = substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $session_id ), 0, 64 );
	$user_id    = substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $user_id ), 0, 64 );
	$url        = esc_url_raw( (string) $url );
	$label      = substr( sanitize_text_field( (string) $label ), 0, 200 );

	if ( empty( $post_id ) || '' === $user_id || '' === $session_id ) {
		return array( 'type' => 'invalid' );
	}

	// Only published posts of a tracked post type.
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, olctk_traffic_log_post_types(), true ) ) {
		return array( 'type' => 'invalid' );
	}

	$meta_key = 'olct-' . gmdate( 'Ymd' ) . "-{$user_id}-{$session_id}";
	$session  = get_post_meta( $post_id, $meta_key, true );
	$session  = is_array( $session ) ? $session : array();

	if ( '' !== $url ) {
		$urls = ( isset( $session['urls'] ) && is_array( $session['urls'] ) ) ? $session['urls'] : array();
		$key  = md5( $url );
		$prev = ( isset( $urls[ $key ] ) && is_array( $urls[ $key ] ) ) ? $urls[ $key ] : array();

		$urls[ $key ] = array(
			'url'   => $url,
			'label' => $label,
			'count' => (int) ( $prev['count'] ?? 0 ) + 1,
		);
		$session['urls'] = $urls;
	} else {
		$session['count'] = (int) ( $session['count'] ?? 0 ) + 1;
	}

	update_post_meta( $post_id, $meta_key, $session );

	return array( 'type' => 'success' );
}
