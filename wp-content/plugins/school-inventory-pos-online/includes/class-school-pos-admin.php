<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_post_school_pos_export_audit', array( __CLASS__, 'export_audit' ) );
	}

	public static function menu() {
		add_menu_page( 'SchoolPOS Oversight', 'SchoolPOS Oversight', 'oversee_all_transactions', 'school-pos-oversight', array( __CLASS__, 'dashboard' ), 'dashicons-chart-area', 26 );
		add_options_page(
			'SchoolPOS Configuration',
			'SchoolPOS',
			'configure_school_pos',
			'school-pos-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function dashboard() {
		if ( ! current_user_can( 'oversee_all_transactions' ) ) {
			wp_die( esc_html__( 'You are not authorized to view SchoolPOS oversight.', 'school-inventory-pos-online' ) );
		}
		global $wpdb;
		$prefix = $wpdb->prefix . 'school_pos_';
		$metrics = array(
			'Pending intakes'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}intakes WHERE status = 'PENDING_WAREHOUSE_HANDOVER'" ),
			'Discrepancies'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}intakes WHERE status = 'DISCREPANCY'" ),
			'Pending store transfers' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}handovers WHERE status = 'PENDING'" ),
			'Completed sales'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}sales WHERE status = 'COMPLETED'" ),
		);
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=school_pos_export_audit' ), 'school_pos_export_audit' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SchoolPOS Oversight', 'school-inventory-pos-online' ); ?></h1>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;max-width:900px">
				<?php foreach ( $metrics as $label => $value ) : ?>
					<div style="background:#fff;border:1px solid #dcdcde;padding:20px"><strong><?php echo esc_html( $label ); ?></strong><p style="font-size:28px;margin:12px 0 0"><?php echo esc_html( number_format_i18n( $value ) ); ?></p></div>
				<?php endforeach; ?>
			</div>
			<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export audit ledger CSV', 'school-inventory-pos-online' ); ?></a></p>
		</div>
		<?php
	}

	public static function export_audit() {
		if ( ! current_user_can( 'view_master_audit_ledger' ) ) {
			wp_die( esc_html__( 'You are not authorized to export the audit ledger.', 'school-inventory-pos-online' ), 403 );
		}
		check_admin_referer( 'school_pos_export_audit' );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT event_id, event_type, actor_user_id, entity_type, entity_id, reference_code, ip_address, user_agent, metadata_json, created_at FROM {$wpdb->prefix}school_pos_audit_events ORDER BY event_id ASC", ARRAY_A );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=schoolpos-audit-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'event_id', 'event_type', 'actor_user_id', 'entity_type', 'entity_id', 'reference_code', 'ip_address', 'user_agent', 'metadata_json', 'created_at' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	public static function settings() {
		register_setting( 'school_pos_settings', 'school_pos_school_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'school_pos_settings', 'school_pos_alert_email', array( 'sanitize_callback' => 'sanitize_email' ) );
		add_settings_section( 'school_pos_general', 'SchoolPOS Settings', '__return_false', 'school-pos-settings' );
		add_settings_field( 'school_pos_school_name', 'School name', array( __CLASS__, 'text_field' ), 'school-pos-settings', 'school_pos_general', array( 'option' => 'school_pos_school_name' ) );
		add_settings_field( 'school_pos_alert_email', 'School Admin alert email', array( __CLASS__, 'text_field' ), 'school-pos-settings', 'school_pos_general', array( 'option' => 'school_pos_alert_email', 'type' => 'email' ) );
	}

	public static function text_field( $args ) {
		$option = isset( $args['option'] ) ? $args['option'] : '';
		$type   = isset( $args['type'] ) ? $args['type'] : 'text';
		printf(
			'<input class="regular-text" type="%1$s" name="%2$s" value="%3$s">',
			esc_attr( $type ),
			esc_attr( $option ),
			esc_attr( get_option( $option, '' ) )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'configure_school_pos' ) ) {
			wp_die( esc_html__( 'You are not authorized to configure SchoolPOS.', 'school-inventory-pos-online' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SchoolPOS Configuration', 'school-inventory-pos-online' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'school_pos_settings' );
				do_settings_sections( 'school-pos-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
