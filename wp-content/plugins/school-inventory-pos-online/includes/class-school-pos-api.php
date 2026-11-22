<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_API {
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'school-pos/v1', '/dashboard', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'dashboard' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'school-pos/v1', '/products', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'products' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'school-pos/v1', '/products', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_product' ), 'permission_callback' => array( __CLASS__, 'admin_product' ) ) );
		register_rest_route( 'school-pos/v1', '/products/(?P<id>\d+)', array( 'methods' => array( 'POST', 'PUT', 'PATCH' ), 'callback' => array( __CLASS__, 'update_product' ), 'permission_callback' => array( __CLASS__, 'admin_product' ) ) );
		register_rest_route( 'school-pos/v1', '/intakes', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_intake' ), 'permission_callback' => array( __CLASS__, 'cap_log_intake' ) ) );
		register_rest_route( 'school-pos/v1', '/intakes', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'intakes' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'school-pos/v1', '/intakes/(?P<id>\d+)/accept', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'accept_intake' ), 'permission_callback' => array( __CLASS__, 'cap_receive_warehouse' ) ) );
		register_rest_route( 'school-pos/v1', '/intakes/(?P<id>\d+)/discrepancy', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'discrepancy' ), 'permission_callback' => array( __CLASS__, 'cap_receive_warehouse' ) ) );
		register_rest_route( 'school-pos/v1', '/handovers', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'dispatch' ), 'permission_callback' => array( __CLASS__, 'cap_dispatch' ) ) );
		register_rest_route( 'school-pos/v1', '/handovers', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'handovers' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'school-pos/v1', '/handovers/(?P<id>\d+)/accept', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'accept_handover' ), 'permission_callback' => array( __CLASS__, 'cap_receive_store' ) ) );
		register_rest_route( 'school-pos/v1', '/vouchers', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'issue_voucher' ), 'permission_callback' => array( __CLASS__, 'cap_record_payment' ) ) );
		register_rest_route( 'school-pos/v1', '/vouchers/lookup', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'lookup_voucher' ), 'permission_callback' => array( __CLASS__, 'cap_redeem' ) ) );
		register_rest_route( 'school-pos/v1', '/sales', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'checkout' ), 'permission_callback' => array( __CLASS__, 'cap_checkout' ) ) );
		register_rest_route( 'school-pos/v1', '/sales', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'sales' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'school-pos/v1', '/audit', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'audit' ), 'permission_callback' => array( __CLASS__, 'cap_audit' ) ) );
		register_rest_route( 'school-pos/v1', '/balances', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'balances' ), 'permission_callback' => '__return_true' ) );
	}

	private static function signed_in() {
		return is_user_logged_in();
	}
	public static function admin_product( $request ) { return self::signed_in() && ( current_user_can( 'configure_school_pos' ) || current_user_can( 'manage_options' ) ); }
	public static function cap_log_intake( $request ) { return self::signed_in() && current_user_can( 'log_stock_intake'); }
	public static function cap_receive_warehouse( $request ) { return self::signed_in() && current_user_can( 'receive_warehouse_handover'); }
	public static function cap_dispatch( $request ) { return self::signed_in() && current_user_can( 'initiate_store_handover'); }
	public static function cap_receive_store( $request ) { return self::signed_in() && ( is_super_admin() || current_user_can( 'receive_store_handover') ); }
	public static function cap_record_payment( $request ) { return self::signed_in() && current_user_can( 'record_bursar_payment'); }
	public static function cap_redeem( $request ) { return self::signed_in() && current_user_can( 'remit_bursar_voucher'); }
	public static function cap_checkout( $request ) { return self::signed_in() && current_user_can( 'operate_pos_checkout') && current_user_can( 'remit_bursar_voucher'); }
	public static function cap_audit( $request ) { return self::signed_in() && ( is_super_admin() || current_user_can( 'view_master_audit_ledger' ) || current_user_can( 'oversee_all_transactions' ) ); }

	public static function dashboard( WP_REST_Request $request ) {
		if ( ! self::signed_in() ) return new WP_Error( 'login_required', 'Please sign in.', array( 'status' => 401 ) );
		global $wpdb;
		$p = $wpdb->prefix . 'school_pos_';
		$role = School_POS_Roles::user_role();
		$today = gmdate( 'Y-m-d 00:00:00' );
		$seven = gmdate( 'Y-m-d 00:00:00', strtotime( '-6 days', current_time( 'timestamp', true ) ) );
		$month = gmdate( 'Y-m-d 00:00:00', strtotime( '-29 days', current_time( 'timestamp', true ) ) );
		$custom_from = sanitize_text_field( $request->get_param( 'from' ) );
		$custom_to = sanitize_text_field( $request->get_param( 'to' ) );
		$sum_sales = static function ( $from, $to = '' ) use ( $wpdb, $p ) {
			$sql = "SELECT COALESCE(SUM(total_kobo),0) FROM {$p}sales WHERE status='COMPLETED' AND created_at>=%s";
			$args = array( $from );
			if ( $to ) {
				$sql .= ' AND created_at<=%s';
				$args[] = $to;
			}
			if ( $to ) {
				return (int) $wpdb->get_var( $wpdb->prepare( $sql, $from, $to ) );
			}
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $from ) );
		};
		$today_sales = $sum_sales( $today );
		$seven_sales = $sum_sales( $seven );
		$month_sales = $sum_sales( $month );
		$custom_sales = 0;
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $custom_from ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $custom_to ) ) {
			$custom_sales = $sum_sales( $custom_from . ' 00:00:00', $custom_to . ' 23:59:59' );
		}
		$warehouse_pieces = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity_base_units),0) FROM {$p}stock_balances WHERE location='WAREHOUSE'" );
		$store_pieces = (int) $wpdb->get_var( "SELECT COALESCE(SUM(quantity_base_units),0) FROM {$p}stock_balances WHERE location='STOREFRONT'" );
		$stock_rows = $wpdb->get_results( "SELECT p.product_id,p.name,p.bulk_unit_label,p.base_unit_label,p.units_per_pack,COALESCE(w.quantity_base_units,0) AS warehouse_units,COALESCE(s.quantity_base_units,0) AS storefront_units FROM {$p}products p LEFT JOIN {$p}stock_balances w ON w.product_id=p.product_id AND w.location='WAREHOUSE' LEFT JOIN {$p}stock_balances s ON s.product_id=p.product_id AND s.location='STOREFRONT' WHERE p.is_active=1 ORDER BY p.name", ARRAY_A );
		$data = array(
			'role' => $role,
			'counts' => array(
				'pending_intakes' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}intakes WHERE status='PENDING_WAREHOUSE_HANDOVER'" ),
				'pending_transfers' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}handovers WHERE status='PENDING'" ),
				'discrepancies' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}intakes WHERE status='DISCREPANCY'" ),
				'issued_vouchers' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}vouchers WHERE status='ISSUED'" ),
				'sales_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sales WHERE status='COMPLETED' AND created_at >= %s", gmdate( 'Y-m-d 00:00:00' ) ) ),
			),
			'sales' => array(
				'today_kobo' => $today_sales,
				'seven_days_kobo' => $seven_sales,
				'thirty_days_kobo' => $month_sales,
				'custom_kobo' => $custom_sales,
			),
			'stock' => array(
				'warehouse_base_units' => $warehouse_pieces,
				'storefront_base_units' => $store_pieces,
				'products' => $stock_rows,
			),
		);
		return rest_ensure_response( $data );
	}

	public static function products( WP_REST_Request $request ) {
		if ( ! self::signed_in() ) return new WP_Error( 'login_required', 'Please sign in.', array( 'status' => 401 ) );
		if ( ! ( is_super_admin() || current_user_can( 'configure_school_pos' ) || current_user_can( 'log_stock_intake') || current_user_can( 'manage_warehouse_stock') || current_user_can( 'operate_pos_checkout') || current_user_can( 'oversee_all_transactions') ) ) {
			return new WP_Error( 'forbidden', 'You are not authorized to view products.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}school_pos_products WHERE is_active=1 ORDER BY name", ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['pack_price_naira'] = School_POS_Core::naira( $row['pack_price_kobo'] );
			$row['piece_price_naira'] = School_POS_Core::naira( $row['piece_price_kobo'] );
		}
		return rest_ensure_response( $rows );
	}

	public static function create_product( WP_REST_Request $request ) {
		global $wpdb;
		$json = $request->get_json_params();
		$name = sanitize_text_field( isset( $json['name'] ) ? $json['name'] : '' );
		if ( '' === $name ) return new WP_Error( 'invalid_product', 'Product name is required.', array( 'status' => 422 ) );
		$units = max( 1, absint( isset( $json['units_per_pack'] ) ? $json['units_per_pack'] : 1 ) );
		$now = current_time( 'mysql', true );
		$sku = strtoupper( sanitize_title( isset( $json['sku'] ) ? $json['sku'] : $name ) );
		$pack_naira = isset( $json['pack_price_naira'] ) ? (float) $json['pack_price_naira'] : 0;
		$piece_naira = isset( $json['piece_price_naira'] ) ? (float) $json['piece_price_naira'] : 0;
		$pack_kobo = isset( $json['pack_price_kobo'] ) ? absint( $json['pack_price_kobo'] ) : (int) round( $pack_naira * 100 );
		$piece_kobo = isset( $json['piece_price_kobo'] ) ? absint( $json['piece_price_kobo'] ) : (int) round( $piece_naira * 100 );
		$ok = $wpdb->insert( $wpdb->prefix . 'school_pos_products',
			array( 'sku' => $sku, 'name' => $name, 'category' => sanitize_key( isset( $json['category'] ) ? $json['category'] : 'general' ), 'bulk_unit_label' => sanitize_text_field( isset( $json['bulk_unit_label'] ) ? $json['bulk_unit_label'] : 'Pack' ), 'base_unit_label' => sanitize_text_field( isset( $json['base_unit_label'] ) ? $json['base_unit_label'] : 'Piece' ), 'units_per_pack' => $units, 'pack_price_kobo' => $pack_kobo, 'piece_price_kobo' => $piece_kobo, 'low_stock_threshold_base_units' => absint( isset( $json['low_stock_threshold'] ) ? $json['low_stock_threshold'] : 0 ), 'is_active' => 1, 'created_by' => get_current_user_id(), 'updated_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ),
			array( '%s','%s','%s','%s','%s','%d','%d','%d','%d','%d','%d','%d','%s','%s' ) );
		if ( false === $ok ) return new WP_Error( 'product_failed', 'Product could not be created.', array( 'status' => 409 ) );
		$id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'school_pos_stock_balances', array( 'location' => 'WAREHOUSE', 'product_id' => $id, 'quantity_base_units' => 0, 'updated_at' => $now ), array( '%s','%d','%d','%s' ) );
		$wpdb->insert( $wpdb->prefix . 'school_pos_stock_balances', array( 'location' => 'STOREFRONT', 'product_id' => $id, 'quantity_base_units' => 0, 'updated_at' => $now ), array( '%s','%d','%d','%s' ) );
		School_POS_Security::audit( 'product_created', 'product', $id, $sku, array( 'name' => $name ) );
		return rest_ensure_response( array( 'product_id' => $id, 'sku' => $sku ) );
	}

	public static function update_product( WP_REST_Request $request ) {
		global $wpdb;
		$id = absint( $request['id'] );
		$j = $request->get_json_params();
		$product = $wpdb->get_row( $wpdb->prepare( "SELECT product_id FROM {$wpdb->prefix}school_pos_products WHERE product_id=%d", $id ) );
		if ( ! $product ) return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
		$fields = array();
		$formats = array();
		if ( isset( $j['name'] ) ) { $fields['name'] = sanitize_text_field( $j['name'] ); $formats[] = '%s'; }
		if ( isset( $j['sku'] ) ) { $fields['sku'] = strtoupper( sanitize_title( $j['sku'] ) ); $formats[] = '%s'; }
		if ( isset( $j['category'] ) ) { $fields['category'] = sanitize_key( $j['category'] ); $formats[] = '%s'; }
		if ( isset( $j['units_per_pack'] ) ) { $fields['units_per_pack'] = max( 1, absint( $j['units_per_pack'] ) ); $formats[] = '%d'; }
		if ( isset( $j['bulk_unit_label'] ) ) { $fields['bulk_unit_label'] = sanitize_text_field( $j['bulk_unit_label'] ); $formats[] = '%s'; }
		if ( isset( $j['base_unit_label'] ) ) { $fields['base_unit_label'] = sanitize_text_field( $j['base_unit_label'] ); $formats[] = '%s'; }
		if ( isset( $j['pack_price_naira'] ) ) { $fields['pack_price_kobo'] = (int) round( (float) $j['pack_price_naira'] * 100 ); $formats[] = '%d'; }
		elseif ( isset( $j['pack_price_kobo'] ) ) { $fields['pack_price_kobo'] = absint( $j['pack_price_kobo'] ); $formats[] = '%d'; }
		if ( isset( $j['piece_price_naira'] ) ) { $fields['piece_price_kobo'] = (int) round( (float) $j['piece_price_naira'] * 100 ); $formats[] = '%d'; }
		elseif ( isset( $j['piece_price_kobo'] ) ) { $fields['piece_price_kobo'] = absint( $j['piece_price_kobo'] ); $formats[] = '%d'; }
		if ( isset( $j['low_stock_threshold'] ) ) { $fields['low_stock_threshold_base_units'] = absint( $j['low_stock_threshold'] ); $formats[] = '%d'; }
		if ( isset( $j['is_active'] ) ) { $fields['is_active'] = absint( $j['is_active'] ) ? 1 : 0; $formats[] = '%d'; }
		if ( empty( $fields ) ) return new WP_Error( 'no_product_changes', 'No product changes supplied.', array( 'status' => 422 ) );
		$fields['updated_by'] = get_current_user_id();
		$formats[] = '%d';
		$fields['updated_at'] = current_time( 'mysql', true );
		$formats[] = '%s';
		$updated = $wpdb->update( $wpdb->prefix . 'school_pos_products', $fields, array( 'product_id' => $id ), $formats, array( '%d' ) );
		if ( false === $updated ) return new WP_Error( 'product_update_failed', 'Product could not be updated.', array( 'status' => 409 ) );
		School_POS_Security::audit( 'product_updated', 'product', $id, (string) $id, $j );
		return rest_ensure_response( array( 'product_id' => $id, 'updated' => true ) );
	}

	public static function create_intake( WP_REST_Request $request ) {
		$j = $request->get_json_params();
		$product_id = absint( isset( $j['product_id'] ) ? $j['product_id'] : 0 );
		$quantity = absint( isset( $j['quantity_packs'] ) ? $j['quantity_packs'] : 0 );
		if ( ! $product_id || $quantity < 1 ) return new WP_Error( 'invalid_intake', 'Select a product and enter a positive pack quantity.', array( 'status' => 422 ) );
		global $wpdb;
		$product = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_products WHERE product_id=%d AND is_active=1", $product_id ) );
		if ( ! $product ) return new WP_Error( 'invalid_product', 'Product not found.', array( 'status' => 404 ) );
		$base = $quantity * (int) $product->units_per_pack;
		$code = School_POS_Core::reference( 'INT' );
		$now = current_time( 'mysql', true );
		$unit_cost_naira = isset( $j['unit_cost_naira'] ) ? (float) $j['unit_cost_naira'] : 0;
		$unit_cost_kobo = isset( $j['unit_cost_kobo'] ) ? absint( $j['unit_cost_kobo'] ) : (int) round( $unit_cost_naira * 100 );
		$ok = $wpdb->insert( $wpdb->prefix . 'school_pos_intakes',
			array( 'intake_code' => $code, 'product_id' => $product_id, 'supplier_name' => sanitize_text_field( isset( $j['supplier_name'] ) ? $j['supplier_name'] : '' ), 'waybill_ref' => sanitize_text_field( isset( $j['waybill_ref'] ) ? $j['waybill_ref'] : '' ), 'units_per_pack_snapshot' => (int) $product->units_per_pack, 'quantity_packs' => $quantity, 'quantity_base_units' => $base, 'unit_cost_kobo' => $unit_cost_kobo, 'status' => 'PENDING_WAREHOUSE_HANDOVER', 'submitted_by' => get_current_user_id(), 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now ),
			array( '%s','%d','%s','%s','%d','%d','%d','%d','%s','%d','%s','%s','%s' ) );
		if ( false === $ok ) return new WP_Error( 'intake_failed', 'Stock intake could not be saved.', array( 'status' => 409 ) );
		$id = (int) $wpdb->insert_id;
		School_POS_Security::audit( 'intake_submitted', 'intake', $id, $code, array( 'base_units' => $base ) );
		return rest_ensure_response( array( 'intake_id' => $id, 'intake_code' => $code ) );
	}

	public static function intakes( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) return new WP_Error( 'login_required', 'Please sign in.', array( 'status' => 401 ) );
		if ( ! ( is_super_admin() || current_user_can( 'view_own_intakes') || current_user_can( 'receive_warehouse_handover') || current_user_can( 'view_secretary_records') || current_user_can( 'oversee_all_transactions') ) ) {
			return new WP_Error( 'forbidden', 'You are not authorized to view intakes.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p = $wpdb->prefix . 'school_pos_';
		if ( current_user_can( 'view_own_intakes' ) && ! current_user_can( 'oversee_all_transactions' ) && ! current_user_can( 'view_secretary_records' ) ) {
			$where = $wpdb->prepare( 'WHERE i.submitted_by=%d', get_current_user_id() );
		} else {
			$where = 'WHERE 1=1';
		}
		$rows = $wpdb->get_results( "SELECT i.*, p.name AS product_name FROM {$p}intakes i JOIN {$p}products p ON p.product_id=i.product_id $where ORDER BY i.intake_id DESC", ARRAY_A );
		return rest_ensure_response( $rows );
	}

	public static function accept_intake( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'school_pos_intakes';
		$id = absint( $request['id'] );
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE intake_id=%d FOR UPDATE", $id ) );
		if ( ! $row || 'PENDING_WAREHOUSE_HANDOVER' !== $row->status ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'invalid_state', 'Intake is not pending.', array( 'status' => 409 ) ); }
		$btable = $wpdb->prefix . 'school_pos_stock_balances';
		$balance = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$btable} WHERE location='WAREHOUSE' AND product_id=%d FOR UPDATE", (int) $row->product_id ) );
		if ( ! $balance ) { $wpdb->insert( $btable, array( 'location'=>'WAREHOUSE','product_id'=>$row->product_id,'quantity_base_units'=>0,'updated_at'=>current_time('mysql',true)), array('%s','%d','%d','%s') ); $balance = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$btable} WHERE location='WAREHOUSE' AND product_id=%d FOR UPDATE", (int) $row->product_id ) ); }
		$before = (int) $balance->quantity_base_units;
		$after = $before + (int) $row->quantity_base_units;
		$now = current_time( 'mysql', true );
		if ( false === $wpdb->update( $btable, array( 'quantity_base_units'=>$after,'updated_at'=>$now ), array( 'balance_id'=>$balance->balance_id ), array('%d','%s'), array('%d') ) ) { $wpdb->query('ROLLBACK'); return new WP_Error('stock_failed','Stock balance could not update.',array('status'=>409)); }
		$wpdb->update( $table, array( 'status'=>'ACCEPTED','verified_by'=>get_current_user_id(),'verified_at'=>$now,'updated_at'=>$now ), array('intake_id'=>$id), array('%s','%d','%s','%s'), array('%d') );
		$ledger_ok = $wpdb->insert( $wpdb->prefix.'school_pos_stock_ledger',
			array('location'=>'WAREHOUSE','product_id'=>$row->product_id,'action_type'=>'INTAKE_ACCEPTED','qty_change_base_units'=>$row->quantity_base_units,'balance_before'=>$before,'balance_after'=>$after,'performed_by_user_id'=>get_current_user_id(),'ip_address'=>School_POS_Security::ip(),'user_agent'=>School_POS_Security::user_agent(),'reference_code'=>$row->intake_code,'notes'=>'Secretary intake accepted.','created_at'=>$now),
			array('%s','%d','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s') );
		if ( false === $ledger_ok ) { $wpdb->query('ROLLBACK'); return new WP_Error('ledger_failed','Ledger could not be written.',array('status'=>409)); }
		$wpdb->query('COMMIT');
		School_POS_Security::audit( 'warehouse_handover_accepted','intake',$id,$row->intake_code,array('balance_after'=>$after) );
		return rest_ensure_response( array( 'intake_id'=>$id,'status'=>'ACCEPTED','warehouse_balance'=>$after ) );
	}

	public static function discrepancy( WP_REST_Request $request ) {
		global $wpdb;
		$j = $request->get_json_params();
		$id = absint( $request['id'] );
		$table = $wpdb->prefix.'school_pos_intakes';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT intake_id,intake_code,status FROM {$table} WHERE intake_id=%d", $id ) );
		if ( ! $row || 'PENDING_WAREHOUSE_HANDOVER' !== $row->status ) return new WP_Error('invalid_state','Intake is not pending.',array('status'=>409));
		$note = sanitize_textarea_field( isset($j['note']) ? $j['note'] : '' );
		$now = current_time('mysql',true);
		$wpdb->update($table,array('status'=>'DISCREPANCY','verified_by'=>get_current_user_id(),'verified_at'=>$now,'discrepancy_note'=>$note,'updated_at'=>$now),array('intake_id'=>$id),array('%s','%d','%s','%s','%s'),array('%d'));
		School_POS_Security::audit('handover_discrepancy_flagged','intake',$id,$row->intake_code,array('note'=>$note));
		School_POS_Core::alert('SchoolPOS handover discrepancy: '.$row->intake_code,$note);
		return rest_ensure_response(array('intake_id'=>$id,'status'=>'DISCREPANCY'));
	}

	public static function dispatch( WP_REST_Request $request ) {
		global $wpdb;
		$j = $request->get_json_params();
		$product_id = absint(isset($j['product_id'])?$j['product_id']:0);
		$quantity = absint(isset($j['quantity_base_units'])?$j['quantity_base_units']:0);
		if (!$product_id || !$quantity) return new WP_Error('invalid_transfer','Product and quantity are required.',array('status'=>422));
		$btable=$wpdb->prefix.'school_pos_stock_balances';
		$wpdb->query('START TRANSACTION');
		$balance=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$btable} WHERE location='WAREHOUSE' AND product_id=%d FOR UPDATE",$product_id));
		if(!$balance || (int)$balance->quantity_base_units<$quantity){$wpdb->query('ROLLBACK');return new WP_Error('insufficient_stock','Warehouse stock is insufficient.',array('status'=>409));}
		$before=(int)$balance->quantity_base_units;$after=$before-$quantity;$now=current_time('mysql',true);
		$wpdb->update($btable,array('quantity_base_units'=>$after,'updated_at'=>$now),array('balance_id'=>$balance->balance_id),array('%d','%s'),array('%d'));
		$code=School_POS_Core::reference('TRF');
		$ok=$wpdb->insert($wpdb->prefix.'school_pos_handovers',array('handover_code'=>$code,'handover_type'=>'WAREHOUSE_STOREFRONT','source_record_id'=>0,'product_id'=>$product_id,'quantity_base_units'=>$quantity,'status'=>'PENDING','initiated_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now),array('%s','%s','%d','%d','%d','%s','%d','%s','%s'));
		if(false==$ok){$wpdb->query('ROLLBACK');return new WP_Error('transfer_failed','Transfer could not be created.',array('status'=>409));}
		$id=(int)$wpdb->insert_id;
		$ledger=$wpdb->insert($wpdb->prefix.'school_pos_stock_ledger',array('location'=>'WAREHOUSE','product_id'=>$product_id,'action_type'=>'STORE_DISPATCH','qty_change_base_units'=>-$quantity,'balance_before'=>$before,'balance_after'=>$after,'performed_by_user_id'=>get_current_user_id(),'ip_address'=>School_POS_Security::ip(),'user_agent'=>School_POS_Security::user_agent(),'reference_code'=>$code,'notes'=>'Reserved for store handover.','created_at'=>$now),array('%s','%d','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s'));
		if(false==$ledger){$wpdb->query('ROLLBACK');return new WP_Error('ledger_failed','Ledger could not be written.',array('status'=>409));}
		$wpdb->query('COMMIT');
		School_POS_Security::audit('store_handover_initiated','handover',$id,$code,array('quantity'=>$quantity));
		return rest_ensure_response(array('handover_id'=>$id,'handover_code'=>$code,'warehouse_balance'=>$after));
	}

	public static function handovers( WP_REST_Request $request ) {
		if(!is_user_logged_in()) return new WP_Error('login_required','Please sign in.',array('status'=>401));
		if(! (is_super_admin()||current_user_can('initiate_store_handover')||current_user_can('receive_store_handover')||current_user_can('oversee_all_transactions'))) return new WP_Error('forbidden','You are not authorized to view transfers.',array('status'=>403));
		global $wpdb;$p=$wpdb->prefix.'school_pos_';
		$rows=$wpdb->get_results("SELECT h.*,p.name AS product_name FROM {$p}handovers h JOIN {$p}products p ON p.product_id=h.product_id ORDER BY h.handover_id DESC",ARRAY_A);
		return rest_ensure_response($rows);
	}

	public static function accept_handover( WP_REST_Request $request ) {
		global $wpdb;$table=$wpdb->prefix.'school_pos_handovers';$id=absint($request['id']);
		$wpdb->query('START TRANSACTION');
		$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE handover_id=%d FOR UPDATE",$id));
		if(!$row||'PENDING'!==$row->status){$wpdb->query('ROLLBACK');return new WP_Error('invalid_state','Transfer is not pending.',array('status'=>409));}
		$btable=$wpdb->prefix.'school_pos_stock_balances';
		$balance=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$btable} WHERE location='STOREFRONT' AND product_id=%d FOR UPDATE",(int)$row->product_id));
		if(!$balance){$wpdb->insert($btable,array('location'=>'STOREFRONT','product_id'=>$row->product_id,'quantity_base_units'=>0,'updated_at'=>current_time('mysql',true)),array('%s','%d','%d','%s'));$balance=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$btable} WHERE location='STOREFRONT' AND product_id=%d FOR UPDATE",(int)$row->product_id));}
		$before=(int)$balance->quantity_base_units;$after=$before+(int)$row->quantity_base_units;$now=current_time('mysql',true);
		$wpdb->update($btable,array('quantity_base_units'=>$after,'updated_at'=>$now),array('balance_id'=>$balance->balance_id),array('%d','%s'),array('%d'));
		$wpdb->update($table,array('status'=>'ACCEPTED','accepted_by'=>get_current_user_id(),'updated_at'=>$now),array('handover_id'=>$id),array('%s','%d','%s'),array('%d'));
		$ledger=$wpdb->insert($wpdb->prefix.'school_pos_stock_ledger',array('location'=>'STOREFRONT','product_id'=>$row->product_id,'action_type'=>'STORE_ACCEPTED','qty_change_base_units'=>$row->quantity_base_units,'balance_before'=>$before,'balance_after'=>$after,'performed_by_user_id'=>get_current_user_id(),'ip_address'=>School_POS_Security::ip(),'user_agent'=>School_POS_Security::user_agent(),'reference_code'=>$row->handover_code,'notes'=>'Store accepted warehouse handover.','created_at'=>$now),array('%s','%d','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s'));
		if(false==$ledger){$wpdb->query('ROLLBACK');return new WP_Error('ledger_failed','Ledger could not be written.',array('status'=>409));}
		$wpdb->query('COMMIT');
		School_POS_Security::audit('store_handover_accepted','handover',$id,$row->handover_code,array('balance_after'=>$after));
		$product=$wpdb->get_row($wpdb->prepare("SELECT name,low_stock_threshold_base_units FROM {$wpdb->prefix}school_pos_products WHERE product_id=%d",(int)$row->product_id));
		if($product&&(int)$product->low_stock_threshold_base_units>0&&$after<(int)$product->low_stock_threshold_base_units)School_POS_Core::alert('SchoolPOS low stock: '.$product->name,'Storefront stock is '.$after.' base units.');
		return rest_ensure_response(array('handover_id'=>$id,'status'=>'ACCEPTED','storefront_balance'=>$after));
	}

	public static function issue_voucher( WP_REST_Request $request ) {
		global $wpdb;
		$j = $request->get_json_params();
		$amount_naira = isset( $j['amount_paid_naira'] ) ? (float) $j['amount_paid_naira'] : 0;
		$amount = isset( $j['amount_paid_kobo'] ) ? absint( $j['amount_paid_kobo'] ) : (int) round( $amount_naira * 100 );
		$name = sanitize_text_field( isset( $j['student_full_name'] ) ? $j['student_full_name'] : '' );
		$admission = sanitize_text_field( isset( $j['admission_number'] ) ? $j['admission_number'] : '' );
		$class = sanitize_text_field( isset( $j['class_grade'] ) ? $j['class_grade'] : '' );
		$method = sanitize_key( isset( $j['payment_method'] ) ? $j['payment_method'] : '' );
		if ( $amount < 1 || '' === $name || '' === $admission || '' === $class ) {
			return new WP_Error( 'invalid_voucher', 'Student, admission number, class, and amount are required.', array( 'status' => 422 ) );
		}
		if ( ! in_array( $method, array( 'cash', 'bank_transfer', 'teller' ), true ) ) {
			return new WP_Error( 'invalid_method', 'Choose cash, bank transfer, or teller.', array( 'status' => 422 ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		$sequence_row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", 'school_pos_voucher_sequence' ) );
		$sequence = $sequence_row ? absint( $sequence_row->option_value ) + 1 : 1;
		$updated = $wpdb->update( $wpdb->options, array( 'option_value' => (string) $sequence ), array( 'option_name' => 'school_pos_voucher_sequence' ), array( '%s' ), array( '%s' ) );
		if ( false === $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sequence_failed', 'Voucher sequence could not be reserved.', array( 'status' => 409 ) );
		}
		try {
			$random = bin2hex( random_bytes( 3 ) );
		} catch ( Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'secure_random_failed', 'Secure voucher generation is unavailable.', array( 'status' => 500 ) );
		}
		$code = sprintf( 'VCH-%s-%s-%05d', gmdate( 'Y' ), strtoupper( $random ), $sequence );
		$now = current_time( 'mysql', true );
		$ok = $wpdb->insert(
			$wpdb->prefix . 'school_pos_vouchers',
			array( 'voucher_code'=>$code,'code_digest'=>hash('sha256',$code),'sequence_no'=>$sequence,'student_full_name'=>$name,'admission_number'=>$admission,'class_grade'=>$class,'amount_paid_kobo'=>$amount,'remaining_kobo'=>$amount,'payment_method'=>$method,'teller_ref_no'=>sanitize_text_field(isset($j['teller_ref_no'])?$j['teller_ref_no']:''),'status'=>'ISSUED','issued_by'=>get_current_user_id(),'issued_at'=>$now,'issued_ip'=>School_POS_Security::ip(),'issued_user_agent'=>School_POS_Security::user_agent() ),
			array( '%s','%s','%d','%s','%s','%s','%d','%d','%s','%s','%s','%d','%s','%s','%s' )
		);
		if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'voucher_failed', 'Voucher could not be issued.', array( 'status' => 409 ) ); }
		$id = (int) $wpdb->insert_id;
		$wpdb->query( 'COMMIT' );
		School_POS_Security::audit( 'voucher_issued', 'voucher', $id, $code, array( 'amount_paid_kobo' => $amount, 'payment_method' => $method ) );
		return rest_ensure_response( array( 'voucher_id'=>$id,'voucher_code'=>$code,'amount_paid_kobo'=>$amount,'status'=>'ISSUED','student_full_name'=>$name,'admission_number'=>$admission,'class_grade'=>$class ) );
	}

	public static function lookup_voucher( WP_REST_Request $request ) {
		$code = strtoupper( sanitize_text_field( $request->get_param( 'code' ) ) );
		if ( '' === $code ) return new WP_Error( 'invalid_code', 'Voucher code is required.', array( 'status' => 422 ) );
		$window_key = 'school_pos_lookup_user_' . get_current_user_id();
		$ip_key = 'school_pos_lookup_ip_' . md5( School_POS_Security::ip() );
		foreach ( array( $window_key, $ip_key ) as $key ) {
			$count = (int) get_transient( $key );
			if ( $count >= 10 ) return new WP_Error( 'rate_limited', 'Too many lookup attempts. Try again later.', array( 'status' => 429 ) );
			set_transient( $key, $count + 1, 60 );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT voucher_id,voucher_code,student_full_name,admission_number,class_grade,amount_paid_kobo,remaining_kobo,status FROM {$wpdb->prefix}school_pos_vouchers WHERE voucher_code=%s LIMIT 1", $code ), ARRAY_A );
		if ( ! $row ) {
			School_POS_Security::audit( 'voucher_lookup_failed', 'voucher', 0, $code, array() );
			return new WP_Error( 'invalid_voucher', 'Voucher code is invalid or unavailable.', array( 'status' => 404 ) );
		}
		School_POS_Security::audit( 'voucher_lookup_succeeded', 'voucher', (int) $row['voucher_id'], $code, array() );
		return rest_ensure_response( $row );
	}

	public static function checkout( WP_REST_Request $request ) {
		global $wpdb;
		$j = $request->get_json_params();
		$code = strtoupper( sanitize_text_field( isset( $j['voucher_code'] ) ? $j['voucher_code'] : '' ) );
		$lines = isset( $j['lines'] ) && is_array( $j['lines'] ) ? $j['lines'] : array();
		if ( '' === $code || empty( $lines ) ) return new WP_Error( 'invalid_sale', 'Voucher and at least one line are required.', array( 'status' => 422 ) );
		$merged = array();
		foreach ( $lines as $line ) {
			$product_id = absint( isset( $line['product_id'] ) ? $line['product_id'] : 0 );
			$quantity = absint( isset( $line['quantity'] ) ? $line['quantity'] : 0 );
			$unit = strtoupper( sanitize_key( isset( $line['unit_type'] ) ? $line['unit_type'] : '' ) );
			if ( ! $product_id || $quantity < 1 || ! in_array( $unit, array( 'PACK', 'PIECE' ), true ) ) return new WP_Error( 'invalid_line', 'Invalid cart line.', array( 'status' => 422 ) );
			$key = $product_id . ':' . $unit;
			$merged[$key]['product_id'] = $product_id;
			$merged[$key]['unit_type'] = $unit;
			$merged[$key]['quantity'] = isset( $merged[$key] ) ? (int) $merged[$key]['quantity'] + $quantity : $quantity;
		}
		$wpdb->query( 'START TRANSACTION' );
		$vtable = $wpdb->prefix . 'school_pos_vouchers';
		$voucher = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$vtable} WHERE voucher_code=%s FOR UPDATE", $code ) );
		if ( ! $voucher || 'ISSUED' !== $voucher->status ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'voucher_unavailable', 'Voucher is invalid or already redeemed.', array( 'status' => 409 ) ); }
		$subtotal = 0;
		$prepared = array();
		foreach ( $merged as $line ) {
			$product = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_products WHERE product_id=%d AND is_active=1", $line['product_id'] ) );
			if ( ! $product ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'invalid_product', 'A cart product is unavailable.', array( 'status' => 409 ) ); }
			$unit_price = 'PACK' === $line['unit_type'] ? (int) $product->pack_price_kobo : (int) $product->piece_price_kobo;
			if ( $unit_price < 1 ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'missing_price', 'A cart product has no price for this unit.', array( 'status' => 409 ) ); }
			$base_units = 'PACK' === $line['unit_type'] ? $line['quantity'] * (int) $product->units_per_pack : $line['quantity'];
			$balance = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_stock_balances WHERE location='STOREFRONT' AND product_id=%d FOR UPDATE", $line['product_id'] ) );
			if ( ! $balance || (int) $balance->quantity_base_units < $base_units ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'insufficient_stock', 'Storefront stock is insufficient.', array( 'status' => 409 ) ); }
			$line_total = $line['quantity'] * $unit_price;
			$subtotal += $line_total;
			$prepared[] = array( 'product'=>$product,'line'=>$line,'unit_price'=>$unit_price,'base_units'=>$base_units,'line_total'=>$line_total,'balance'=>$balance );
		}
		$voucher_remaining = isset( $voucher->remaining_kobo ) ? (int) $voucher->remaining_kobo : (int) $voucher->amount_paid_kobo;
		if ( $voucher_remaining < $subtotal ) {
			$shortfall = $subtotal - $voucher_remaining;
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'voucher_shortfall', 'Voucher is short by ' . School_POS_Core::naira( $shortfall ) . '.', array( 'status' => 409 ) );
		}
		$now = current_time( 'mysql', true );
		$receipt = School_POS_Core::reference( 'RCT' );
		$ok = $wpdb->insert( $wpdb->prefix . 'school_pos_sales',
			array( 'receipt_no'=>$receipt,'voucher_id'=>$voucher->voucher_id,'voucher_code_snapshot'=>$voucher->voucher_code,'student_full_name_snapshot'=>$voucher->student_full_name,'admission_number_snapshot'=>$voucher->admission_number,'class_grade_snapshot'=>$voucher->class_grade,'seller_user_id'=>get_current_user_id(),'subtotal_kobo'=>$subtotal,'voucher_applied_kobo'=>$subtotal,'total_kobo'=>$subtotal,'status'=>'COMPLETED','ip_address'=>School_POS_Security::ip(),'user_agent'=>School_POS_Security::user_agent(),'created_at'=>$now,'updated_at'=>$now ),
			array( '%s','%d','%s','%s','%s','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s' ) );
		if ( false === $ok ) { $wpdb->query( 'ROLLBACK' ); return new WP_Error( 'sale_failed', 'Sale could not be saved.', array( 'status' => 409 ) ); }
		$sale_id = (int) $wpdb->insert_id;
		foreach ( $prepared as $item ) {
			$line = $item['line']; $product = $item['product']; $balance = $item['balance'];
			$wpdb->insert( $wpdb->prefix.'school_pos_sale_lines',
				array( 'sale_id'=>$sale_id,'product_id'=>$product->product_id,'product_name_snapshot'=>$product->name,'unit_type'=>$line['unit_type'],'quantity'=>$line['quantity'],'base_units_consumed'=>$item['base_units'],'unit_price_kobo'=>$item['unit_price'],'line_total_kobo'=>$item['line_total'],'pack_size_snapshot'=>$product->units_per_pack,'created_at'=>$now ),
				array( '%d','%d','%s','%s','%d','%d','%d','%d','%d','%s' ) );
			$after = (int) $balance->quantity_base_units - $item['base_units'];
			if ( false === $wpdb->update( $wpdb->prefix.'school_pos_stock_balances', array( 'quantity_base_units'=>$after,'updated_at'=>$now ), array( 'balance_id'=>$balance->balance_id ), array( '%d','%s'), array('%d') ) ) { $wpdb->query('ROLLBACK'); return new WP_Error('stock_failed','Stock update failed.',array('status'=>409)); }
			$ledger = $wpdb->insert( $wpdb->prefix.'school_pos_stock_ledger',
				array( 'location'=>'STOREFRONT','product_id'=>$product->product_id,'action_type'=>'POS_SALE','qty_change_base_units'=>-$item['base_units'],'balance_before'=>$balance->quantity_base_units,'balance_after'=>$after,'performed_by_user_id'=>get_current_user_id(),'ip_address'=>School_POS_Security::ip(),'user_agent'=>School_POS_Security::user_agent(),'reference_code'=>$receipt,'notes'=>'POS sale redeemed voucher '.$code.'.','created_at'=>$now ),
				array( '%s','%d','%s','%d','%d','%d','%d','%s','%s','%s','%s','%s' ) );
			if ( false === $ledger ) { $wpdb->query('ROLLBACK'); return new WP_Error('ledger_failed','Ledger could not be written.',array('status'=>409)); }
		}
		$new_remaining = $voucher_remaining - $subtotal;
		$voucher_status = $new_remaining > 0 ? 'ISSUED' : 'REDEEMED';
		$voucher_update = array(
			'remaining_kobo' => $new_remaining,
			'status' => $voucher_status,
		);
		$voucher_formats = array( '%d', '%s' );
		$voucher_update['redeemed_ip'] = School_POS_Security::ip();
		$voucher_update['redeemed_user_agent'] = School_POS_Security::user_agent();
		$voucher_formats[] = '%s';
		$voucher_formats[] = '%s';
		if ( $new_remaining === 0 ) {
			$voucher_update['redeemed_by'] = get_current_user_id();
			$voucher_update['redeemed_sale_id'] = $sale_id;
			$voucher_update['redeemed_at'] = $now;
			$voucher_formats[] = '%d';
			$voucher_formats[] = '%d';
			$voucher_formats[] = '%s';
		}
		$redeemed = $wpdb->update( $vtable, $voucher_update, array( 'voucher_id'=>$voucher->voucher_id,'status'=>'ISSUED' ), $voucher_formats, array('%d','%s') );
		if ( 1 !== $redeemed ) { $wpdb->query('ROLLBACK'); return new WP_Error('redeem_failed','Voucher could not be redeemed.',array('status'=>409)); }
		$wpdb->query('COMMIT');
		School_POS_Security::audit( 'pos_sale_completed','sale',$sale_id,$receipt,array('voucher_code'=>$code,'total_kobo'=>$subtotal) );
		return rest_ensure_response(array('sale_id'=>$sale_id,'receipt_no'=>$receipt,'total_kobo'=>$subtotal,'voucher_code'=>$code));
	}

	public static function sales( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) return new WP_Error('login_required','Please sign in.',array('status'=>401));
		if ( ! ( is_super_admin() || current_user_can('view_own_store_sales') || current_user_can('view_store_sales') || current_user_can('oversee_all_transactions') ) ) return new WP_Error('forbidden','You are not authorized to view sales.',array('status'=>403));
		global $wpdb;$p=$wpdb->prefix.'school_pos_';
		$where='WHERE 1=1';
		if ( current_user_can('view_own_store_sales') && !current_user_can('view_store_sales') && !current_user_can('oversee_all_transactions') ) $where=$wpdb->prepare('WHERE seller_user_id=%d',get_current_user_id());
		$rows=$wpdb->get_results("SELECT * FROM {$p}sales $where ORDER BY sale_id DESC LIMIT 100",ARRAY_A);
		return rest_ensure_response($rows);
	}

	public static function audit( WP_REST_Request $request ) {
		global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}school_pos_audit_events ORDER BY event_id DESC LIMIT 200",ARRAY_A);
		return rest_ensure_response($rows);
	}

	public static function balances( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) return new WP_Error('login_required','Please sign in.',array('status'=>401));
		global $wpdb;$p=$wpdb->prefix.'school_pos_';
		$role=School_POS_Roles::user_role();
		$location='store';
		if(in_array($role,array('warehouse_manager','administrator'),true))$location='warehouse';
		if(in_array($role,array('store_seller'),true))$location='store';
		$sql_location=$location==='store'?'STOREFRONT':'WAREHOUSE';
		$rows=$wpdb->get_results($wpdb->prepare("SELECT b.*,p.name,p.bulk_unit_label,p.base_unit_label,p.units_per_pack,p.pack_price_kobo,p.piece_price_kobo,p.low_stock_threshold_base_units FROM {$p}stock_balances b JOIN {$p}products p ON p.product_id=b.product_id WHERE b.location=%s ORDER BY p.name",$sql_location),ARRAY_A);
		return rest_ensure_response($rows);
	}
}
