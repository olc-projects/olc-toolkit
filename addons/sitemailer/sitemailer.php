<?php
/**
 * SiteMailer Alerts add-on for OLC Toolkit.
 *
 * Loaded only while the add-on is enabled on the OLC Toolkit page.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

require_once __DIR__ . '/integration.php';
require_once __DIR__ . '/alerts.php';

if ( is_admin() ) {
	require_once __DIR__ . '/admin.php';
}

// When the add-on is switched off, stop the 15-minute check.
// The saved webhook is kept so switching it back on restores the setup.
add_action(
	'olctk_addon_disabled_sitemailer',
	function () {
		wp_clear_scheduled_hook( OLCTK_SITEMAILER_CRON_HOOK );
	}
);
