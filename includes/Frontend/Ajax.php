<?php
/**
 * AJAX country and currency changes.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Frontend;

use PayGlocal\Currency\Currency_Manager;
use PayGlocal\Pricing\Cart_Adjustments;

/**
 * Updates the WooCommerce session and refreshes cart fragments.
 *
 * The handlers accept a country or a supported currency. They do not accept an amount.
 */
final class Ajax {

	private Currency_Manager $currency;

	private Cart_Adjustments $cart;

	public function __construct( Currency_Manager $currency, Cart_Adjustments $cart ) {
		$this->currency = $currency;
		$this->cart     = $cart;
	}

	public function register(): void {
		add_action( 'wp_ajax_payglocal_set_country', array( $this, 'set_country' ) );
		add_action( 'wp_ajax_nopriv_payglocal_set_country', array( $this, 'set_country' ) );
		add_action( 'wp_ajax_payglocal_set_currency', array( $this, 'set_currency' ) );
		add_action( 'wp_ajax_nopriv_payglocal_set_currency', array( $this, 'set_currency' ) );
	}

	public function set_country(): void {
		$this->authorize();
		$country = isset( $_POST['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ) ) ) : '';
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Choose a valid country.', 'payglocal' ),
				),
				400
			);
		}

		$result = $this->currency->apply_country( $country );
		$this->recalculate();
		wp_send_json_success( $this->payload( $result['country'], $result['currency'] ) );
	}

	public function set_currency(): void {
		$this->authorize();
		$currency = isset( $_POST['currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';
		$result   = $this->currency->apply_currency( $currency );
		if ( null === $result ) {
			wp_send_json_error(
				array(
					'message' => __( 'That currency is not available for payment.', 'payglocal' ),
				),
				400
			);
		}

		$this->recalculate();
		wp_send_json_success( $this->payload( $result['country'], $result['currency'] ) );
	}

	private function authorize(): void {
		check_ajax_referer( 'payglocal_currency', 'nonce' );
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	private function recalculate(): void {
		$this->cart->flush_shipping();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload( string $country, string $currency ): array {
		return array(
			'country'   => $country,
			'currency'  => $currency,
			'symbol'    => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency ) : $currency,
			'fragments' => $this->fragments(),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function fragments(): array {
		if ( ! function_exists( 'woocommerce_mini_cart' ) ) {
			return array();
		}

		ob_start();
		woocommerce_mini_cart();
		$mini_cart = (string) ob_get_clean();

		$fragments = array(
			'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
		);

		return apply_filters( 'woocommerce_add_to_cart_fragments', $fragments );
	}
}
