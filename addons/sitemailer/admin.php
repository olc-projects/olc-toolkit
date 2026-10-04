<?php
/**
 * SiteMailer add-on settings page (OLC Toolkit > SiteMailer).
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/** Admin page slug for this add-on. */
define( 'OLCTK_SITEMAILER_PAGE', 'olc-toolkit-sitemailer' );

/* -------------------------------------------------------------------------
 * Menu: OLC Toolkit > SiteMailer.
 * ---------------------------------------------------------------------- */
add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'olc-toolkit',
			__( 'SiteMailer Alerts', 'olc-toolkit' ),
			__( 'SiteMailer', 'olc-toolkit' ),
			'manage_options',
			OLCTK_SITEMAILER_PAGE,
			'olctk_sitemailer_render_page'
		);
	},
	20 // After the main OLC Toolkit menu exists.
);

/* -------------------------------------------------------------------------
 * Form handlers (admin-post.php).
 * ---------------------------------------------------------------------- */

/**
 * Back to the SiteMailer page with a notice.
 *
 * @param string $notice Notice key.
 */
function olctk_sitemailer_redirect( string $notice ): void {
	olc_toolkit_redirect_with_notice( $notice, OLCTK_SITEMAILER_PAGE );
}

// Save or remove the webhook address.
add_action(
	'admin_post_olctk_save_sitemailer',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'olc-toolkit' ), 403 );
		}
		check_admin_referer( 'olctk_save_sitemailer' );

		if ( ! empty( $_POST['olctk_remove_webhook'] ) ) {
			olctk_sitemailer_delete_webhook();
			olctk_sitemailer_redirect( 'removed' );
		}

		$url = isset( $_POST['olctk_webhook_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['olctk_webhook_url'] ) ) ) : '';

		// Blank field = keep the current address.
		if ( '' === $url ) {
			olctk_sitemailer_redirect( 'unchanged' );
		}

		if ( ! olctk_sitemailer_is_valid_webhook( $url ) ) {
			olctk_sitemailer_redirect( 'invalid' );
		}

		olctk_sitemailer_save_webhook( $url );
		olctk_sitemailer_redirect( 'saved' );
	}
);

// Send a test message to Slack.
add_action(
	'admin_post_olctk_test_sitemailer',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'olc-toolkit' ), 403 );
		}
		check_admin_referer( 'olctk_test_sitemailer' );

		olctk_sitemailer_redirect( olctk_sitemailer_test_slack() ? 'test_ok' : 'test_fail' );
	}
);

// Run the delivery check now.
add_action(
	'admin_post_olctk_run_sitemailer_check',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'olc-toolkit' ), 403 );
		}
		check_admin_referer( 'olctk_run_sitemailer_check' );

		olctk_sitemailer_check_emails( true );
		olctk_sitemailer_redirect( 'check_done' );
	}
);

/* -------------------------------------------------------------------------
 * Page output.
 * ---------------------------------------------------------------------- */
function olctk_sitemailer_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$from_config = olctk_sitemailer_webhook_from_config();
	$masked      = olctk_sitemailer_masked_webhook();
	$unreadable  = ! $from_config && olctk_sitemailer_webhook_unreadable();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SiteMailer Alerts', 'olc-toolkit' ); ?></h1>

		<?php
		olc_toolkit_render_notice(
			array(
				'saved'      => array( 'success', esc_html__( 'Slack webhook saved.', 'olc-toolkit' ) ),
				'removed'    => array( 'success', esc_html__( 'Slack webhook removed.', 'olc-toolkit' ) ),
				'unchanged'  => array( 'info', esc_html__( 'No new address entered, so the saved webhook was kept.', 'olc-toolkit' ) ),
				'invalid'    => array( 'error', esc_html__( 'That doesn\'t look like a Slack webhook address. It should start with https://hooks.slack.com/services/', 'olc-toolkit' ) ),
				'test_ok'    => array( 'success', esc_html__( 'Test message sent to Slack.', 'olc-toolkit' ) ),
				'test_fail'  => array( 'error', esc_html__( 'The test message could not be sent. Check the webhook address and try again.', 'olc-toolkit' ) ),
				'check_done' => array( 'success', esc_html__( 'Delivery check finished. See the result under Email delivery alerts.', 'olc-toolkit' ) ),
			)
		);
		?>

		<h2><?php esc_html_e( 'Slack notifications', 'olc-toolkit' ); ?></h2>

		<?php if ( $unreadable ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'A webhook is saved but can no longer be read, probably because this site\'s security keys in wp-config.php were changed. Please enter the address again.', 'olc-toolkit' ); ?>
			</p></div>
		<?php endif; ?>

		<?php if ( $from_config ) : ?>
			<p>
				<?php esc_html_e( 'The webhook is set in wp-config.php (SITEMAILER_WEBHOOK_URL), which overrides this page.', 'olc-toolkit' ); ?>
				<br /><code><?php echo esc_html( $masked ); ?></code>
			</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="olctk_save_sitemailer" />
				<?php wp_nonce_field( 'olctk_save_sitemailer' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="olctk_webhook_url"><?php esc_html_e( 'Slack webhook address', 'olc-toolkit' ); ?></label></th>
						<td>
							<?php if ( '' !== $masked ) : ?>
								<p><?php esc_html_e( 'Current:', 'olc-toolkit' ); ?> <code><?php echo esc_html( $masked ); ?></code></p>
							<?php endif; ?>
							<input
								type="password"
								id="olctk_webhook_url"
								name="olctk_webhook_url"
								class="regular-text"
								autocomplete="off"
								spellcheck="false"
								placeholder="https://hooks.slack.com/services/..."
							/>
							<p class="description">
								<?php
								echo '' !== $masked
									? esc_html__( 'Paste a new address to replace the current one, or leave blank to keep it. It is stored encrypted.', 'olc-toolkit' )
									: esc_html__( 'Paste the Slack incoming-webhook address. It is stored encrypted.', 'olc-toolkit' );
								?>
							</p>
							<?php if ( '' !== $masked || $unreadable ) : ?>
								<p>
									<label>
										<input type="checkbox" name="olctk_remove_webhook" value="1" />
										<?php esc_html_e( 'Remove the saved webhook', 'olc-toolkit' ); ?>
									</label>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save webhook', 'olc-toolkit' ) ); ?>
			</form>
		<?php endif; ?>

		<?php if ( '' !== $masked ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="olctk_test_sitemailer" />
				<?php wp_nonce_field( 'olctk_test_sitemailer' ); ?>
				<?php submit_button( __( 'Send test message', 'olc-toolkit' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<hr />

		<?php olctk_sitemailer_render_alerts_panel(); ?>
	</div>
	<?php
}

/**
 * "Email delivery alerts" section: status of the last check and a button to run one now.
 */
function olctk_sitemailer_render_alerts_panel(): void {
	$last      = get_option( OLCTK_SITEMAILER_LAST_CHECK_OPTION, array() );
	$next      = wp_next_scheduled( OLCTK_SITEMAILER_CRON_HOOK );
	$newest    = olctk_sitemailer_newest_record_time();
	$installed = olctk_sitemailer_is_installed();
	$connected = olctk_sitemailer_is_connected();
	$date_fmt  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

	$outcomes = array(
		'ok'            => __( 'No failed or delayed emails.', 'olc-toolkit' ),
		'problems'      => __( 'Problems found.', 'olc-toolkit' ),
		'no_webhook'    => __( 'Skipped: no Slack webhook saved.', 'olc-toolkit' ),
		'not_installed' => __( 'Skipped: Site Mailer is not active.', 'olc-toolkit' ),
		'not_connected' => __( 'Site Mailer is not connected to Elementor\'s mail service.', 'olc-toolkit' ),
	);
	$refreshes = array(
		'refreshed'    => __( 'statuses refreshed from the mail service', 'olc-toolkit' ),
		'rate_limited' => __( 'statuses were refreshed less than 15 minutes ago, used those', 'olc-toolkit' ),
		'error'        => __( 'status refresh failed, used saved statuses', 'olc-toolkit' ),
	);
	?>
	<h2><?php esc_html_e( 'Email delivery alerts', 'olc-toolkit' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %d: minutes */
			esc_html__( 'Every 15 minutes, Site Mailer emails are checked. Failed emails, and emails still not delivered after %d minutes, are posted to Slack once each.', 'olc-toolkit' ),
			(int) olctk_sitemailer_stuck_minutes()
		);
		?>
	</p>

	<table class="widefat striped" style="max-width:720px">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Site Mailer', 'olc-toolkit' ); ?></th>
				<td>
					<?php
					if ( ! $installed ) {
						esc_html_e( 'Not active', 'olc-toolkit' );
					} elseif ( false === $connected ) {
						esc_html_e( 'Active, but not connected', 'olc-toolkit' );
					} else {
						esc_html_e( 'Active and connected', 'olc-toolkit' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Last check', 'olc-toolkit' ); ?></th>
				<td>
					<?php
					if ( empty( $last['time'] ) ) {
						esc_html_e( 'Not run yet', 'olc-toolkit' );
					} else {
						echo esc_html( wp_date( $date_fmt, (int) $last['time'] ) . ': ' . ( $outcomes[ $last['outcome'] ] ?? $last['outcome'] ) );
						if ( isset( $refreshes[ $last['refresh'] ] ) ) {
							echo ' <span class="description">(' . esc_html( $refreshes[ $last['refresh'] ] ) . ')</span>';
						}
						if ( 'problems' === $last['outcome'] ) {
							echo '<br />';
							printf(
								/* translators: 1: failed count, 2: delayed count */
								esc_html__( '%1$d failed, %2$d delayed.', 'olc-toolkit' ),
								(int) $last['failed'],
								(int) $last['stuck']
							);
							echo ' ';
							echo $last['slack']
								? esc_html__( 'Slack alert sent.', 'olc-toolkit' )
								: '<strong>' . esc_html__( 'Slack alert could not be sent; will retry at the next check.', 'olc-toolkit' ) . '</strong>';
						}
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Next check', 'olc-toolkit' ); ?></th>
				<td><?php echo $next ? esc_html( wp_date( $date_fmt, $next ) ) : esc_html__( 'Not scheduled', 'olc-toolkit' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Clock check', 'olc-toolkit' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: 1: site time now, 2: newest Site Mailer record time */
						esc_html__( 'Site time now: %1$s. Newest Site Mailer record: %2$s.', 'olc-toolkit' ),
						esc_html( current_time( 'mysql' ) ),
						esc_html( $newest ? $newest : __( 'none', 'olc-toolkit' ) )
					);
					?>
					<br /><span class="description"><?php esc_html_e( 'Send yourself an email, then check that its record time matches the site time. If they differ by hours, delay alerts will be early or late.', 'olc-toolkit' ); ?></span>
				</td>
			</tr>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
		<input type="hidden" name="action" value="olctk_run_sitemailer_check" />
		<?php wp_nonce_field( 'olctk_run_sitemailer_check' ); ?>
		<?php submit_button( __( 'Run check now', 'olc-toolkit' ), 'secondary', 'submit', false ); ?>
	</form>
	<?php
}
