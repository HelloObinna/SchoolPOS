<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Chain_REST {
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'school-pos/v1', '/intakes', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create_intake' ), 'permission_callback' => array( __CLASS__, 'can_log_intake' ) ) );
		register_rest_route( 'school-pos/v1', '/intakes/(?P<id>\d+)/accept', array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'accept_intake' ), 'permission_callback' => array( __CLASS__, 'can_accept_warehouse' ) ) );
		register_rest_route( 'school-pos/v1', '/intakes/(?P<id>\d+)/discrepancy', array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'flag_discrepancy' ), 'permission_callback' => array( __CLASS__, 'can_accept_warehouse' ) ) );
		register_rest_route( 'school-pos/v1', '/handovers/store', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'dispatch_store' ), 'permission_callback' => array( __CLASS__, 'can_dispatch' ) ) );
		register_rest_route( 'school-pos/v1', '/handovers/(?P<id>\d+)/accept', array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'accept_store' ), 'permission_callback' => array( __CLASS__, 'can_accept_store' ) ) );
	}

	public static function can_log_intake() {
		return is_user_logged_in() && current_user_can( 'log_stock_intake' );
	}

	public static function can_accept_warehouse() {
		return is_user_logged_in() && current_user_can( 'receive_warehouse_handover' );
	}

	public static function can_dispatch() {
		return is_user_logged_in() && current_user_can( 'initiate_store_handover' );
	}

	public static function can_accept_store() {
		return is_user_logged_in() && current_user_can( 'receive_store_handover' );
	}

	public static function create_intake( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$result = School_POS_Inventory::create_intake(
			array(
				'product_id'     => isset( $params['product_id'] ) ? $params['product_id'] : 0,
				'supplier_name'  => isset( $params['supplier_name'] ) ? $params['supplier_name'] : '',
				'waybill_ref'    => isset( $params['waybill_ref'] ) ? $params['waybill_ref'] : '',
				'quantity_packs' => isset( $params['quantity_packs'] ) ? $params['quantity_packs'] : 0,
				'unit_cost_kobo' => isset( $params['unit_cost_kobo'] ) ? $params['unit_cost_kobo'] : 0,
			)
		);
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	public static function accept_intake( WP_REST_Request $request ) {
		$result = School_POS_Inventory::accept_intake( $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function flag_discrepancy( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$result = School_POS_Inventory::flag_discrepancy( $request['id'], isset( $params['note'] ) ? $params['note'] : '' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public static function dispatch_store( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$result = School_POS_Inventory::dispatch_to_store( isset( $params['product_id'] ) ? $params['product_id'] : 0, isset( $params['quantity_base_units'] ) ? $params['quantity_base_units'] : 0 );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	public static function accept_store( WP_REST_Request $request ) {
		$result = School_POS_Inventory::accept_store_handover( $request['id'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
