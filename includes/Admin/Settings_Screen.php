<?php
/**
 * WooCommerce admin screen for the whole PayGlocal configuration.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Admin;

use PayGlocal\Currency\Exchange_Rates;
use PayGlocal\Currency\Rate_Table;

/**
 * Lets a store admin save gateway credentials, currencies, and rates in one place.
 */
final class Settings_Screen {

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
	}

	public static function register_menu(): void {
		$cap  = current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ? 'manage_woocommerce' : 'manage_options';
		$hook = add_menu_page(
			__( 'PayGlocal', 'payglocal' ),
			__( 'PayGlocal', 'payglocal' ),
			$cap,
			'payglocal',
			array( self::class, 'render' ),
			'dashicons-money-alt',
			56
		);
		if ( is_string( $hook ) ) {
			add_action( 'load-' . $hook, array( self::class, 'handle_post' ) );
		}
		add_action( 'admin_post_payglocal_refresh_rates', array( self::class, 'refresh_rates' ) );
	}

	public static function handle_post(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You cannot manage PayGlocal.', 'payglocal' ) );
		}
		check_admin_referer( 'payglocal_save_settings' );

		$existing = get_option( 'woocommerce_payglocal_settings', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$gateway = array(
			'enabled'     => isset( $_POST['payglocal_enabled'] ) ? 'yes' : 'no',
			'title'       => sanitize_text_field( wp_unslash( $_POST['payglocal_title'] ?? '' ) ),
			'description' => sanitize_textarea_field( wp_unslash( $_POST['payglocal_description'] ?? '' ) ),
			'mode'        => 'production' === ( $_POST['payglocal_mode'] ?? '' ) ? 'production' : 'uat',
			'merchant_id' => sanitize_text_field( wp_unslash( $_POST['payglocal_merchant_id'] ?? '' ) ),
			'public_kid'  => sanitize_text_field( wp_unslash( $_POST['payglocal_public_kid'] ?? '' ) ),
			'private_kid' => sanitize_text_field( wp_unslash( $_POST['payglocal_private_kid'] ?? '' ) ),
			'public_pem'  => self::incoming_pem( 'payglocal_public_pem', (string) ( $existing['public_pem'] ?? '' ), false ),
			'private_pem' => self::incoming_pem( 'payglocal_private_pem', (string) ( $existing['private_pem'] ?? '' ), true ),
		);
		if ( '' === $gateway['title'] ) {
			$gateway['title'] = __( 'PayGlocal', 'payglocal' );
		}

		$supported = array( 'INR' );
		$posted_supported = $_POST['payglocal_supported'] ?? array();
		if ( is_array( $posted_supported ) ) {
			foreach ( $posted_supported as $code ) {
				$code = strtoupper( sanitize_text_field( wp_unslash( (string) $code ) ) );
				if ( preg_match( '/^[A-Z]{3}$/', $code ) ) {
					$supported[] = $code;
				}
			}
		}
		$supported = array_values( array_unique( $supported ) );

		$rates = array();
		$posted_rates = $_POST['payglocal_rates'] ?? array();
		if ( is_array( $posted_rates ) ) {
			foreach ( $posted_rates as $code => $rate ) {
				$code = strtoupper( sanitize_text_field( (string) $code ) );
				$raw  = trim( sanitize_text_field( wp_unslash( (string) $rate ) ) );
				if ( ! preg_match( '/^[A-Z]{3}$/', $code ) || 'INR' === $code || '' === $raw ) {
					continue;
				}
				$clean = wc_format_decimal( $raw );
				if ( '' === $clean || (float) $clean <= 0 ) {
					Admin_Flash::add_error( sprintf( '%s is not a valid INR exchange rate and was skipped.', $code ) );
					continue;
				}
				$rates[ $code ] = (string) $clean;
			}
		}

		$conversion = isset( $_POST['payglocal_conversion_enabled'] ) ? 'yes' : 'no';
		$snapshot   = array(
			'enabled'            => $gateway['enabled'],
			'merchant_id'        => $gateway['merchant_id'],
			'public_kid'         => $gateway['public_kid'],
			'private_kid'        => $gateway['private_kid'],
			'public_pem'         => $gateway['public_pem'],
			'private_pem'        => $gateway['private_pem'],
			'conversion_enabled' => $conversion,
			'store_currency'     => class_exists( 'WooCommerce' ) ? (string) get_option( 'woocommerce_currency', 'INR' ) : 'INR',
			'supported'          => $supported,
			'rates'              => $rates,
			'openssl'            => function_exists( 'openssl_pkey_get_public' ),
			'rsa_math'           => function_exists( 'gmp_powm' ) || function_exists( 'bcpowmod' ),
		);
		$codes = Config_Check::codes( $snapshot );
		foreach ( Config_Check::messages( $codes ) as $message ) {
			Admin_Flash::add_error( $message );
		}
		if ( 'yes' === $gateway['enabled'] && Config_Check::blocks_gateway( $codes ) ) {
			$gateway['enabled'] = 'no';
			Admin_Flash::add_error( 'PayGlocal payments stay off until the merchant ID, key IDs, and both RSA keys are valid.' );
		}

		update_option( 'woocommerce_payglocal_settings', array_merge( $existing, $gateway ), true );
		update_option( 'payglocal_conversion_enabled', $conversion, true );
		update_option( 'payglocal_show_switcher', isset( $_POST['payglocal_show_switcher'] ) ? 'yes' : 'no', true );
		update_option( 'payglocal_supported_currencies', $supported, true );
		update_option( 'payglocal_rate_mode', 'manual' === ( $_POST['payglocal_rate_mode'] ?? '' ) ? 'manual' : 'automatic', true );
		( new Exchange_Rates() )->save( $rates, 'manual' === get_option( 'payglocal_rate_mode', 'automatic' ) ? 'manual' : 'saved' );

		Admin_Flash::add_success( 'PayGlocal settings saved.' );
		wp_safe_redirect( admin_url( 'admin.php?page=payglocal' ) );
		exit;
	}

	public static function refresh_rates(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You cannot refresh rates.', 'payglocal' ) );
		}
		check_admin_referer( 'payglocal_refresh_rates' );
		update_option( 'payglocal_rate_mode', 'automatic' );
		$ok = ( new Exchange_Rates() )->refresh();
		if ( ! $ok ) {
			Admin_Flash::add_error( __( 'PayGlocal could not refresh exchange rates. The last cached rates are still in use.', 'payglocal' ) );
		} else {
			Admin_Flash::add_success( __( 'Exchange rates refreshed.', 'payglocal' ) );
		}
		$redirect = wp_get_referer();
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = admin_url( 'admin.php?page=payglocal' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function render(): void {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You cannot manage PayGlocal.', 'payglocal' ) );
		}

		$gateway = get_option( 'woocommerce_payglocal_settings', array() );
		if ( ! is_array( $gateway ) ) {
			$gateway = array();
		}
		$rates     = ( new Exchange_Rates() )->all();
		$supported = get_option( 'payglocal_supported_currencies', Rate_Table::default_supported() );
		if ( ! is_array( $supported ) || array() === $supported ) {
			$supported = Rate_Table::default_supported();
		}
		$supported = array_map(
			static function ( $code ) {
				return strtoupper( (string) $code );
			},
			$supported
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'PayGlocal', 'payglocal' ) . '</h1>';
		echo '<p>' . esc_html__( 'Manage the payment account, the currencies shoppers can pay in, and the INR exchange rates. Product prices stay stored in INR.', 'payglocal' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=payglocal' ) ) . '">';
		wp_nonce_field( 'payglocal_save_settings' );

		echo '<h2>' . esc_html__( 'Payments', 'payglocal' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox( 'payglocal_enabled', __( 'Enable PayGlocal', 'payglocal' ), 'yes' === ( $gateway['enabled'] ?? 'no' ) );
		self::text( 'payglocal_title', __( 'Checkout title', 'payglocal' ), (string) ( $gateway['title'] ?? 'PayGlocal' ) );
		self::textarea( 'payglocal_description', __( 'Checkout description', 'payglocal' ), (string) ( $gateway['description'] ?? '' ) );
		self::select(
			'payglocal_mode',
			__( 'Environment', 'payglocal' ),
			array(
				'uat'        => __( 'Sandbox', 'payglocal' ),
				'production' => __( 'Production', 'payglocal' ),
			),
			(string) ( $gateway['mode'] ?? 'uat' )
		);
		self::text( 'payglocal_merchant_id', __( 'Merchant ID', 'payglocal' ), (string) ( $gateway['merchant_id'] ?? '' ) );
		self::text( 'payglocal_public_kid', __( 'Public key ID', 'payglocal' ), (string) ( $gateway['public_kid'] ?? '' ) );
		self::text( 'payglocal_private_kid', __( 'Private key ID', 'payglocal' ), (string) ( $gateway['private_kid'] ?? '' ) );
		self::textarea( 'payglocal_public_pem', __( 'PayGlocal public key (PEM)', 'payglocal' ), (string) ( $gateway['public_pem'] ?? '' ) );
		self::textarea( 'payglocal_private_pem', __( 'Merchant private key (PEM)', 'payglocal' ), (string) ( $gateway['private_pem'] ?? '' ) );
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Leave a key blank to keep the key already saved. An invalid key is rejected and the previous key stays in place.', 'payglocal' ) . '</p>';

		echo '<h2>' . esc_html__( 'Price conversion', 'payglocal' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox( 'payglocal_conversion_enabled', __( 'Convert storefront prices from INR', 'payglocal' ), 'yes' === get_option( 'payglocal_conversion_enabled', 'yes' ) );
		self::checkbox( 'payglocal_show_switcher', __( 'Show the country and currency selector', 'payglocal' ), 'yes' === get_option( 'payglocal_show_switcher', 'yes' ) );
		self::select(
			'payglocal_rate_mode',
			__( 'Rate source', 'payglocal' ),
			array(
				'automatic' => __( 'Refresh from the exchange-rate feed and cache the result', 'payglocal' ),
				'manual'    => __( 'Use only the rates saved on this screen', 'payglocal' ),
			),
			(string) get_option( 'payglocal_rate_mode', 'automatic' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Supported currencies', 'payglocal' ) . '</th><td><fieldset>';
		foreach ( Rate_Table::catalog() as $code ) {
			$checked = in_array( $code, $supported, true );
			$disabled = 'INR' === $code;
			echo '<label style="display:inline-block;min-width:5em;margin:0 12px 8px 0"><input type="checkbox" name="payglocal_supported[]" value="' . esc_attr( $code ) . '" ' . checked( $checked, true, false ) . ( $disabled ? ' checked disabled' : '' ) . ' /> ' . esc_html( $code ) . '</label>';
		}
		echo '<input type="hidden" name="payglocal_supported[]" value="INR" />';
		echo '<p class="description">' . esc_html__( 'INR is always available. A shopper whose currency is not listed here, or has no rate, sees INR. JPY is off unless you enable it and save a rate.', 'payglocal' ) . '</p>';
		echo '</fieldset></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Exchange rates', 'payglocal' ) . '</h2>';
		echo '<p>' . esc_html__( 'How many INR equal 1 unit of the currency. USD 85 means a ₹8,500 product displays as $100.', 'payglocal' ) . '</p>';
		$updated = ( new Exchange_Rates() )->updated_at();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: rate source, 2: date */
				__( 'Cached source: %1$s. Last update: %2$s.', 'payglocal' ),
				( new Exchange_Rates() )->source(),
				$updated ? wp_date( 'Y-m-d H:i', $updated ) : __( 'not yet', 'payglocal' )
			)
		) . '</p>';
		echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>' . esc_html__( 'Currency', 'payglocal' ) . '</th><th>' . esc_html__( 'INR per 1 unit', 'payglocal' ) . '</th></tr></thead><tbody>';
		foreach ( Rate_Table::catalog() as $code ) {
			if ( 'INR' === $code ) {
				continue;
			}
			$value = isset( $rates[ $code ] ) ? (string) $rates[ $code ] : '';
			echo '<tr><td>' . esc_html( $code ) . '</td><td><input type="text" class="regular-text" name="payglocal_rates[' . esc_attr( $code ) . ']" value="' . esc_attr( $value ) . '" /></td></tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Save PayGlocal settings', 'payglocal' ) );
		$refresh = wp_nonce_url( admin_url( 'admin-post.php?action=payglocal_refresh_rates' ), 'payglocal_refresh_rates' );
		echo '<a class="button" href="' . esc_url( $refresh ) . '">' . esc_html__( 'Refresh rates now', 'payglocal' ) . '</a>';
		echo '</form></div>';
	}

	private static function incoming_pem( string $field, string $existing, bool $private ): string {
		$posted = isset( $_POST[ $field ] ) ? trim( (string) wp_unslash( $_POST[ $field ] ) ) : '';
		if ( '' === $posted ) {
			return $existing;
		}
		$valid = $private ? Config_Check::private_key_is_valid( $posted ) : Config_Check::public_key_is_valid( $posted );
		if ( ! $valid ) {
			Admin_Flash::add_error( Config_Check::message( $private ? 'private_pem_invalid' : 'public_pem_invalid' ) );
			return $existing;
		}
		return $posted;
	}

	private static function checkbox( string $name, string $label, bool $checked ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $label ) . '</label></td></tr>';
	}

	private static function text( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" /></td></tr>';
	}

	private static function textarea( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><textarea class="large-text code" rows="6" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea></td></tr>';
	}

	/**
	 * @param array<string, string> $options Options.
	 */
	private static function select( string $name, string $label, array $options, string $selected ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $selected, (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}
}
