<?php
/**
 * Add-on system for OLC Toolkit.
 *
 * Each add-on lives in its own folder under /addons/ and is only loaded when
 * it is enabled on the OLC Toolkit dashboard page.
 *
 * To add a new add-on:
 *   1. Create /addons/<id>/<id>.php (it loads the rest of the add-on's files).
 *   2. Add an entry to olctk_addons() below.
 *   3. If it has a settings page, register it as a submenu of 'olc-toolkit'
 *      and set 'settings_page' to its page slug.
 *   4. Optional: hook 'olctk_addon_disabled_<id>' to clean up (cron jobs etc.)
 *      when the add-on is switched off. Saved settings should be kept so
 *      switching it back on restores them.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/** Option holding the list of enabled add-on ids. */
define( 'OLCTK_ENABLED_ADDONS_OPTION', 'olctk_enabled_addons' );

/**
 * All available add-ons.
 *
 * @return array<string, array{name: string, description: string, file: string, settings_page: string}>
 */
function olctk_addons(): array {
	// Add-ons are loaded before WordPress can translate text (before 'init'),
	// so names and descriptions are only translated once 'init' has run.
	$__ = did_action( 'init' ) ? '__' : static function ( $text ) {
		return $text;
	};

	$addons = array(
		'sitemailer' => array(
			'name'          => $__( 'SiteMailer Alerts', 'olc-toolkit' ),
			'description'   => $__( 'Checks Site Mailer every 15 minutes and posts failed or delayed emails to a Slack channel.', 'olc-toolkit' ),
			'file'          => OLC_TOOLKIT_DIR . 'addons/sitemailer/sitemailer.php',
			'settings_page' => 'olc-toolkit-sitemailer',
		),
		'traffic-log' => array(
			'name'          => $__( 'Traffic Log', 'olc-toolkit' ),
			'description'   => $__( 'Counts visitors, sessions, page views and tracked link clicks on chosen post types, with a report under each post type\'s menu.', 'olc-toolkit' ),
			'file'          => OLC_TOOLKIT_DIR . 'addons/traffic-log/traffic-log.php',
			'settings_page' => 'olc-toolkit-traffic-log',
		),
	);

	/**
	 * Filter the list of add-ons (lets a separate plugin register its own).
	 *
	 * @param array $addons Add-ons keyed by id.
	 */
	return (array) apply_filters( 'olctk_addons', $addons );
}

/**
 * Ids of the enabled add-ons that still exist.
 *
 * @return string[]
 */
function olctk_enabled_addons(): array {
	$enabled = (array) get_option( OLCTK_ENABLED_ADDONS_OPTION, array() );
	return array_values( array_intersect( $enabled, array_keys( olctk_addons() ) ) );
}

/**
 * Whether an add-on is enabled.
 *
 * @param string $id Add-on id.
 * @return bool
 */
function olctk_addon_enabled( string $id ): bool {
	return in_array( $id, olctk_enabled_addons(), true );
}

/**
 * Load one add-on's code.
 *
 * @param string $id Add-on id.
 * @return bool True if loaded.
 */
function olctk_load_addon( string $id ): bool {
	$addons = olctk_addons();
	if ( empty( $addons[ $id ]['file'] ) || ! is_readable( $addons[ $id ]['file'] ) ) {
		return false;
	}
	require_once $addons[ $id ]['file'];
	return true;
}

/**
 * Switch an add-on on or off.
 *
 * @param string $id     Add-on id.
 * @param bool   $enable True to enable, false to disable.
 * @return bool False if the add-on doesn't exist.
 */
function olctk_set_addon_enabled( string $id, bool $enable ): bool {
	if ( ! isset( olctk_addons()[ $id ] ) ) {
		return false;
	}

	$enabled = olctk_enabled_addons();

	if ( $enable ) {
		if ( ! in_array( $id, $enabled, true ) ) {
			$enabled[] = $id;
		}
		update_option( OLCTK_ENABLED_ADDONS_OPTION, $enabled );
		olctk_load_addon( $id );
		do_action( "olctk_addon_enabled_{$id}" );
	} else {
		// Run clean-up while the add-on's code is still loaded.
		do_action( "olctk_addon_disabled_{$id}" );
		update_option( OLCTK_ENABLED_ADDONS_OPTION, array_values( array_diff( $enabled, array( $id ) ) ) );
	}
	return true;
}

/**
 * First run after upgrading from 1.0.x: SiteMailer alerts used to be always
 * on, so keep them on for sites that already have a webhook set up.
 */
function olctk_maybe_migrate_addons(): void {
	if ( false !== get_option( OLCTK_ENABLED_ADDONS_OPTION ) ) {
		return;
	}

	$enabled = array();
	$has_webhook = '' !== (string) get_option( 'olctk_sitemailer_webhook', '' )
		|| ( defined( 'SITEMAILER_WEBHOOK_URL' ) && '' !== SITEMAILER_WEBHOOK_URL );

	if ( $has_webhook ) {
		$enabled[] = 'sitemailer';
	} else {
		// Remove the schedule left by the old version.
		wp_clear_scheduled_hook( 'olctk_sitemailer_check_emails' );
	}

	update_option( OLCTK_ENABLED_ADDONS_OPTION, $enabled );
}

// Load enabled add-ons.
olctk_maybe_migrate_addons();
foreach ( olctk_enabled_addons() as $olctk_addon_id ) {
	olctk_load_addon( $olctk_addon_id );
}
unset( $olctk_addon_id );
