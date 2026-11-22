<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Installer {
	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p = $wpdb->prefix . 'school_pos_';
		$sql = array(
			"CREATE TABLE {$p}products (
			 product_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 sku varchar(64) NOT NULL,
			 name varchar(190) NOT NULL,
			 category varchar(40) NOT NULL,
			 bulk_unit_label varchar(32) NOT NULL,
			 base_unit_label varchar(32) NOT NULL,
			 units_per_pack int(10) unsigned NOT NULL DEFAULT 1,
			 pack_price_kobo bigint(20) unsigned NULL,
			 piece_price_kobo bigint(20) unsigned NULL,
			 low_stock_threshold_base_units int(10) unsigned NOT NULL DEFAULT 0,
			 is_active tinyint(1) NOT NULL DEFAULT 1,
			 created_by bigint(20) unsigned NOT NULL,
			 updated_by bigint(20) unsigned NOT NULL,
			 created_at datetime NOT NULL,
			 updated_at datetime NOT NULL,
			 PRIMARY KEY (product_id),
			 UNIQUE KEY sku (sku),
			 KEY category_active (category,is_active)
			) $charset;",
			"CREATE TABLE {$p}intakes (
			 intake_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 intake_code varchar(64) NOT NULL,
			 product_id bigint(20) unsigned NOT NULL,
			 supplier_name varchar(190) NOT NULL,
			 waybill_ref varchar(100) NOT NULL,
			 units_per_pack_snapshot int(10) unsigned NOT NULL,
			 quantity_packs int(10) unsigned NOT NULL,
			 quantity_base_units int(10) unsigned NOT NULL,
			 unit_cost_kobo bigint(20) unsigned NOT NULL,
			 status varchar(40) NOT NULL,
			 submitted_by bigint(20) unsigned NOT NULL,
			 verified_by bigint(20) unsigned NULL,
			 submitted_at datetime NOT NULL,
			 verified_at datetime NULL,
			 discrepancy_note text NULL,
			 created_at datetime NOT NULL,
			 updated_at datetime NOT NULL,
			 PRIMARY KEY (intake_id),
			 UNIQUE KEY intake_code (intake_code),
			 KEY submitted_status (submitted_by,status),
			 KEY product_status (product_id,status)
			) $charset;",
			"CREATE TABLE {$p}stock_balances (
			 balance_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 location varchar(20) NOT NULL,
			 product_id bigint(20) unsigned NOT NULL,
			 quantity_base_units bigint(20) unsigned NOT NULL DEFAULT 0,
			 updated_at datetime NOT NULL,
			 PRIMARY KEY (balance_id),
			 UNIQUE KEY location_product (location,product_id)
			) $charset;",
			"CREATE TABLE {$p}handovers (
			 handover_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 handover_code varchar(64) NOT NULL,
			 handover_type varchar(40) NOT NULL,
			 source_record_id bigint(20) unsigned NOT NULL DEFAULT 0,
			 product_id bigint(20) unsigned NOT NULL,
			 quantity_base_units bigint(20) unsigned NOT NULL,
			 status varchar(20) NOT NULL,
			 initiated_by bigint(20) unsigned NOT NULL,
			 accepted_by bigint(20) unsigned NULL,
			 discrepancy_note text NULL,
			 created_at datetime NOT NULL,
			 updated_at datetime NOT NULL,
			 PRIMARY KEY (handover_id),
			 UNIQUE KEY handover_code (handover_code),
			 KEY status_type (status,handover_type),
			 KEY product_id (product_id)
			) $charset;",
			"CREATE TABLE {$p}stock_ledger (
			 ledger_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 location varchar(20) NOT NULL,
			 product_id bigint(20) unsigned NOT NULL,
			 action_type varchar(32) NOT NULL,
			 qty_change_base_units bigint(20) NOT NULL,
			 balance_before bigint(20) unsigned NOT NULL,
			 balance_after bigint(20) unsigned NOT NULL,
			 performed_by_user_id bigint(20) unsigned NOT NULL,
			 ip_address varchar(45) NOT NULL,
			 user_agent varchar(512) NOT NULL,
			 reference_code varchar(100) NOT NULL,
			 notes text NOT NULL,
			 created_at datetime NOT NULL,
			 PRIMARY KEY (ledger_id),
			 KEY location_product (location,product_id),
			 KEY reference_code (reference_code),
			 KEY created_at (created_at)
			) $charset;",
			"CREATE TABLE {$p}vouchers (
			 voucher_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 voucher_code varchar(64) NOT NULL,
			 code_digest char(64) NOT NULL,
			 sequence_no bigint(20) unsigned NOT NULL,
			 student_full_name varchar(190) NOT NULL,
			 admission_number varchar(100) NOT NULL,
			 class_grade varchar(100) NOT NULL,
			 amount_paid_kobo bigint(20) unsigned NOT NULL,
			 remaining_kobo bigint(20) unsigned NOT NULL,
			 payment_method varchar(30) NOT NULL,
			 teller_ref_no varchar(100) NULL,
			 status varchar(20) NOT NULL,
			 issued_by bigint(20) unsigned NOT NULL,
			 redeemed_by bigint(20) unsigned NULL,
			 redeemed_sale_id bigint(20) unsigned NULL,
			 issued_at datetime NOT NULL,
			 redeemed_at datetime NULL,
			 issued_ip varchar(45) NOT NULL,
			 issued_user_agent varchar(512) NOT NULL,
			 redeemed_ip varchar(45) NULL,
			 redeemed_user_agent varchar(512) NULL,
			 PRIMARY KEY (voucher_id),
			 UNIQUE KEY voucher_code (voucher_code),
			 UNIQUE KEY code_digest (code_digest),
			 UNIQUE KEY sequence_no (sequence_no),
			 KEY admission_status (admission_number,status)
			) $charset;",
			"CREATE TABLE {$p}sales (
			 sale_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 receipt_no varchar(64) NOT NULL,
			 voucher_id bigint(20) unsigned NOT NULL,
			 voucher_code_snapshot varchar(64) NOT NULL,
			 student_full_name_snapshot varchar(190) NOT NULL,
			 admission_number_snapshot varchar(100) NOT NULL,
			 class_grade_snapshot varchar(100) NOT NULL,
			 seller_user_id bigint(20) unsigned NOT NULL,
			 subtotal_kobo bigint(20) unsigned NOT NULL,
			 voucher_applied_kobo bigint(20) unsigned NOT NULL,
			 total_kobo bigint(20) unsigned NOT NULL,
			 status varchar(20) NOT NULL,
			 ip_address varchar(45) NOT NULL,
			 user_agent varchar(512) NOT NULL,
			 created_at datetime NOT NULL,
			 updated_at datetime NOT NULL,
			 PRIMARY KEY (sale_id),
			 UNIQUE KEY receipt_no (receipt_no),
			 KEY seller_status (seller_user_id,status),
			 KEY voucher_id (voucher_id)
			) $charset;",
			"CREATE TABLE {$p}sale_lines (
			 line_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 sale_id bigint(20) unsigned NOT NULL,
			 product_id bigint(20) unsigned NOT NULL,
			 product_name_snapshot varchar(190) NOT NULL,
			 unit_type varchar(10) NOT NULL,
			 quantity int(10) unsigned NOT NULL,
			 base_units_consumed bigint(20) unsigned NOT NULL,
			 unit_price_kobo bigint(20) unsigned NOT NULL,
			 line_total_kobo bigint(20) unsigned NOT NULL,
			 pack_size_snapshot int(10) unsigned NOT NULL,
			 created_at datetime NOT NULL,
			 PRIMARY KEY (line_id),
			 KEY sale_product (sale_id,product_id)
			) $charset;",
			"CREATE TABLE {$p}audit_events (
			 event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			 event_type varchar(64) NOT NULL,
			 actor_user_id bigint(20) unsigned NOT NULL,
			 entity_type varchar(64) NOT NULL,
			 entity_id bigint(20) unsigned NULL,
			 reference_code varchar(100) NOT NULL,
			 ip_address varchar(45) NOT NULL,
			 user_agent varchar(512) NOT NULL,
			 metadata_json longtext NOT NULL,
			 created_at datetime NOT NULL,
			 PRIMARY KEY (event_id),
			 KEY actor_event (actor_user_id,event_type),
			 KEY entity (entity_type,entity_id),
			 KEY created_at (created_at)
			) $charset;",
		);
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		add_option( 'school_pos_voucher_sequence', '0', '', 'no' );
		update_option( 'school_pos_schema_version', SCHOOL_POS_VERSION, false );
		$voucher_table = $wpdb->prefix . 'school_pos_vouchers';
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$voucher_table}", 0 );
		if ( $columns && ! in_array( 'remaining_kobo', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$voucher_table} ADD COLUMN remaining_kobo bigint(20) unsigned NOT NULL DEFAULT 0 AFTER amount_paid_kobo" );
			$wpdb->query( "UPDATE {$voucher_table} SET remaining_kobo=amount_paid_kobo WHERE remaining_kobo=0" );
			$wpdb->query( "UPDATE {$voucher_table} SET remaining_kobo=0 WHERE status='REDEEMED'" );
		}
		School_POS_Roles::register();
		flush_rewrite_rules();
	}
}
