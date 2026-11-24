<?php
/**
 * Activation / deactivation.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Activator {

	/**
	 * On activation: build the tables and make sure a pepper exists.
	 */
	public static function activate() {
		Vantage_Schema::install();
		Vantage_Pepper::ensure();
	}

	/**
	 * On deactivation: intentionally leave data and schema intact.
	 * Teardown happens only on uninstall (Phase 6).
	 */
	public static function deactivate() {
		// No-op by design.
	}
}
