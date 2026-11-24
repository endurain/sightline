<?php
/**
 * Database schema for Vantage.
 *
 * Tables (prefixed with the site's $wpdb->prefix + "vt_"):
 *   vt_visitors     one row per visitor (anon_id, optional hashed_email)
 *   vt_touches      every captured touch, linked to a visitor
 *   vt_conversions  a conversion event (form submit), linked to a visitor
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Schema {

	/**
	 * Fully-qualified table name for a given short name.
	 *
	 * @param string $name One of: visitors, touches, conversions.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'vt_' . $name;
	}

	/**
	 * Create or update the tables. Safe to run repeatedly (dbDelta).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$visitors        = self::table( 'visitors' );
		$touches         = self::table( 'touches' );
		$conversions     = self::table( 'conversions' );

		$statements = array();

		// A visitor. hashed_email is NULL until they convert (submit a form).
		$statements[] = "CREATE TABLE $visitors (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			anon_id char(36) NOT NULL,
			hashed_email char(64) DEFAULT NULL,
			first_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY anon_id (anon_id),
			KEY hashed_email (hashed_email)
		) $charset_collate;";

		// A single captured touch. click_ids is a JSON blob (gclid, fbclid, ...).
		$statements[] = "CREATE TABLE $touches (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned NOT NULL,
			utm_source varchar(191) DEFAULT NULL,
			utm_medium varchar(191) DEFAULT NULL,
			utm_campaign varchar(191) DEFAULT NULL,
			utm_term varchar(191) DEFAULT NULL,
			utm_content varchar(191) DEFAULT NULL,
			click_ids text DEFAULT NULL,
			referrer text DEFAULT NULL,
			landing_url text DEFAULT NULL,
			touch_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY visitor_id (visitor_id),
			KEY touch_time (touch_time),
			KEY utm_source (utm_source)
		) $charset_collate;";

		// A conversion event tied to a visitor.
		$statements[] = "CREATE TABLE $conversions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			visitor_id bigint(20) unsigned NOT NULL,
			form_id varchar(191) DEFAULT NULL,
			form_type varchar(64) DEFAULT NULL,
			page_url text DEFAULT NULL,
			conversion_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY visitor_id (visitor_id),
			KEY conversion_time (conversion_time)
		) $charset_collate;";

		foreach ( $statements as $sql ) {
			dbDelta( $sql );
		}

		update_option( 'vantage_db_version', VANTAGE_DB_VERSION );
	}
}
