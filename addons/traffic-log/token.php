<?php
/**
 * Traffic Log: signed token that the tracking script sends with each hit.
 *
 * Doesn't use wp_create_nonce(), because that needs the user/session code,
 * which the lightweight endpoint (SHORTINIT) doesn't load. Only needs the
 * salts from wp-config.php, so it works in both full WordPress and the
 * endpoint.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

if ( ! defined( 'OLCTK_TRAFFIC_LOG_TICK_LENGTH' ) ) {
	// 12 hours per tick. The current and previous tick are accepted,
	// so a token stays valid for 12-24 hours (like a WordPress nonce).
	define( 'OLCTK_TRAFFIC_LOG_TICK_LENGTH', 12 * HOUR_IN_SECONDS );
}

/** Secret used to sign tokens. */
function olctk_traffic_log_secret(): string {
	if ( defined( 'NONCE_SALT' ) && '' !== NONCE_SALT ) {
		return NONCE_SALT;
	}
	if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
		return AUTH_KEY;
	}
	// Only on a site with no salts in wp-config.php.
	return 'olctk-traffic-log-fallback-secret-please-configure-salts';
}

/** Current time window number. */
function olctk_traffic_log_current_tick(): int {
	return (int) floor( time() / OLCTK_TRAFFIC_LOG_TICK_LENGTH );
}

/**
 * Token for a time window (default: the current one).
 *
 * @param int|null $tick Time window.
 * @return string
 */
function olctk_traffic_log_generate_token( $tick = null ): string {
	$tick = null === $tick ? olctk_traffic_log_current_tick() : (int) $tick;
	return hash_hmac( 'sha256', 'olc-traffic-log-' . $tick, olctk_traffic_log_secret() );
}

/**
 * Check a submitted token. Accepts the current and previous time window.
 *
 * @param string $token Submitted token.
 * @param string $tick  Submitted time window.
 * @return bool
 */
function olctk_traffic_log_verify_token( $token, $tick ): bool {
	$token = (string) $token;
	$tick  = (string) $tick;
	if ( '' === $token || '' === $tick || ! ctype_digit( $tick ) ) {
		return false;
	}

	$tick    = (int) $tick;
	$current = olctk_traffic_log_current_tick();
	if ( $tick !== $current && $tick !== $current - 1 ) {
		return false;
	}

	return hash_equals( olctk_traffic_log_generate_token( $tick ), $token );
}
