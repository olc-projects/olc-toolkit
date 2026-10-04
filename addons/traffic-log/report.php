<?php
/**
 * Traffic Log: report page, added as "Traffic Log" under each tracked
 * post type's menu (Posts > Traffic Log, Pages > Traffic Log, ...).
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/** Report page slug prefix; the post type is appended. */
define( 'OLCTK_TRAFFIC_LOG_REPORT_PREFIX', 'olctk-traffic-log-' );

add_action(
	'admin_menu',
	function () {
		foreach ( olctk_traffic_log_post_types() as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}
			add_submenu_page(
				'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . $post_type,
				__( 'Traffic Log', 'olc-toolkit' ),
				__( 'Traffic Log', 'olc-toolkit' ),
				'edit_posts',
				OLCTK_TRAFFIC_LOG_REPORT_PREFIX . $post_type,
				'olctk_traffic_log_render_report'
			);
		}
	},
	20
);

/**
 * A Y-m-d date from the query string, or '' if missing or invalid.
 *
 * @param string $name Query arg.
 * @return string
 */
function olctk_traffic_log_get_date_arg( string $name ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
	$value = isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
	return olctk_traffic_log_valid_date( $value ) ? $value : '';
}

/**
 * Whether a value is a real Y-m-d date.
 *
 * @param string $value Value to check.
 * @return bool
 */
function olctk_traffic_log_valid_date( string $value ): bool {
	$d = DateTimeImmutable::createFromFormat( 'Y-m-d', $value );
	return $d && $d->format( 'Y-m-d' ) === $value;
}

/**
 * Add up the logged traffic for every published post of a type.
 *
 * @param string $post_type Post type.
 * @param string $from      Earliest day, Ymd.
 * @param string $to        Latest day, Ymd.
 * @return array{rows: array, users: int, sessions: int, views: int, links: int, clicks: int, sublinks: array}
 */
function olctk_traffic_log_build_report( string $post_type, string $from, string $to ): array {
	$report = array(
		'rows'     => array(),
		'users'    => 0,
		'sessions' => 0,
		'views'    => 0,
		'links'    => 0,
		'clicks'   => 0,
		'sublinks' => array(),
	);
	$all_users    = array();
	$all_sessions = array();
	$wpml         = has_filter( 'wpml_element_language_details' );

	$post_ids = get_posts(
		array(
			'numberposts' => -1,
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'fields'      => 'ids',
			'orderby'     => 'title',
			'order'       => 'ASC',
		)
	);

	foreach ( $post_ids as $post_id ) {
		$post     = get_post( $post_id );
		$users    = array();
		$sessions = 0;
		$views    = 0;
		$links    = array();

		foreach ( (array) get_post_meta( $post_id ) as $meta_key => $values ) {
			if ( 0 !== strpos( $meta_key, 'olct-' ) ) {
				continue;
			}
			$parts = explode( '-', $meta_key );
			if ( count( $parts ) < 4 ) {
				continue;
			}
			list( , $date, $visitor ) = $parts;
			if ( $date < $from || $date > $to ) {
				continue;
			}

			$users[ $visitor ]           = true;
			$all_users[ $visitor ]       = true;
			$all_sessions[ $meta_key ]   = true;
			$sessions++;

			$session = maybe_unserialize( $values[0] ?? '' );
			if ( ! is_array( $session ) ) {
				continue;
			}
			$views += (int) ( $session['count'] ?? 0 );

			foreach ( (array) ( $session['urls'] ?? array() ) as $url_key => $data ) {
				$url = (string) ( $data['url'] ?? '' );
				if ( '' === $url || false !== strpos( $url, 'off_canvas' ) ) {
					continue; // Skip menu toggles.
				}
				$label = trim( (string) ( $data['label'] ?? '' ) );
				if ( '' === $label ) {
					$label = str_replace( 'www.', '', (string) wp_parse_url( $url, PHP_URL_HOST ) );
				}
				if ( ! isset( $links[ $url_key ] ) ) {
					$links[ $url_key ] = array(
						'url'   => $url,
						'label' => $label,
						'count' => 0,
					);
				}
				$links[ $url_key ]['count'] += (int) ( $data['count'] ?? 0 );
			}
		}

		$clicks = 0;
		foreach ( $links as $link ) {
			$clicks += $link['count'];
			$title   = $post->post_title . ' - ' . $link['label'];
			$key     = sanitize_title( $title );
			if ( ! isset( $report['sublinks'][ $key ] ) ) {
				$report['sublinks'][ $key ] = array(
					'title' => $title,
					'count' => 0,
				);
			}
			$report['sublinks'][ $key ]['count'] += $link['count'];
		}

		$lang = '';
		if ( $wpml ) {
			$details = apply_filters(
				'wpml_element_language_details',
				null,
				array(
					'element_id'   => $post_id,
					'element_type' => 'post_' . $post_type,
				)
			);
			$lang = is_object( $details ) && isset( $details->language_code ) ? (string) $details->language_code : '';
		}

		$report['rows'][] = array(
			'id'       => $post_id,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'lang'     => $lang,
			'users'    => count( $users ),
			'sessions' => $sessions,
			'views'    => $views,
			'links'    => $links,
			'clicks'   => $clicks,
		);

		$report['views']  += $views;
		$report['links']  += count( $links );
		$report['clicks'] += $clicks;
	}

	$report['users']    = count( $all_users );
	$report['sessions'] = count( $all_sessions );
	return $report;
}

/**
 * Report page output.
 */
function olctk_traffic_log_render_report() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
	$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$post_type = substr( $page, strlen( OLCTK_TRAFFIC_LOG_REPORT_PREFIX ) );
	$type_obj  = get_post_type_object( $post_type );

	if ( ! $type_obj || ! in_array( $post_type, olctk_traffic_log_post_types(), true ) ) {
		echo '<div class="wrap"><p>' . esc_html__( 'This post type is not being tracked.', 'olc-toolkit' ) . '</p></div>';
		return;
	}

	$dates      = (array) get_option( OLCTK_TRAFFIC_LOG_DATES_OPTION, array() );
	$start_date = isset( $dates[ $post_type ] ) && olctk_traffic_log_valid_date( (string) $dates[ $post_type ] ) ? $dates[ $post_type ] : '';

	$from = olctk_traffic_log_get_date_arg( 'from_date' );
	$from = '' !== $from ? $from : ( '' !== $start_date ? $start_date : wp_date( 'Y-m-01' ) );
	$to   = olctk_traffic_log_get_date_arg( 'to_date' );
	$to   = '' !== $to ? $to : wp_date( 'Y-m-d' );

	$report = olctk_traffic_log_build_report( $post_type, str_replace( '-', '', $from ), str_replace( '-', '', $to ) );
	$date_fmt = 'd/m/Y';
	?>
	<div class="wrap olctk-traffic-log">
		<h1 class="wp-heading-inline">
			<?php
			/* translators: %s: post type name */
			printf( esc_html__( 'Traffic Log (%s)', 'olc-toolkit' ), esc_html( $type_obj->label ) );
			?>
		</h1>
		<hr class="wp-header-end" />

		<div class="olctk-tl-toolbar">
			<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
				<?php if ( 'post' !== $post_type ) : ?>
					<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />
				<?php endif; ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( $page ); ?>" />
				<label><?php esc_html_e( 'From', 'olc-toolkit' ); ?>
					<input type="date" name="from_date" value="<?php echo esc_attr( $from ); ?>" min="<?php echo esc_attr( $start_date ); ?>" />
				</label>
				<label><?php esc_html_e( 'To', 'olc-toolkit' ); ?>
					<input type="date" name="to_date" value="<?php echo esc_attr( $to ); ?>" />
				</label>
				<?php submit_button( __( 'Filter', 'olc-toolkit' ), 'secondary', '', false ); ?>
			</form>
			<button type="button" class="button" id="olctk-tl-copy"><?php esc_html_e( 'Copy to clipboard', 'olc-toolkit' ); ?></button>
		</div>

		<div id="olctk-tl-report">
			<div class="olctk-tl-title">
				<h2>
					<?php
					/* translators: %s: post type name */
					printf( esc_html__( 'Traffic Log: %s', 'olc-toolkit' ), esc_html( strtoupper( str_replace( '_', ' ', $post_type ) ) ) );
					if ( '' !== $start_date ) {
						echo ' ';
						/* translators: %s: date */
						printf( esc_html__( 'tracker started on %s', 'olc-toolkit' ), esc_html( wp_date( $date_fmt, strtotime( $start_date ) ) ) );
					}
					?>
				</h2>
				<input type="search" id="olctk-tl-search" placeholder="<?php esc_attr_e( 'Search... (min 3 characters)', 'olc-toolkit' ); ?>" />
			</div>

			<table id="olctk-tl-table" class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Page Title', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Page Slug', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Language', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Users', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Sessions', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Page Views', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Links', 'olc-toolkit' ); ?></th>
						<th><?php esc_html_e( 'Link Clicks', 'olc-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $report['rows'] as $row ) : ?>
						<?php $empty = 0 === $row['users'] && 0 === $row['sessions'] && 0 === $row['views']; ?>
						<tr class="<?php echo $empty ? 'no-traffics' : 'has-traffics'; ?>">
							<td><?php echo (int) $row['id']; ?></td>
							<td><?php echo esc_html( $row['title'] ); ?></td>
							<td><?php echo esc_html( $row['slug'] ); ?></td>
							<td><?php echo esc_html( $row['lang'] ); ?></td>
							<td><?php echo (int) $row['users']; ?></td>
							<td><?php echo (int) $row['sessions']; ?></td>
							<td><?php echo (int) $row['views']; ?></td>
							<td>
								<?php if ( $row['links'] ) : ?>
									<table class="olctk-tl-links">
										<?php foreach ( $row['links'] as $link ) : ?>
											<tr>
												<th><a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?></a></th>
												<th><?php echo (int) $link['count']; ?></th>
											</tr>
										<?php endforeach; ?>
									</table>
								<?php endif; ?>
							</td>
							<td style="text-align:center"><?php echo $row['clicks'] ? (int) $row['clicks'] : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot id="olctk-tl-default-footer">
					<tr>
						<th></th><th></th>
						<th><?php esc_html_e( 'Unique Total', 'olc-toolkit' ); ?></th>
						<th></th>
						<?php /* translators: %d: count */ ?>
						<th><?php printf( esc_html__( '%d Unique users', 'olc-toolkit' ), (int) $report['users'] ); ?></th>
						<?php /* translators: %d: count */ ?>
						<th><?php printf( esc_html__( '%d Unique sessions', 'olc-toolkit' ), (int) $report['sessions'] ); ?></th>
						<?php /* translators: %d: count */ ?>
						<th><?php printf( esc_html__( '%d Total page views', 'olc-toolkit' ), (int) $report['views'] ); ?></th>
						<?php /* translators: %d: count */ ?>
						<th><?php printf( esc_html__( '%d Links', 'olc-toolkit' ), (int) $report['links'] ); ?></th>
						<?php /* translators: %d: count */ ?>
						<th><?php printf( esc_html__( '%d Clicks', 'olc-toolkit' ), (int) $report['clicks'] ); ?></th>
					</tr>
				</tfoot>
				<tfoot id="olctk-tl-search-footer" style="display:none">
					<tr>
						<th></th><th></th>
						<th><?php esc_html_e( 'Search total', 'olc-toolkit' ); ?></th>
						<th></th><th></th><th></th><th></th><th></th>
						<th style="text-align:center"></th>
					</tr>
				</tfoot>
			</table>

			<?php if ( $report['sublinks'] ) : ?>
				<?php $total_clicks = 0; ?>
				<hr />
				<div style="max-width:500px">
					<h2><?php esc_html_e( 'SUB-LINKS SUMMARY', 'olc-toolkit' ); ?></h2>
					<p>
						<?php
						/* translators: 1: from date, 2: to date */
						printf( esc_html__( 'Sub-link clicks counted from: %1$s - %2$s', 'olc-toolkit' ), esc_html( wp_date( 'd-m-Y', strtotime( $from ) ) ), esc_html( wp_date( 'd-m-Y', strtotime( $to ) ) ) );
						?>
					</p>
					<table class="wp-list-table widefat fixed striped">
						<thead><tr><th><?php esc_html_e( 'Links', 'olc-toolkit' ); ?></th><th><?php esc_html_e( 'Clicks', 'olc-toolkit' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $report['sublinks'] as $sublink ) : ?>
								<?php $total_clicks += $sublink['count']; ?>
								<tr><td><?php echo esc_html( $sublink['title'] ); ?></td><td><?php echo (int) $sublink['count']; ?></td></tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<?php /* translators: %d: count */ ?>
							<tr><th></th><th><?php printf( esc_html__( '%d Clicks', 'olc-toolkit' ), (int) $total_clicks ); ?></th></tr>
						</tfoot>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<style>
			.olctk-tl-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin:12px 0; }
			.olctk-tl-toolbar form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
			.olctk-tl-title { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
			#olctk-tl-search { padding:6px 10px; width:260px; margin-bottom:12px; }
			#olctk-tl-table > thead th { cursor:pointer; text-decoration:underline; }
			#olctk-tl-table.search-active > tbody > tr.no-traffics { display:none !important; }
			.olctk-tl-links th { padding:2px 6px; font-weight:normal; }
		</style>

		<script>
		jQuery(function ($) {
			var $table = $('#olctk-tl-table');
			var $rows  = function () { return $table.children('tbody').children('tr'); };

			// Totals of the visible rows, shown while searching.
			function updateSearchTotals(columns) {
				var totals = {};
				columns.forEach(function (c) { totals[c] = 0; });
				$rows().filter(':visible').each(function () {
					var $cells = $(this).children('td');
					columns.forEach(function (c) {
						var n = parseFloat($cells.eq(c).text().replace(/[^0-9.-]/g, ''));
						if (!isNaN(n)) { totals[c] += n; }
					});
				});
				var $footer = $('#olctk-tl-search-footer th');
				columns.forEach(function (c) { $footer.eq(c).text(totals[c].toFixed(0)); });
			}

			// Search.
			$('#olctk-tl-search').on('input', function () {
				var term = $.trim($(this).val()).toLowerCase();
				if (term.length < 3) {
					$table.removeClass('search-active');
					$rows().show();
					$('#olctk-tl-default-footer').show();
					$('#olctk-tl-search-footer').hide();
					return;
				}
				$table.addClass('search-active');
				$('#olctk-tl-default-footer').hide();
				$('#olctk-tl-search-footer').show();
				$rows().each(function () {
					$(this).toggle($(this).text().toLowerCase().indexOf(term) !== -1);
				});
				updateSearchTotals([4, 5, 6, 8]);
			});

			// Sort by clicking a column heading.
			$table.find('> thead th').on('click', function () {
				var index = $(this).index();
				this.asc = !this.asc;
				var asc = this.asc;
				var sorted = $rows().toArray().sort(function (a, b) {
					var A = $(a).children('td').eq(index).text().trim().toUpperCase();
					var B = $(b).children('td').eq(index).text().trim().toUpperCase();
					var r = (A !== '' && B !== '' && !isNaN(A) && !isNaN(B)) ? A - B : A.localeCompare(B);
					return asc ? r : -r;
				});
				$table.children('tbody').append(sorted);
			});

			// Copy the report as tab-separated text (pastes into a spreadsheet).
			$('#olctk-tl-copy').on('click', function () {
				var lines = [];
				$('#olctk-tl-report').find('h2, tr').each(function () {
					var $el = $(this);
					if (this.tagName === 'H2') {
						lines.push($el.text().replace(/\s+/g, ' ').trim());
					} else {
						lines.push($el.children('th, td').map(function () {
							return $(this).text().replace(/\s+/g, ' ').trim();
						}).get().join('\t'));
					}
				});
				var text = lines.join('\n');
				var done = function () { alert('<?php echo esc_js( __( 'Report copied to clipboard', 'olc-toolkit' ) ); ?>'); };
				if (navigator.clipboard && window.isSecureContext) {
					navigator.clipboard.writeText(text).then(done);
				} else {
					var $tmp = $('<textarea>').val(text).appendTo('body').select();
					document.execCommand('copy');
					$tmp.remove();
					done();
				}
			});
		});
		</script>
	</div>
	<?php
}
