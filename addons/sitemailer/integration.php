<?php
/**
 * SiteMailer integration for OLC Toolkit.
 *
 * The Slack webhook address is entered on the OLC Toolkit admin page and
 * stored encrypted in the database (never in code). A site can still
 * override it by defining SITEMAILER_WEBHOOK_URL in wp-config.php.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/** Option name that holds the encrypted webhook address. */
define( 'OLCTK_SITEMAILER_WEBHOOK_OPTION', 'olctk_sitemailer_webhook' );

/**
 * Build the 32-byte encryption key from this site's wp-config.php salts.
 *
 * @return string Raw binary key.
 */
function olctk_sitemailer_key(): string {
	return hash( 'sha256', wp_salt( 'auth' ) . '|olctk-sitemailer', true );
}

/**
 * Encrypt a value for storage.
 *
 * @param string $plain Plain-text value.
 * @return string Base64 of nonce + ciphertext.
 */
function olctk_sitemailer_encrypt( string $plain ): string {
	$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$cipher = sodium_crypto_secretbox( $plain, $nonce, olctk_sitemailer_key() );
	return base64_encode( $nonce . $cipher );
}

/**
 * Decrypt a stored value.
 *
 * @param string $stored Value produced by olctk_sitemailer_encrypt().
 * @return string|false Plain text, or false if it can't be decrypted
 *                      (for example, the site's salts were changed).
 */
function olctk_sitemailer_decrypt( string $stored ) {
	$raw = base64_decode( $stored, true );
	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return false;
	}
	$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	try {
		return sodium_crypto_secretbox_open( $cipher, $nonce, olctk_sitemailer_key() );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Check that a value looks like a Slack incoming-webhook address.
 *
 * @param string $url Address to check.
 * @return bool
 */
function olctk_sitemailer_is_valid_webhook( string $url ): bool {
	return (bool) preg_match( '#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#', $url );
}

/**
 * Encrypt and save the webhook address.
 *
 * @param string $url Webhook address.
 */
function olctk_sitemailer_save_webhook( string $url ): void {
	update_option( OLCTK_SITEMAILER_WEBHOOK_OPTION, olctk_sitemailer_encrypt( $url ), false );
}

/**
 * Remove the saved webhook address.
 */
function olctk_sitemailer_delete_webhook(): void {
	delete_option( OLCTK_SITEMAILER_WEBHOOK_OPTION );
}

/**
 * Whether the address is set in wp-config.php (which takes priority).
 *
 * @return bool
 */
function olctk_sitemailer_webhook_from_config(): bool {
	return defined( 'SITEMAILER_WEBHOOK_URL' ) && '' !== SITEMAILER_WEBHOOK_URL;
}

/**
 * Get the webhook address to use.
 *
 * @return string Address, or '' if none is set or it can't be decrypted.
 */
function olctk_sitemailer_get_webhook_url(): string {
	if ( olctk_sitemailer_webhook_from_config() ) {
		return (string) SITEMAILER_WEBHOOK_URL;
	}
	$stored = (string) get_option( OLCTK_SITEMAILER_WEBHOOK_OPTION, '' );
	if ( '' === $stored ) {
		return '';
	}
	$plain = olctk_sitemailer_decrypt( $stored );
	return false === $plain ? '' : $plain;
}

/**
 * Whether a saved address exists but can no longer be decrypted.
 *
 * @return bool
 */
function olctk_sitemailer_webhook_unreadable(): bool {
	$stored = (string) get_option( OLCTK_SITEMAILER_WEBHOOK_OPTION, '' );
	return '' !== $stored && false === olctk_sitemailer_decrypt( $stored );
}

/**
 * Masked version of the address for display, e.g. "hooks.slack.com/services/••••••••VGS".
 *
 * @return string Masked address, or '' if none is set.
 */
function olctk_sitemailer_masked_webhook(): string {
	$url = olctk_sitemailer_get_webhook_url();
	if ( '' === $url ) {
		return '';
	}
	return 'hooks.slack.com/services/' . str_repeat( '•', 8 ) . substr( $url, -4 );
}

/**
 * Send a test message to the SiteMailer Slack channel.
 *
 * @return bool True if Slack accepted the message.
 */
function olctk_sitemailer_test_slack(): bool {
	$webhookUrl = olctk_sitemailer_get_webhook_url();
	if ( '' === $webhookUrl ) {
		return false;
	}
	return olctk_send_slack_message( 'Hello from PHP!', $webhookUrl );
}
