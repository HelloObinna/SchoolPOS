<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
	}

	public static function menu() {
		add_options_page(
			'SchoolPOS Configuration',
			'SchoolPOS',
			'configure_school_pos',
			'school-pos-settings',
			array( __CLASS__, 'render' )
		);
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
