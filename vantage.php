<?php
/**
 * Plugin Name:       Vantage
 * Plugin URI:        https://getcompario.com/vantage
 * Description:       Privacy-first multi-touch attribution. Captures UTM/ad-click touches, stores them first-party, and visualizes the path to conversion. (Phase 4: reporting backend.)
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Zac Sanders
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vantage
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VANTAGE_VERSION', '0.6.0' );
define( 'VANTAGE_DB_VERSION', '1' );
define( 'VANTAGE_FILE', __FILE__ );
define( 'VANTAGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'VANTAGE_URL', plugin_dir_url( __FILE__ ) );

require_once VANTAGE_DIR . 'includes/class-vantage-schema.php';
require_once VANTAGE_DIR . 'includes/class-vantage-pepper.php';
require_once VANTAGE_DIR . 'includes/class-vantage-activator.php';
require_once VANTAGE_DIR . 'includes/class-vantage-seeder.php';
require_once VANTAGE_DIR . 'includes/class-vantage-capture.php';
require_once VANTAGE_DIR . 'includes/interface-vantage-sink.php';
require_once VANTAGE_DIR . 'includes/class-vantage-db-sink.php';
require_once VANTAGE_DIR . 'includes/class-vantage-sinks.php';
require_once VANTAGE_DIR . 'includes/class-vantage-rest.php';
require_once VANTAGE_DIR . 'includes/class-vantage-forms.php';
require_once VANTAGE_DIR . 'includes/class-vantage-iframe.php';
require_once VANTAGE_DIR . 'includes/class-vantage-reports.php';
require_once VANTAGE_DIR . 'includes/class-vantage-rest-admin.php';

Vantage_Capture::init();
Vantage_REST::init();
Vantage_Forms::init();
Vantage_Iframe::init();
Vantage_REST_Admin::init();

if ( is_admin() ) {
	require_once VANTAGE_DIR . 'admin/class-vantage-admin.php';
	Vantage_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once VANTAGE_DIR . 'includes/class-vantage-cli.php';
}

register_activation_hook( __FILE__, array( 'Vantage_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Vantage_Activator', 'deactivate' ) );

/**
 * Run a lightweight schema check on load in case the plugin files were updated
 * without a re-activation (common during development).
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'vantage_db_version' ) !== VANTAGE_DB_VERSION ) {
		Vantage_Schema::install();
	}
} );
