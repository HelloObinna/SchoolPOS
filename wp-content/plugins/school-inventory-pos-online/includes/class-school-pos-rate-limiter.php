<?php
defined( 'ABSPATH' ) || exit;

final class School_POS_Rate_Limiter {
	const LIMIT = 10;
	const WINDOW = 60;

	public static function allow_voucher_lookup( $user_id, $ip ) {
		$keys = array(
			'school_pos_voucher_user_' . absint( $user_id ),
			'school_pos_voucher_ip_' . md5( $ip ),
		);

		foreach ( $keys as $key ) {
			$attempts = get_transient( $key );
			if ( false === $attempts ) {
				$attempts = 0;
			}
			if ( $attempts >= self::LIMIT ) {
				return false;
			}
		}

		foreach ( $keys as $key ) {
			$attempts = get_transient( $key );
			set_transient( $key, false === $attempts ? 1 : (int) $attempts + 1, self::WINDOW );
		}
		return true;
	}
}
