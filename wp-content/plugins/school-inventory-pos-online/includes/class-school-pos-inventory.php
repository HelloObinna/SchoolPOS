<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Inventory {
	public static function create_intake( array $data ) {
		global $wpdb;

		$product = self::product( $data['product_id'] );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', 'The selected product does not exist.' );
		}

		$quantity_packs = absint( $data['quantity_packs'] );
		if ( $quantity_packs < 1 ) {
			return new WP_Error( 'invalid_quantity', 'Quantity must be at least one pack or unit.' );
		}

		$unit_cost_kobo = absint( $data['unit_cost_kobo'] );
		$now            = current_time( 'mysql', true );
		$code           = self::reference( 'INT' );
		$result         = $wpdb->insert(
			$wpdb->prefix . 'school_pos_intakes',
			array(
				'intake_code'              => $code,
				'product_id'               => (int) $product->product_id,
				'supplier_name'            => sanitize_text_field( $data['supplier_name'] ),
				'waybill_ref'              => sanitize_text_field( $data['waybill_ref'] ),
				'packaging_type'           => sanitize_text_field( $product->bulk_unit_label ),
				'units_per_pack_snapshot' => (int) $product->units_per_pack,
				'quantity_packs'           => $quantity_packs,
				'quantity_base_units'      => $quantity_packs * (int) $product->units_per_pack,
				'unit_cost_kobo'           => $unit_cost_kobo,
				'status'                   => 'PENDING_WAREHOUSE_HANDOVER',
				'submitted_by'             => get_current_user_id(),
				'submitted_at'             => $now,
				'created_at'               => $now,
				'updated_at'               => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return new WP_Error( 'intake_create_failed', 'The stock intake could not be saved.' );
		}

		$intake_id = (int) $wpdb->insert_id;
		self::audit(
			'intake_submitted',
			'intake',
			$intake_id,
			$code,
			array( 'product_id' => (int) $product->product_id, 'quantity_base_units' => $quantity_packs * (int) $product->units_per_pack )
		);
		return array( 'intake_id' => $intake_id, 'intake_code' => $code );
	}

	public static function accept_intake( $intake_id ) {
		global $wpdb;
		$intakes = $wpdb->prefix . 'school_pos_intakes';
		$now     = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );
		$intake = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$intakes} WHERE intake_id = %d FOR UPDATE", absint( $intake_id ) ) );
		if ( ! $intake || 'PENDING_WAREHOUSE_HANDOVER' !== $intake->status ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'invalid_intake_state', 'This intake is no longer awaiting warehouse acceptance.' );
		}

		$balance = self::lock_balance( 'WAREHOUSE', (int) $intake->product_id );
		if ( is_wp_error( $balance ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $balance;
		}

		$before = (int) $balance->quantity_base_units;
		$after  = $before + (int) $intake->quantity_base_units;
		$updated = $wpdb->update(
			$wpdb->prefix . 'school_pos_stock_balances',
			array( 'quantity_base_units' => $after, 'updated_at' => $now ),
			array( 'balance_id' => (int) $balance->balance_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $updated || false === $wpdb->update( $intakes, array( 'status' => 'ACCEPTED', 'verified_by' => get_current_user_id(), 'verified_at' => $now, 'updated_at' => $now ), array( 'intake_id' => (int) $intake->intake_id ), array( '%s', '%d', '%s', '%s' ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'intake_accept_failed', 'Warehouse acceptance could not be completed.' );
		}

		$ledger = self::ledger( 'WAREHOUSE', (int) $intake->product_id, 'INTAKE_ACCEPTED', (int) $intake->quantity_base_units, $before, $after, $intake->intake_code, 'Secretary intake accepted by warehouse.' );
		if ( is_wp_error( $ledger ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $ledger;
		}
		$wpdb->query( 'COMMIT' );
		self::audit( 'warehouse_handover_accepted', 'intake', (int) $intake->intake_id, $intake->intake_code, array( 'balance_after' => $after ) );
		return array( 'intake_id' => (int) $intake->intake_id, 'warehouse_balance_base_units' => $after );
	}

	public static function flag_discrepancy( $intake_id, $note ) {
		global $wpdb;
		$table = $wpdb->prefix . 'school_pos_intakes';
		$intake = $wpdb->get_row( $wpdb->prepare( "SELECT intake_id, intake_code, status FROM {$table} WHERE intake_id = %d", absint( $intake_id ) ) );
		if ( ! $intake || 'PENDING_WAREHOUSE_HANDOVER' !== $intake->status ) {
			return new WP_Error( 'invalid_intake_state', 'This intake is no longer awaiting discrepancy review.' );
		}
		$updated = $wpdb->update(
			$table,
			array( 'status' => 'DISCREPANCY', 'verified_by' => get_current_user_id(), 'verified_at' => current_time( 'mysql', true ), 'discrepancy_note' => sanitize_textarea_field( $note ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'intake_id' => (int) $intake->intake_id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'discrepancy_failed', 'The discrepancy could not be recorded.' );
		}
		self::audit( 'handover_discrepancy_flagged', 'intake', (int) $intake->intake_id, $intake->intake_code, array( 'note' => sanitize_textarea_field( $note ) ) );
		self::notify_admins( 'SchoolPOS handover discrepancy: ' . $intake->intake_code, sanitize_textarea_field( $note ) );
		return array( 'intake_id' => (int) $intake->intake_id, 'status' => 'DISCREPANCY' );
	}

	public static function dispatch_to_store( $product_id, $quantity_base_units ) {
		global $wpdb;
		$quantity_base_units = absint( $quantity_base_units );
		if ( $quantity_base_units < 1 ) {
			return new WP_Error( 'invalid_quantity', 'Transfer quantity must be at least one base unit.' );
		}
		if ( ! self::product( $product_id ) ) {
			return new WP_Error( 'invalid_product', 'The selected product does not exist.' );
		}

		$wpdb->query( 'START TRANSACTION' );
		$balance = self::lock_balance( 'WAREHOUSE', absint( $product_id ) );
		if ( is_wp_error( $balance ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $balance;
		}
		$before = (int) $balance->quantity_base_units;
		if ( $before < $quantity_base_units ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'insufficient_warehouse_stock', 'Warehouse stock is insufficient for this transfer.' );
		}
		$after = $before - $quantity_base_units;
		$now   = current_time( 'mysql', true );
		if ( false === $wpdb->update( $wpdb->prefix . 'school_pos_stock_balances', array( 'quantity_base_units' => $after, 'updated_at' => $now ), array( 'balance_id' => (int) $balance->balance_id ), array( '%d', '%s' ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'dispatch_failed', 'Warehouse stock could not be reserved.' );
		}

		$code = self::reference( 'TRF' );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'school_pos_handovers',
			array( 'handover_code' => $code, 'handover_type' => 'WAREHOUSE_STOREFRONT', 'source_record_id' => 0, 'product_id' => absint( $product_id ), 'quantity_base_units' => $quantity_base_units, 'status' => 'PENDING', 'initiated_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now ),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'dispatch_failed', 'The store transfer could not be created.' );
		}
		$handover_id = (int) $wpdb->insert_id;
		$ledger = self::ledger( 'WAREHOUSE', absint( $product_id ), 'STORE_DISPATCH', -$quantity_base_units, $before, $after, $code, 'Stock reserved for storefront handover.' );
		if ( is_wp_error( $ledger ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $ledger;
		}
		$wpdb->query( 'COMMIT' );
		self::audit( 'store_handover_initiated', 'handover', $handover_id, $code, array( 'product_id' => absint( $product_id ), 'quantity_base_units' => $quantity_base_units ) );
		return array( 'handover_id' => $handover_id, 'handover_code' => $code, 'warehouse_balance_base_units' => $after );
	}

	public static function accept_store_handover( $handover_id ) {
		global $wpdb;
		$handovers = $wpdb->prefix . 'school_pos_handovers';
		$wpdb->query( 'START TRANSACTION' );
		$handover = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$handovers} WHERE handover_id = %d FOR UPDATE", absint( $handover_id ) ) );
		if ( ! $handover || 'PENDING' !== $handover->status || 'WAREHOUSE_STOREFRONT' !== $handover->handover_type ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'invalid_handover_state', 'This store transfer is no longer awaiting acceptance.' );
		}
		$balance = self::lock_balance( 'STOREFRONT', (int) $handover->product_id );
		if ( is_wp_error( $balance ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $balance;
		}
		$before = (int) $balance->quantity_base_units;
		$after  = $before + (int) $handover->quantity_base_units;
		$now    = current_time( 'mysql', true );
		if ( false === $wpdb->update( $wpdb->prefix . 'school_pos_stock_balances', array( 'quantity_base_units' => $after, 'updated_at' => $now ), array( 'balance_id' => (int) $balance->balance_id ), array( '%d', '%s' ), array( '%d' ) ) || false === $wpdb->update( $handovers, array( 'status' => 'ACCEPTED', 'accepted_by' => get_current_user_id(), 'updated_at' => $now ), array( 'handover_id' => (int) $handover->handover_id ), array( '%s', '%d', '%s' ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'store_acceptance_failed', 'Storefront acceptance could not be completed.' );
		}
		$ledger = self::ledger( 'STOREFRONT', (int) $handover->product_id, 'STORE_ACCEPTED', (int) $handover->quantity_base_units, $before, $after, $handover->handover_code, 'Store transfer accepted by seller.' );
		if ( is_wp_error( $ledger ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $ledger;
		}
		$wpdb->query( 'COMMIT' );
		self::audit( 'store_handover_accepted', 'handover', (int) $handover->handover_id, $handover->handover_code, array( 'balance_after' => $after ) );
		self::notify_low_stock( (int) $handover->product_id, $after );
		return array( 'handover_id' => (int) $handover->handover_id, 'storefront_balance_base_units' => $after );
	}

	private static function product( $product_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'school_pos_products WHERE product_id = %d AND is_active = 1', absint( $product_id ) ) );
	}

	private static function lock_balance( $location, $product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'school_pos_stock_balances';
		$balance = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE location = %s AND product_id = %d FOR UPDATE", $location, absint( $product_id ) ) );
		if ( $balance ) {
			return $balance;
		}
		$inserted = $wpdb->insert( $table, array( 'location' => $location, 'product_id' => absint( $product_id ), 'quantity_base_units' => 0, 'updated_at' => current_time( 'mysql', true ) ), array( '%s', '%d', '%d', '%s' ) );
		if ( false === $inserted ) {
			return new WP_Error( 'balance_unavailable', 'The stock balance could not be initialized.' );
		}
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE location = %s AND product_id = %d FOR UPDATE", $location, absint( $product_id ) ) );
	}

	private static function ledger( $location, $product_id, $action, $change, $before, $after, $reference, $notes ) {
		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'school_pos_stock_ledger',
			array( 'location' => $location, 'product_id' => $product_id, 'action_type' => $action, 'qty_change_base_units' => $change, 'balance_before' => $before, 'balance_after' => $after, 'performed_by_user_id' => get_current_user_id(), 'ip_address' => School_POS_Security::client_ip(), 'user_agent' => School_POS_Security::user_agent(), 'reference_code' => $reference, 'notes' => $notes, 'created_at' => current_time( 'mysql', true ) ),
			array( '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return false === $inserted ? new WP_Error( 'ledger_write_failed', 'The immutable stock ledger could not be written.' ) : true;
	}

	private static function audit( $event_type, $entity_type, $entity_id, $reference, array $metadata = array() ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'school_pos_audit_events',
			array( 'event_type' => $event_type, 'actor_user_id' => get_current_user_id(), 'entity_type' => $entity_type, 'entity_id' => $entity_id, 'reference_code' => $reference, 'ip_address' => School_POS_Security::client_ip(), 'user_agent' => School_POS_Security::user_agent(), 'metadata_json' => wp_json_encode( $metadata ), 'created_at' => current_time( 'mysql', true ) ),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function notify_admins( $subject, $message ) {
		$recipient = sanitize_email( get_option( 'school_pos_alert_email', get_option( 'admin_email' ) ) );
		if ( $recipient && ! wp_mail( $recipient, $subject, $message ) ) {
			self::audit( 'admin_alert_failed', 'system', 0, 'ALERT', array( 'subject' => $subject ) );
		}
	}

	private static function notify_low_stock( $product_id, $balance ) {
		$product = self::product( $product_id );
		if ( $product && (int) $product->low_stock_threshold_base_units > 0 && $balance <= (int) $product->low_stock_threshold_base_units ) {
			self::notify_admins( 'SchoolPOS low stock: ' . $product->name, sprintf( 'Storefront stock for %s is %d base units.', $product->name, $balance ) );
		}
	}

	private static function reference( $prefix ) {
		return strtoupper( $prefix . '-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 8, false, false ) );
	}
}
