<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_post_school_pos_assign_role', array( __CLASS__, 'assign_role' ) );
		add_action( 'admin_post_school_pos_export_audit', array( __CLASS__, 'export_audit' ) );
	}

	public static function menu() {
		add_menu_page( 'SchoolPOS', 'SchoolPOS', 'configure_school_pos', 'school-pos-settings', array( __CLASS__, 'render' ), 'dashicons-store', 26 );
	}

	public static function settings() {
		register_setting( 'school_pos_settings', 'school_pos_school_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'school_pos_settings', 'school_pos_alert_email', array( 'sanitize_callback' => 'sanitize_email' ) );
		add_settings_section( 'school_pos_general', 'School and alert configuration', '__return_false', 'school-pos-settings' );
		add_settings_field( 'school_name', 'School name', array( __CLASS__, 'text_field' ), 'school-pos-settings', 'school_pos_general', array( 'option' => 'school_pos_school_name' ) );
		add_settings_field( 'alert_email', 'School Admin alert email', array( __CLASS__, 'text_field' ), 'school-pos-settings', 'school_pos_general', array( 'option' => 'school_pos_alert_email', 'type' => 'email' ) );
	}

	public static function text_field( $args ) {
		$option = $args['option'];
		printf( '<input class="regular-text" type="%1$s" name="%2$s" value="%3$s">', esc_attr( isset( $args['type'] ) ? $args['type'] : 'text' ), esc_attr( $option ), esc_attr( get_option( $option, '' ) ) );
	}

	public static function render() {
		if ( ! current_user_can( 'configure_school_pos' ) ) wp_die( 'Not authorized.', 'Forbidden', array( 'response' => 403 ) );
		$users = get_users( array( 'orderby' => 'display_name', 'number' => 500 ) );
		$roles = array( '' => 'No SchoolPOS role', 'school_admin' => 'School Admin', 'school_secretary' => 'School Secretary', 'warehouse_manager' => 'Warehouse Manager', 'school_bursar' => 'School Bursar', 'store_seller' => 'Store Seller' );
		?>
		<div class="wrap">
			<h1>SchoolPOS configuration</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'school_pos_settings' ); do_settings_sections( 'school-pos-settings' ); submit_button(); ?>
			</form>
			<hr>
			<h2>Staff role assignments</h2>
			<table class="widefat striped"><thead><tr><th>User</th><th>Email</th><th>SchoolPOS role</th><th></th></tr></thead><tbody>
			<?php foreach ( $users as $user ) :
				if ( in_array( 'administrator', $user->roles, true ) ) continue;
				$current = '';
				foreach ( array_keys( $roles ) as $role ) if ( $role && in_array( $role, $user->roles, true ) ) $current = $role;
			?>
				<tr>
					<td><?php echo esc_html( $user->display_name ); ?></td>
					<td><?php echo esc_html( $user->user_email ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px">
							<input type="hidden" name="action" value="school_pos_assign_role">
							<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
							<?php wp_nonce_field( 'school_pos_assign_role_' . $user->ID ); ?>
							<select name="school_role"><?php foreach ( $roles as $slug => $label ) : ?><option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $current ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
							<button class="button" type="submit">Update</button>
						</form>
					</td>
					<td></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=school_pos_export_audit' ), 'school_pos_export_audit' ) ); ?>">Export audit ledger CSV</a></p>
		</div>
		<?php
	}

	public static function assign_role() {
		if ( ! current_user_can( 'assign_school_roles' ) ) wp_die( 'Not authorized.', 'Forbidden', array( 'response' => 403 ) );
		$user_id = absint( isset( $_POST['user_id'] ) ? $_POST['user_id'] : 0 );
		check_admin_referer( 'school_pos_assign_role_' . $user_id );
		$role = sanitize_key( isset( $_POST['school_role'] ) ? $_POST['school_role'] : '' );
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) wp_die( 'User not found.', 'Not found', array( 'response' => 404 ) );
		foreach ( array( 'school_admin','school_secretary','warehouse_manager','school_bursar','store_seller' ) as $old_role ) $user->remove_role( $old_role );
		if ( $role ) $user->add_role( $role );
		School_POS_Security::audit( 'school_role_assigned', 'user', $user_id, (string) $user_id, array( 'role' => $role ) );
		wp_safe_redirect( add_query_arg( 'school_pos_updated', '1', admin_url( 'admin.php?page=school-pos-settings' ) ) );
		exit;
	}

	public static function export_audit() {
		if ( ! current_user_can( 'view_master_audit_ledger' ) ) wp_die( 'Not authorized.', 'Forbidden', array( 'response' => 403 ) );
		check_admin_referer( 'school_pos_export_audit' );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT event_id,event_type,actor_user_id,entity_type,entity_id,reference_code,ip_address,user_agent,metadata_json,created_at FROM {$wpdb->prefix}school_pos_audit_events ORDER BY event_id ASC", ARRAY_A );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=schoolpos-audit-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'event_id','event_type','actor_user_id','entity_type','entity_id','reference_code','ip_address','user_agent','metadata_json','created_at' ) );
		foreach ( $rows as $row ) fputcsv( $out, $row );
		fclose( $out );
		exit;
	}
}
