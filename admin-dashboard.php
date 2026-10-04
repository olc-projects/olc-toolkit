<?php
/**
 * WordPress dashboard (admin) page for OLC Toolkit: enable / disable add-ons.
 *
 * Add-on settings pages are added by each add-on as submenus of 'olc-toolkit'.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/* -------------------------------------------------------------------------
 * Menu.
 * ---------------------------------------------------------------------- */
add_action(
	'admin_menu',
	function () {
		add_menu_page(
			__( 'OLC Toolkit', 'olc-toolkit' ),
			__( 'OLC Toolkit', 'olc-toolkit' ),
			'manage_options',
			'olc-toolkit',
			'olc_toolkit_render_admin_page',
			'dashicons-admin-tools'
		);

		// First submenu item (replaces the duplicate "OLC Toolkit" entry).
		add_submenu_page(
			'olc-toolkit',
			__( 'OLC Toolkit Add-ons', 'olc-toolkit' ),
			__( 'Add-ons', 'olc-toolkit' ),
			'manage_options',
			'olc-toolkit',
			'olc_toolkit_render_admin_page'
		);
	}
);

/* -------------------------------------------------------------------------
 * Shared helpers (also used by add-on settings pages).
 * ---------------------------------------------------------------------- */

/**
 * Send the admin back to an OLC Toolkit page with a status notice.
 *
 * @param string $notice Notice key.
 * @param string $page   Admin page slug.
 * @param array  $extra  Extra query args.
 */
function olc_toolkit_redirect_with_notice( string $notice, string $page = 'olc-toolkit', array $extra = array() ): void {
	wp_safe_redirect(
		add_query_arg(
			array_merge(
				array(
					'page'         => $page,
					'olctk_notice' => $notice,
				),
				$extra
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Show the status notice after a form submission.
 *
 * @param array $notices Notice key => array( type, message ).
 */
function olc_toolkit_render_notice( array $notices ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$key = isset( $_GET['olctk_notice'] ) ? sanitize_key( wp_unslash( $_GET['olctk_notice'] ) ) : '';

	if ( ! isset( $notices[ $key ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $notices[ $key ][0] ),
		wp_kses( $notices[ $key ][1], array( 'a' => array( 'href' => array() ) ) )
	);
}

/**
 * URL of an add-on's settings page, or '' if it has none.
 *
 * @param string $id Add-on id.
 * @return string
 */
function olc_toolkit_addon_settings_url( string $id ): string {
	$addons = olctk_addons();
	if ( empty( $addons[ $id ]['settings_page'] ) ) {
		return '';
	}
	return admin_url( 'admin.php?page=' . $addons[ $id ]['settings_page'] );
}

/* -------------------------------------------------------------------------
 * Enable / disable handler (admin-post.php).
 * ---------------------------------------------------------------------- */
add_action(
	'admin_post_olctk_toggle_addon',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'olc-toolkit' ), 403 );
		}

		$id     = isset( $_POST['addon'] ) ? sanitize_key( wp_unslash( $_POST['addon'] ) ) : '';
		$enable = ! empty( $_POST['enable'] );

		check_admin_referer( 'olctk_toggle_addon_' . $id );

		if ( ! olctk_set_addon_enabled( $id, $enable ) ) {
			olc_toolkit_redirect_with_notice( 'addon_unknown' );
		}

		olc_toolkit_redirect_with_notice( $enable ? 'addon_enabled' : 'addon_disabled', 'olc-toolkit', array( 'olctk_addon' => $id ) );
	}
);

/* -------------------------------------------------------------------------
 * Page output.
 * ---------------------------------------------------------------------- */
function olc_toolkit_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$addons = olctk_addons();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$notice_id   = isset( $_GET['olctk_addon'] ) ? sanitize_key( wp_unslash( $_GET['olctk_addon'] ) ) : '';
	$notice_name = isset( $addons[ $notice_id ] ) ? esc_html( $addons[ $notice_id ]['name'] ) : '';
	$notice_url  = $notice_name ? olc_toolkit_addon_settings_url( $notice_id ) : '';

	$enabled_msg = sprintf(
		/* translators: %s: add-on name */
		esc_html__( '%s enabled.', 'olc-toolkit' ),
		$notice_name
	);
	if ( $notice_url ) {
		$enabled_msg .= ' <a href="' . esc_url( $notice_url ) . '">' . esc_html__( 'Go to its settings', 'olc-toolkit' ) . '</a>';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'OLC Toolkit', 'olc-toolkit' ); ?></h1>

		<?php
		olc_toolkit_render_notice(
			array(
				'addon_enabled'  => array( 'success', $enabled_msg ),
				/* translators: %s: add-on name */
				'addon_disabled' => array( 'success', sprintf( esc_html__( '%s disabled. Its settings are kept.', 'olc-toolkit' ), $notice_name ) ),
				'addon_unknown'  => array( 'error', esc_html__( 'That add-on could not be found.', 'olc-toolkit' ) ),
			)
		);
		?>

		<p>
			<?php
			printf(
				/* translators: %s: plugin version */
				esc_html__( 'Installed version: %s', 'olc-toolkit' ),
				esc_html( OLC_TOOLKIT_VERSION )
			);
			?>
			&middot; <?php esc_html_e( 'Updates are checked automatically. To check now, go to Dashboard > Updates.', 'olc-toolkit' ); ?>
		</p>

		<h2><?php esc_html_e( 'Add-ons', 'olc-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Turn on only the add-ons this site needs. Enabled add-ons with settings appear under the OLC Toolkit menu.', 'olc-toolkit' ); ?></p>

		<table class="wp-list-table widefat plugins" style="max-width:900px">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Add-on', 'olc-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'olc-toolkit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $addons ) ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No add-ons available.', 'olc-toolkit' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $addons as $id => $addon ) : ?>
					<?php
					$is_on        = olctk_addon_enabled( $id );
					$settings_url = $is_on ? olc_toolkit_addon_settings_url( $id ) : '';
					?>
					<tr class="<?php echo $is_on ? 'active' : 'inactive'; ?>">
						<td class="plugin-title column-primary">
							<strong><?php echo esc_html( $addon['name'] ); ?></strong>
							<div class="row-actions visible">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="olctk_toggle_addon" />
									<input type="hidden" name="addon" value="<?php echo esc_attr( $id ); ?>" />
									<input type="hidden" name="enable" value="<?php echo $is_on ? '0' : '1'; ?>" />
									<?php wp_nonce_field( 'olctk_toggle_addon_' . $id ); ?>
									<?php
									submit_button(
										$is_on ? __( 'Disable', 'olc-toolkit' ) : __( 'Enable', 'olc-toolkit' ),
										$is_on ? 'secondary small' : 'primary small',
										'submit',
										false
									);
									?>
								</form>
								<?php if ( $settings_url ) : ?>
									&nbsp;<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'olc-toolkit' ); ?></a>
								<?php endif; ?>
							</div>
						</td>
						<td class="column-description desc">
							<p><?php echo esc_html( $addon['description'] ); ?></p>
							<p class="description">
								<?php echo $is_on ? esc_html__( 'Enabled', 'olc-toolkit' ) : esc_html__( 'Disabled', 'olc-toolkit' ); ?>
							</p>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
