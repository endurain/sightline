<?php
/**
 * Default sink: writes touches to the local WordPress database.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_DB_Sink implements Vantage_Sink {

	/** Minutes within which an identical consecutive touch is treated as a dupe. */
	const DEDUPE_MINUTES = 30;

	/**
	 * Upsert the visitor, then insert new (non-duplicate) touches.
	 *
	 * @param string $anon_id Opaque visitor id.
	 * @param array  $touches Sanitized touch arrays.
	 * @return array { visitor_id:int, inserted:int }
	 */
	public function record( $anon_id, array $touches ) {
		global $wpdb;

		$visitors = Vantage_Schema::table( 'visitors' );
		$touches_t = Vantage_Schema::table( 'touches' );
		$now      = current_time( 'mysql', true ); // GMT

		// --- Upsert visitor by anon_id ---
		$visitor_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `$visitors` WHERE anon_id = %s", $anon_id ) // phpcs:ignore WordPress.DB
		);

		if ( $visitor_id ) {
			$wpdb->update( $visitors, array( 'last_seen' => $now ), array( 'id' => $visitor_id ) );
		} else {
			$wpdb->insert(
				$visitors,
				array(
					'anon_id'    => $anon_id,
					'first_seen' => $now,
					'last_seen'  => $now,
				)
			);
			$visitor_id = (int) $wpdb->insert_id;
		}

		if ( ! $visitor_id ) {
			return array(
				'visitor_id' => 0,
				'inserted'   => 0,
			);
		}

		// --- Insert touches, skipping identical consecutive dupes ---
		$inserted    = 0;
		$latest_seen = $now;

		foreach ( $touches as $t ) {
			if ( $this->is_recent_duplicate( $visitor_id, $t ) ) {
				continue;
			}

			$wpdb->insert(
				$touches_t,
				array(
					'visitor_id'   => $visitor_id,
					'utm_source'   => $t['utm_source'],
					'utm_medium'   => $t['utm_medium'],
					'utm_campaign' => $t['utm_campaign'],
					'utm_term'     => $t['utm_term'],
					'utm_content'  => $t['utm_content'],
					'click_ids'    => $t['click_ids'],
					'referrer'     => $t['referrer'],
					'landing_url'  => $t['landing_url'],
					'touch_time'   => $t['time'],
				)
			);
			if ( $wpdb->insert_id ) {
				$inserted++;
				if ( $t['time'] > $latest_seen ) {
					$latest_seen = $t['time'];
				}
			}
		}

		// Keep last_seen at the newest touch time.
		if ( $latest_seen !== $now ) {
			$wpdb->update( $visitors, array( 'last_seen' => $latest_seen ), array( 'id' => $visitor_id ) );
		}

		return array(
			'visitor_id' => $visitor_id,
			'inserted'   => $inserted,
		);
	}

	/**
	 * Record a conversion and attach the hashed identity to the visitor.
	 *
	 * @param string      $anon_id      Opaque visitor id.
	 * @param string|null $hashed_email HMAC of the email, or null.
	 * @param array       $conversion   { form_id, form_type, page_url, time }
	 * @return array { visitor_id:int, conversion_id:int }
	 */
	public function record_conversion( $anon_id, $hashed_email, array $conversion ) {
		global $wpdb;

		$visitors    = Vantage_Schema::table( 'visitors' );
		$conversions = Vantage_Schema::table( 'conversions' );
		$now         = current_time( 'mysql', true );

		// Upsert the visitor by anon_id.
		$visitor_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM `$visitors` WHERE anon_id = %s", $anon_id ) // phpcs:ignore WordPress.DB
		);

		if ( $visitor_id ) {
			$existing_hash = $wpdb->get_var(
				$wpdb->prepare( "SELECT hashed_email FROM `$visitors` WHERE id = %d", $visitor_id ) // phpcs:ignore WordPress.DB
			);
			$update = array( 'last_seen' => $now );
			// First identity wins: only set the hash if the row has none yet.
			if ( $hashed_email && empty( $existing_hash ) ) {
				$update['hashed_email'] = $hashed_email;
			}
			$wpdb->update( $visitors, $update, array( 'id' => $visitor_id ) );
		} else {
			$wpdb->insert(
				$visitors,
				array(
					'anon_id'      => $anon_id,
					'hashed_email' => $hashed_email,
					'first_seen'   => $now,
					'last_seen'    => $now,
				)
			);
			$visitor_id = (int) $wpdb->insert_id;
		}

		if ( ! $visitor_id ) {
			return array(
				'visitor_id'    => 0,
				'conversion_id' => 0,
			);
		}

		$ctime = ! empty( $conversion['time'] ) ? $conversion['time'] : $now;

		$wpdb->insert(
			$conversions,
			array(
				'visitor_id'      => $visitor_id,
				'form_id'         => isset( $conversion['form_id'] ) ? $conversion['form_id'] : null,
				'form_type'       => isset( $conversion['form_type'] ) ? $conversion['form_type'] : null,
				'page_url'        => isset( $conversion['page_url'] ) ? $conversion['page_url'] : null,
				'conversion_time' => $ctime,
			)
		);

		return array(
			'visitor_id'    => $visitor_id,
			'conversion_id' => (int) $wpdb->insert_id,
		);
	}

	/**
	 * Is this touch identical to the visitor's most recent touch, within the
	 * dedupe window? Guards against refreshes double-logging the same hit.
	 *
	 * @param int   $visitor_id Visitor row id.
	 * @param array $t          Sanitized touch.
	 * @return bool
	 */
	private function is_recent_duplicate( $visitor_id, $t ) {
		global $wpdb;
		$touches_t = Vantage_Schema::table( 'touches' );

		$last = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT utm_source, utm_medium, utm_campaign, utm_term, utm_content, click_ids, referrer, touch_time
				 FROM `$touches_t` WHERE visitor_id = %d ORDER BY touch_time DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB
				$visitor_id
			),
			ARRAY_A
		);
		if ( ! $last ) {
			return false;
		}

		$same = $last['utm_source'] === $t['utm_source']
			&& $last['utm_medium'] === $t['utm_medium']
			&& $last['utm_campaign'] === $t['utm_campaign']
			&& $last['utm_term'] === $t['utm_term']
			&& $last['utm_content'] === $t['utm_content']
			&& (string) $last['click_ids'] === (string) $t['click_ids'];

		// Without UTMs or click ids the referring site IS the source: a return
		// from Google search right after a direct visit is a new touch.
		if ( $same && null === $t['utm_source'] && null === $t['utm_medium'] && null === $t['click_ids'] ) {
			$same = self::ref_host( $last['referrer'] ) === self::ref_host( $t['referrer'] );
		}

		if ( ! $same ) {
			return false;
		}

		$last_ts = strtotime( $last['touch_time'] . ' UTC' );
		$this_ts = strtotime( $t['time'] . ' UTC' );
		if ( ! $last_ts || ! $this_ts ) {
			return true; // identical and unparseable time: treat as dupe.
		}
		return ( $this_ts - $last_ts ) < ( self::DEDUPE_MINUTES * MINUTE_IN_SECONDS );
	}

	/**
	 * @param string|null $url Referrer.
	 * @return string Lowercase host without www, or ''.
	 */
	private static function ref_host( $url ) {
		$h = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return preg_replace( '/^www\./', '', $h );
	}
}
