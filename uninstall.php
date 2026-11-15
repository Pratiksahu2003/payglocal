<?php
/**
 * Remove PayGlocal settings. Orders and catalog prices are left in place.
 *
 * @package PayGlocal
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'payglocal_conversion_enabled' );
delete_option( 'payglocal_supported_currencies' );
delete_option( 'payglocal_exchange_rates' );
delete_option( 'payglocal_rate_mode' );
delete_option( 'payglocal_show_switcher' );
delete_option( 'woocommerce_payglocal_settings' );
wp_clear_scheduled_hook( 'payglocal_refresh_exchange_rates' );
