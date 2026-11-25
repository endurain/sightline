<?php
/**
 * Bot / preview / automation detection for ingestion (risk #4).
 *
 * Most link unfurlers (Slack, iMessage, WhatsApp, Facebook) fetch HTML and never
 * run JavaScript, so they can't create touches at all: capture only happens in
 * capture.js. This filter is for clients that DO run JS (search-engine
 * renderers, SEO and uptime tools, headless scrapers, ad verification) and for
 * anything posting to /collect directly with an honest bot user agent.
 *
 * A user agent can be faked, so this stops honest and lazy bots, not a
 * determined forger (see the /collect limits in docs).
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Bots {

	/**
	 * Case-insensitive fragments that mark a non-human client. Chosen so that
	 * in-app browsers (Facebook FBAN/FBAV, Instagram, LinkedInApp, Twitter for
	 * iPhone, Slack's own desktop app) do NOT match: those are real people.
	 *
	 * @return string[]
	 */
	public static function patterns() {
		$p = array(
			// Generic markers.
			'bot', 'crawl', 'spider', 'slurp', 'scrap', 'fetcher', 'monitor', 'preview',
			// Link unfurlers / social previews.
			'facebookexternalhit', 'facebot', 'meta-externalagent', 'embedly', 'quora link',
			'outbrain', 'pinterestbot', 'vkshare', 'w3c_validator', 'whatsapp', 'skypeuripreview',
			'iframely', 'nuzzel', 'bitlybot', 'redditbot', 'mastodon',
			// Search / AI renderers that execute JS.
			'googleother', 'google-inspectiontool', 'mediapartners-google', 'adsbot', 'apis-google',
			'feedfetcher', 'bingpreview', 'yandex', 'baiduspider', 'bytespider', 'petalbot',
			'gptbot', 'chatgpt-user', 'oai-searchbot', 'ccbot', 'claudebot', 'claude-web',
			'anthropic-ai', 'perplexity', 'amazonbot', 'applebot', 'duckduckbot', 'ia_archiver',
			// SEO / performance / uptime tools.
			'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom', 'uptimerobot', 'statuscake',
			'site24x7', 'datadog', 'newrelic', 'ahrefs', 'semrush', 'mj12', 'dotbot', 'screaming frog',
			'sitebulb', 'rogerbot', 'seokicks', 'blexbot',
			// Automation and HTTP libraries.
			'headlesschrome', 'phantomjs', 'puppeteer', 'playwright', 'selenium',
			'python-requests', 'python-urllib', 'aiohttp', 'httpx', 'curl/', 'wget/',
			'go-http-client', 'okhttp', 'java/', 'apache-httpclient', 'libwww', 'httpclient',
			'axios/', 'node-fetch', 'undici', 'guzzlehttp', 'ruby', 'perl', 'postmanruntime',
			'insomnia', 'wordpress/', 'jetpack',
		);
		/**
		 * Filter the bot user-agent fragments.
		 *
		 * @param string[] $p Lowercase substrings.
		 */
		return (array) apply_filters( 'vantage_bot_patterns', $p );
	}

	/**
	 * @param string|null $ua User agent.
	 * @return bool True when the client should not be counted.
	 */
	public static function is_bot( $ua ) {
		$ua = strtolower( trim( (string) $ua ) );
		if ( '' === $ua || strlen( $ua ) < 20 ) {
			return true; // Real browsers always send a long UA.
		}
		foreach ( self::patterns() as $needle ) {
			if ( '' !== $needle && false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Speculative / prefetch requests (browser prefetch of a link the user
	 * never opened). Browsers send one of these headers on such requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function is_prefetch( $request ) {
		foreach ( array( 'sec_purpose', 'purpose', 'x_purpose', 'x_moz' ) as $h ) {
			$v = strtolower( (string) $request->get_header( $h ) );
			if ( false !== strpos( $v, 'prefetch' ) || false !== strpos( $v, 'prerender' ) || 'preview' === $v ) {
				return true;
			}
		}
		return false;
	}
}
