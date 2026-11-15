<?php
/**
 * Plugin Name: PayGlocal
 * Description: Standalone PayGlocal currency and payments. Storefront, cart, and checkout features attach when WooCommerce is active. Prices stay stored in INR.
 * Version: 1.0.0
 * Author: VEDMINT CONSULTANCY SERVICES
 * Author URI: https://www.vedmint.com
 * Requires at least: 6.2
 * Requires PHP: 8.0
 * Text Domain: payglocal
 * WC tested up to: 9.4
 *
 * @package PayGlocal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAYGLOCAL_VERSION', '1.0.0' );
define( 'PAYGLOCAL_FILE', __FILE__ );
define( 'PAYGLOCAL_DIR', plugin_dir_path( __FILE__ ) );

require PAYGLOCAL_DIR . 'includes/autoload.php';
require PAYGLOCAL_DIR . 'includes/functions.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAYGLOCAL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PAYGLOCAL_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		payglocal();
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! wp_next_scheduled( 'payglocal_refresh_exchange_rates' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'payglocal_refresh_exchange_rates' );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( 'payglocal_refresh_exchange_rates' );
	}
);
