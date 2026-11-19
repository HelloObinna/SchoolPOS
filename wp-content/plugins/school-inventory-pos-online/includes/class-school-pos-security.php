<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Security {
	public static function register() {
		add_action( 'send_headers', array( __CLASS__, 'send_headers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect_page_cache' ), 0 );
		add_filter( 'wp_headers', array( __CLASS__, 'filter_headers' ) );
	}

	public static function is_protected_request() {
		return (bool) apply_filters(
			'school_pos_protected_request',
			is_user_logged_in() && ( isset( $_GET['school_pos_route'] ) || is_admin() )
		);
	}

	public static function protect_page_cache() {
		if ( self::is_protected_request() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public static function filter_headers( $headers ) {
		if ( self::is_protected_request() ) {
			$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';
			$headers['Pragma']        = 'no-cache';
			$headers['X-Content-Type-Options'] = 'nosniff';
			$headers['X-Frame-Options']         = 'SAMEORIGIN';
			$headers['Referrer-Policy']         = 'strict-origin-when-cross-origin';
		}
		return $headers;
	}

	public static function send_headers() {
		if ( ! self::is_protected_request() ) {
			return;
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	public static function user_agent() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( $user_agent, 0, 512 );
	}
}
