<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_REST {
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'school-pos/v1',
			'/voucher/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'lookup_voucher' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => array(
					'code' => array(
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_code' ),
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && preg_match( '/^[A-Z0-9-]{8,64}$/', $value );
						},
					),
				),
			)
		);
	}

	public static function permission() {
		return is_user_logged_in() && current_user_can( 'remit_bursar_voucher' );
	}

	public static function sanitize_code( $code ) {
		return strtoupper( sanitize_text_field( $code ) );
	}

	public static function lookup_voucher( WP_REST_Request $request ) {
		global $wpdb;
		$ip = School_POS_Security::client_ip();
		if ( ! School_POS_Rate_Limiter::allow_voucher_lookup( get_current_user_id(), $ip ) ) {
			self::audit_lookup( 'voucher_lookup_rate_limited', $request->get_param( 'code' ) );
			return new WP_Error( 'rate_limited', 'Too many voucher lookup attempts. Please wait and try again.', array( 'status' => 429 ) );
		}

		$code = self::sanitize_code( $request->get_param( 'code' ) );
		$table = $wpdb->prefix . 'school_pos_vouchers';
		$voucher = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT voucher_id, voucher_code, student_full_name, admission_number, class_grade, amount_paid_kobo, status
				FROM {$table} WHERE voucher_code = %s LIMIT 1",
				$code
			)
		);

		if ( ! $voucher ) {
			self::audit_lookup( 'voucher_lookup_failed', $code );
			return new WP_Error( 'invalid_voucher', 'Voucher code is invalid or unavailable.', array( 'status' => 404 ) );
		}

		self::audit_lookup( 'voucher_lookup_succeeded', $code, (int) $voucher->voucher_id );
		return rest_ensure_response(
			array(
				'voucher_code'      => $voucher->voucher_code,
				'student_full_name' => $voucher->student_full_name,
				'admission_number'  => $voucher->admission_number,
				'class_grade'       => $voucher->class_grade,
				'amount_paid_kobo'  => (int) $voucher->amount_paid_kobo,
				'status'            => $voucher->status,
			)
		);
	}

	private static function audit_lookup( $event_type, $code, $entity_id = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'school_pos_audit_events';
		$wpdb->insert(
			$table,
			array(
				'event_type'      => $event_type,
				'actor_user_id'   => get_current_user_id(),
				'entity_type'     => 'voucher',
				'entity_id'       => $entity_id,
				'reference_code'  => substr( $code, 0, 100 ),
				'ip_address'      => School_POS_Security::client_ip(),
				'user_agent'      => School_POS_Security::user_agent(),
				'metadata_json'   => wp_json_encode( array( 'endpoint' => 'voucher_lookup' ) ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
