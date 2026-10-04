<?php
/**
 * Traffic Log add-on settings page (OLC Toolkit > Traffic Log).
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/** Admin page slug for this add-on. */
define( 'OLCTK_TRAFFIC_LOG_PAGE', 'olc-toolkit-traffic-log' );

/** Settings group (kept from the child-theme version). */
define( 'OLCTK_TRAFFIC_LOG_GROUP', 'olc_traffic_log_group' );

/* -------------------------------------------------------------------------
 * Menu: OLC Toolkit > Traffic Log.
 * ---------------------------------------------------------------------- */
add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'olc-toolkit',
			__( 'Traffic Log', 'olc-toolkit' ),
			__( 'Traffic Log', 'olc-toolkit' ),
			'manage_options',
			OLCTK_TRAFFIC_LOG_PAGE,
			'olctk_traffic_log_render_page'
		);
	},
	20 // After the main OLC Toolkit menu exists.
);

/* -------------------------------------------------------------------------
 * Settings.
 * ---------------------------------------------------------------------- */
add_action(
	'admin_init',
	function () {
		register_setting(
			OLCTK_TRAFFIC_LOG_GROUP,
			OLCTK_TRAFFIC_LOG_TYPES_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'olctk_traffic_log_sanitize_types',
				'default'           => array(),
			)
		);
		register_setting(
			OLCTK_TRAFFIC_LOG_GROUP,
			OLCTK_TRAFFIC_LOG_DATES_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'olctk_traffic_log_sanitize_dates',
				'default'           => array(),
			)
		);
	}
);

/**
 * Post types that can be tracked: posts, pages and public custom post types
 * (except page-builder ones such as Elementor templates).
 *
 * @return array<string, string> Post type => label.
 */
function olctk_traffic_log_available_post_types(): array {
	$exclude = (array) apply_filters( 'olctk_traffic_log_excluded_post_types', array( 'templates', 'buttons', 'library', 'elementor' ) );
	$types   = array(
		'post' => __( 'Posts', 'olc-toolkit' ),
		'page' => __( 'Pages', 'olc-toolkit' ),
	);

	foreach ( get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' ) as $obj ) {
		foreach ( $exclude as $word ) {
			if ( false !== strpos( $obj->name, $word ) ) {
				continue 2;
			}
		}
		$types[ $obj->name ] = $obj->label;
	}
	return $types;
}

/**
 * Keep only valid post types.
 *
 * @param mixed $input Submitted value.
 * @return string[]
 */
function olctk_traffic_log_sanitize_types( $input ): array {
	$input = is_array( $input ) ? array_map( 'sanitize_key', $input ) : array();
	return array_values( array_intersect( $input, array_keys( olctk_traffic_log_available_post_types() ) ) );
}

/**
 * Keep valid start dates; an invalid date becomes today.
 *
 * @param mixed $input Submitted value.
 * @return array<string, string>
 */
function olctk_traffic_log_sanitize_dates( $input ): array {
	$clean = array();
	foreach ( is_array( $input ) ? $input : array() as $post_type => $date ) {
		$date                              = sanitize_text_field( (string) $date );
		$clean[ sanitize_key( $post_type ) ] = olctk_traffic_log_valid_date( $date ) ? $date : wp_date( 'Y-m-d' );
	}
	return $clean;
}

/* -------------------------------------------------------------------------
 * Warning while the old child-theme copy is still installed.
 * ---------------------------------------------------------------------- */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) || ! olctk_traffic_log_theme_copy_active() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'OLC Toolkit Traffic Log: the old Traffic Log code is still in the child theme. Remove it from the theme (and its include in functions.php). Until then the add-on doesn\'t track visits, so nothing is counted twice.', 'olc-toolkit' );
		echo '</p></div>';
	}
);

/* -------------------------------------------------------------------------
 * Page output.
 * ---------------------------------------------------------------------- */
function olctk_traffic_log_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$selected = olctk_traffic_log_post_types();
	$dates    = (array) get_option( OLCTK_TRAFFIC_LOG_DATES_OPTION, array() );
	$types    = olctk_traffic_log_available_post_types();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Traffic Log', 'olc-toolkit' ); ?></h1>

		<?php settings_errors(); ?>

		<p><?php esc_html_e( 'Counts visitors, sessions and page views on the selected post types, and clicks on links placed inside an element with the CSS class track_clicks. Administrators and editors are not counted.', 'olc-toolkit' ); ?></p>

		<form method="post" action="options.php">
			<?php settings_fields( OLCTK_TRAFFIC_LOG_GROUP ); ?>

			<h2><?php esc_html_e( 'Post types to log', 'olc-toolkit' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post type', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Log start date', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Report', 'olc-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $types as $post_type => $label ) : ?>
						<?php
						$is_on = in_array( $post_type, $selected, true );
						$date  = ! empty( $dates[ $post_type ] ) ? $dates[ $post_type ] : wp_date( 'Y-m-d' );
						$url   = add_query_arg(
							'page',
							OLCTK_TRAFFIC_LOG_REPORT_PREFIX . $post_type,
							admin_url( 'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . $post_type )
						);
						?>
						<tr>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( OLCTK_TRAFFIC_LOG_TYPES_OPTION ); ?>[]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( $is_on ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							</td>
							<td>
								<input type="date" name="<?php echo esc_attr( OLCTK_TRAFFIC_LOG_DATES_OPTION ); ?>[<?php echo esc_attr( $post_type ); ?>]" value="<?php echo esc_attr( $date ); ?>" />
							</td>
							<td>
								<?php if ( $is_on ) : ?>
									<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'View report', 'olc-toolkit' ); ?></a>
								<?php else : ?>
									<span class="description"><?php esc_html_e( 'Not tracked', 'olc-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Each tracked post type gets a "Traffic Log" report in its own menu (for example Posts > Traffic Log). The start date is shown on the report and is the earliest date it can be filtered from.', 'olc-toolkit' ); ?></p>

			<?php submit_button( __( 'Save Settings', 'olc-toolkit' ) ); ?>
		</form>
	</div>
	<?php
}
