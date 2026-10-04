<?php
/**
 * Traffic Log: tracking script on the front end, plus the admin-ajax fallback.
 *
 * Counts a page view when a visitor opens a post of a tracked type, and a
 * link click when they click a link inside an element with the class
 * "track_clicks". Administrators and editors are not counted.
 *
 * @package OLC_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Minutes of inactivity after which a visitor's next page view starts a new session.
 *
 * @return int
 */
function olctk_traffic_log_session_minutes(): int {
	return max( 1, (int) apply_filters( 'olctk_traffic_log_session_minutes', 30 ) );
}

/**
 * Whether the old child-theme version of Traffic Log is still active.
 * While it is, this add-on doesn't track, so visits aren't counted twice.
 *
 * @return bool
 */
function olctk_traffic_log_theme_copy_active(): bool {
	return function_exists( 'olc_traffic_log_footer' ) || function_exists( 'olc_traffic_log_process' );
}

/**
 * Whether the page is being shown inside the Elementor editor/preview or the Customizer.
 *
 * Same checks as the child theme's is_elementor_editor(), but safe when
 * Elementor isn't active.
 *
 * @return bool
 */
function olctk_traffic_log_is_builder_preview(): bool {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only checks.
	if (
		( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] )
		|| isset( $_GET['elementor-preview'] )
		|| is_customize_preview()
	) {
		return true;
	}
	// phpcs:enable

	if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance ) ) {
		return false;
	}
	try {
		$elementor = \Elementor\Plugin::$instance;
		if ( isset( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
			return true;
		}
		if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
			return true;
		}
	} catch ( \Throwable $e ) {
		return false;
	}
	return false;
}

/* -------------------------------------------------------------------------
 * Front-end script.
 * ---------------------------------------------------------------------- */
add_action(
	'wp_footer',
	function () {
		if ( is_admin() || ! is_singular() || current_user_can( 'edit_others_posts' ) || olctk_traffic_log_is_builder_preview() ) {
			return;
		}
		if ( olctk_traffic_log_theme_copy_active() ) {
			return;
		}

		$post = get_post( get_queried_object_id() );
		if ( ! $post || ! in_array( $post->post_type, olctk_traffic_log_post_types(), true ) ) {
			return;
		}

		$config = array(
			'endpoint' => plugins_url( 'endpoint.php', __FILE__ ),
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'postId'   => (int) $post->ID,
			'token'    => olctk_traffic_log_generate_token(),
			'tick'     => (string) olctk_traffic_log_current_tick(),
			'timeout'  => olctk_traffic_log_session_minutes() * 60000,
		);
		?>
		<script id="olctk-traffic-log">
		(function () {
			var cfg = <?php echo wp_json_encode( $config ); ?>;

			function newKey() {
				var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', out = '';
				for (var i = 0; i < 32; i++) { out += chars[Math.floor(Math.random() * chars.length)]; }
				return out;
			}

			// Visitor and session ids, kept in localStorage (same keys as before, so returning visitors keep their id).
			var fallbackIds = { s: newKey(), u: newKey() };
			function ids() {
				try {
					var now = Date.now();
					var s = localStorage.getItem('session_id');
					var t = parseInt(localStorage.getItem('session_time'), 10) || 0;
					var u = localStorage.getItem('user_id');
					if (!s || !t || t + cfg.timeout < now) { s = newKey(); }
					if (!u) { u = newKey(); localStorage.setItem('user_id', u); }
					localStorage.setItem('session_id', s);
					localStorage.setItem('session_time', String(now));
					return { s: s, u: u };
				} catch (e) {
					return fallbackIds; // Storage blocked: one session per page.
				}
			}

			function send(url, label) {
				var id = ids();
				var body = new URLSearchParams({
					post_id: cfg.postId, session_id: id.s, user_id: id.u,
					url: url, label: label, token: cfg.token, tick: cfg.tick
				});
				fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'omit' })
					.then(function (r) { if (!r.ok && r.status !== 403) { throw new Error(r.status); } })
					.catch(function () {
						// Endpoint unreachable (e.g. host blocks it): use admin-ajax instead.
						body.set('action', 'olctk_traffic_log_add');
						fetch(cfg.ajax, { method: 'POST', body: body, keepalive: true }).catch(function () {});
					});
			}

			// Page view.
			send('', '');

			// Link clicks inside any element with the class "track_clicks".
			document.addEventListener('click', function (e) {
				var a = e.target && e.target.closest ? e.target.closest('.track_clicks a[href]') : null;
				if (a) { send(a.href, (a.textContent || '').trim()); }
			}, true);
		})();
		</script>
		<?php
	},
	100
);

/* -------------------------------------------------------------------------
 * admin-ajax fallback.
 * ---------------------------------------------------------------------- */
function olctk_traffic_log_ajax_add() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- signed token checked instead.
	$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
	$tick  = isset( $_POST['tick'] ) ? sanitize_text_field( wp_unslash( $_POST['tick'] ) ) : '';

	if ( ! olctk_traffic_log_verify_token( $token, $tick ) ) {
		wp_send_json( array( 'type' => 'invalid' ), 403 );
	}

	wp_send_json(
		olctk_traffic_log_process(
			isset( $_POST['post_id'] ) ? wp_unslash( $_POST['post_id'] ) : '',
			isset( $_POST['session_id'] ) ? wp_unslash( $_POST['session_id'] ) : '',
			isset( $_POST['user_id'] ) ? wp_unslash( $_POST['user_id'] ) : '',
			isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
			isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : ''
		)
	);
	// phpcs:enable
}
add_action( 'wp_ajax_olctk_traffic_log_add', 'olctk_traffic_log_ajax_add' );
add_action( 'wp_ajax_nopriv_olctk_traffic_log_add', 'olctk_traffic_log_ajax_add' );
