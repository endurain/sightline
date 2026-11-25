<?php
/**
 * REST ingestion endpoint.
 *
 *   POST /wp-json/vantage/v1/collect
 *   body: { "anon_id": "<uuid v4>", "touches": [ {utm_source,...,time}, ... ] }
 *
 * Validates and sanitizes, then hands the batch to the active sink. The
 * controller never touches the database directly (that's the sink's job),
 * which keeps ingestion destination-agnostic.
 *
 * Cache-safe (risk #1): no REST nonce. A full-page cache serves one HTML copy
 * to every anonymous visitor, so anything per-visitor baked into the page
 * (like a nonce) goes stale and silently drops their touches. Nothing here
 * depends on the page HTML being fresh.
 *
 * What the endpoint checks instead (risk #6). It stops cross-site injection,
 * honest bots, malformed or out-of-range data and floods from one visitor. It
 * can NOT stop someone scripting requests that imitate a real browser on the
 * site: anything a browser can send, a script can too. That limit is
 * documented in the user guide.
 *   - Origin: the request's Origin (or Referer) must be this site.
 *   - Bots and prefetch: dropped quietly (200, nothing stored).
 *   - anon_id: must be a UUID v4, as capture.js generates.
 *   - Touches: landing page must be on this site; time clamped to the last
 *     30 days and never in the future; at most 20 per request.
 *   - Volume: per-IP rate limit, plus a per-visitor daily touch cap.
 *
 * Identity on Safari (risk #2): the response re-sets the vt_anon cookie from
 * the server. Safari's ITP caps cookies created by JavaScript at 7 days; a
 * cookie set in an HTTP response from the site's own server is not capped
 * that way, so the visitor keeps the same id for the full cookie lifetime.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_REST {

	const NAMESPACE = 'vantage/v1';

	const COOKIE          = 'vt_anon';
	const COOKIE_DAYS     = 365;
	const MAX_PER_REQUEST = 20;
	const MAX_PER_DAY     = 100;   // touches per visitor per day
	const MAX_AGE_DAYS    = 30;    // older client times are treated as "now"
	const RATE_PER_MINUTE = 600;   // requests per IP per minute

	const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/** Click id keys we accept. */
	private static $click_keys = array(
		'gclid', 'wbraid', 'gbraid', 'dclid', 'fbclid', 'msclkid',
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
	 * Hosts that count as "this site": home and site URL hosts, with and
	 * without www. Filterable for multi-domain or headless front ends.
	 *
	 * @return string[]
	 */
	public static function allowed_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $h ) {
				continue;
			}
			$bare    = preg_replace( '/^www\./', '', $h );
			$hosts[] = $bare;
			$hosts[] = 'www.' . $bare;
		}
		/**
		 * Filter the hosts allowed to send touches and appear as landing pages.
		 *
		 * @param string[] $hosts Lowercase hostnames.
		 */
		return array_values( array_unique( (array) apply_filters( 'vantage_allowed_hosts', $hosts ) ) );
	}

	/**
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_own_url( $url ) {
		$h = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return '' !== $h && in_array( $h, self::allowed_hosts(), true );
	}

	/**
	 * Public endpoint (anonymous visitors). No nonce (see header); the request
	 * must come from a page on this site, within the per-IP rate limit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin || 'null' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}
		if ( ! self::is_own_url( $origin ) ) {
			return new WP_Error( 'vantage_bad_origin', 'Requests must come from this site.', array( 'status' => 403 ) );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'vt_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_PER_MINUTE ) {
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
		// Bots and speculative loads: answer "ok" so the client stops retrying,
		// but store nothing.
		if ( Vantage_Bots::is_bot( $request->get_header( 'user_agent' ) ) || Vantage_Bots::is_prefetch( $request ) ) {
			return self::response( array( 'ok' => true, 'ignored' => 'bot' ), 200 );
		}

		$params  = $request->get_json_params();
		$anon_id = isset( $params['anon_id'] ) ? strtolower( trim( (string) $params['anon_id'] ) ) : '';
		$raw     = isset( $params['touches'] ) && is_array( $params['touches'] ) ? array_values( $params['touches'] ) : array();

		if ( ! preg_match( self::UUID_RE, $anon_id ) ) {
			return self::response( array( 'ok' => false, 'error' => 'anon_id must be a UUID v4.' ), 400 );
		}
		if ( empty( $raw ) ) {
			return self::response( array( 'ok' => false, 'error' => 'touches are required.' ), 400 );
		}

		$touches  = array();
		$rejected = 0;
		foreach ( array_slice( $raw, 0, self::MAX_PER_REQUEST ) as $t ) {
			$clean = is_array( $t ) ? self::sanitize_touch( $t ) : null;
			if ( null === $clean ) {
				$rejected++;
			} else {
				$touches[] = $clean;
			}
		}
		$rejected += max( 0, count( $raw ) - self::MAX_PER_REQUEST );

		// Per-visitor daily cap (stops one id being stuffed with fake history).
		$cap_key = 'vt_cap_' . md5( $anon_id . gmdate( 'Ymd' ) );
		$used    = (int) get_transient( $cap_key );
		$room    = max( 0, self::MAX_PER_DAY - $used );
		if ( count( $touches ) > $room ) {
			$rejected += count( $touches ) - $room;
			$touches   = array_slice( $touches, 0, $room );
		}

		$result = array( 'visitor_id' => 0, 'inserted' => 0 );
		if ( $touches ) {
			set_transient( $cap_key, $used + count( $touches ), DAY_IN_SECONDS );
			$result = Vantage_Sinks::get()->record( $anon_id, $touches );
		}

		self::set_cookie( $anon_id );

		return self::response(
			array(
				'ok'         => true,
				'visitor_id' => (int) $result['visitor_id'],
				'inserted'   => (int) $result['inserted'],
				'rejected'   => $rejected,
			),
			200
		);
	}

	private static function response( array $data, $status ) {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/**
	 * Re-set the visitor id cookie from the server (risk #2). Same name, path
	 * and host as the cookie capture.js writes, so it replaces it in place.
	 * Not HttpOnly: capture.js must read it.
	 *
	 * @param string $anon_id Validated id.
	 */
	private static function set_cookie( $anon_id ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$anon_id,
			array(
				'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Sanitize one touch into the shape the sink expects, or null to drop it.
	 *
	 * @param array $raw Raw touch.
	 * @return array|null
	 */
	private static function sanitize_touch( $raw ) {
		$field = function ( $key ) use ( $raw ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				return null;
			}
			$v = sanitize_text_field( (string) $raw[ $key ] );
			$v = substr( $v, 0, 191 );
			return ( '' === $v ) ? null : $v;
		};

		// The landing page must be on this site: capture.js always sends
		// location.href, so anything else was not sent by our script.
		$landing = isset( $raw['landing_url'] ) && is_scalar( $raw['landing_url'] ) ? esc_url_raw( trim( (string) $raw['landing_url'] ) ) : '';
		if ( '' === $landing || ! self::is_own_url( $landing ) ) {
			return null;
		}

		// Click ids: keep only known keys, sanitized.
		$click = array();
		if ( ! empty( $raw['click_ids'] ) && is_array( $raw['click_ids'] ) ) {
			foreach ( $raw['click_ids'] as $k => $v ) {
				$k = sanitize_key( $k );
				if ( in_array( $k, self::$click_keys, true ) && is_scalar( $v ) ) {
					$v = substr( sanitize_text_field( (string) $v ), 0, 191 );
					if ( '' !== $v ) {
						$click[ $k ] = $v;
					}
				}
			}
		}

		// Time: the client's clock orders a backlog correctly, but it is not
		// trusted outside [now - 30 days, now]: future times become now, and so
		// do implausibly old ones.
		$now  = time();
		$ts   = $now;
		if ( ! empty( $raw['time'] ) && is_scalar( $raw['time'] ) ) {
			$parsed = strtotime( (string) $raw['time'] );
			if ( $parsed && $parsed <= $now && $parsed >= $now - self::MAX_AGE_DAYS * DAY_IN_SECONDS ) {
				$ts = $parsed;
			}
		}

		$referrer = isset( $raw['referrer'] ) && is_scalar( $raw['referrer'] ) ? esc_url_raw( trim( (string) $raw['referrer'] ) ) : '';

		return array(
			'utm_source'   => $field( 'utm_source' ),
			'utm_medium'   => $field( 'utm_medium' ),
			'utm_campaign' => $field( 'utm_campaign' ),
			'utm_term'     => $field( 'utm_term' ),
			'utm_content'  => $field( 'utm_content' ),
			'click_ids'    => empty( $click ) ? null : wp_json_encode( $click ),
			'referrer'     => ( '' === $referrer ) ? null : substr( $referrer, 0, 2000 ),
			'landing_url'  => substr( $landing, 0, 2000 ),
			'time'         => gmdate( 'Y-m-d H:i:s', $ts ),
		);
	}
}
