<?php
/**
 * Traffic Log add-on for OLC Toolkit.
 *
 * Moved from the child theme. Settings (olc_traffic_log_cpts,
 * olc_traffic_log_dates) and logged data (post meta "olct-...") are the
 * same as before, so existing traffic shows in the reports.
 *
 * Loaded only while the add-on is enabled on the OLC Toolkit page.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

require_once __DIR__ . '/token.php';
require_once __DIR__ . '/handler.php';
require_once __DIR__ . '/tracker.php';

if ( is_admin() ) {
	require_once __DIR__ . '/report.php';
	require_once __DIR__ . '/admin.php';
}

// Nothing to clean up when switched off: no scheduled jobs, and settings
// and logged traffic are kept for when it's switched back on.
