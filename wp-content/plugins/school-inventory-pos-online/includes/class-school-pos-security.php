<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Security {
	public static function register() {
		add_action( 'send_headers', array( __CLASS__, 'headers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'cache_bypass' ), 0 );
		add_filter( 'wp_headers', array( __CLASS__, 'wp_headers' ) );
	}

	public static function protected_request() {
		$route = isset( $_GET['school_pos_app'] ) || is_admin();
		$route = $route || ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), '/school-pos/' ) );
		return (bool) apply_filters( 'school_pos_protected_request', $route );
	}

	public static function cache_bypass() {
		if ( self::protected_request() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
			nocache_headers();
		}
	}

	public static function headers() {
		if ( ! self::protected_request() ) return;
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	public static function wp_headers( $headers ) {
		if ( self::protected_request() ) {
			$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
			$headers['Pragma'] = 'no-cache';
			$headers['X-Content-Type-Options'] = 'nosniff';
			$headers['X-Frame-Options'] = 'SAMEORIGIN';
			$headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
		}
		return $headers;
	}

	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	public static function user_agent() {
		return substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 512 );
	}

	public static function audit( $event, $entity_type, $entity_id, $reference, array $metadata = array() ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'school_pos_audit_events',
			array(
				'event_type' => $event,
				'actor_user_id' => get_current_user_id(),
				'entity_type' => $entity_type,
				'entity_id' => absint( $entity_id ),
				'reference_code' => substr( sanitize_text_field( $reference ), 0, 100 ),
				'ip_address' => self::ip(),
				'user_agent' => self::user_agent(),
				'metadata_json' => wp_json_encode( $metadata ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
