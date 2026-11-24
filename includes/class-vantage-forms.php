<?php
/**
 * Form integration + identity resolution.
 *
 * Hooks the common form plugins. On submit:
 *   1. read the anon_id injected by capture.js (hidden vt_anon_id field),
 *   2. find the submitted email, hash it with the pepper, DISCARD the raw email,
 *   3. record a conversion and attach the hashed identity to the visitor.
 *
 * The raw email never reaches the sink or the database. Only its hash does.
 *
 * @package Vantage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vantage_Forms {

	public static function init() {
		// Contact Form 7. CF7 fires exactly one of these per valid submission.
		// Hook both: a failed notification email doesn't mean the visitor
		// didn't convert, so the conversion must still be recorded.
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_cf7' ) );
		add_action( 'wpcf7_mail_failed', array( __CLASS__, 'on_cf7' ) );
		// Gravity Forms.
		add_action( 'gform_after_submission', array( __CLASS__, 'on_gforms' ), 10, 2 );
		// WPForms.
		add_action( 'wpforms_process_complete', array( __CLASS__, 'on_wpforms' ), 10, 4 );
	}

	/**
	 * Contact Form 7.
	 *
	 * @param WPCF7_ContactForm $contact_form Form.
	 */
	public static function on_cf7( $contact_form ) {
		$data = array();
		if ( class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
			if ( $submission ) {
				$data = $submission->get_posted_data();
			}
		}
		$email   = self::find_email( $data );
		$form_id = ( is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ) ? $contact_form->id() : null;
		self::handle( $email, 'cf7', $form_id );
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public static function on_gforms( $entry, $form ) {
		$email = null;

		// Prefer the declared email field.
		if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				if ( isset( $field->type ) && 'email' === $field->type && isset( $field->id ) ) {
					$val = isset( $entry[ $field->id ] ) ? $entry[ $field->id ] : '';
					if ( $val && is_email( $val ) ) {
						$email = $val;
						break;
					}
				}
			}
		}
		if ( ! $email ) {
			$email = self::find_email( $entry );
		}

		$form_id = isset( $form['id'] ) ? $form['id'] : null;
		self::handle( $email, 'gravityforms', $form_id );
	}

	/**
	 * WPForms.
	 *
	 * @param array $fields    Processed fields.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form config.
	 * @param int   $entry_id  Entry id.
	 */
	public static function on_wpforms( $fields, $entry, $form_data, $entry_id ) {
		$email = null;

		if ( is_array( $fields ) ) {
			// Prefer a field typed as email.
			foreach ( $fields as $f ) {
				if ( isset( $f['type'] ) && 'email' === $f['type'] && ! empty( $f['value'] ) && is_email( $f['value'] ) ) {
					$email = $f['value'];
					break;
				}
			}
			if ( ! $email ) {
				foreach ( $fields as $f ) {
					if ( ! empty( $f['value'] ) && is_email( $f['value'] ) ) {
						$email = $f['value'];
						break;
					}
				}
			}
		}

		$form_id = isset( $form_data['id'] ) ? $form_data['id'] : null;
		self::handle( $email, 'wpforms', $form_id );
	}

	/**
	 * Recursively find the first valid email in submitted data.
	 *
	 * @param mixed $data Submitted values.
	 * @return string|null
	 */
	private static function find_email( $data ) {
		if ( ! is_array( $data ) ) {
			return ( is_string( $data ) && is_email( $data ) ) ? $data : null;
		}
		foreach ( $data as $v ) {
			if ( is_array( $v ) ) {
				$found = self::find_email( $v );
				if ( $found ) {
					return $found;
				}
			} elseif ( is_string( $v ) && is_email( $v ) ) {
				return $v;
			}
		}
		return null;
	}

	/**
	 * Shared handler: resolve identity and record the conversion.
	 *
	 * @param string|null $email     Raw email (hashed then discarded here).
	 * @param string      $form_type Plugin slug.
	 * @param mixed       $form_id   Form id.
	 */
	private static function handle( $email, $form_type, $form_id ) {
		// The form plugin has already validated this submission; we read the
		// anon_id that capture.js injected as a hidden field.
		$anon_id = isset( $_POST['vt_anon_id'] ) ? sanitize_text_field( wp_unslash( $_POST['vt_anon_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$anon_id = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $anon_id );
		$anon_id = substr( $anon_id, 0, 64 );

		if ( '' === $anon_id ) {
			// No visitor id: nothing to attribute to. (capture.js didn't run,
			// or the field was stripped.) Logged as a known gap, not an error.
			return;
		}

		$hashed_email = $email ? Vantage_Pepper::hash_email( $email ) : null;
		unset( $email ); // Discard the raw email immediately.

		$page_url = '';
		if ( isset( $_POST['_wp_http_referer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$page_url = esc_url_raw( wp_unslash( $_POST['_wp_http_referer'] ) );
		} elseif ( wp_get_referer() ) {
			$page_url = esc_url_raw( wp_get_referer() );
		}

		$conversion = array(
			'form_id'   => $form_id ? (string) $form_id : null,
			'form_type' => $form_type,
			'page_url'  => ( '' === $page_url ) ? null : $page_url,
			'time'      => current_time( 'mysql', true ),
		);

		Vantage_Sinks::get()->record_conversion( $anon_id, $hashed_email, $conversion );
	}
}
