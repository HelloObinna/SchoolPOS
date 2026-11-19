<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Print {
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'render' ), 1 );
	}

	public static function render() {
		if ( empty( $_GET['school_pos_print'] ) || empty( $_GET['id'] ) || ! is_user_logged_in() ) {
			return;
		}
		$type = sanitize_key( wp_unslash( $_GET['school_pos_print'] ) );
		$id   = absint( $_GET['id'] );
		if ( 'voucher' === $type && ! current_user_can( 'print_bursar_voucher' ) ) {
			return;
		}
		if ( 'sale' === $type && ! current_user_can( 'view_own_store_sales' ) ) {
			return;
		}
		global $wpdb;
		$data = 'voucher' === $type ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_vouchers WHERE voucher_id = %d", $id ) ) : $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_sales WHERE sale_id = %d", $id ) );
		if ( ! $data ) {
			status_header( 404 );
			wp_die( esc_html__( 'Print record not found.', 'school-inventory-pos-online' ) );
		}
		if ( 'sale' === $type && (int) $data->seller_user_id !== get_current_user_id() && ! current_user_can( 'oversee_all_transactions' ) ) {
			wp_die( esc_html__( 'You are not authorized to print this receipt.', 'school-inventory-pos-online' ) );
		}
		School_POS_Security::protect_page_cache();
		nocache_headers();
		$school = esc_html( get_option( 'school_pos_school_name', get_bloginfo( 'name' ) ) );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php echo esc_html( 'voucher' === $type ? 'Bursar Voucher' : 'POS Receipt' ); ?></title><style>@page{size:80mm auto;margin:0}body{width:72mm;margin:0 auto;font:12px/1.35 monospace;color:#000}.center{text-align:center}.strong{font-weight:700;font-size:16px}.row{display:flex;justify-content:space-between;gap:8px}hr{border:0;border-top:1px dashed #000;margin:8px 0}@media print{.no-print{display:none}}</style></head>
		<body onload="window.print()">
		<div class="center"><div class="strong"><?php echo $school; ?></div><div><?php echo esc_html( 'voucher' === $type ? 'BURSAR PAYMENT VOUCHER' : 'STORE POS RECEIPT' ); ?></div></div><hr>
		<?php
		if ( 'voucher' === $type ) {
			printf( '<div class="center strong">%s</div><div class="row"><span>Date</span><span>%s</span></div><div>Student: %s</div><div>Admission: %s</div><div>Class: %s</div><div>Method: %s</div><div class="row strong"><span>Amount</span><span>%s</span></div>', esc_html( $data->voucher_code ), esc_html( $data->issued_at ), esc_html( $data->student_full_name ), esc_html( $data->admission_number ), esc_html( $data->class_grade ), esc_html( $data->payment_method ), esc_html( School_POS_Checkout::naira( $data->amount_paid_kobo ) ) );
		} else {
			$lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_sale_lines WHERE sale_id = %d ORDER BY line_id ASC", $id ) );
			printf( '<div class="row"><span>Receipt</span><span>%s</span></div><div class="row"><span>Date</span><span>%s</span></div><div>Voucher: %s</div><div>Student: %s</div><div>Admission: %s</div><div>Class: %s</div><hr>', esc_html( $data->receipt_no ), esc_html( $data->created_at ), esc_html( $data->voucher_code_snapshot ), esc_html( $data->student_full_name_snapshot ), esc_html( $data->admission_number_snapshot ), esc_html( $data->class_grade_snapshot ) );
			foreach ( $lines as $line ) {
				printf( '<div>%d x %s [%s] @ %s = %s</div>', (int) $line->quantity, esc_html( $line->product_name_snapshot ), esc_html( $line->unit_type ), esc_html( School_POS_Checkout::naira( $line->unit_price_kobo ) ), esc_html( School_POS_Checkout::naira( $line->line_total_kobo ) ) );
			}
			printf( '<hr><div class="row strong"><span>Grand Total</span><span>%s</span></div><div class="row"><span>Voucher Applied</span><span>%s</span></div>', esc_html( School_POS_Checkout::naira( $data->total_kobo ) ), esc_html( School_POS_Checkout::naira( $data->voucher_applied_kobo ) ) );
		}
		?>
		<hr><div class="center">Verified SchoolPOS transaction</div><button class="no-print" onclick="window.print()">Print</button>
		</body></html>
		<?php
		exit;
	}
}
