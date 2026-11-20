<?php
/**
 * Iframe form attribution (Phase 3 pass 2).
 *
 * Embedded CRM forms (Pardot iframes, HubSpot share links) are cross-origin, so
 * the parent page cannot reach inside them. What it CAN do is control the
 * iframe's src. When enabled, this class:
 *
 *   1. Rewrites allowlisted iframes in the server HTML from `src` to
 *      `data-sl-src` (+ `data-sl-pending`), so the browser does not load them
 *      yet. A <noscript> copy of the original iframe keeps no-JS visitors working.
 *   2. Enqueues iframe-attribution.js, which appends first/last-touch params and
 *      sets the real src. Result: one load, with attribution in the URL.
 *   3. Adds a tiny inline fail-safe that releases any still-pending iframe after
 *      a short delay, so a blocked/failed script can never leave a lead form blank.
 *
 * The server never injects attribution itself: touches live in the visitor's
 * browser, and server-side injection would leak one visitor's data to another
 * through full-page caches.
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sightline_Iframe {

	const OPT_ENABLED = 'sightline_iframe_enabled';
	const OPT_DOMAINS = 'sightline_iframe_domains';

	/** Milliseconds before the inline fail-safe releases pending iframes. */
	const FAILSAFE_MS = 2500;

	/** Suggested allowlist when the option has never been saved. */
	public static function default_domains() {
		return array( 'go.pardot.com', 'pi.pardot.com', 'share.hsforms.com', 'share-eu1.hsforms.com' );
	}

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );
	}

	public static function enabled() {
		return (bool) get_option( self::OPT_ENABLED, false );
	}

	/**
	 * @return string[] Lowercase hostnames.
	 */
	public static function domains() {
		$d = get_option( self::OPT_DOMAINS, null );
		if ( null === $d ) {
			return self::default_domains();
		}
		return is_array( $d ) ? $d : array();
	}

	/**
	 * Normalize free-text allowlist input (one per line or comma separated) into
	 * bare lowercase hostnames. Accepts pasted URLs.
	 *
	 * @param string $raw Input.
	 * @return string[]
	 */
	public static function sanitize_domains( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) $raw ) ) as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( false !== strpos( $item, '/' ) ) {
				$host = wp_parse_url( ( 0 === strpos( $item, '//' ) || preg_match( '#^[a-z]+://#', $item ) ) ? $item : 'https://' . $item, PHP_URL_HOST );
				$item = $host ? $host : '';
			}
			$item = ltrim( $item, '*.' );
			if ( preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $item ) ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Exact host or any subdomain of an allowlisted domain.
	 *
	 * @param string   $host    Hostname.
	 * @param string[] $domains Allowlist.
	 * @return bool
	 */
	public static function host_allowed( $host, array $domains ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $domains as $d ) {
			if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Config handed to the browser (merged into window.SightlineConfig).
	 *
	 * @return array
	 */
	public static function client_config() {
		return array(
			'enabled' => self::enabled(),
			'domains' => self::domains(),
		);
	}

	/**
	 * Buffer front-end HTML so allowlisted iframes can be deferred.
	 */
	public static function start_buffer() {
		if ( ! self::enabled() || ! self::domains() ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'filter_html' ) );
	}

	/**
	 * Output-buffer callback: defer allowlisted iframes.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<iframe' ) ) {
			return $html;
		}
		// Only touch HTML documents.
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'content-type:' ) && false === stripos( $h, 'text/html' ) ) {
				return $html;
			}
		}

		$domains  = self::domains();
		$deferred = 0;

		$out = preg_replace_callback(
			'#<iframe\b[^>]*>.*?</iframe\s*>#is',
			function ( $m ) use ( $domains, &$deferred ) {
				$element = $m[0];
				$open    = substr( $element, 0, strpos( $element, '>' ) + 1 );

				if ( false !== stripos( $open, 'data-sl-src' ) ) {
					return $element; // Already processed.
				}
				if ( ! preg_match( '#\ssrc\s*=\s*(["\'])(.*?)\1#is', $open, $src ) ) {
					return $element;
				}
				$url  = html_entity_decode( $src[2], ENT_QUOTES );
				$host = wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
				if ( ! self::host_allowed( $host, $domains ) ) {
					return $element;
				}

				$new_open = str_replace( $src[0], ' data-sl-src=' . $src[1] . $src[2] . $src[1] . ' data-sl-pending', $open );
				$deferred++;

				// No-JS copy: original iframe, minus its id so the page never has duplicates.
				$fallback = preg_replace( '#\sid\s*=\s*(["\']).*?\1#is', '', $open, 1 ) . substr( $element, strlen( $open ) );
				return $new_open . substr( $element, strlen( $open ) ) . '<noscript>' . $fallback . '</noscript>';
			},
			$html
		);

		if ( null === $out || ! $deferred ) {
			return $html;
		}

		// No JS: hide the deferred (blank) iframes; their <noscript> copies show instead.
		$failsafe = '<noscript><style>iframe[data-sl-pending]{display:none!important}</style></noscript>'
			. '<script>(function(){function r(){var l=document.querySelectorAll("iframe[data-sl-pending]");'
			. 'for(var i=0;i<l.length;i++){l[i].removeAttribute("data-sl-pending");l[i].src=l[i].getAttribute("data-sl-src");}}'
			. 'function t(){setTimeout(r,' . (int) self::FAILSAFE_MS . ');}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",t);}else{t();}})();</script>';

		$pos = strripos( $out, '</body>' );
		return false === $pos ? $out . $failsafe : substr( $out, 0, $pos ) . $failsafe . substr( $out, $pos );
	}
}
