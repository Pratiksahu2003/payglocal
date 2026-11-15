<?php
/**
 * Decides whether the current request should show converted prices.
 *
 * @package PayGlocal
 */

namespace PayGlocal;

/**
 * Storefront requests convert prices. Admin, cron, and product editing do not,
 * so converted amounts are never saved back onto products.
 */
final class Storefront {

	public static function is_request(): bool {
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			return false !== strpos( $uri, '/wc/store/' );
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$action  = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
			$referer = wp_get_referer();
			if ( in_array( $action, array( 'payglocal_set_country', 'payglocal_set_currency' ), true ) ) {
				return true;
			}
			if ( ! is_string( $referer ) || false !== strpos( $referer, '/wp-admin/' ) ) {
				return false;
			}
		}
		return true;
	}
}
