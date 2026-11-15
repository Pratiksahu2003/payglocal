<?php
/**
 * Admin notices for PayGlocal configuration.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Admin;

/**
 * Prints configuration errors on every WooCommerce admin screen.
 */
final class Notices {

	public static function render(): void {
		if ( ! Settings_Screen::can_manage() ) {
			return;
		}

		$flash    = Admin_Flash::consume();
		$codes    = self::live_codes();
		$visible  = array();
		$on_screen = self::is_settings_screen();
		foreach ( $codes as $code ) {
			if ( ! $on_screen && str_starts_with( $code, 'currency_without_rate:' ) ) {
				continue;
			}
			$visible[] = $code;
		}
		$messages = array_values( array_unique( array_merge( $flash['errors'], Config_Check::messages( $visible ) ) ) );

		foreach ( $flash['success'] as $message ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
		if ( array() === $messages ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'PayGlocal', 'payglocal' ) . '</strong></p><ul style="list-style:disc;margin-left:1.2em">';
		foreach ( $messages as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		$link = admin_url( 'admin.php?page=payglocal' );
		echo '</ul><p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Manage PayGlocal settings', 'payglocal' ) . '</a></p></div>';
	}

	private static function is_settings_screen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( 'payglocal', 'wc-settings' ), true );
	}

	/**
	 * @return array<int, string>
	 */
	private static function live_codes(): array {
		$gateway = get_option( 'woocommerce_payglocal_settings', array() );
		if ( ! is_array( $gateway ) ) {
			$gateway = array();
		}
		$rates = ( new \PayGlocal\Currency\Exchange_Rates() )->all();
		$supported = get_option( 'payglocal_supported_currencies', array() );
		if ( ! is_array( $supported ) || array() === $supported ) {
			$supported = \PayGlocal\Currency\Rate_Table::default_supported();
		}

		return Config_Check::codes(
			array(
				'enabled'            => (string) ( $gateway['enabled'] ?? 'no' ),
				'merchant_id'        => (string) ( $gateway['merchant_id'] ?? '' ),
				'public_kid'         => (string) ( $gateway['public_kid'] ?? '' ),
				'private_kid'        => (string) ( $gateway['private_kid'] ?? '' ),
				'public_pem'         => (string) ( $gateway['public_pem'] ?? '' ),
				'private_pem'        => (string) ( $gateway['private_pem'] ?? '' ),
				'conversion_enabled' => (string) get_option( 'payglocal_conversion_enabled', 'yes' ),
				'store_currency'     => class_exists( 'WooCommerce' ) ? (string) get_option( 'woocommerce_currency', 'INR' ) : 'INR',
				'supported'          => $supported,
				'rates'              => $rates,
				'openssl'            => function_exists( 'openssl_pkey_get_public' ),
				'rsa_math'           => function_exists( 'gmp_powm' ) || function_exists( 'bcpowmod' ),
			)
		);
	}
}
