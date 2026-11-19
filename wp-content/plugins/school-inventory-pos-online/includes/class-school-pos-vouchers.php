<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Vouchers {
	public static function issue( array $data ) {
		global $wpdb;
		$amount = absint( $data['amount_paid_kobo'] );
		if ( $amount < 1 ) {
			return new WP_Error( 'invalid_amount', 'Voucher amount must be greater than zero.' );
		}
		$payment_method = strtoupper( sanitize_key( $data['payment_method'] ) );
		if ( ! in_array( $payment_method, array( 'cash', 'bank_transfer', 'teller' ), true ) ) {
			return new WP_Error( 'invalid_payment_method', 'Unsupported payment method.' );
		}

		$wpdb->query( 'START TRANSACTION' );
		$sequence = self::next_sequence();
		if ( is_wp_error( $sequence ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $sequence;
		}
		try {
			$random = strtoupper( rtrim( strtr( base64_encode( random_bytes( 5 ) ), '+/', 'AZ' ), '=' ) );
		} catch ( Exception $exception ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'secure_random_failed', 'A secure voucher code could not be generated.' );
		}
		$code = sprintf( 'VCH-%s-%s-%05d', gmdate( 'Y' ), substr( preg_replace( '/[^A-Z0-9]/', '', $random ), 0, 4 ), $sequence );
		$now  = current_time( 'mysql', true );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'school_pos_vouchers',
			array(
				'voucher_code'      => $code,
				'code_digest'       => hash( 'sha256', $code ),
				'sequence_no'       => $sequence,
				'student_full_name' => sanitize_text_field( $data['student_full_name'] ),
				'admission_number'  => sanitize_text_field( $data['admission_number'] ),
				'class_grade'       => sanitize_text_field( $data['class_grade'] ),
				'amount_paid_kobo'  => $amount,
				'payment_method'    => $payment_method,
				'teller_ref_no'     => sanitize_text_field( $data['teller_ref_no'] ),
				'status'            => 'ISSUED',
				'issued_by'         => get_current_user_id(),
				'issued_at'         => $now,
				'issued_ip'         => School_POS_Security::client_ip(),
				'issued_user_agent' => School_POS_Security::user_agent(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'voucher_issue_failed', 'The voucher could not be issued.' );
		}
		$voucher_id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );
		self::audit( 'voucher_issued', $voucher_id, $code, array( 'amount_paid_kobo' => $amount, 'payment_method' => $payment_method ) );
		return array( 'voucher_id' => $voucher_id, 'voucher_code' => $code, 'amount_paid_kobo' => $amount, 'status' => 'ISSUED' );
	}

	private static function next_sequence() {
		global $wpdb;
		$options = $wpdb->options;
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$options} WHERE option_name = %s FOR UPDATE", 'school_pos_voucher_sequence' ) );
		$next    = $row ? absint( $row->option_value ) + 1 : 1;
		if ( $row ) {
			$updated = $wpdb->update( $options, array( 'option_value' => (string) $next ), array( 'option_name' => 'school_pos_voucher_sequence' ), array( '%s' ), array( '%s' ) );
		} else {
			$updated = $wpdb->insert( $options, array( 'option_name' => 'school_pos_voucher_sequence', 'option_value' => (string) $next, 'autoload' => 'no' ), array( '%s', '%s', '%s' ) );
		}
		return false === $updated ? new WP_Error( 'sequence_failed', 'The voucher sequence could not be reserved.' ) : $next;
	}

	private static function audit( $event_type, $voucher_id, $code, array $metadata ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'school_pos_audit_events',
			array( 'event_type' => $event_type, 'actor_user_id' => get_current_user_id(), 'entity_type' => 'voucher', 'entity_id' => $voucher_id, 'reference_code' => $code, 'ip_address' => School_POS_Security::client_ip(), 'user_agent' => School_POS_Security::user_agent(), 'metadata_json' => wp_json_encode( $metadata ), 'created_at' => current_time( 'mysql', true ) ),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
