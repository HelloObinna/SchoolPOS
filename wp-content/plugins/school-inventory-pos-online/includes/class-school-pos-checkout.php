<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Checkout {
	public static function complete( $voucher_code, array $lines ) {
		global $wpdb;
		if ( empty( $lines ) ) {
			return new WP_Error( 'empty_cart', 'At least one item is required.' );
		}
		$voucher_code = strtoupper( sanitize_text_field( $voucher_code ) );
		$wpdb->query( 'START TRANSACTION' );
		$voucher_table = $wpdb->prefix . 'school_pos_vouchers';
		$voucher = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$voucher_table} WHERE voucher_code = %s FOR UPDATE", $voucher_code ) );
		if ( ! $voucher || 'ISSUED' !== $voucher->status ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'voucher_unavailable', 'Voucher code is invalid, voided, or already redeemed.' );
		}

		$normalized = self::normalize_lines( $lines );
		if ( is_wp_error( $normalized ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $normalized;
		}
		$subtotal = 0;
		$balances = array();
		foreach ( $normalized as $index => $line ) {
			$product = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_products WHERE product_id = %d AND is_active = 1", $line['product_id'] ) );
			if ( ! $product ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'invalid_product', 'A cart product is unavailable.' );
			}
			$unit_price = 'PACK' === $line['unit_type'] ? $product->pack_price_kobo : $product->piece_price_kobo;
			if ( null === $unit_price ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'missing_price', 'A cart product has no price for the selected unit.' );
			}
			$base_units = 'PACK' === $line['unit_type'] ? $line['quantity'] * (int) $product->units_per_pack : $line['quantity'];
			$line_total = $line['quantity'] * (int) $unit_price;
			$balance = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_stock_balances WHERE location = 'STOREFRONT' AND product_id = %d FOR UPDATE", $line['product_id'] ) );
			if ( ! $balance || (int) $balance->quantity_base_units < $base_units ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'insufficient_storefront_stock', 'Storefront stock is insufficient for a cart item.' );
			}
			$normalized[ $index ]['product']           = $product;
			$normalized[ $index ]['unit_price_kobo']   = (int) $unit_price;
			$normalized[ $index ]['base_units']        = $base_units;
			$normalized[ $index ]['line_total_kobo']   = $line_total;
			$normalized[ $index ]['balance_before']    = (int) $balance->quantity_base_units;
			$normalized[ $index ]['balance_id']        = (int) $balance->balance_id;
			$subtotal += $line_total;
			$balances[ $line['product_id'] ] = true;
		}
		if ( (int) $voucher->amount_paid_kobo < $subtotal ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'voucher_shortfall', sprintf( 'Voucher is short by %s.', self::naira( $subtotal - (int) $voucher->amount_paid_kobo ) ) );
		}

		$now        = current_time( 'mysql', true );
		$receipt_no = self::reference( 'RCT' );
		$inserted   = $wpdb->insert(
			$wpdb->prefix . 'school_pos_sales',
			array( 'receipt_no' => $receipt_no, 'voucher_id' => (int) $voucher->voucher_id, 'voucher_code_snapshot' => $voucher->voucher_code, 'student_full_name_snapshot' => $voucher->student_full_name, 'admission_number_snapshot' => $voucher->admission_number, 'class_grade_snapshot' => $voucher->class_grade, 'seller_user_id' => get_current_user_id(), 'subtotal_kobo' => $subtotal, 'voucher_applied_kobo' => $subtotal, 'total_kobo' => $subtotal, 'status' => 'COMPLETED', 'ip_address' => School_POS_Security::client_ip(), 'user_agent' => School_POS_Security::user_agent(), 'created_at' => $now, 'updated_at' => $now ),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'sale_create_failed', 'The sale could not be saved.' );
		}
		$sale_id = (int) $wpdb->insert_id;
		foreach ( $normalized as $line ) {
			$wpdb->insert( $wpdb->prefix . 'school_pos_sale_lines', array( 'sale_id' => $sale_id, 'product_id' => $line['product_id'], 'product_name_snapshot' => $line['product']->name, 'unit_type' => $line['unit_type'], 'quantity' => $line['quantity'], 'base_units_consumed' => $line['base_units'], 'unit_price_kobo' => $line['unit_price_kobo'], 'line_total_kobo' => $line['line_total_kobo'], 'pack_size_snapshot' => (int) $line['product']->units_per_pack, 'created_at' => $now ), array( '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ) );
			$after = $line['balance_before'] - $line['base_units'];
			if ( false === $wpdb->update( $wpdb->prefix . 'school_pos_stock_balances', array( 'quantity_base_units' => $after, 'updated_at' => $now ), array( 'balance_id' => $line['balance_id'] ), array( '%d', '%s' ), array( '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'stock_update_failed', 'The storefront stock update failed.' );
			}
			$ledger = $wpdb->insert( $wpdb->prefix . 'school_pos_stock_ledger', array( 'location' => 'STOREFRONT', 'product_id' => $line['product_id'], 'action_type' => 'POS_SALE', 'qty_change_base_units' => -$line['base_units'], 'balance_before' => $line['balance_before'], 'balance_after' => $after, 'performed_by_user_id' => get_current_user_id(), 'ip_address' => School_POS_Security::client_ip(), 'user_agent' => School_POS_Security::user_agent(), 'reference_code' => $receipt_no, 'notes' => 'POS sale completed against redeemed bursar voucher.', 'created_at' => $now ), array( '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ) );
			if ( false === $ledger ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'ledger_write_failed', 'The immutable stock ledger could not be written.' );
			}
		}
		$updated = $wpdb->update( $voucher_table, array( 'status' => 'REDEEMED', 'redeemed_by' => get_current_user_id(), 'redeemed_sale_id' => $sale_id, 'redeemed_at' => $now, 'redeemed_ip' => School_POS_Security::client_ip(), 'redeemed_user_agent' => School_POS_Security::user_agent() ), array( 'voucher_id' => (int) $voucher->voucher_id, 'status' => 'ISSUED' ), array( '%s', '%d', '%d', '%s', '%s', '%s' ), array( '%d', '%s' ) );
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'voucher_redeem_failed', 'The voucher could not be marked as redeemed.' );
		}
		$wpdb->query( 'COMMIT' );
		$wpdb->insert( $wpdb->prefix . 'school_pos_audit_events', array( 'event_type' => 'pos_sale_completed', 'actor_user_id' => get_current_user_id(), 'entity_type' => 'sale', 'entity_id' => $sale_id, 'reference_code' => $receipt_no, 'ip_address' => School_POS_Security::client_ip(), 'user_agent' => School_POS_Security::user_agent(), 'metadata_json' => wp_json_encode( array( 'voucher_code' => $voucher_code, 'total_kobo' => $subtotal ) ), 'created_at' => current_time( 'mysql', true ) ), array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
		return array( 'sale_id' => $sale_id, 'receipt_no' => $receipt_no, 'total_kobo' => $subtotal, 'voucher_code' => $voucher_code );
	}

	private static function normalize_lines( array $lines ) {
		$result = array();
		foreach ( $lines as $line ) {
			$product_id = absint( isset( $line['product_id'] ) ? $line['product_id'] : 0 );
			$quantity   = absint( isset( $line['quantity'] ) ? $line['quantity'] : 0 );
			$unit_type  = strtoupper( sanitize_key( isset( $line['unit_type'] ) ? $line['unit_type'] : '' ) );
			if ( ! $product_id || $quantity < 1 || ! in_array( $unit_type, array( 'PACK', 'PIECE' ), true ) ) {
				return new WP_Error( 'invalid_cart_line', 'Each cart line requires a product, positive quantity, and PACK or PIECE unit type.' );
			}
			$result[] = array( 'product_id' => $product_id, 'quantity' => $quantity, 'unit_type' => $unit_type );
		}
		usort(
			$result,
			static function ( $left, $right ) {
				return $left['product_id'] <=> $right['product_id'];
			}
		);
		return $result;
	}

	public static function naira( $kobo ) {
		return '₦' . number_format( (int) $kobo / 100, 2, '.', ',' );
	}

	private static function reference( $prefix ) {
		return strtoupper( $prefix . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false ) );
	}
}
