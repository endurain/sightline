<?php
/**
 * Sink resolver. Returns the active sink, filterable so the hosted version
 * (Phase 7) can swap the local DB sink for a remote-API sink.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Sinks {

	/**
	 * @return Vantage_Sink
	 */
	public static function get() {
		$sink = new Vantage_DB_Sink();

		/**
		 * Filter the active sink.
		 *
		 * @param Vantage_Sink $sink Default local-DB sink.
		 */
		return apply_filters( 'vantage_sink', $sink );
	}
}
