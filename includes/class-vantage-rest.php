<?php
/**
 * REST ingestion endpoint.
 *
 *   POST /wp-json/vantage/v1/collect
 *   body: { "anon_id": "...", "touches": [ {utm_source,...,time}, ... ] }
 *
 * Validates and sanitizes, then hands the batch to the active sink. The
 * controller never touches the database directly (that's the sink's job),
 * which keeps ingestion destination-agnostic.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_REST {

	const NAMESPACE = 'vantage/v1';

	/** Click id keys we accept. */
	private static $click_keys = array(
		'gclid', 'wbraid', 'gbraid', 'fbclid', 'msclkid',
		'ttclid', 'li_fat_id', 'twclid', 'epik', 'sccid',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route(
			self::NAMESPACE,
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'collect' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Public endpoint (anonymous visitors), protected by the REST nonce and a
	 * light per-IP rate limit. Note: full-page caches can serve stale nonces;
	 * a cache-friendly token is a Phase 6 refinement.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'vantage_forbidden', 'Invalid or missing nonce.', array( 'status' => 403 ) );
		}

		// Light rate limit: ~600 requests/min per IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'vt_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n > 600 ) {
			return new WP_Error( 'vantage_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Handle a collect request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function collect( $request ) {
		$params  = $request->get_json_params();
		$anon_id = isset( $params['anon_id'] ) ? self::sanitize_anon_id( $params['anon_id'] ) : '';
		$raw     = isset( $params['touches'] ) && is_array( $params['touches'] ) ? $params['touches'] : array();

		if ( '' === $anon_id || empty( $raw ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'error' => 'anon_id and touches are required.',
				),
				400
			);
		}

		// Cap batch size defensively.
		$raw = array_slice( $raw, 0, 50 );

		$touches = array();
		foreach ( $raw as $t ) {
			if ( is_array( $t ) ) {
				$touches[] = self::sanitize_touch( $t );
			}
		}

		$result = Vantage_Sinks::get()->record( $anon_id, $touches );

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'visitor_id' => (int) $result['visitor_id'],
				'inserted'   => (int) $result['inserted'],
			),
			200
		);
	}

	/**
	 * @param mixed $value Raw anon id.
	 * @return string
	 */
	private static function sanitize_anon_id( $value ) {
		$value = sanitize_text_field( (string) $value );
		// Keep it to id-safe chars and a sane length.
		$value = preg_replace( '/[^A-Za-z0-9\-]/', '', $value );
		return substr( (string) $value, 0, 64 );
	}

	/**
	 * Sanitize one touch into the shape the sink expects.
	 *
	 * @param array $raw Raw touch.
	 * @return array
	 */
	private static function sanitize_touch( $raw ) {
		$field = function ( $key ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || null === $raw[ $key ] ) {
				return null;
			}
			$v = sanitize_text_field( (string) $raw[ $key ] );
			$v = substr( $v, 0, 191 );
			return ( '' === $v ) ? null : $v;
		};

		// Click ids: keep only known keys, sanitized.
		$click = array();
		if ( ! empty( $raw['click_ids'] ) && is_array( $raw['click_ids'] ) ) {
			foreach ( $raw['click_ids'] as $k => $v ) {
				$k = sanitize_key( $k );
				if ( in_array( $k, self::$click_keys, true ) ) {
					$click[ $k ] = substr( sanitize_text_field( (string) $v ), 0, 191 );
				}
			}
		}

		// Time: accept client ISO, else now. Store as GMT datetime.
		$time = current_time( 'mysql', true );
		if ( ! empty( $raw['time'] ) ) {
			$ts = strtotime( (string) $raw['time'] );
			if ( $ts ) {
				$time = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$referrer = isset( $raw['referrer'] ) ? esc_url_raw( trim( (string) $raw['referrer'] ) ) : '';
		$landing  = isset( $raw['landing_url'] ) ? esc_url_raw( trim( (string) $raw['landing_url'] ) ) : '';

		return array(
			'utm_source'   => $field( 'utm_source' ),
			'utm_medium'   => $field( 'utm_medium' ),
			'utm_campaign' => $field( 'utm_campaign' ),
			'utm_term'     => $field( 'utm_term' ),
			'utm_content'  => $field( 'utm_content' ),
			'click_ids'    => empty( $click ) ? null : wp_json_encode( $click ),
			'referrer'     => ( '' === $referrer ) ? null : substr( $referrer, 0, 2000 ),
			'landing_url'  => ( '' === $landing ) ? null : substr( $landing, 0, 2000 ),
			'time'         => $time,
		);
	}
}
