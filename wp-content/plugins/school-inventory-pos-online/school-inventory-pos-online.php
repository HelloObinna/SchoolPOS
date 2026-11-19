<?php
/**
 * Plugin Name: School Inventory POS Online
 * Description: Secure custom-table inventory, bursar voucher, and school POS foundation.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: SchoolPOS
 * Text Domain: school-inventory-pos-online
 */

defined( 'ABSPATH' ) || exit;

define( 'SCHOOL_POS_VERSION', '0.1.0' );
define( 'SCHOOL_POS_FILE', __FILE__ );
define( 'SCHOOL_POS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCHOOL_POS_URL', plugin_dir_url( __FILE__ ) );

require_once SCHOOL_POS_DIR . 'includes/class-school-pos-installer.php';
require_once SCHOOL_POS_DIR . 'includes/class-school-pos-roles.php';
require_once SCHOOL_POS_DIR . 'includes/class-school-pos-security.php';
require_once SCHOOL_POS_DIR . 'includes/class-school-pos-rate-limiter.php';
require_once SCHOOL_POS_DIR . 'includes/class-school-pos-rest.php';
require_once SCHOOL_POS_DIR . 'includes/class-school-pos-inventory.php';
require_once SCHOOL_POS_DIR . 'includes/class-school-pos-chain-rest.php';
require_once SCHOOL_POS_DIR . 'includes/class-school-pos-admin.php';

register_activation_hook( __FILE__, array( 'School_POS_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'School_POS_Roles', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		School_POS_Roles::register();
		School_POS_Security::register();
		School_POS_REST::register();
		School_POS_Chain_REST::register();
		School_POS_Admin::register();
	}
);
