<?php
/**
 * Pepper management and email hashing.
 *
 * The pepper is a secret added to an email before hashing, so the stored
 * identifier cannot be reversed by brute force. Raw emails are NEVER stored.
 *
 * Storage priority:
 *   1. VANTAGE_PEPPER constant (wp-config) if the host allows editing it.
 *      Strongest isolation: the secret is not in the database.
 *   2. Otherwise an autoload=no option. Works on locked-down managed hosts
 *      (WP Engine, etc.) that forbid wp-config edits. The honest trade-off is
 *      that the pepper then lives in the same database as the hashes; the
 *      hosted version (Phase 7) removes this by hashing server-side.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Pepper {

	const OPTION = 'vantage_pepper';

	/**
	 * Get the active pepper, generating and storing one on first use.
	 *
	 * @return string
	 */
	public static function get() {
		if ( defined( 'VANTAGE_PEPPER' ) && VANTAGE_PEPPER ) {
			return VANTAGE_PEPPER;
		}

		$pepper = get_option( self::OPTION );
		if ( ! $pepper ) {
			$pepper = self::generate();
			// autoload = 'no' keeps it out of the big autoloaded options blob.
			add_option( self::OPTION, $pepper, '', 'no' );
		}
		return $pepper;
	}

	/**
	 * Ensure a pepper exists (called on activation).
	 */
	public static function ensure() {
		self::get();
	}

	/**
	 * Where the pepper currently lives: 'constant', 'database', or 'missing'.
	 *
	 * @return string
	 */
	public static function status() {
		if ( defined( 'VANTAGE_PEPPER' ) && VANTAGE_PEPPER ) {
			return 'constant';
		}
		return get_option( self::OPTION ) ? 'database' : 'missing';
	}

	/**
	 * Generate a cryptographically strong pepper.
	 *
	 * @return string 64 hex chars.
	 */
	public static function generate() {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return bin2hex( random_bytes( 32 ) );
			} catch ( \Exception $e ) {
				// Fall through to the WP fallback below.
			}
		}
		return wp_generate_password( 64, true, true );
	}

	/**
	 * Hash an email into a stable identifier. The raw email is normalized,
	 * hashed, and must then be discarded by the caller. Never stored.
	 *
	 * @param string $email Raw email address.
	 * @return string|null  64-char hex identifier, or null if email is empty.
	 */
	public static function hash_email( $email ) {
		$normalized = strtolower( trim( (string) $email ) );
		if ( '' === $normalized ) {
			return null;
		}
		return hash_hmac( 'sha256', $normalized, self::get() );
	}
}
