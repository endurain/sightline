<?php
/**
 * Front-end enqueue of the capture script.
 *
 * The JS itself has no WordPress coupling. All WordPress-specific values
 * (endpoint URL) are passed in as window.VantageConfig via
 * wp_localize_script, so the same capture.js lifts into the headless/hosted
 * world unchanged.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Capture {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'exclude_from_optimizers' ), 10, 2 );
		add_filter( 'wp_inline_script_attributes', array( __CLASS__, 'exclude_inline_from_optimizers' ) );
	}

	public static function enqueue() {
		wp_enqueue_script(
			'vantage-capture',
			VANTAGE_URL . 'assets/js/capture.js',
			array(),
			VANTAGE_VERSION,
			true
		);

		// Config injected as window.VantageConfig. No nonce: the page may be
		// served from a full-page cache, so nothing per-visitor goes in here.
		// endpoint is used by
		// the Phase 2 transport; harmless to expose now.
		wp_localize_script(
			'vantage-capture',
			'VantageConfig',
			/**
			 * Filter the capture config (window.VantageConfig).
			 *
			 * @param array $config Config passed to capture.js.
			 */
			apply_filters(
				'vantage_capture_config',
				array(
					'endpoint'             => esc_url_raw( rest_url( 'vantage/v1/collect' ) ),
					'debug'                => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
					'iframe'               => Vantage_Iframe::client_config(),
					/**
					 * Filter extra referrer hosts that are steps inside a visit
					 * (checkout, booking, sign-in), added to capture.js's
					 * built-in list (PayPal, Stripe, Google/Apple/Microsoft sign-in).
					 * Subdomains match. Example: array( 'calendly.com', 'myshopify.com' ).
					 *
					 * @param string[] $hosts Hostnames.
					 */
					'extraIgnoreReferrers' => array_values( array_filter( array_map( 'strval', (array) apply_filters( 'vantage_ignore_referrers', array() ) ) ) ),
				)
			)
		);

		// Iframe form attribution (Phase 3 pass 2): only when the toggle is on.
		if ( Vantage_Iframe::enabled() ) {
			wp_enqueue_script(
				'vantage-iframe-attribution',
				VANTAGE_URL . 'assets/js/iframe-attribution.js',
				array( 'vantage-capture' ),
				VANTAGE_VERSION,
				true
			);
		}
	}

	/**
	 * Attributes that tell page optimizers to leave capture alone (risk #1).
	 * "Delay JavaScript until interaction" (WP Rocket, LiteSpeed, Perfmatters)
	 * would otherwise skip every visitor who leaves without scrolling or
	 * clicking: exactly the non-converters the traffic view is about.
	 *   nowprocket: WP Rocket   data-no-optimize / data-no-defer: LiteSpeed
	 *   data-cfasync="false": Cloudflare Rocket Loader   data-noptimize: Autoptimize
	 *
	 * @return string
	 */
	public static function optimizer_attributes() {
		return 'nowprocket data-no-optimize="1" data-no-defer="1" data-noptimize="1" data-cfasync="false"';
	}

	/**
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function exclude_from_optimizers( $tag, $handle ) {
		if ( in_array( $handle, array( 'vantage-capture', 'vantage-iframe-attribution' ), true ) && false === strpos( $tag, 'nowprocket' ) ) {
			$tag = preg_replace( '/<script\b/', '<script ' . self::optimizer_attributes(), $tag, 1 );
		}
		return $tag;
	}

	/**
	 * Same for the inline window.VantageConfig block.
	 *
	 * @param array $attributes Inline script attributes.
	 * @return array
	 */
	public static function exclude_inline_from_optimizers( $attributes ) {
		if ( isset( $attributes['id'] ) && 0 === strpos( (string) $attributes['id'], 'vantage-' ) ) {
			$attributes['nowprocket']       = true;
			$attributes['data-no-optimize'] = '1';
			$attributes['data-no-defer']    = '1';
			$attributes['data-noptimize']   = '1';
			$attributes['data-cfasync']     = 'false';
		}
		return $attributes;
	}
}
