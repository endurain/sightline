<?php
/**
 * Admin screen for Phase 0: shows table counts, pepper status, and the
 * seed / clear demo-data controls.
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
		?>
		<div class="wrap">
			<h1>Sightline</h1>
			<p><em>Phase 0 — scaffold, schema, and demo data.</em></p>

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
