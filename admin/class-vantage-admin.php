<?php
/**
 * Admin screens.
 *
 *   Vantage > Dashboard  (page=vantage)           Phase 5 attribution dashboard;
 *                                                 rendered by assets/js/dashboard.js
 *                                                 from the /reports/* REST API.
 *   Vantage > Settings   (page=vantage-settings)  table counts, pepper status, iframe
 *                                                 form attribution, demo data.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_vantage_seed', array( __CLASS__, 'handle_seed' ) );
		add_action( 'admin_post_vantage_clear', array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_post_vantage_save_iframe', array( __CLASS__, 'handle_save_iframe' ) );
	}

	const DASHBOARD_HOOK = 'toplevel_page_vantage';

	public static function menu() {
		add_menu_page(
			'Vantage',
			'Vantage',
			'manage_options',
			'vantage',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-chart-line',
			58
		);
		add_submenu_page( 'vantage', 'Vantage dashboard', 'Dashboard', 'manage_options', 'vantage', array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( 'vantage', 'Vantage settings', 'Settings', 'manage_options', 'vantage-settings', array( __CLASS__, 'render' ) );
	}

	/**
	 * Dashboard assets, on the dashboard screen ONLY (ECharts is ~1.1 MB).
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( self::DASHBOARD_HOOK !== $hook ) {
			return;
		}
		wp_enqueue_style( 'vantage-dashboard', VANTAGE_URL . 'assets/css/dashboard.css', array(), VANTAGE_VERSION );
		// Bundled locally (WP.org disallows CDN libraries). Apache-2.0; see assets/vendor/echarts/.
		wp_enqueue_script( 'vantage-echarts', VANTAGE_URL . 'assets/vendor/echarts/echarts.min.js', array(), '6.1.0', true );
		wp_enqueue_script( 'vantage-dashboard', VANTAGE_URL . 'assets/js/dashboard.js', array( 'vantage-echarts' ), VANTAGE_VERSION, true );
		wp_localize_script(
			'vantage-dashboard',
			'VantageDashboard',
			array(
				'api'         => esc_url_raw( rest_url( Vantage_REST::NAMESPACE . '/reports/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'today'       => wp_date( 'Y-m-d' ),
				'timezone'    => wp_timezone_string(),
				'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=vantage-settings' ) ),
			)
		);
	}

	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// The whole UI is built by dashboard.js; this is the mount point plus a
		// no-JS fallback.
		?>
		<div class="wrap vt-dash-wrap">
			<h1 class="screen-reader-text">Vantage dashboard</h1>
			<div id="vt-dash" class="vt-dash">
				<noscript><p>The Vantage dashboard needs JavaScript.</p></noscript>
			</div>
		</div>
		<?php
	}

	/**
	 * Row counts for the three tables.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$out = array();
		foreach ( array( 'visitors', 'touches', 'conversions' ) as $short ) {
			$table           = Vantage_Schema::table( $short );
			$out[ $short ]   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" ); // phpcs:ignore WordPress.DB
		}
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$counts = self::counts();
		$pepper = Vantage_Pepper::status();

		// Notices from redirects.
		if ( isset( $_GET['seeded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$n = (int) $_GET['seeded']; // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Seeded ' . esc_html( number_format_i18n( $n ) ) . ' visitors.</p></div>';
		}
		if ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Vantage data cleared.</p></div>';
		}
		if ( isset( $_GET['iframe_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Iframe form attribution settings saved.</p></div>';
		}
		$iframe_on      = Vantage_Iframe::enabled();
		$iframe_domains = Vantage_Iframe::domains();
		?>
		<div class="wrap">
			<h1>Vantage settings</h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vantage' ) ); ?>">&larr; Back to the dashboard</a></p>

			<h2>Database</h2>
			<table class="widefat striped" style="max-width:420px">
				<tbody>
					<tr><td>Visitors</td><td><strong><?php echo esc_html( number_format_i18n( $counts['visitors'] ) ); ?></strong></td></tr>
					<tr><td>Touches</td><td><strong><?php echo esc_html( number_format_i18n( $counts['touches'] ) ); ?></strong></td></tr>
					<tr><td>Conversions</td><td><strong><?php echo esc_html( number_format_i18n( $counts['conversions'] ) ); ?></strong></td></tr>
				</tbody>
			</table>

			<h2>Pepper</h2>
			<p>
				Status: <strong><?php echo esc_html( ucfirst( $pepper ) ); ?></strong>
				<?php if ( 'constant' === $pepper ) : ?>
					&mdash; defined in wp-config (strongest isolation).
				<?php elseif ( 'database' === $pepper ) : ?>
					&mdash; stored in options with autoload off.
				<?php else : ?>
					&mdash; not set yet.
				<?php endif; ?>
			</p>

			<h2>Iframe form attribution</h2>
			<p style="max-width:640px">
				For CRM forms embedded as an iframe (Pardot, HubSpot share links), Vantage
				can't reach inside the form, but it can add first- and last-touch UTMs to the
				iframe's URL before it loads. Only iframes on the domains below are changed.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vantage_save_iframe">
				<?php wp_nonce_field( 'vantage_save_iframe' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable iframe form attribution</th>
						<td>
							<label>
								<input type="checkbox" name="vantage_iframe_enabled" value="1" <?php checked( $iframe_on ); ?>>
								Add attribution to allowlisted form iframes
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vantage_iframe_domains">Form domain allowlist</label></th>
						<td>
							<textarea id="vantage_iframe_domains" name="vantage_iframe_domains" rows="5" cols="40" class="code"><?php echo esc_textarea( implode( "\n", $iframe_domains ) ); ?></textarea>
							<p class="description">
								One domain per line. Subdomains match too. Add your Pardot
								custom tracker domain (for example <code>go.yourcompany.com</code>).
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save iframe settings' ); ?>
			</form>

			<h3>Setup checklist (required for the data to land)</h3>
			<ol style="max-width:640px">
				<li>Create the CRM fields from the User Guide: <code>vt_first_utm_source</code>,
					<code>vt_first_utm_medium</code>, <code>vt_first_utm_campaign</code>,
					<code>vt_last_utm_source</code>, <code>vt_last_utm_medium</code>,
					<code>vt_last_utm_campaign</code>.</li>
				<li>Add them to the embedded form as hidden fields.</li>
				<li><strong>Pardot:</strong> set each hidden field to prefill from the URL
					(the URL parameter must match the field name above). If this isn't set,
					the values arrive in the iframe URL but are silently ignored.</li>
				<li><strong>HubSpot share links:</strong> use the property internal names above;
					HubSpot prefills hidden fields from matching URL parameters.</li>
				<li>Add the form's domain to the allowlist, enable the toggle, then load a page
					with <code>?utm_source=test</code> and confirm the iframe URL carries
					<code>vt_first_utm_source=test</code> (browser devtools &rarr; Elements).</li>
			</ol>

			<h2>Demo data</h2>
			<p style="max-width:640px">
				Generate ~2,000 synthetic visitors with realistic multi-touch
				journeys so later phases have data to build against. No real data
				is used and no email addresses are stored.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="vantage_seed">
				<?php wp_nonce_field( 'vantage_seed' ); ?>
				<button class="button button-primary">Seed demo data</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Clear all Vantage data? This cannot be undone.');">
				<input type="hidden" name="action" value="vantage_clear">
				<?php wp_nonce_field( 'vantage_clear' ); ?>
				<button class="button">Clear data</button>
			</form>
		</div>
		<?php
	}

	public static function handle_seed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'vantage_seed' );

		$created = Vantage_Seeder::seed( 2000 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'vantage-settings',
					'seeded' => $created,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_iframe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'vantage_save_iframe' );

		$enabled = ! empty( $_POST['vantage_iframe_enabled'] );
		$raw     = isset( $_POST['vantage_iframe_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['vantage_iframe_domains'] ) ) : '';

		update_option( Vantage_Iframe::OPT_ENABLED, $enabled ? 1 : 0 );
		update_option( Vantage_Iframe::OPT_DOMAINS, Vantage_Iframe::sanitize_domains( $raw ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'vantage-settings',
					'iframe_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'vantage_clear' );

		Vantage_Seeder::clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'vantage-settings',
					'cleared' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
