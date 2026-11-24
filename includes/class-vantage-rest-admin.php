<?php
/**
 * Admin-only reporting API (Phase 4). The Phase 5 dashboard calls these.
 *
 *   GET /wp-json/vantage/v1/reports/summary
 *   GET /wp-json/vantage/v1/reports/conversions   (page, per_page)
 *   GET /wp-json/vantage/v1/reports/attribution
 *   GET /wp-json/vantage/v1/reports/sankey        (max_steps, include_nonconverters)
 *   GET /wp-json/vantage/v1/reports/traffic
 *   GET /wp-json/vantage/v1/reports/dropoff       (quiet_days)
 *
 * Common args: start, end (YYYY-MM-DD, site timezone; default last 90 days),
 * group_by (source|campaign), campaign (exact utm_campaign filter).
 *
 * Requires manage_options. Uses standard WP REST cookie auth (X-WP-Nonce).
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_REST_Admin {

	const REPORTS = array( 'summary', 'conversions', 'attribution', 'sankey', 'traffic', 'dropoff' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_rest_route(
			Vantage_REST::NAMESPACE,
			'/reports/(?P<report>' . implode( '|', self::REPORTS ) . ')',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => self::args(),
			)
		);
	}

	public static function permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'vantage_forbidden', 'You do not have permission to view Vantage reports.', array( 'status' => rest_authorization_required_code() ) );
	}

	private static function args() {
		$date = array(
			'type'              => 'string',
			'validate_callback' => function ( $v ) {
				return '' === $v || (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
			},
		);
		return array(
			'start'                 => $date,
			'end'                   => $date,
			'group_by'              => array(
				'type'    => 'string',
				'enum'    => array( 'source', 'campaign' ),
				'default' => 'source',
			),
			'campaign'              => array( 'type' => 'string' ),
			'max_steps'             => array( 'type' => 'integer', 'minimum' => 2, 'maximum' => 8 ),
			'quiet_days'            => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365 ),
			'page'                  => array( 'type' => 'integer', 'minimum' => 1 ),
			'per_page'              => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ),
			'include_nonconverters' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$report = $request['report'];
		$args   = array();
		foreach ( array_keys( self::args() ) as $k ) {
			if ( null !== $request->get_param( $k ) ) {
				$args[ $k ] = $request->get_param( $k );
			}
		}
		$data     = call_user_func( array( 'Vantage_Reports', $report ), $args );
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
