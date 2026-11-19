<?php
/**
 * Sink interface.
 *
 * A "sink" is where captured touches go. v1's default sink is the local
 * WordPress database. The hosted version (Phase 7) swaps in a sink that POSTs
 * to a central API, with zero change to the capture/ingestion code. The REST
 * controller only ever talks to a sink, never to the database directly.
 *
 * @package Sightline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Sightline_Sink {

	/**
	 * Persist a batch of touches for one anonymous visitor.
	 *
	 * @param string $anon_id Opaque first-party visitor id.
	 * @param array  $touches Array of sanitized touch arrays.
	 * @return array { visitor_id:int, inserted:int }
	 */
	public function record( $anon_id, array $touches );

	/**
	 * Persist a conversion and attach the hashed identity to the visitor.
	 * The raw email is NEVER passed here; only its hash (or null).
	 *
	 * @param string      $anon_id      Opaque first-party visitor id.
	 * @param string|null $hashed_email HMAC of the email, or null if none found.
	 * @param array       $conversion   { form_id, form_type, page_url, time }
	 * @return array { visitor_id:int, conversion_id:int }
	 */
	public function record_conversion( $anon_id, $hashed_email, array $conversion );
}
