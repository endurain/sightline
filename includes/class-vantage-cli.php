<?php
/**
 * WP-CLI commands for Vantage.
 *
 *   wp vantage seed --count=2000
 *   wp vantage clear
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_CLI {

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
	 *     wp vantage seed
	 *     wp vantage seed --count=5000
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function seed( $args, $assoc_args ) {
		$count   = isset( $assoc_args['count'] ) ? (int) $assoc_args['count'] : 2000;
		$created = Vantage_Seeder::seed( $count );
		WP_CLI::success( sprintf( 'Seeded %d visitors.', $created ) );
	}

	/**
	 * Clear all Vantage data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vantage clear
	 */
	public function clear() {
		Vantage_Seeder::clear();
		WP_CLI::success( 'Cleared Vantage data.' );
	}
}

WP_CLI::add_command( 'vantage', 'Vantage_CLI' );
