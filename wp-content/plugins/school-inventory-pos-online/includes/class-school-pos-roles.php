<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Roles {
	public static function register() {
		$roles = array(
			'school_admin'      => array(
				'label' => 'School Admin',
				'caps'  => array( 'oversee_all_transactions', 'view_secretary_records', 'view_warehouse_records', 'view_store_sales', 'view_bursar_vouchers', 'view_master_audit_ledger', 'resolve_stock_discrepancies' ),
			),
			'school_secretary'  => array(
				'label' => 'School Secretary',
				'caps'  => array( 'log_stock_intake', 'view_own_intakes' ),
			),
			'warehouse_manager' => array(
				'label' => 'Warehouse Manager',
				'caps'  => array( 'receive_warehouse_handover', 'manage_warehouse_stock', 'initiate_store_handover', 'view_warehouse_ledger' ),
			),
			'school_bursar'     => array(
				'label' => 'School Bursar',
				'caps'  => array( 'record_bursar_payment', 'print_bursar_voucher', 'view_bursar_vouchers' ),
			),
			'store_seller'      => array(
				'label' => 'Store Seller',
				'caps'  => array( 'receive_store_handover', 'manage_storefront_stock', 'operate_pos_checkout', 'remit_bursar_voucher', 'view_own_store_sales' ),
			),
		);

		foreach ( $roles as $role_key => $role ) {
			$existing = get_role( $role_key );
			if ( ! $existing ) {
				$existing = add_role( $role_key, $role['label'], array( 'read' => true ) );
			}
			if ( $existing ) {
				foreach ( $role['caps'] as $capability ) {
					$existing->add_cap( $capability );
				}
			}
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( array( 'assign_school_roles', 'configure_school_pos' ) as $capability ) {
				$administrator->add_cap( $capability );
			}
		}
	}

	public static function deactivate() {
		// Role definitions and capabilities are retained so deactivation does not destroy access configuration.
	}
}
