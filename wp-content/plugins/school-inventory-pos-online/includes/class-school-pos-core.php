<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Core {
	public static function register() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_shortcode( 'school_pos_app', array( __CLASS__, 'app' ) );
		add_action( 'template_redirect', array( __CLASS__, 'print_view' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'auth_view' ), 4 );
	}

	public static function rewrite() {
		add_rewrite_rule( '^school-pos/?$', 'index.php?school_pos_app=1', 'top' );
		add_rewrite_rule( '^school-pos/(login|register)/?$', 'index.php?school_pos_auth=$matches[1]', 'top' );
		add_rewrite_tag( '%school_pos_app%', '([^&]+)' );
		add_rewrite_tag( '%school_pos_auth%', '([^&]+)' );
	}

	public static function app() {
		if ( ! is_user_logged_in() ) {
			return '<div class="pos-card"><h1>SchoolPOS</h1><p>Sign in to open your assigned workspace.</p><p><a class="pos-button" href="' . esc_url( home_url( '/school-pos/login/' ) ) . '">Sign in</a> <a class="pos-button pos-button-secondary" href="' . esc_url( home_url( '/school-pos/register/' ) ) . '">Create account</a></p></div>';
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
				<?php if ( in_array( $role, array( 'administrator', 'store_seller' ), true ) ) : ?><button type="button" data-view="receiving">Receive Stock</button><?php endif; ?>
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

	public static function auth_view() {
		$mode = get_query_var( 'school_pos_auth' );
		if ( ! in_array( $mode, array( 'login', 'register' ), true ) ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			if ( false === strpos( $request_uri, '/school-pos/login/' ) && false === strpos( $request_uri, '/school-pos/register/' ) ) {
				return;
			}
			$mode = false !== strpos( $request_uri, '/school-pos/register/' ) ? 'register' : 'login';
		}

		if ( is_user_logged_in() && 'login' === $mode ) {
			wp_safe_redirect( home_url( '/school-pos/' ) );
			exit;
		}

		$error = '';
		$success = '';

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
			$action = isset( $_POST['school_pos_auth_action'] ) ? sanitize_key( wp_unslash( $_POST['school_pos_auth_action'] ) ) : '';
			$nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'school_pos_' . $action ) ) {
				$error = 'Your session expired. Please try again.';
			} elseif ( 'login' === $action ) {
				$identity = sanitize_text_field( isset( $_POST['identity'] ) ? wp_unslash( $_POST['identity'] ) : '' );
				$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
				$remember = isset( $_POST['remember'] );
				$user = wp_signon(
					array(
						'user_login' => $identity,
						'user_password' => $password,
						'remember' => $remember,
					),
					is_ssl()
				);
				if ( is_wp_error( $user ) ) {
					$error = 'Incorrect username/email or password.';
					School_POS_Security::audit( 'schoolpos_login_failed', 'session', 0, 'LOGIN', array( 'identity' => $identity ) );
				} else {
					School_POS_Security::audit( 'schoolpos_login_succeeded', 'user', (int) $user->ID, (string) $user->ID, array() );
					wp_safe_redirect( home_url( '/school-pos/' ) );
					exit;
				}
			} elseif ( 'register' === $action ) {
				$name = sanitize_text_field( isset( $_POST['display_name'] ) ? wp_unslash( $_POST['display_name'] ) : '' );
				$email = sanitize_email( isset( $_POST['email'] ) ? wp_unslash( $_POST['email'] ) : '' );
				$username = sanitize_user( isset( $_POST['username'] ) ? wp_unslash( $_POST['username'] ) : '', true );
				$password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
				if ( '' === $name || '' === $email || '' === $username || strlen( $password ) < 10 ) {
					$error = 'Enter a name, username, valid email, and password of at least 10 characters.';
				} elseif ( ! is_email( $email ) ) {
					$error = 'Enter a valid email address.';
				} elseif ( username_exists( $username ) || email_exists( $email ) ) {
					$error = 'That username or email is already registered.';
				} else {
					$user_id = wp_create_user( $username, $password, $email );
					if ( is_wp_error( $user_id ) ) {
						$error = 'The account could not be created.';
					} else {
						wp_update_user( array( 'ID' => $user_id, 'display_name' => $name ) );
						School_POS_Security::audit( 'schoolpos_account_created', 'user', $user_id, (string) $user_id, array( 'role' => 'subscriber_pending_assignment' ) );
						$success = 'Account created. A Super Admin must assign your SchoolPOS role before you can access the workspace.';
					}
				}
			}
		}

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		$school = esc_html( get_option( 'school_pos_school_name', get_bloginfo( 'name' ) ) );
		$is_register = 'register' === $mode;
		$title = $is_register ? 'Create SchoolPOS account' : 'Sign in to SchoolPOS';
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title><style>
		:root{--ink:#132238;--blue:#2366b1;--canvas:#eef3f8}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:radial-gradient(circle at top right,#dceafa,var(--canvas) 42%,#f7f9fc);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink)}.auth-shell{width:min(440px,calc(100vw - 32px));background:#fff;border:1px solid #dce5ef;border-radius:18px;padding:32px;box-shadow:0 18px 50px rgba(19,34,56,.12)}.brand{display:flex;align-items:center;gap:12px;font-size:19px;font-weight:850;margin-bottom:28px}.brand-mark{display:grid;place-items:center;width:44px;height:44px;border-radius:12px;background:var(--ink);color:#fff}.auth-shell h1{font-size:25px;line-height:1.2;margin:0 0 8px}.subtitle{color:#66778a;margin:0 0 22px}.field{display:grid;gap:6px;margin:12px 0}.field label{font-size:13px;font-weight:750}.field input{width:100%;min-height:46px;border:1px solid #c9d5e2;border-radius:9px;padding:10px 12px;font:inherit}.field input:focus{outline:3px solid rgba(35,102,177,.12);border-color:var(--blue)}.remember{display:flex;align-items:center;gap:8px;font-size:14px;margin:14px 0}.remember input{width:auto}.button{width:100%;border:0;border-radius:9px;background:var(--blue);color:#fff;padding:12px;font:800 15px system-ui;cursor:pointer}.button:hover{background:#174e88}.alt{text-align:center;color:#66778a;font-size:14px;margin:18px 0 0}.alt a{color:var(--blue);font-weight:800}.message{border-radius:9px;padding:11px 13px;margin:12px 0;font-size14px}.error{background:#fff1f3;border:1px solid #edabb3;color:#982936}.success{background:#edf8f4;border:1px solid #9fd5c3;color:#0f624b}@media(max-width:520px){body{place-items:start center}.auth-shell{width:100%;min-height:100vh;border:0;border-radius:0;padding:24px 20px}}
		</style></head><body><main class="auth-shell"><div class="brand"><span class="brand-mark">SP</span><span>' . $school . '</span></div><h1>' . esc_html( $title ) . '</h1><p class="subtitle">' . ( $is_register ? 'Request access to the SchoolPOS operations workspace.' : 'Use your WordPress staff account.' ) . '</p>';
		if ( $error ) echo '<div class="message error">' . esc_html( $error ) . '</div>';
		if ( $success ) echo '<div class="message success">' . esc_html( $success ) . '</div>';
		echo '<form method="post"><input type="hidden" name="school_pos_auth_action" value="' . esc_attr( $mode ) . '">';
		wp_nonce_field( 'school_pos_' . $mode );
		if ( $is_register ) {
			echo '<div class="field"><label for="display_name">Full name</label><input id="display_name" name="display_name" autocomplete="name" required></div><div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required></div><div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required></div><button class="button" type="submit">Create account</button><p class="alt">Already have an account? <a href="' . esc_url( home_url( '/school-pos/login/' ) ) . '">Sign in</a></p>';
		} else {
			echo '<div class="field"><label for="identity">Username or email</label><input id="identity" name="identity" autocomplete="username" required></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><label class="remember"><input type="checkbox" name="remember"> Keep me signed in</label><button class="button" type="submit">Sign in</button><p class="alt">Need access? <a href="' . esc_url( home_url( '/school-pos/register/' ) ) . '">Create account</a></p>';
		}
		echo '</form></main></body></html>';
		exit;
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
