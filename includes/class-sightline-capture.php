<?php
/**
 * Front-end enqueue of the capture script.
 *
 * The JS itself has no WordPress coupling. All WordPress-specific values
 * (endpoint URL, nonce) are passed in as window.SightlineConfig via
 * wp_localize_script, so the same capture.js lifts into the headless/hosted
 * world unchanged.
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sightline_Capture {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		wp_enqueue_script(
			'sightline-capture',
			SIGHTLINE_URL . 'assets/js/capture.js',
			array(),
			SIGHTLINE_VERSION,
			true
		);

		// Config injected as window.SightlineConfig. endpoint/nonce are used by
		// the Phase 2 transport; harmless to expose now.
		wp_localize_script(
			'sightline-capture',
			'SightlineConfig',
			array(
				'endpoint' => esc_url_raw( rest_url( 'sightline/v1/collect' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'debug'    => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			)
		);
	}
}
