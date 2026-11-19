<?php
/**
 * WP-CLI commands for Sightline.
 *
 *   wp sightline seed --count=2000
 *   wp sightline clear
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sightline_CLI {

	/**
	 * Seed synthetic attribution data.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<n>]
	 * : Number of visitors to create. Default 2000.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sightline seed
	 *     wp sightline seed --count=5000
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function seed( $args, $assoc_args ) {
		$count   = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 2000;
		$created = Sightline_Seeder::seed( $count );
		WP_CLI::success( sprintf( 'Seeded %d visitors.', $created ) );
	}

	/**
	 * Clear all Sightline data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sightline clear
	 */
	public function clear() {
		Sightline_Seeder::clear();
		WP_CLI::success( 'Cleared Sightline data.' );
	}
}

WP_CLI::add_command( 'sightline', 'Sightline_CLI' );
