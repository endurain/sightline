<?php
/**
 * Sink resolver. Returns the active sink, filterable so the hosted version
 * (Phase 7) can swap the local DB sink for a remote-API sink.
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sightline_Sinks {

	/**
	 * @return Sightline_Sink
	 */
	public static function get() {
		$sink = new Sightline_DB_Sink();

		/**
		 * Filter the active sink.
		 *
		 * @param Sightline_Sink $sink Default local-DB sink.
		 */
		return apply_filters( 'sightline_sink', $sink );
	}
}
