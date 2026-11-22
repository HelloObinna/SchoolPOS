<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Core {
	public static function register() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_shortcode( 'school_pos_app', array( __CLASS__, 'app' ) );
		add_action( 'template_redirect', array( __CLASS__, 'print_view' ), 5 );
	}

	public static function rewrite() {
		add_rewrite_rule( '^school-pos/?$', 'index.php?school_pos_app=1', 'top' );
		add_rewrite_tag( '%school_pos_app%', '([0-9]+)' );
	}

	public static function app() {
		if ( ! is_user_logged_in() ) {
			return '<div class="pos-card"><h1>SchoolPOS</h1><p><a class="pos-button" href="' . esc_url( wp_login_url( home_url( '/school-pos/' ) ) ) . '">Sign in</a></p></div>';
		}
		$user = wp_get_current_user();
		$role = School_POS_Roles::user_role();
		if ( '' === $role ) {
			return '<div class="pos-card"><h1>Access unavailable</h1><p>Your account has not been assigned a SchoolPOS role.</p></div>';
		}
		$map = array(
			'administrator' => 'Administrator',
			'school_admin' => 'School Admin',
			'school_secretary' => 'School Secretary',
			'warehouse_manager' => 'Warehouse Manager',
			'school_bursar' => 'School Bursar',
			'store_seller' => 'Store Seller',
		);
		ob_start();
		?>
		<section class="pos-app" data-role="<?php echo esc_attr( $role ); ?>" data-user="<?php echo esc_attr( $user->display_name ); ?>">
			<div class="pos-welcome">
				<div><span class="pos-eyebrow">SchoolPOS workspace</span><h1><?php echo esc_html( $map[ $role ] ); ?></h1></div>
				<button class="pos-button pos-button-secondary" type="button" data-action="refresh"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.34 5.66M20 5v6h-6"/></svg> Refresh</button>
			</div>
			<div id="pos-notice" class="pos-notice" hidden></div>
			<div id="pos-dashboard" class="pos-grid pos-grid-stats"></div>
			<nav class="pos-tabs" aria-label="SchoolPOS sections">
				<button type="button" class="is-active" data-view="overview">Overview</button>
				<?php if ( in_array( $role, array( 'administrator', 'school_secretary' ), true ) ) : ?><button type="button" data-view="intakes">Stock Intake</button><?php endif; ?>
				<?php if ( in_array( $role, array( 'administrator', 'warehouse_manager' ), true ) ) : ?><button type="button" data-view="warehouse">Warehouse</button><?php endif; ?>
				<?php if ( in_array( $role, array( 'administrator', 'school_bursar' ), true ) ) : ?><button type="button" data-view="bursar">Bursar Desk</button><?php endif; ?>
				<?php if ( in_array( $role, array( 'administrator', 'store_seller' ), true ) ) : ?><button type="button" data-view="pos">Store POS</button><?php endif; ?>
				<?php if ( in_array( $role, array( 'administrator', 'school_admin' ), true ) ) : ?><button type="button" data-view="audit">Audit Ledger</button><?php endif; ?>
			</nav>
			<div id="pos-view" class="pos-view"></div>
		</section>
		<script>
			window.SchoolPOS = window.SchoolPOS || {};
			window.SchoolPOS.root = <?php echo wp_json_encode( esc_url_raw( rest_url( 'school-pos/v1/' ) ) ); ?>;
			window.SchoolPOS.nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
		</script>
		<?php
		return ob_get_clean();
	}

	public static function naira( $kobo ) {
		return '₦' . number_format( (float) $kobo / 100, 2, '.', ',' );
	}

	public static function reference( $prefix ) {
		return sprintf( '%s-%s-%06d', $prefix, gmdate( 'YmdHis' ), wp_rand( 100000, 999999 ) );
	}

	public static function alert( $subject, $message ) {
		$to = sanitize_email( get_option( 'school_pos_alert_email', get_option( 'admin_email' ) ) );
		if ( $to ) {
			wp_mail( $to, $subject, $message );
		}
	}

	public static function print_view() {
		if ( empty( $_GET['school_pos_print'] ) || empty( $_GET['id'] ) || ! is_user_logged_in() ) return;
		$type = sanitize_key( wp_unslash( $_GET['school_pos_print'] ) );
		$id = absint( $_GET['id'] );
		global $wpdb;
		$data = null;
		$lines = array();
		if ( 'voucher' === $type ) {
			if ( ! current_user_can( 'print_bursar_voucher' ) ) wp_die( 'Not authorized.', 'Forbidden', array( 'response' => 403 ) );
			$data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_vouchers WHERE voucher_id=%d", $id ) );
		} elseif ( 'sale' === $type ) {
			if ( ! ( current_user_can( 'view_own_store_sales' ) || current_user_can( 'view_store_sales' ) ) ) wp_die( 'Not authorized.', 'Forbidden', array( 'response' => 403 ) );
			$data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_sales WHERE sale_id=%d", $id ) );
			if ( $data && (int) $data->seller_user_id !== get_current_user_id() && ! current_user_can( 'view_store_sales' ) && ! current_user_can( 'oversee_all_transactions' ) ) wp_die( 'Not authorized.', 'Forbidden', array( 'response' => 403 ) );
			$lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}school_pos_sale_lines WHERE sale_id=%d ORDER BY line_id", $id ) );
		}
		if ( ! $data ) wp_die( 'Record not found.', 'Not found', array( 'response' => 404 ) );
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>SchoolPOS Print</title><style>@page{size:80mm auto;margin:0}body{width:72mm;margin:0 auto;font:12px/1.4 monospace;color:#000}.center{text-align:center}.brand{font-size:17px;font-weight:800}.voucher-code{font-size:20px;font-weight:900;text-align:center;border:1px solid #000;padding:8px;margin:8px 0}.row{display:flex;justify-content:space-between;gap:8px}.strong{font-weight:800}hr{border:0;border-top:1px dashed #000;margin:7px 0}@media print{.no-print{display:none}}</style></head><body onload="window.print()">';
		echo '<div class="center brand">' . esc_html( get_option( 'school_pos_school_name', get_bloginfo( 'name' ) ) ) . '</div>';
		if ( 'voucher' === $type ) {
			echo '<div class="center strong">BURSAR PAYMENT VOUCHER</div><div class="voucher-code">' . esc_html( $data->voucher_code ) . '</div>';
			echo '<div>Issued: ' . esc_html( $data->issued_at ) . '</div><div>Student: ' . esc_html( $data->student_full_name ) . '</div><div>Admission: ' . esc_html( $data->admission_number ) . '</div><div>Class: ' . esc_html( $data->class_grade ) . '</div><div>Method: ' . esc_html( $data->payment_method ) . '</div><hr><div class="row strong"><span>Amount</span><span>' . esc_html( self::naira( $data->amount_paid_kobo ) ) . '</span></div>';
		} else {
			echo '<div class="center strong">STORE POS RECEIPT</div><div class="row"><span>Receipt</span><span>' . esc_html( $data->receipt_no ) . '</span></div><div>' . esc_html( $data->created_at ) . '</div><hr>';
			echo '<div>Voucher: ' . esc_html( $data->voucher_code_snapshot ) . '</div><div>Student: ' . esc_html( $data->student_full_name_snapshot ) . '</div><div>Admission: ' . esc_html( $data->admission_number_snapshot ) . '</div><div>Class: ' . esc_html( $data->class_grade_snapshot ) . '</div><hr>';
			foreach ( $lines as $line ) {
				echo '<div>' . esc_html( $line->quantity . ' x ' . $line->product_name_snapshot ) . ' [' . esc_html( $line->unit_type ) . ']</div><div class="row"><span>@ ' . esc_html( self::naira( $line->unit_price_kobo ) ) . '</span><strong>' . esc_html( self::naira( $line->line_total_kobo ) ) . '</strong></div>';
			}
			echo '<hr><div class="row strong"><span>Grand Total</span><span>' . esc_html( self::naira( $data->total_kobo ) ) . '</span></div><div class="row"><span>Voucher Applied</span><span>' . esc_html( self::naira( $data->voucher_applied_kobo ) ) . '</span></div>';
		}
		echo '<hr><div class="center">Verified SchoolPOS transaction</div><button class="no-print" onclick="window.print()">Print</button></body></html>';
		exit;
	}
}
