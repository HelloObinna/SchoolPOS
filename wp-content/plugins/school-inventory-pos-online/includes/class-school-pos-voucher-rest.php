<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Voucher_REST {
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'school-pos/v1', '/vouchers', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'issue' ), 'permission_callback' => array( __CLASS__, 'can_issue' ) ) );
		register_rest_route( 'school-pos/v1', '/sales', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'sale' ), 'permission_callback' => array( __CLASS__, 'can_sell' ) ) );
	}

	public static function can_issue() {
		return is_user_logged_in() && current_user_can( 'record_bursar_payment' );
	}

	public static function can_sell() {
		return is_user_logged_in() && current_user_can( 'operate_pos_checkout' ) && current_user_can( 'remit_bursar_voucher' );
	}

	public static function issue( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$result = School_POS_Vouchers::issue(
			array(
				'student_full_name' => isset( $params['student_full_name'] ) ? $params['student_full_name'] : '',
				'admission_number'  => isset( $params['admission_number'] ) ? $params['admission_number'] : '',
				'class_grade'       => isset( $params['class_grade'] ) ? $params['class_grade'] : '',
				'amount_paid_kobo'  => isset( $params['amount_paid_kobo'] ) ? $params['amount_paid_kobo'] : 0,
				'payment_method'    => isset( $params['payment_method'] ) ? $params['payment_method'] : '',
				'teller_ref_no'     => isset( $params['teller_ref_no'] ) ? $params['teller_ref_no'] : '',
			)
		);
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	public static function sale( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$result = School_POS_Checkout::complete( isset( $params['voucher_code'] ) ? $params['voucher_code'] : '', isset( $params['lines'] ) && is_array( $params['lines'] ) ? $params['lines'] : array() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}
}
