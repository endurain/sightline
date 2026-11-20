<?php
/**
 * Plugin Name:       Sightline
 * Plugin URI:        https://example.com/sightline
 * Description:       Privacy-first multi-touch attribution. Captures UTM/ad-click touches, stores them first-party, and visualizes the path to conversion. (Phase 3: forms, identity resolution, iframe attribution.)
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Zac Sanders
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sightline
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SIGHTLINE_VERSION', '0.5.0' );
define( 'SIGHTLINE_DB_VERSION', '1' );
define( 'SIGHTLINE_FILE', __FILE__ );
define( 'SIGHTLINE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIGHTLINE_URL', plugin_dir_url( __FILE__ ) );

require_once SIGHTLINE_DIR . 'includes/class-sightline-schema.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-pepper.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-activator.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-seeder.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-capture.php';
require_once SIGHTLINE_DIR . 'includes/interface-sightline-sink.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-db-sink.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-sinks.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-rest.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-forms.php';
require_once SIGHTLINE_DIR . 'includes/class-sightline-iframe.php';

Sightline_Capture::init();
Sightline_REST::init();
Sightline_Forms::init();
Sightline_Iframe::init();

if ( is_admin() ) {
	require_once SIGHTLINE_DIR . 'admin/class-sightline-admin.php';
	Sightline_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SIGHTLINE_DIR . 'includes/class-sightline-cli.php';
}

register_activation_hook( __FILE__, array( 'Sightline_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Sightline_Activator', 'deactivate' ) );

/**
 * Run a lightweight schema check on load in case the plugin files were updated
 * without a re-activation (common during development).
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'sightline_db_version' ) !== SIGHTLINE_DB_VERSION ) {
		Sightline_Schema::install();
	}
} );
