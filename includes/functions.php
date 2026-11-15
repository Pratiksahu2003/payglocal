<?php
/**
 * Public helpers.
 *
 * @package PayGlocal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 *
 * @return \PayGlocal\Plugin
 */
function payglocal() {
	return \PayGlocal\Plugin::instance();
}

/**
 * Convert an INR amount into the active payment currency.
 *
 * Empty values are returned unchanged. The WooCommerce product record is not modified.
 *
 * @param mixed       $amount   INR amount.
 * @param string|null $currency ISO code. Null uses the active currency.
 * @return mixed
 */
function payglocal_convert_price( $amount, $currency = null ) {
	return payglocal()->convert_price( $amount, $currency );
}

/**
 * Active payment currency for this request.
 *
 * @return string
 */
function payglocal_get_currency() {
	return payglocal()->currency()->get_active_currency();
}
