<?php
/**
 * Slack integration helpers for OLC Toolkit.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

function olctk_send_slack_message( string $message, string $webhookUrl ): bool {

	$response = wp_remote_post(
		$webhookUrl,
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'text' => $message ) ),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	return 200 === wp_remote_retrieve_response_code( $response )
		&& 'ok' === wp_remote_retrieve_body( $response );
}
