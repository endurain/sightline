<?php
/**
 * Synthetic data seeder.
 *
 * Generates realistic, messy multi-touch journeys so later phases (reporting,
 * Sankey) have something to build against without a live site. No real data.
 * "Converters" get a hashed_email derived from a fake address; the fake address
 * is never stored, exactly like production.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Seeder {

	/**
	 * Campaign channels used to build touches.
	 *
	 * @return array
	 */
	public static function channels() {
		return array(
			array(
				'utm_source' => 'google',
				'utm_medium' => 'cpc',
				'campaigns'  => array( 'brand', 'competitor', 'nonbrand-generic' ),
				'click'      => 'gclid',
			),
			array(
				'utm_source' => 'bing',
				'utm_medium' => 'cpc',
				'campaigns'  => array( 'brand', 'nonbrand' ),
				'click'      => 'msclkid',
			),
			array(
				'utm_source' => 'facebook',
				'utm_medium' => 'paid_social',
				'campaigns'  => array( 'retargeting', 'lookalike', 'prospecting' ),
				'click'      => 'fbclid',
			),
			array(
				'utm_source' => 'linkedin',
				'utm_medium' => 'paid_social',
				'campaigns'  => array( 'abm-q4', 'webinar-promo' ),
				'click'      => null,
			),
			array(
				'utm_source' => 'newsletter',
				'utm_medium' => 'email',
				'campaigns'  => array( 'monthly-digest', 'product-update' ),
				'click'      => null,
			),
			array(
				'utm_source' => 'partner',
				'utm_medium' => 'referral',
				'campaigns'  => array( 'co-marketing' ),
				'click'      => null,
			),
		);
	}

	/**
	 * Seed N visitors with journeys. Returns the number of visitors created.
	 *
	 * @param int $num_visitors Default 2000.
	 * @return int
	 */
	public static function seed( $num_visitors = 2000 ) {
		global $wpdb;

		$visitors_t    = Vantage_Schema::table( 'visitors' );
		$touches_t     = Vantage_Schema::table( 'touches' );
		$conversions_t = Vantage_Schema::table( 'conversions' );

		$channels = self::channels();
		// Real UTC epoch: every stored time is written with gmdate() as UTC.
		$now      = time();
		$created  = 0;

		for ( $i = 0; $i < $num_visitors; $i++ ) {

			$num_touches    = self::weighted_touch_count();
			$start_days_ago = wp_rand( 1, 90 );
			$first_seen_ts  = $now - ( $start_days_ago * DAY_IN_SECONDS ) - wp_rand( 0, DAY_IN_SECONDS );

			// Build touch timestamps, each a bit later than the last. If the
			// journey would run past now, scale the gaps down to fit so no touch
			// (or conversion) is ever in the future.
			$gaps = array();
			for ( $t = 1; $t < $num_touches; $t++ ) {
				$gaps[] = wp_rand( HOUR_IN_SECONDS, 10 * DAY_IN_SECONDS );
			}
			$room = $now - $first_seen_ts - wp_rand( 0, HOUR_IN_SECONDS );
			$span = array_sum( $gaps );
			if ( $span > $room ) {
				$scale = $room / $span;
				foreach ( $gaps as &$g ) {
					$g = max( MINUTE_IN_SECONDS, (int) floor( $g * $scale ) );
				}
				unset( $g );
			}
			$touch_times = array( $first_seen_ts );
			foreach ( $gaps as $g ) {
				$touch_times[] = end( $touch_times ) + $g;
			}
			$last_seen_ts = end( $touch_times );

			// About 22% convert.
			$converts     = ( wp_rand( 1, 100 ) <= 22 );
			$hashed_email = null;
			if ( $converts ) {
				// Fake email, hashed then discarded, same as production.
				$fake_email   = 'seed' . $i . '_' . wp_rand( 1000, 9999 ) . '@example.com';
				$hashed_email = Vantage_Pepper::hash_email( $fake_email );
				unset( $fake_email );
			}

			$wpdb->insert(
				$visitors_t,
				array(
					'anon_id'      => self::uuid(),
					'hashed_email' => $hashed_email,
					'first_seen'   => gmdate( 'Y-m-d H:i:s', $first_seen_ts ),
					'last_seen'    => gmdate( 'Y-m-d H:i:s', $last_seen_ts ),
				)
			);
			$visitor_id = (int) $wpdb->insert_id;
			if ( ! $visitor_id ) {
				continue;
			}

			foreach ( $touch_times as $idx => $tt ) {
				$touch = self::make_touch( $channels, $idx );
				$wpdb->insert(
					$touches_t,
					array(
						'visitor_id'   => $visitor_id,
						'utm_source'   => $touch['utm_source'],
						'utm_medium'   => $touch['utm_medium'],
						'utm_campaign' => $touch['utm_campaign'],
						'utm_term'     => $touch['utm_term'],
						'utm_content'  => $touch['utm_content'],
						'click_ids'    => $touch['click_ids'],
						'referrer'     => $touch['referrer'],
						'landing_url'  => $touch['landing_url'],
						'touch_time'   => gmdate( 'Y-m-d H:i:s', $tt ),
					)
				);
			}

			if ( $converts ) {
				$wpdb->insert(
					$conversions_t,
					array(
						'visitor_id'      => $visitor_id,
						'form_id'         => 'demo-form-' . wp_rand( 1, 3 ),
						'form_type'       => 'seed',
						'page_url'        => home_url( '/contact/' ),
						'conversion_time' => gmdate( 'Y-m-d H:i:s', $last_seen_ts ),
					)
				);
			}

			$created++;
		}

		update_option( 'vantage_seeded', $created );
		return $created;
	}

	/**
	 * Remove all Vantage data (truncates the three tables).
	 */
	public static function clear() {
		global $wpdb;
		foreach ( array( 'touches', 'conversions', 'visitors' ) as $short ) {
			$table = Vantage_Schema::table( $short );
			// Table name is built from a fixed whitelist above, safe to interpolate.
			$wpdb->query( "TRUNCATE TABLE `$table`" ); // phpcs:ignore WordPress.DB
		}
		delete_option( 'vantage_seeded' );
	}

	/**
	 * Build one touch. First touches lean toward discovery channels; all
	 * touches have a chance of being organic (referrer only) or direct (neither).
	 *
	 * @param array $channels Channel definitions.
	 * @param int   $idx      Touch index within the journey.
	 * @return array
	 */
	private static function make_touch( $channels, $idx ) {
		$roll = wp_rand( 1, 100 );

		// ~20% organic search, ~12% direct, rest campaign traffic.
		if ( $roll <= 20 ) {
			return self::empty_touch( array( 'referrer' => 'https://www.google.com/' ) );
		}
		if ( $roll <= 32 ) {
			return self::empty_touch( array() ); // direct
		}

		$c         = $channels[ array_rand( $channels ) ];
		$campaign  = $c['campaigns'][ array_rand( $c['campaigns'] ) ];
		$click_ids = null;
		if ( $c['click'] ) {
			$click_ids = wp_json_encode( array( $c['click'] => self::rand_token() ) );
		}

		return array(
			'utm_source'   => $c['utm_source'],
			'utm_medium'   => $c['utm_medium'],
			'utm_campaign' => $campaign,
			'utm_term'     => null,
			'utm_content'  => null,
			'click_ids'    => $click_ids,
			'referrer'     => null,
			'landing_url'  => home_url( '/' ),
		);
	}

	/**
	 * A touch with no UTM data (organic or direct).
	 *
	 * @param array $over Fields to override.
	 * @return array
	 */
	private static function empty_touch( $over ) {
		return array_merge(
			array(
				'utm_source'   => null,
				'utm_medium'   => null,
				'utm_campaign' => null,
				'utm_term'     => null,
				'utm_content'  => null,
				'click_ids'    => null,
				'referrer'     => null,
				'landing_url'  => home_url( '/' ),
			),
			$over
		);
	}

	/**
	 * Weighted number of touches per visitor: most are 1-2, long tail to 6.
	 *
	 * @return int
	 */
	private static function weighted_touch_count() {
		$r = wp_rand( 1, 100 );
		if ( $r <= 45 ) {
			return 1;
		}
		if ( $r <= 70 ) {
			return 2;
		}
		if ( $r <= 85 ) {
			return 3;
		}
		if ( $r <= 94 ) {
			return 4;
		}
		if ( $r <= 98 ) {
			return 5;
		}
		return 6;
	}

	/**
	 * Generate a v4-style UUID for anon_id.
	 *
	 * @return string
	 */
	private static function uuid() {
		return sprintf(
			'%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0x0fff ),
			wp_rand( 0, 0x3fff ) | 0x8000,
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff ),
			wp_rand( 0, 0xffff )
		);
	}

	/**
	 * A random-ish click id token.
	 *
	 * @return string
	 */
	private static function rand_token() {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 20 );
	}
}
