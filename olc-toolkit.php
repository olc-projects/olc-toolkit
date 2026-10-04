<?php
/**
 * Plugin Name:       OLC Toolkit
 * Plugin URI:        https://github.com/olc-projects/olc-toolkit
 * Description:       A toolkit plugin for OLC development
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Our Little Company
 * License:           GPL-2.0-or-later
 * Text Domain:       olc-toolkit
 * Update URI:        https://github.com/olc-projects/olc-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'OLC_TOOLKIT_VERSION', '1.1.0' );
define( 'OLC_TOOLKIT_FILE', __FILE__ );
define( 'OLC_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );

// Shared helpers used by add-ons.
require_once OLC_TOOLKIT_DIR . 'slack-integration.php';

// Add-on system: loads only the add-ons enabled on the OLC Toolkit page.
require_once OLC_TOOLKIT_DIR . 'addons.php';

if ( is_admin() ) {
	require_once OLC_TOOLKIT_DIR . 'admin-dashboard.php';
}

/* -------------------------------------------------------------------------
 * 1. Update checker (Plugin Update Checker by YahnisElsts)
 * ---------------------------------------------------------------------- */
require_once OLC_TOOLKIT_DIR . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/*
 * Updates come straight from the GitHub repository's "master" branch.
 * Workflow: edit locally -> bump the Version header and OLC_TOOLKIT_VERSION
 * -> push/sync to GitHub. Sites update when they see a higher version.
 */
$olc_toolkit_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/olc-projects/olc-toolkit/',
	__FILE__,
	'olc-toolkit'
);

// Branch to read updates from.
$olc_toolkit_update_checker->setBranch( 'master' );

/* -------------------------------------------------------------------------
 * 2. Allow WordPress to auto-install updates for this plugin.
 *    Remove this block if you want admins to update manually.
 * ---------------------------------------------------------------------- */
add_filter(
	'auto_update_plugin',
	function ( $update, $item ) {
		if ( isset( $item->slug ) && 'olc-toolkit' === $item->slug ) {
			return true;
		}
		return $update;
	},
	10,
	2
);
