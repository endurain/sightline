<?php
/**
 * Admin screen: table counts, pepper status, iframe form attribution settings,
 * and the seed / clear demo-data controls.
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sightline_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_sightline_seed', array( __CLASS__, 'handle_seed' ) );
		add_action( 'admin_post_sightline_clear', array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_post_sightline_save_iframe', array( __CLASS__, 'handle_save_iframe' ) );
	}

	public static function menu() {
		add_menu_page(
			'Sightline',
			'Sightline',
			'manage_options',
			'sightline',
			array( __CLASS__, 'render' ),
			'dashicons-chart-line',
			58
		);
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
			$table           = Sightline_Schema::table( $short );
			$out[ $short ]   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" ); // phpcs:ignore WordPress.DB
		}
		return $out;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$counts = self::counts();
		$pepper = Sightline_Pepper::status();

		// Notices from redirects.
		if ( isset( $_GET['seeded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$n = (int) $_GET['seeded']; // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Seeded ' . esc_html( number_format_i18n( $n ) ) . ' visitors.</p></div>';
		}
		if ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Sightline data cleared.</p></div>';
		}
		if ( isset( $_GET['iframe_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>Iframe form attribution settings saved.</p></div>';
		}
		$iframe_on      = Sightline_Iframe::enabled();
		$iframe_domains = Sightline_Iframe::domains();
		?>
		<div class="wrap">
			<h1>Sightline</h1>
			<p><em>Phase 3 — capture, ingestion, forms, and iframe attribution.</em></p>

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
				For CRM forms embedded as an iframe (Pardot, HubSpot share links), Sightline
				can't reach inside the form, but it can add first- and last-touch UTMs to the
				iframe's URL before it loads. Only iframes on the domains below are changed.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sightline_save_iframe">
				<?php wp_nonce_field( 'sightline_save_iframe' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Enable iframe form attribution</th>
						<td>
							<label>
								<input type="checkbox" name="sightline_iframe_enabled" value="1" <?php checked( $iframe_on ); ?>>
								Add attribution to allowlisted form iframes
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sightline_iframe_domains">Form domain allowlist</label></th>
						<td>
							<textarea id="sightline_iframe_domains" name="sightline_iframe_domains" rows="5" cols="40" class="code"><?php echo esc_textarea( implode( "\n", $iframe_domains ) ); ?></textarea>
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
				<li>Create the CRM fields from the User Guide: <code>sl_first_utm_source</code>,
					<code>sl_first_utm_medium</code>, <code>sl_first_utm_campaign</code>,
					<code>sl_last_utm_source</code>, <code>sl_last_utm_medium</code>,
					<code>sl_last_utm_campaign</code>.</li>
				<li>Add them to the embedded form as hidden fields.</li>
				<li><strong>Pardot:</strong> set each hidden field to prefill from the URL
					(the URL parameter must match the field name above). If this isn't set,
					the values arrive in the iframe URL but are silently ignored.</li>
				<li><strong>HubSpot share links:</strong> use the property internal names above;
					HubSpot prefills hidden fields from matching URL parameters.</li>
				<li>Add the form's domain to the allowlist, enable the toggle, then load a page
					with <code>?utm_source=test</code> and confirm the iframe URL carries
					<code>sl_first_utm_source=test</code> (browser devtools &rarr; Elements).</li>
			</ol>

			<h2>Demo data</h2>
			<p style="max-width:640px">
				Generate ~2,000 synthetic visitors with realistic multi-touch
				journeys so later phases have data to build against. No real data
				is used and no email addresses are stored.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="sightline_seed">
				<?php wp_nonce_field( 'sightline_seed' ); ?>
				<button class="button button-primary">Seed demo data</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Clear all Sightline data? This cannot be undone.');">
				<input type="hidden" name="action" value="sightline_clear">
				<?php wp_nonce_field( 'sightline_clear' ); ?>
				<button class="button">Clear data</button>
			</form>
		</div>
		<?php
	}

	public static function handle_seed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'sightline_seed' );

		$created = Sightline_Seeder::seed( 2000 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'sightline',
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
		check_admin_referer( 'sightline_save_iframe' );

		$enabled = ! empty( $_POST['sightline_iframe_enabled'] );
		$raw     = isset( $_POST['sightline_iframe_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sightline_iframe_domains'] ) ) : '';

		update_option( Sightline_Iframe::OPT_ENABLED, $enabled ? 1 : 0 );
		update_option( Sightline_Iframe::OPT_DOMAINS, Sightline_Iframe::sanitize_domains( $raw ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'sightline',
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
		check_admin_referer( 'sightline_clear' );

		Sightline_Seeder::clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'sightline',
					'cleared' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
