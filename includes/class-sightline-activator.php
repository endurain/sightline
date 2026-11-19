<?php
/**
 * Activation / deactivation.
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sightline_Activator {

	/**
	 * On activation: build the tables and make sure a pepper exists.
	 */
	public static function activate() {
		Sightline_Schema::install();
		Sightline_Pepper::ensure();
	}

	/**
	 * On deactivation: intentionally leave data and schema intact.
	 * Teardown happens only on uninstall (Phase 6).
	 */
	public static function deactivate() {
		// No-op by design.
	}
}
