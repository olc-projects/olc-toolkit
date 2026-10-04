<?php
/**
 * SiteMailer delivery alerts for OLC Toolkit.
 *
 * Every 15 minutes (WP-Cron):
 *   1. Ask Site Mailer to refresh delivery statuses from Elementor's mail
 *      service (respecting its own 15-minute rate limit).
 *   2. Look in Site Mailer's status table for emails that failed, or that
 *      are still undelivered after OLCTK stuck threshold (default 60 min).
 *   3. Post one summary message to Slack. Each email is reported once
 *      (a "stuck" email that later fails is reported again as failed).
 *
 * Site Mailer's classes are internal, so every call is guarded and a Site
 * Mailer update can't break this plugin.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'OLCTK_SITEMAILER_CRON_HOOK', 'olctk_sitemailer_check_emails' );
define( 'OLCTK_SITEMAILER_ALERTED_OPTION', 'olctk_sitemailer_alerted' );
define( 'OLCTK_SITEMAILER_LAST_CHECK_OPTION', 'olctk_sitemailer_last_check' );

/* -------------------------------------------------------------------------
 * Settings (change with filters if needed).
 * ---------------------------------------------------------------------- */

/** Statuses that mean the email failed. */
function olctk_sitemailer_failed_statuses(): array {
	return (array) apply_filters(
		'olctk_sitemailer_failed_statuses',
		array( 'failed', 'bounce', 'dropped', 'not sent', 'rate limit', 'not valid' )
	);
}

/** Statuses that mean the email is still on its way. */
function olctk_sitemailer_in_progress_statuses(): array {
	return (array) apply_filters(
		'olctk_sitemailer_in_progress_statuses',
		array( 'pending', 'accepted', 'processed', 'deferred' )
	);
}

/** Minutes after which an undelivered email is reported. Default 60. */
function olctk_sitemailer_stuck_minutes(): int {
	return max( 5, (int) apply_filters( 'olctk_sitemailer_stuck_minutes', 60 ) );
}

/** Only look at emails sent in the last N hours. Default 24. */
function olctk_sitemailer_lookback_hours(): int {
	return max( 2, (int) apply_filters( 'olctk_sitemailer_lookback_hours', 24 ) );
}

/* -------------------------------------------------------------------------
 * Scheduling.
 * ---------------------------------------------------------------------- */

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['olctk_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (OLC Toolkit)', 'olc-toolkit' ),
		);
		return $schedules;
	}
);

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( OLCTK_SITEMAILER_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'olctk_fifteen_minutes', OLCTK_SITEMAILER_CRON_HOOK );
		}
	}
);

add_action(
	OLCTK_SITEMAILER_CRON_HOOK,
	function () {
		olctk_sitemailer_check_emails();
	}
);

register_deactivation_hook(
	OLC_TOOLKIT_FILE,
	function () {
		wp_clear_scheduled_hook( OLCTK_SITEMAILER_CRON_HOOK );
	}
);

/* -------------------------------------------------------------------------
 * Talking to Site Mailer.
 * ---------------------------------------------------------------------- */

/** Whether Site Mailer (with the parts we need) is active. */
function olctk_sitemailer_is_installed(): bool {
	return class_exists( '\SiteMailer\Modules\Logs\Components\Log_Pull' )
		&& method_exists( '\SiteMailer\Modules\Logs\Components\Log_Pull', 'pull_logs' );
}

/**
 * Whether Site Mailer is connected to Elementor's mail service.
 *
 * @return bool|null Null if it can't be determined.
 */
function olctk_sitemailer_is_connected() {
	$connect = '\SiteMailer\Modules\Connect\Module';
	if ( ! class_exists( $connect ) || ! method_exists( $connect, 'is_connected' ) ) {
		return null;
	}
	try {
		return (bool) $connect::is_connected();
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Ask Site Mailer to fetch the latest delivery statuses.
 *
 * @param bool $force Skip Site Mailer's 15-minute rate limit (manual use only).
 * @return string 'refreshed', 'rate_limited', 'not_connected', 'not_installed' or 'error'.
 */
function olctk_sitemailer_refresh_statuses( bool $force = false ): string {
	if ( ! olctk_sitemailer_is_installed() ) {
		return 'not_installed';
	}
	if ( false === olctk_sitemailer_is_connected() ) {
		return 'not_connected';
	}

	$pull      = '\SiteMailer\Modules\Logs\Components\Log_Pull';
	$transient = defined( $pull . '::STATUSES_REFRESH_TIMESTAMP' )
		? constant( $pull . '::STATUSES_REFRESH_TIMESTAMP' )
		: 'site-mailer-statuses_refresh_timestamp';

	if ( $force ) {
		delete_transient( $transient );
	} elseif ( get_transient( $transient ) ) {
		return 'rate_limited';
	}

	try {
		$pull::pull_logs();
	} catch ( \Throwable $e ) {
		return 'error';
	}
	return 'refreshed';
}

/** Name of Site Mailer's status table. */
function olctk_sitemailer_statuses_table(): string {
	global $wpdb;
	$cls = '\SiteMailer\Modules\Statuses\Database\Statuses_Table';
	if ( class_exists( $cls ) && method_exists( $cls, 'table_name' ) ) {
		try {
			$name = (string) $cls::table_name();
			if ( '' !== $name ) {
				return $name;
			}
		} catch ( \Throwable $e ) {
			// Fall through to the default name.
		}
	}
	return $wpdb->prefix . 'site_mail_statuses';
}

/** Whether Site Mailer's status table exists. */
function olctk_sitemailer_table_exists( string $table ): bool {
	global $wpdb;
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/**
 * Newest record time in Site Mailer's status table (for checking clocks).
 *
 * @return string|null
 */
function olctk_sitemailer_newest_record_time() {
	global $wpdb;
	$table = olctk_sitemailer_statuses_table();
	if ( ! olctk_sitemailer_table_exists( $table ) ) {
		return null;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
	return $wpdb->get_var( "SELECT MAX(`created_at`) FROM `{$table}`" );
}

/**
 * Run a status query against Site Mailer's table.
 *
 * @param array  $statuses Statuses to match.
 * @param string $since    Earliest created_at (site time).
 * @param string $before   Latest created_at (site time), or '' for none.
 * @return array Rows as objects.
 */
function olctk_sitemailer_query_statuses( array $statuses, string $since, string $before = '' ): array {
	global $wpdb;
	if ( empty( $statuses ) ) {
		return array();
	}
	$table        = olctk_sitemailer_statuses_table();
	$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$args         = array_merge( array( $since ), $statuses );
	$before_sql   = '';
	if ( '' !== $before ) {
		$before_sql = ' AND `created_at` < %s';
		$args[]     = $before;
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; values are prepared.
	$sql = $wpdb->prepare(
		"SELECT `id`, `log_id`, `email`, `status`, `created_at`, `updated_at`
		FROM `{$table}`
		WHERE `created_at` >= %s AND `status` IN ({$placeholders}){$before_sql}
		ORDER BY `created_at` DESC
		LIMIT 200",
		$args
	);
	// phpcs:enable

	return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Find emails that failed or are stuck, and haven't been reported yet.
 *
 * Site Mailer writes its times with current_time( 'mysql' ) (site time),
 * so cut-offs here use site time too.
 *
 * @return array{failed: array, stuck: array}
 */
function olctk_sitemailer_find_problems(): array {
	$result = array(
		'failed' => array(),
		'stuck'  => array(),
	);
	if ( ! olctk_sitemailer_table_exists( olctk_sitemailer_statuses_table() ) ) {
		return $result;
	}

	$now          = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- site-time wall clock.
	$since        = gmdate( 'Y-m-d H:i:s', $now - olctk_sitemailer_lookback_hours() * HOUR_IN_SECONDS );
	$stuck_before = gmdate( 'Y-m-d H:i:s', $now - olctk_sitemailer_stuck_minutes() * MINUTE_IN_SECONDS );
	$alerted      = (array) get_option( OLCTK_SITEMAILER_ALERTED_OPTION, array() );

	foreach ( olctk_sitemailer_query_statuses( olctk_sitemailer_failed_statuses(), $since ) as $row ) {
		if ( 'failed' !== ( $alerted[ $row->id ] ?? '' ) ) {
			$result['failed'][] = $row;
		}
	}
	foreach ( olctk_sitemailer_query_statuses( olctk_sitemailer_in_progress_statuses(), $since, $stuck_before ) as $row ) {
		if ( ! isset( $alerted[ $row->id ] ) ) {
			$result['stuck'][] = $row;
		}
	}
	return $result;
}

/**
 * Remember which emails were reported, so each is reported once.
 *
 * @param array $problems Output of olctk_sitemailer_find_problems().
 */
function olctk_sitemailer_mark_alerted( array $problems ): void {
	$alerted = (array) get_option( OLCTK_SITEMAILER_ALERTED_OPTION, array() );
	foreach ( $problems['failed'] as $row ) {
		$alerted[ (int) $row->id ] = 'failed';
	}
	foreach ( $problems['stuck'] as $row ) {
		$alerted[ (int) $row->id ] = 'stuck';
	}
	// Keep the list small: Site Mailer deletes rows after 30 days anyway.
	if ( count( $alerted ) > 2000 ) {
		ksort( $alerted );
		$alerted = array_slice( $alerted, -2000, null, true );
	}
	update_option( OLCTK_SITEMAILER_ALERTED_OPTION, $alerted, false );
}

/* -------------------------------------------------------------------------
 * Slack message.
 * ---------------------------------------------------------------------- */

/** Escape text for Slack messages. */
function olctk_slack_escape( string $text ): string {
	return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
}

/**
 * Build the Slack summary.
 *
 * @param array $problems Output of olctk_sitemailer_find_problems().
 * @return string
 */
function olctk_sitemailer_build_message( array $problems ): string {
	$max   = 10;
	$lines = array();

	$lines[] = sprintf(
		':rotating_light: *Site Mailer alert: %s* (<%s>)',
		olctk_slack_escape( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
		esc_url_raw( home_url( '/' ) )
	);

	$groups = array(
		'failed' => sprintf( '*Failed (%d):*', count( $problems['failed'] ) ),
		'stuck'  => sprintf( '*Not delivered after %d minutes (%d):*', olctk_sitemailer_stuck_minutes(), count( $problems['stuck'] ) ),
	);

	foreach ( $groups as $key => $heading ) {
		if ( empty( $problems[ $key ] ) ) {
			continue;
		}
		$lines[] = '';
		$lines[] = $heading;
		foreach ( array_slice( $problems[ $key ], 0, $max ) as $row ) {
			$lines[] = sprintf(
				'• %s: _%s_ (sent %s)',
				olctk_slack_escape( (string) $row->email ),
				olctk_slack_escape( (string) $row->status ),
				olctk_slack_escape( (string) $row->created_at )
			);
		}
		$more = count( $problems[ $key ] ) - $max;
		if ( $more > 0 ) {
			$lines[] = sprintf( '…and %d more.', $more );
		}
	}

	$lines[] = '';
	$lines[] = 'Details: Site Mailer › Logs in the WordPress dashboard.';

	return implode( "\n", $lines );
}

/* -------------------------------------------------------------------------
 * The check itself.
 * ---------------------------------------------------------------------- */

/**
 * Refresh statuses, find problems and alert Slack.
 *
 * @param bool $force_refresh Skip Site Mailer's 15-minute limit (manual "Run check now").
 * @return array Summary of what happened (also saved for the admin page).
 */
function olctk_sitemailer_check_emails( bool $force_refresh = false ): array {
	$result = array(
		'time'    => time(),
		'outcome' => '',
		'refresh' => '',
		'failed'  => 0,
		'stuck'   => 0,
		'slack'   => null,
	);

	$webhook = olctk_sitemailer_get_webhook_url();

	if ( '' === $webhook ) {
		$result['outcome'] = 'no_webhook';
	} else {
		$result['refresh'] = olctk_sitemailer_refresh_statuses( $force_refresh );

		if ( 'not_installed' === $result['refresh'] ) {
			$result['outcome'] = 'not_installed';
		} elseif ( 'not_connected' === $result['refresh'] ) {
			// Statuses can't update, so everything would look stuck. Alert once a day instead.
			$result['outcome'] = 'not_connected';
			if ( ! get_transient( 'olctk_sitemailer_disconnected_alerted' ) ) {
				$result['slack'] = olctk_send_slack_message(
					sprintf(
						':warning: *Site Mailer is not connected* on %s (<%s>). Emails are not being sent through Site Mailer and delivery can\'t be checked.',
						olctk_slack_escape( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
						esc_url_raw( home_url( '/' ) )
					),
					$webhook
				);
				if ( $result['slack'] ) {
					set_transient( 'olctk_sitemailer_disconnected_alerted', 1, DAY_IN_SECONDS );
				}
			}
		} else {
			$problems         = olctk_sitemailer_find_problems();
			$result['failed'] = count( $problems['failed'] );
			$result['stuck']  = count( $problems['stuck'] );

			if ( 0 === $result['failed'] + $result['stuck'] ) {
				$result['outcome'] = 'ok';
			} else {
				$result['outcome'] = 'problems';
				$result['slack']   = olctk_send_slack_message( olctk_sitemailer_build_message( $problems ), $webhook );
				if ( $result['slack'] ) {
					olctk_sitemailer_mark_alerted( $problems );
				}
			}
		}
	}

	update_option( OLCTK_SITEMAILER_LAST_CHECK_OPTION, $result, false );
	return $result;
}
