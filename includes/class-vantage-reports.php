<?php
/**
 * Reporting backend (Phase 4).
 *
 * Assembles attribution and traffic reports from the local tables. Read-only.
 *
 * Concepts:
 *   - Person: visitors sharing a hashed_email are one person (cross-device
 *     stitching, issue #4); a visitor with no hash is a person on its own.
 *     The hash itself is never returned by any report.
 *   - Channel: a label for a touch, e.g. "google / cpc", "google / organic",
 *     "(direct)". With group_by=campaign UTM touches also carry the campaign.
 *   - Path: a conversion's path is the person's touches up to the conversion time.
 *
 * Date ranges are whole days in the site's timezone; all times returned are UTC.
 * Data is loaded per request and computed in PHP: fine at plugin scale; a
 * pre-aggregated table is the upgrade path if sites get very large.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Reports {

	const DIRECT       = '(direct)';
	const NO_TOUCH     = '(no touch recorded)';
	const CONVERTED    = 'Converted';
	const NOT_CONVERTED = 'No conversion';
	const MAX_RANGE_DAYS = 731;

	/** Click id => [source, medium] when a click id arrives without UTMs. */
	private static $click_map = array(
		'gclid'     => 'google',
		'wbraid'    => 'google',
		'gbraid'    => 'google',
		'msclkid'   => 'bing',
		'fbclid'    => 'facebook',
		'ttclid'    => 'tiktok',
		'li_fat_id' => 'linkedin',
		'twclid'    => 'twitter',
		'epik'      => 'pinterest',
		'sccid'     => 'snapchat',
	);

	private static $search_hosts = array(
		'google'     => 'google',
		'bing'       => 'bing',
		'duckduckgo' => 'duckduckgo',
		'yahoo'      => 'yahoo',
		'baidu'      => 'baidu',
		'yandex'     => 'yandex',
		'ecosia'     => 'ecosia',
		'search.brave' => 'brave',
	);

	private static $social_hosts = array(
		'facebook'  => 'facebook',
		'fb.com'    => 'facebook',
		'instagram' => 'instagram',
		'linkedin'  => 'linkedin',
		'lnkd.in'   => 'linkedin',
		't.co'      => 'twitter',
		'twitter'   => 'twitter',
		'x.com'     => 'twitter',
		'reddit'    => 'reddit',
		'youtube'   => 'youtube',
		'pinterest' => 'pinterest',
		'tiktok'    => 'tiktok',
	);

	/* ------------------------------------------------------------------ */
	/* Arguments                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalize report arguments.
	 *
	 * @param array $args Raw args (start, end, group_by, campaign, max_steps,
	 *                    quiet_days, page, per_page, include_nonconverters).
	 * @return array
	 */
	public static function parse_args( array $args ) {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );

		$end   = self::parse_date( isset( $args['end'] ) ? $args['end'] : '', $tz );
		$end   = $end ? $end : $today;
		$start = self::parse_date( isset( $args['start'] ) ? $args['start'] : '', $tz );
		$start = $start ? $start : $end->modify( '-89 days' );
		if ( $start > $end ) {
			list( $start, $end ) = array( $end, $start );
		}
		if ( (int) $start->diff( $end )->days > self::MAX_RANGE_DAYS ) {
			$start = $end->modify( '-' . self::MAX_RANGE_DAYS . ' days' );
		}

		$utc      = new DateTimeZone( 'UTC' );
		$start_l  = $start->setTime( 0, 0, 0 );
		$end_l    = $end->setTime( 23, 59, 59 );
		$group_by = ( isset( $args['group_by'] ) && 'campaign' === $args['group_by'] ) ? 'campaign' : 'source';

		return array(
			'start'                 => $start_l->format( 'Y-m-d' ),
			'end'                   => $end_l->format( 'Y-m-d' ),
			'start_utc'             => $start_l->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_utc'               => $end_l->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'group_by'              => $group_by,
			'campaign'              => isset( $args['campaign'] ) ? substr( sanitize_text_field( (string) $args['campaign'] ), 0, 191 ) : '',
			'channel'               => isset( $args['channel'] ) ? substr( sanitize_text_field( (string) $args['channel'] ), 0, 191 ) : '',
			'max_steps'             => self::clamp( isset( $args['max_steps'] ) ? $args['max_steps'] : 5, 2, 8 ),
			'quiet_days'            => self::clamp( isset( $args['quiet_days'] ) ? $args['quiet_days'] : 14, 1, 365 ),
			'page'                  => self::clamp( isset( $args['page'] ) ? $args['page'] : 1, 1, 100000 ),
			'per_page'              => self::clamp( isset( $args['per_page'] ) ? $args['per_page'] : 50, 1, 500 ),
			'include_nonconverters' => ! empty( $args['include_nonconverters'] ) && 'false' !== $args['include_nonconverters'],
			'now_utc'               => isset( $args['now_utc'] ) ? (string) $args['now_utc'] : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private static function parse_date( $value, $tz ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $d : null;
	}

	private static function clamp( $v, $min, $max ) {
		return max( $min, min( $max, (int) $v ) );
	}

	private static function meta( array $a ) {
		return array(
			'start'        => $a['start'],
			'end'          => $a['end'],
			'timezone'     => wp_timezone_string(),
			'group_by'     => $a['group_by'],
			'campaign'     => '' === $a['campaign'] ? null : $a['campaign'],
			'channel'      => '' === $a['channel'] ? null : $a['channel'],
			'generated_at' => gmdate( 'c' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Data loading                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Load every person who touched or converted in range, with their full
	 * (all-time) touches and conversions, stitched across visitor rows.
	 *
	 * @param array $a Parsed args.
	 * @return array person_key => person
	 */
	public static function load_people( array $a ) {
		global $wpdb;
		$v_t = Vantage_Schema::table( 'visitors' );
		$t_t = Vantage_Schema::table( 'touches' );
		$c_t = Vantage_Schema::table( 'conversions' );

		// phpcs:disable WordPress.DB
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT visitor_id FROM `$t_t` WHERE touch_time BETWEEN %s AND %s
				 UNION SELECT visitor_id FROM `$c_t` WHERE conversion_time BETWEEN %s AND %s",
				$a['start_utc'], $a['end_utc'], $a['start_utc'], $a['end_utc']
			)
		);
		if ( ! $ids ) {
			return array();
		}

		// Visitor rows, then pull in other devices of the same people.
		$visitors = array();
		foreach ( self::chunks( $ids ) as $in ) {
			foreach ( $wpdb->get_results( "SELECT id, hashed_email FROM `$v_t` WHERE id IN ($in)", ARRAY_A ) as $r ) {
				$visitors[ (int) $r['id'] ] = $r['hashed_email'];
			}
		}
		$hashes = array_values( array_unique( array_filter( $visitors ) ) );
		foreach ( array_chunk( $hashes, 500 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, hashed_email FROM `$v_t` WHERE hashed_email IN ($ph)", $chunk ), ARRAY_A ) as $r ) {
				$visitors[ (int) $r['id'] ] = $r['hashed_email'];
			}
		}

		$people = array();
		$key_of = array();
		foreach ( $visitors as $id => $hash ) {
			$key           = $hash ? 'h:' . $hash : 'v:' . $id;
			$key_of[ $id ] = $key;
			if ( ! isset( $people[ $key ] ) ) {
				$people[ $key ] = array(
					'key'         => $key,
					'visitor_ids' => array(),
					'touches'     => array(),
					'conversions' => array(),
				);
			}
			$people[ $key ]['visitor_ids'][] = $id;
		}

		foreach ( self::chunks( array_keys( $visitors ) ) as $in ) {
			$rows = $wpdb->get_results(
				"SELECT id, visitor_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
				        click_ids, referrer, landing_url, touch_time
				 FROM `$t_t` WHERE visitor_id IN ($in)",
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$people[ $key_of[ (int) $r['visitor_id'] ] ]['touches'][] = $r;
			}
			$rows = $wpdb->get_results(
				"SELECT id, visitor_id, form_id, form_type, page_url, conversion_time FROM `$c_t` WHERE visitor_id IN ($in)",
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$people[ $key_of[ (int) $r['visitor_id'] ] ]['conversions'][] = $r;
			}
		}
		// phpcs:enable WordPress.DB

		foreach ( $people as &$p ) {
			usort( $p['touches'], array( __CLASS__, 'cmp_touch' ) );
			usort( $p['conversions'], array( __CLASS__, 'cmp_conversion' ) );
			foreach ( $p['touches'] as &$t ) {
				$t = self::classify( $t, $a['group_by'] );
			}
			unset( $t );
		}
		unset( $p );

		// Filters keep a person if ANY of their touches matches (all filters must hold).
		if ( '' !== $a['campaign'] || '' !== $a['channel'] ) {
			$people = array_filter(
				$people,
				function ( $p ) use ( $a ) {
					foreach ( array( 'campaign', 'channel' ) as $f ) {
						if ( '' === $a[ $f ] ) {
							continue;
						}
						$hit = false;
						foreach ( $p['touches'] as $t ) {
							$value = 'campaign' === $f ? $t['campaign'] : self::base_channel( $t );
							if ( $value === $a[ $f ] ) {
								$hit = true;
								break;
							}
						}
						if ( ! $hit ) {
							return false;
						}
					}
					return true;
				}
			);
		}

		return $people;
	}

	/**
	 * A classified touch's "source / medium" label (or "(direct)"), regardless
	 * of group_by. This is what the `channel` filter matches against.
	 *
	 * @param array $t Classified touch.
	 * @return string
	 */
	public static function base_channel( array $t ) {
		return null === $t['source'] ? self::DIRECT : $t['source'] . ' / ' . $t['medium'];
	}

	/**
	 * Filter options for the dashboard: campaigns and channels present on the
	 * people active in the date range, with a people count each. Ignores the
	 * campaign / channel filters themselves so every option stays listed.
	 *
	 * @param array $args Raw args.
	 * @return array
	 */
	public static function filters( array $args = array() ) {
		$args['campaign'] = '';
		$args['channel']  = '';
		$a                = self::parse_args( $args );
		$campaigns        = array();
		$channels         = array();
		foreach ( self::load_people( $a ) as $p ) {
			$seen_c = array();
			$seen_h = array();
			foreach ( $p['touches'] as $t ) {
				if ( null !== $t['campaign'] ) {
					$seen_c[ $t['campaign'] ] = true;
				}
				$seen_h[ self::base_channel( $t ) ] = true;
			}
			foreach ( array_keys( $seen_c ) as $c ) {
				$campaigns[ $c ] = ( isset( $campaigns[ $c ] ) ? $campaigns[ $c ] : 0 ) + 1;
			}
			foreach ( array_keys( $seen_h ) as $h ) {
				$channels[ $h ] = ( isset( $channels[ $h ] ) ? $channels[ $h ] : 0 ) + 1;
			}
		}
		$rows = function ( array $counts, $key ) {
			arsort( $counts );
			$out = array();
			foreach ( $counts as $value => $n ) {
				$out[] = array( $key => (string) $value, 'people' => $n );
			}
			return $out;
		};
		return array(
			'meta'      => self::meta( $a ),
			'campaigns' => $rows( $campaigns, 'campaign' ),
			'channels'  => $rows( $channels, 'channel' ),
		);
	}

	private static function chunks( array $ids ) {
		$out = array();
		foreach ( array_chunk( array_map( 'intval', $ids ), 1000 ) as $c ) {
			$out[] = implode( ',', $c );
		}
		return $out;
	}

	public static function cmp_touch( $x, $y ) {
		return strcmp( $x['touch_time'], $y['touch_time'] ) ?: ( (int) $x['id'] - (int) $y['id'] );
	}

	public static function cmp_conversion( $x, $y ) {
		return strcmp( $x['conversion_time'], $y['conversion_time'] ) ?: ( (int) $x['id'] - (int) $y['id'] );
	}

	/* ------------------------------------------------------------------ */
	/* Classification                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Add source / medium / campaign / channel to a raw touch row.
	 *
	 * @param array  $t        Touch row.
	 * @param string $group_by source|campaign.
	 * @return array
	 */
	public static function classify( array $t, $group_by = 'source' ) {
		$source   = null;
		$medium   = null;
		$campaign = ( isset( $t['utm_campaign'] ) && '' !== $t['utm_campaign'] ) ? $t['utm_campaign'] : null;

		if ( ! empty( $t['utm_source'] ) || ! empty( $t['utm_medium'] ) ) {
			$source = ! empty( $t['utm_source'] ) ? strtolower( $t['utm_source'] ) : '(not set)';
			$medium = ! empty( $t['utm_medium'] ) ? strtolower( $t['utm_medium'] ) : '(not set)';
		} elseif ( ! empty( $t['click_ids'] ) && ( $ids = json_decode( $t['click_ids'], true ) ) && is_array( $ids ) ) { // phpcs:ignore
			foreach ( self::$click_map as $k => $src ) {
				if ( isset( $ids[ $k ] ) ) {
					$source = $src;
					$medium = '(click id)';
					break;
				}
			}
		}

		if ( null === $source && ! empty( $t['referrer'] ) ) {
			list( $source, $medium ) = self::classify_referrer( $t['referrer'] );
		}

		if ( null === $source ) {
			$channel = self::DIRECT;
		} else {
			$channel = $source . ' / ' . $medium;
			if ( 'campaign' === $group_by && null !== $campaign ) {
				$channel .= ' · ' . $campaign;
			}
		}

		$t['source']   = $source;
		$t['medium']   = $medium;
		$t['campaign'] = $campaign;
		$t['channel']  = $channel;
		return $t;
	}

	/**
	 * @param string $referrer URL.
	 * @return array [source|null, medium|null]
	 */
	private static function classify_referrer( $referrer ) {
		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$host = preg_replace( '/^(www|m|l|lm)\./', '', $host );
		if ( '' === $host ) {
			return array( null, null );
		}
		$own = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		if ( $host === $own ) {
			return array( null, null ); // Internal navigation counts as direct.
		}
		foreach ( self::$search_hosts as $needle => $name ) {
			if ( self::host_has( $host, $needle ) ) {
				return array( $name, 'organic' );
			}
		}
		foreach ( self::$social_hosts as $needle => $name ) {
			if ( self::host_has( $host, $needle ) ) {
				return array( $name, 'social' );
			}
		}
		return array( $host, 'referral' );
	}

	/** Does host contain the label (e.g. "google" in "news.google.co.uk")? */
	private static function host_has( $host, $needle ) {
		if ( false !== strpos( $needle, '.' ) ) {
			return $host === $needle || substr( $host, -strlen( '.' . $needle ) ) === '.' . $needle;
		}
		return in_array( $needle, explode( '.', $host ), true );
	}

	/* ------------------------------------------------------------------ */
	/* Journeys                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Every conversion in range with its path, newest first.
	 *
	 * @param array $people Loaded people.
	 * @param array $a      Parsed args.
	 * @return array
	 */
	private static function journeys( array $people, array $a ) {
		$out = array();
		foreach ( $people as $p ) {
			foreach ( $p['conversions'] as $c ) {
				if ( $c['conversion_time'] < $a['start_utc'] || $c['conversion_time'] > $a['end_utc'] ) {
					continue;
				}
				$path = array();
				foreach ( $p['touches'] as $t ) {
					if ( $t['touch_time'] <= $c['conversion_time'] ) {
						$path[] = $t;
					}
				}
				$out[] = array(
					'person'     => $p,
					'conversion' => $c,
					'path'       => $path,
				);
			}
		}
		usort(
			$out,
			function ( $x, $y ) {
				return -self::cmp_conversion( $x['conversion'], $y['conversion'] );
			}
		);
		return $out;
	}

	/** Channel labels of a path ("(no touch recorded)" if empty). */
	private static function labels( array $path ) {
		if ( ! $path ) {
			return array( self::NO_TOUCH );
		}
		return array_map(
			function ( $t ) {
				return $t['channel'];
			},
			$path
		);
	}

	/** Drop consecutive repeats: A A B A -> A B A. */
	private static function collapse( array $labels ) {
		$out = array();
		foreach ( $labels as $l ) {
			if ( ! $out || end( $out ) !== $l ) {
				$out[] = $l;
			}
		}
		return $out;
	}

	private static function touch_out( array $t ) {
		return array(
			'channel'  => $t['channel'],
			'source'   => $t['source'],
			'medium'   => $t['medium'],
			'campaign' => $t['campaign'],
			'time'     => self::iso( $t['touch_time'] ),
		);
	}

	private static function iso( $mysql ) {
		return str_replace( ' ', 'T', $mysql ) . 'Z';
	}

	private static function days_between( $from, $to ) {
		return round( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS, 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Reports                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Paginated conversions with first touch, last touch and full path.
	 */
	public static function conversions( array $args = array() ) {
		$a     = self::parse_args( $args );
		$all   = self::journeys( self::load_people( $a ), $a );
		$slice = array_slice( $all, ( $a['page'] - 1 ) * $a['per_page'], $a['per_page'] );

		$rows = array();
		foreach ( $slice as $j ) {
			$c      = $j['conversion'];
			$path   = $j['path'];
			$rows[] = array(
				'conversion_id'   => (int) $c['id'],
				'time'            => self::iso( $c['conversion_time'] ),
				'form_type'       => $c['form_type'],
				'form_id'         => $c['form_id'],
				'page_url'        => $c['page_url'],
				'visitor_id'      => (int) $c['visitor_id'],
				'devices'         => count( $j['person']['visitor_ids'] ),
				'touch_count'     => count( $path ),
				'days_to_convert' => $path ? self::days_between( $path[0]['touch_time'], $c['conversion_time'] ) : null,
				'first_touch'     => $path ? self::touch_out( $path[0] ) : null,
				'last_touch'      => $path ? self::touch_out( end( $path ) ) : null,
				'path'            => array_map( array( __CLASS__, 'touch_out' ), $path ),
			);
		}

		return array(
			'meta'     => self::meta( $a ) + array(
				'page'     => $a['page'],
				'per_page' => $a['per_page'],
				'total'    => count( $all ),
				'pages'    => (int) ceil( count( $all ) / $a['per_page'] ),
			),
			'rows'     => $rows,
		);
	}

	/**
	 * Conversion credit per channel under four models.
	 */
	public static function attribution( array $args = array() ) {
		$a      = self::parse_args( $args );
		$credit = array();
		$add    = function ( $channel, $model, $v ) use ( &$credit ) {
			if ( ! isset( $credit[ $channel ] ) ) {
				$credit[ $channel ] = array( 'first_touch' => 0, 'last_touch' => 0, 'linear' => 0, 'position_based' => 0 );
			}
			$credit[ $channel ][ $model ] += $v;
		};

		$journeys = self::journeys( self::load_people( $a ), $a );
		foreach ( $journeys as $j ) {
			$l = self::labels( $j['path'] );
			$n = count( $l );
			$add( $l[0], 'first_touch', 1 );
			$add( $l[ $n - 1 ], 'last_touch', 1 );
			foreach ( $l as $i => $ch ) {
				$add( $ch, 'linear', 1 / $n );
				if ( 1 === $n ) {
					$w = 1;
				} elseif ( 2 === $n ) {
					$w = 0.5;
				} else {
					$w = ( 0 === $i || $n - 1 === $i ) ? 0.4 : 0.2 / ( $n - 2 );
				}
				$add( $ch, 'position_based', $w );
			}
		}

		$rows = array();
		foreach ( $credit as $ch => $m ) {
			$rows[] = array( 'channel' => $ch ) + array_map(
				function ( $v ) {
					return round( $v, 2 );
				},
				$m
			);
		}
		usort(
			$rows,
			function ( $x, $y ) {
				return ( $y['linear'] <=> $x['linear'] ) ?: strcmp( $x['channel'], $y['channel'] );
			}
		);

		return array(
			'meta'        => self::meta( $a ),
			'conversions' => count( $journeys ),
			'models'      => array(
				'first_touch'    => '100% to the first touch',
				'last_touch'     => '100% to the last touch before converting',
				'linear'         => 'Split evenly across every touch',
				'position_based' => '40% first, 40% last, 20% split across the middle',
			),
			'rows'        => $rows,
		);
	}

	/**
	 * Sankey-ready nodes and links: step 1 -> step 2 -> ... -> Converted.
	 * Consecutive repeats are collapsed; long paths keep the first touch and
	 * the last (max_steps - 1) touches.
	 */
	public static function sankey( array $args = array() ) {
		$a      = self::parse_args( $args );
		$people = self::load_people( $a );
		$max    = $a['max_steps'];

		$paths = array();
		foreach ( self::journeys( $people, $a ) as $j ) {
			$paths[] = array( self::collapse( self::labels( $j['path'] ) ), self::CONVERTED );
		}
		if ( $a['include_nonconverters'] ) {
			foreach ( $people as $p ) {
				if ( ! $p['conversions'] && self::touched_in_range( $p, $a ) ) {
					$paths[] = array( self::collapse( self::labels( $p['touches'] ) ), self::NOT_CONVERTED );
				}
			}
		}

		$nodes     = array();
		$links     = array();
		$truncated = 0;
		foreach ( $paths as $pair ) {
			list( $l, $outcome ) = $pair;
			if ( count( $l ) > $max ) {
				$l = array_merge( array( $l[0] ), array_slice( $l, -( $max - 1 ) ) );
				$truncated++;
			}
			$ids = array();
			foreach ( $l as $i => $label ) {
				$ids[] = self::node( $nodes, ( $i + 1 ) . '|' . $label, $label, $i + 1 );
			}
			$ids[] = self::node( $nodes, 'end|' . $outcome, $outcome, 'end' );
			for ( $i = 0; $i < count( $ids ) - 1; $i++ ) {
				$k = $ids[ $i ] . '>' . $ids[ $i + 1 ];
				if ( ! isset( $links[ $k ] ) ) {
					$links[ $k ] = array( 'source' => $ids[ $i ], 'target' => $ids[ $i + 1 ], 'value' => 0 );
				}
				$links[ $k ]['value']++;
			}
		}

		$nodes = array_values( $nodes );
		usort(
			$nodes,
			function ( $x, $y ) {
				$sx = 'end' === $x['step'] ? PHP_INT_MAX : $x['step'];
				$sy = 'end' === $y['step'] ? PHP_INT_MAX : $y['step'];
				return ( $sx <=> $sy ) ?: ( $y['value'] <=> $x['value'] );
			}
		);
		$links = array_values( $links );
		usort(
			$links,
			function ( $x, $y ) {
				return $y['value'] <=> $x['value'];
			}
		);

		return array(
			'meta'  => self::meta( $a ) + array(
				'max_steps'             => $max,
				'include_nonconverters' => $a['include_nonconverters'],
				'paths'                 => count( $paths ),
				'truncated_paths'       => $truncated,
			),
			'nodes' => $nodes,
			'links' => $links,
		);
	}

	private static function node( array &$nodes, $id, $label, $step ) {
		if ( ! isset( $nodes[ $id ] ) ) {
			$nodes[ $id ] = array( 'id' => $id, 'label' => $label, 'step' => $step, 'value' => 0 );
		}
		$nodes[ $id ]['value']++;
		return $id;
	}

	private static function touched_in_range( array $p, array $a ) {
		foreach ( $p['touches'] as $t ) {
			if ( $t['touch_time'] >= $a['start_utc'] && $t['touch_time'] <= $a['end_utc'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whole-funnel traffic by channel, converters and non-converters alike.
	 * A person counts for a channel if they had a touch from it in range;
	 * they count as a converter for it if they converted (in range) at or
	 * after their first in-range touch from that channel.
	 */
	public static function traffic( array $args = array() ) {
		$a   = self::parse_args( $args );
		$agg = array();

		foreach ( self::load_people( $a ) as $p ) {
			$conv_times = array();
			foreach ( $p['conversions'] as $c ) {
				if ( $c['conversion_time'] >= $a['start_utc'] && $c['conversion_time'] <= $a['end_utc'] ) {
					$conv_times[] = $c['conversion_time'];
				}
			}
			$last_conv = $conv_times ? max( $conv_times ) : null;

			$first_by_channel = array();
			$touches_by_ch    = array();
			foreach ( $p['touches'] as $t ) {
				if ( $t['touch_time'] < $a['start_utc'] || $t['touch_time'] > $a['end_utc'] ) {
					continue;
				}
				$ch = $t['channel'];
				if ( ! isset( $first_by_channel[ $ch ] ) ) {
					$first_by_channel[ $ch ] = $t['touch_time'];
				}
				$touches_by_ch[ $ch ] = ( isset( $touches_by_ch[ $ch ] ) ? $touches_by_ch[ $ch ] : 0 ) + 1;
			}
			if ( ! $first_by_channel ) {
				continue;
			}

			$total = count( $p['touches'] );
			$span  = self::days_between( $p['touches'][0]['touch_time'], end( $p['touches'] )['touch_time'] );
			$first = $p['touches'][0];
			$is_new = $first['touch_time'] >= $a['start_utc'] && $first['touch_time'] <= $a['end_utc'];

			foreach ( $first_by_channel as $ch => $first_time ) {
				if ( ! isset( $agg[ $ch ] ) ) {
					$agg[ $ch ] = array( 'people' => 0, 'touches' => 0, 'first_touch_people' => 0, 'converters' => 0, 'sum_touches' => 0, 'sum_span' => 0 );
				}
				$agg[ $ch ]['people']++;
				$agg[ $ch ]['touches']     += $touches_by_ch[ $ch ];
				$agg[ $ch ]['sum_touches'] += $total;
				$agg[ $ch ]['sum_span']    += $span;
				if ( $is_new && $first['channel'] === $ch ) {
					$agg[ $ch ]['first_touch_people']++;
				}
				if ( $last_conv && $last_conv >= $first_time ) {
					$agg[ $ch ]['converters']++;
				}
			}
		}

		$rows = array();
		foreach ( $agg as $ch => $r ) {
			$rows[] = array(
				'channel'            => $ch,
				'people'             => $r['people'],
				'touches'            => $r['touches'],
				'first_touch_people' => $r['first_touch_people'],
				'converters'         => $r['converters'],
				'conversion_rate'    => round( $r['converters'] / $r['people'], 4 ),
				'avg_touches'        => round( $r['sum_touches'] / $r['people'], 2 ),
				'avg_active_days'    => round( $r['sum_span'] / $r['people'], 1 ),
			);
		}
		usort(
			$rows,
			function ( $x, $y ) {
				return ( $y['people'] <=> $x['people'] ) ?: strcmp( $x['channel'], $y['channel'] );
			}
		);

		return array(
			'meta' => self::meta( $a ),
			'note' => 'On-site behavior only: Vantage cannot see where a visitor goes after leaving the site.',
			'rows' => $rows,
		);
	}

	/**
	 * Drop-off: people active in range, bucketed by total touch count, split
	 * into converted / still active / went quiet (no touch for quiet_days and
	 * never converted). Plus the last channel quiet people came through.
	 */
	public static function dropoff( array $args = array() ) {
		$a        = self::parse_args( $args );
		$quiet_ts = strtotime( $a['now_utc'] . ' UTC' ) - $a['quiet_days'] * DAY_IN_SECONDS;
		$labels   = array( '1', '2', '3', '4', '5', '6+' );
		$buckets  = array();
		foreach ( $labels as $l ) {
			$buckets[ $l ] = array( 'touches' => $l, 'people' => 0, 'converted' => 0, 'still_active' => 0, 'went_quiet' => 0 );
		}
		$last_ch = array();
		$totals  = array( 'people' => 0, 'converted' => 0, 'still_active' => 0, 'went_quiet' => 0 );

		foreach ( self::load_people( $a ) as $p ) {
			if ( ! self::touched_in_range( $p, $a ) ) {
				continue;
			}
			$n = count( $p['touches'] );
			$b = $n >= 6 ? '6+' : (string) $n;
			$buckets[ $b ]['people']++;
			$totals['people']++;

			if ( $p['conversions'] ) {
				$state = 'converted';
			} elseif ( strtotime( end( $p['touches'] )['touch_time'] . ' UTC' ) < $quiet_ts ) {
				$state = 'went_quiet';
				$ch    = end( $p['touches'] )['channel'];
				if ( ! isset( $last_ch[ $ch ] ) ) {
					$last_ch[ $ch ] = array( 'channel' => $ch, 'people' => 0, 'sum_touches' => 0 );
				}
				$last_ch[ $ch ]['people']++;
				$last_ch[ $ch ]['sum_touches'] += $n;
			} else {
				$state = 'still_active';
			}
			$buckets[ $b ][ $state ]++;
			$totals[ $state ]++;
		}

		foreach ( $buckets as &$r ) {
			$r['conversion_rate'] = $r['people'] ? round( $r['converted'] / $r['people'], 4 ) : 0;
		}
		unset( $r );

		$quiet = array();
		foreach ( $last_ch as $r ) {
			$quiet[] = array(
				'channel'     => $r['channel'],
				'people'      => $r['people'],
				'avg_touches' => round( $r['sum_touches'] / $r['people'], 2 ),
			);
		}
		usort(
			$quiet,
			function ( $x, $y ) {
				return ( $y['people'] <=> $x['people'] ) ?: strcmp( $x['channel'], $y['channel'] );
			}
		);

		return array(
			'meta'                 => self::meta( $a ) + array( 'quiet_days' => $a['quiet_days'] ),
			'note'                 => 'On-site behavior only. "Went quiet" = no touch in the last quiet_days and never converted.',
			'totals'               => $totals,
			'by_touch_count'       => array_values( $buckets ),
			'quiet_by_last_channel' => $quiet,
		);
	}

	/**
	 * Headline numbers + top paths for the dashboard tiles.
	 */
	public static function summary( array $args = array() ) {
		$a        = self::parse_args( $args );
		$people   = self::load_people( $a );
		$journeys = self::journeys( $people, $a );

		$active     = 0;
		$active_set = array();
		$touches    = 0;
		foreach ( $people as $p ) {
			$in = 0;
			foreach ( $p['touches'] as $t ) {
				if ( $t['touch_time'] >= $a['start_utc'] && $t['touch_time'] <= $a['end_utc'] ) {
					$in++;
				}
			}
			if ( $in ) {
				$active++;
				$active_set[ $p['key'] ] = true;
				$touches                += $in;
			}
		}

		$converters = array();
		$lens       = array();
		$days       = array();
		$paths      = array();
		$firsts     = array();
		$lasts      = array();
		foreach ( $journeys as $j ) {
			$converters[ $j['person']['key'] ] = true;
			$l        = self::labels( $j['path'] );
			$lens[]   = count( $j['path'] );
			if ( $j['path'] ) {
				$days[] = self::days_between( $j['path'][0]['touch_time'], $j['conversion']['conversion_time'] );
			}
			$key            = implode( ' → ', self::collapse( $l ) );
			$paths[ $key ]  = ( isset( $paths[ $key ] ) ? $paths[ $key ] : 0 ) + 1;
			$firsts[ $l[0] ] = ( isset( $firsts[ $l[0] ] ) ? $firsts[ $l[0] ] : 0 ) + 1;
			$last            = end( $l );
			$lasts[ $last ]  = ( isset( $lasts[ $last ] ) ? $lasts[ $last ] : 0 ) + 1;
		}

		arsort( $paths );
		$top = array();
		foreach ( array_slice( $paths, 0, 10, true ) as $path => $n ) {
			$top[] = array( 'path' => $path, 'conversions' => $n );
		}

		return array(
			'meta'                   => self::meta( $a ),
			'people'                 => $active,
			'touches'                => $touches,
			'conversions'            => count( $journeys ),
			'converting_people'      => count( $converters ),
			// Of the people active in range, the share who converted in range.
			'conversion_rate'        => $active ? round( count( array_intersect_key( $converters, $active_set ) ) / $active, 4 ) : 0,
			'avg_touches_to_convert' => $lens ? round( array_sum( $lens ) / count( $lens ), 2 ) : null,
			'median_days_to_convert' => self::median( $days ),
			'top_first_touch'        => self::top_key( $firsts ),
			'top_last_touch'         => self::top_key( $lasts ),
			'top_paths'              => $top,
		);
	}

	private static function median( array $v ) {
		if ( ! $v ) {
			return null;
		}
		sort( $v );
		$n = count( $v );
		$m = (int) floor( $n / 2 );
		return $n % 2 ? $v[ $m ] : round( ( $v[ $m - 1 ] + $v[ $m ] ) / 2, 1 );
	}

	private static function top_key( array $counts ) {
		if ( ! $counts ) {
			return null;
		}
		arsort( $counts );
		$k = array_key_first( $counts );
		return array( 'channel' => $k, 'conversions' => $counts[ $k ] );
	}
}
