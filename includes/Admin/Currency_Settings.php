<?php
/**
 * WooCommerce settings for conversion.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Admin;

use PayGlocal\Currency\Exchange_Rates;
use PayGlocal\Currency\Rate_Table;

/**
 * Settings tab for supported currencies and cached rates.
 */
final class Currency_Settings extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'payglocal_currency';
		$this->label = __( 'PayGlocal Currency', 'payglocal' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_payglocal_rate_table', array( $this, 'render_rate_table' ) );
		add_action( 'woocommerce_update_options_payglocal_currency', array( $this, 'save_rate_table' ) );
		add_action( 'admin_post_payglocal_refresh_rates', array( $this, 'refresh_rates' ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings() {
		$catalog = array();
		foreach ( Rate_Table::catalog() as $code ) {
			$catalog[ $code ] = $code;
		}

		return apply_filters(
			'woocommerce_payglocal_currency_settings',
			array(
				array(
					'title' => __( 'Global price conversion', 'payglocal' ),
					'type'  => 'title',
					'desc'  => __( 'Product prices stay stored in INR. Shoppers see and pay in the currency chosen from their country and the currencies this PayGlocal account can charge.', 'payglocal' ),
					'id'    => 'payglocal_currency_options',
				),
				array(
					'title'   => __( 'Enable conversion', 'payglocal' ),
					'id'      => 'payglocal_conversion_enabled',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'title'   => __( 'Show selector', 'payglocal' ),
					'desc'    => __( 'Show the country and currency selector in the footer. You can also use the [payglocal_currency_switcher] shortcode.', 'payglocal' ),
					'id'      => 'payglocal_show_switcher',
					'type'    => 'checkbox',
					'default' => 'yes',
				),
				array(
					'title'   => __( 'Supported currencies', 'payglocal' ),
					'desc'    => __( 'INR is always available. A shopper whose local currency is not in this list sees INR. JPY is off by default.', 'payglocal' ),
					'id'      => 'payglocal_supported_currencies',
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'options' => $catalog,
					'default' => Rate_Table::default_supported(),
				),
				array(
					'title'   => __( 'Rate source', 'payglocal' ),
					'id'      => 'payglocal_rate_mode',
					'type'    => 'select',
					'default' => 'automatic',
					'options' => array(
						'automatic' => __( 'Refresh from the exchange-rate feed and cache the result', 'payglocal' ),
						'manual'    => __( 'Use only the rates saved below', 'payglocal' ),
					),
				),
				array(
					'type' => 'payglocal_rate_table',
					'id'   => 'payglocal_rate_table',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'payglocal_currency_options',
				),
			)
		);
	}

	/**
	 * @param array<string, string> $field Field definition.
	 */
	public function render_rate_table( $field ): void {
		unset( $field );
		$rates   = ( new Exchange_Rates() )->all();
		$updated = ( new Exchange_Rates() )->updated_at();
		$source  = ( new Exchange_Rates() )->source();
		$refresh = wp_nonce_url( admin_url( 'admin-post.php?action=payglocal_refresh_rates' ), 'payglocal_refresh_rates' );

		echo '<tr valign="top"><th scope="row">' . esc_html__( 'Exchange rates', 'payglocal' ) . '</th><td>';
		echo '<p>' . esc_html__( 'Enter how many INR equal 1 unit of the currency. USD 85 means a ₹8,500 product displays as $100.', 'payglocal' ) . '</p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: rate source, 2: date */
				__( 'Cached source: %1$s. Last update: %2$s. Sample USD, EUR, and GBP rates are used until you save or refresh.', 'payglocal' ),
				$source,
				$updated ? wp_date( 'Y-m-d H:i', $updated ) : __( 'not yet', 'payglocal' )
			)
		) . '</p>';
		echo '<table class="widefat striped" style="max-width:520px"><thead><tr><th>' . esc_html__( 'Currency', 'payglocal' ) . '</th><th>' . esc_html__( 'INR per 1 unit', 'payglocal' ) . '</th></tr></thead><tbody>';

		foreach ( Rate_Table::catalog() as $code ) {
			if ( 'INR' === $code ) {
				continue;
			}
			$value = isset( $rates[ $code ] ) ? $rates[ $code ] : '';
			echo '<tr><td>' . esc_html( $code ) . '</td><td><input type="text" name="payglocal_rates[' . esc_attr( $code ) . ']" value="' . esc_attr( (string) $value ) . '" /></td></tr>';
		}

		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( $refresh ) . '">' . esc_html__( 'Refresh rates now', 'payglocal' ) . '</a></p>';
		echo '</td></tr>';
	}

	public function save_rate_table(): void {
		if ( ! isset( $_POST['payglocal_rates'] ) || ! is_array( $_POST['payglocal_rates'] ) ) {
			return;
		}

		$clean = array();
		foreach ( wp_unslash( $_POST['payglocal_rates'] ) as $code => $rate ) {
			$code = strtoupper( sanitize_text_field( (string) $code ) );
			$raw  = trim( sanitize_text_field( (string) $rate ) );
			if ( ! preg_match( '/^[A-Z]{3}$/', $code ) || 'INR' === $code || '' === $raw ) {
				continue;
			}
			$rate = wc_format_decimal( $raw );
			if ( '' === $rate || (float) $rate <= 0 ) {
				\PayGlocal\Admin\Admin_Flash::add_error( sprintf( '%s is not a valid INR exchange rate and was skipped.', $code ) );
				continue;
			}
			$clean[ $code ] = (string) $rate;
		}

		( new Exchange_Rates() )->save( $clean, 'manual' === get_option( 'payglocal_rate_mode', 'automatic' ) ? 'manual' : 'saved' );
	}

	public function refresh_rates(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You cannot refresh rates.', 'payglocal' ) );
		}
		check_admin_referer( 'payglocal_refresh_rates' );
		update_option( 'payglocal_rate_mode', 'automatic' );
		$ok = ( new Exchange_Rates() )->refresh();
		if ( ! $ok ) {
			\PayGlocal\Admin\Admin_Flash::add_error( __( 'PayGlocal could not refresh exchange rates. The last cached rates are still in use.', 'payglocal' ) );
		} else {
			\PayGlocal\Admin\Admin_Flash::add_success( __( 'Exchange rates refreshed.', 'payglocal' ) );
		}
		$redirect = wp_get_referer();
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = admin_url( 'admin.php?page=payglocal' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}
}
