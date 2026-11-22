<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Roles {
	public static function register() {
		$roles = array(
			'school_admin' => array( 'School Admin', array( 'oversee_all_transactions', 'view_secretary_records', 'view_warehouse_records', 'view_store_sales', 'view_bursar_vouchers', 'view_master_audit_ledger', 'resolve_stock_discrepancies', 'adjust_product_price', 'view_sales_dashboard' ) ),
			'school_secretary' => array( 'School Secretary', array( 'log_stock_intake', 'view_own_intakes', 'create_catalog_product', 'create_stock_order', 'set_initial_product_price' ) ),
			'warehouse_manager' => array( 'Warehouse Manager', array( 'receive_warehouse_handover', 'manage_warehouse_stock', 'initiate_store_handover', 'view_warehouse_ledger' ) ),
			'school_bursar' => array( 'School Bursar', array( 'record_bursar_payment', 'print_bursar_voucher', 'view_bursar_vouchers', 'view_sales_dashboard' ) ),
			'store_seller' => array( 'Store Seller', array( 'receive_store_handover', 'manage_storefront_stock', 'operate_pos_checkout', 'remit_bursar_voucher', 'view_own_store_sales' ) ),
		);
		foreach ( $roles as $slug => $role ) {
			$object = get_role( $slug );
			if ( ! $object ) {
				$object = add_role( $slug, $role[0], array( 'read' => true ) );
			}
			if ( $object ) {
				foreach ( $role[1] as $cap ) {
					$object->add_cap( $cap );
				}
			}
		}
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->add_cap( 'assign_school_roles' );
			$administrator->add_cap( 'configure_school_pos' );
		}
	}

	public static function user_role() {
		$user = wp_get_current_user();
		if ( in_array( 'administrator', $user->roles, true ) ) return 'administrator';
		foreach ( array( 'school_admin', 'school_secretary', 'warehouse_manager', 'school_bursar', 'store_seller' ) as $role ) {
			if ( in_array( $role, $user->roles, true ) ) return $role;
		}
		return '';
	}
}
