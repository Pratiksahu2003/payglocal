<?php
/**
 * PayGlocal WooCommerce gateway.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Gateway;

use PayGlocal\Currency\Price_Converter;
use WC_Payment_Gateway;

/**
 * Redirects checkout to PayGlocal for the order total in the order currency.
 *
 * The charged amount is read from the order WooCommerce created. Request fields
 * named amount, currency, totalAmount, or txnCurrency are ignored.
 */
final class Gateway extends WC_Payment_Gateway {

	private Token_Service $tokens;

	public function __construct( ?Token_Service $tokens = null ) {
		$this->id                 = 'payglocal';
		$this->method_title       = __( 'PayGlocal', 'payglocal' );
		$this->method_description = __( 'Take payment in the currency the customer has been shopping in. Product prices stay stored in INR.', 'payglocal' );
		$this->has_fields         = false;
		$this->supports           = array( 'products', 'refunds' );
		$this->tokens             = $tokens ?? new Token_Service();

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'PayGlocal', 'payglocal' ) );
		$this->description = $this->get_option( 'description', __( 'You will complete payment in the currency shown on this order.', 'payglocal' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_payglocal', array( $this, 'handle_callback' ) );
	}

	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'payglocal' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable PayGlocal', 'payglocal' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'payglocal' ),
				'type'    => 'text',
				'default' => __( 'PayGlocal', 'payglocal' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'payglocal' ),
				'type'    => 'textarea',
				'default' => __( 'Pay securely with PayGlocal in the currency shown at checkout.', 'payglocal' ),
			),
			'mode'        => array(
				'title'   => __( 'Environment', 'payglocal' ),
				'type'    => 'select',
				'default' => 'uat',
				'options' => array(
					'uat'        => __( 'Sandbox', 'payglocal' ),
					'production' => __( 'Production', 'payglocal' ),
				),
			),
			'merchant_id' => array(
				'title' => __( 'Merchant ID', 'payglocal' ),
				'type'  => 'text',
			),
			'public_kid'  => array(
				'title' => __( 'Public key ID', 'payglocal' ),
				'type'  => 'text',
			),
			'private_kid' => array(
				'title' => __( 'Private key ID', 'payglocal' ),
				'type'  => 'text',
			),
			'public_pem'  => array(
				'title'             => __( 'PayGlocal public key (PEM)', 'payglocal' ),
				'type'              => 'textarea',
				'css'               => 'height: 140px;',
				'sanitize_callback' => array( $this, 'sanitize_pem' ),
			),
			'private_pem' => array(
				'title'             => __( 'Merchant private key (PEM)', 'payglocal' ),
				'type'              => 'textarea',
				'css'               => 'height: 140px;',
				'sanitize_callback' => array( $this, 'sanitize_pem' ),
			),
		);
	}

	/**
	 * @param mixed $value Posted PEM.
	 */
	public function sanitize_pem( $value ) {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Save gateway settings and reject keys that cannot sign a payment.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$this->init_settings();
		$this->keep_previous_key_when_invalid( 'public_pem', false );
		$this->keep_previous_key_when_invalid( 'private_pem', true );

		$saved = parent::process_admin_options();
		$this->init_settings();

		$codes = \PayGlocal\Admin\Config_Check::codes( $this->settings_snapshot() );
		foreach ( \PayGlocal\Admin\Config_Check::messages( $codes ) as $message ) {
			\PayGlocal\Admin\Admin_Flash::add_error( $message );
			if ( class_exists( '\WC_Admin_Settings' ) ) {
				\WC_Admin_Settings::add_error( $message );
			}
		}

		if ( 'yes' === $this->get_option( 'enabled' ) && \PayGlocal\Admin\Config_Check::blocks_gateway( $codes ) ) {
			$this->settings['enabled'] = 'no';
			update_option( $this->get_option_key(), $this->settings );
			$message = __( 'PayGlocal payments stay off until the merchant ID, key IDs, and both RSA keys are valid.', 'payglocal' );
			\PayGlocal\Admin\Admin_Flash::add_error( $message );
			if ( class_exists( '\WC_Admin_Settings' ) ) {
				\WC_Admin_Settings::add_error( $message );
			}
		}

		return $saved;
	}

	/**
	 * @param string $key     Settings key.
	 * @param bool   $private Whether this PEM is a private key.
	 */
	private function keep_previous_key_when_invalid( string $key, bool $private ): void {
		$field = $this->get_field_key( $key );
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}
		$value = trim( (string) wp_unslash( $_POST[ $field ] ) );
		if ( '' === $value ) {
			return;
		}
		$valid = $private ? \PayGlocal\Admin\Config_Check::private_key_is_valid( $value ) : \PayGlocal\Admin\Config_Check::public_key_is_valid( $value );
		if ( $valid ) {
			return;
		}
		$_POST[ $field ] = $this->get_option( $key, '' );
		\PayGlocal\Admin\Admin_Flash::add_error( \PayGlocal\Admin\Config_Check::message( $private ? 'private_pem_invalid' : 'public_pem_invalid' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function settings_snapshot(): array {
		$supported = get_option( 'payglocal_supported_currencies', array() );
		if ( ! is_array( $supported ) || array() === $supported ) {
			$supported = \PayGlocal\Currency\Rate_Table::default_supported();
		}

		return array(
			'enabled'            => (string) $this->get_option( 'enabled', 'no' ),
			'merchant_id'        => (string) $this->get_option( 'merchant_id', '' ),
			'public_kid'         => (string) $this->get_option( 'public_kid', '' ),
			'private_kid'        => (string) $this->get_option( 'private_kid', '' ),
			'public_pem'         => (string) $this->get_option( 'public_pem', '' ),
			'private_pem'        => (string) $this->get_option( 'private_pem', '' ),
			'conversion_enabled' => (string) get_option( 'payglocal_conversion_enabled', 'yes' ),
			'store_currency'     => (string) get_option( 'woocommerce_currency', 'INR' ),
			'supported'          => $supported,
			'rates'              => ( new \PayGlocal\Currency\Exchange_Rates() )->all(),
			'openssl'            => function_exists( 'openssl_pkey_get_public' ),
			'rsa_math'           => function_exists( 'gmp_powm' ) || function_exists( 'bcpowmod' ),
		);
	}

	/**
	 * @param int $order_id Order id.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'payglocal' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$this->note_catalog_drift( $order );

		$charge = Payment_Charge::from_order(
			(string) $order->get_currency(),
			(string) $order->get_total(),
			$this->ignored_request_amounts()
		);

		if ( ! $this->currency_allowed( $charge['txnCurrency'] ) ) {
			wc_add_notice( __( 'This currency is not enabled for PayGlocal.', 'payglocal' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$txn_id   = strtoupper( 'PG' . $order->get_id() . bin2hex( random_bytes( 4 ) ) );
		$unique   = $this->unique_id();
		$payload  = $this->payload( $order, $charge, $txn_id, $unique );

		try {
			$sealed = $this->tokens->seal(
				$payload,
				$this->get_option( 'merchant_id' ),
				$this->get_option( 'public_kid' ),
				$this->get_option( 'public_pem' ),
				$this->get_option( 'private_kid' ),
				$this->get_option( 'private_pem' )
			);
			$response = $this->post( $this->endpoint( 'payments/initiate/paycollect' ), $sealed['jwe'], $sealed['jws'] );
		} catch ( \Throwable $error ) {
			wc_add_notice( esc_html( $error->getMessage() ), 'error' );
			$order->add_order_note( sprintf( 'PayGlocal request failed: %s', $error->getMessage() ) );
			return array( 'result' => 'failure' );
		}

		$redirect = $response['data']['redirectUrl'] ?? '';
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			wc_add_notice( __( 'PayGlocal did not return a payment page.', 'payglocal' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_payglocal_merchant_txn_id', $txn_id );
		$order->update_meta_data( '_payglocal_merchant_unique_id', $unique );
		$order->update_meta_data( '_payglocal_gid', (string) ( $response['gid'] ?? '' ) );
		$order->update_meta_data( '_payglocal_status_url', (string) ( $response['data']['statusUrl'] ?? '' ) );
		$order->update_meta_data( '_payglocal_charged_amount', $charge['totalAmount'] );
		$order->update_meta_data( '_payglocal_charged_currency', $charge['txnCurrency'] );
		$order->save();
		$order->add_order_note(
			sprintf(
				'Customer sent to PayGlocal for %s %s.',
				$charge['txnCurrency'],
				$charge['totalAmount']
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Refund amount in the order currency.
	 * @param string     $reason   Reason.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		unset( $reason );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'payglocal_refund', __( 'Order not found.', 'payglocal' ) );
		}

		$gid = (string) $order->get_meta( '_payglocal_gid' );
		if ( '' === $gid ) {
			return new \WP_Error( 'payglocal_refund', __( 'This order has no PayGlocal transaction id.', 'payglocal' ) );
		}

		$order_total = (float) $order->get_total();
		$full        = null === $amount || (float) $amount >= $order_total;
		$payload     = array(
			'merchantTxnId' => strtoupper( 'R' . $order->get_id() . bin2hex( random_bytes( 3 ) ) ),
			'refundType'    => $full ? 'F' : 'P',
		);

		if ( ! $full ) {
			$charge = Payment_Charge::from_order( (string) $order->get_currency(), (string) $amount, array() );
			$payload['paymentData'] = array(
				'totalAmount' => $charge['totalAmount'],
				'txnCurrency' => $charge['txnCurrency'],
			);
		}

		try {
			$sealed = $this->tokens->seal(
				$payload,
				$this->get_option( 'merchant_id' ),
				$this->get_option( 'public_kid' ),
				$this->get_option( 'public_pem' ),
				$this->get_option( 'private_kid' ),
				$this->get_option( 'private_pem' )
			);
			$this->post( $this->endpoint( 'payments/' . rawurlencode( $gid ) . '/refund' ), $sealed['jwe'], $sealed['jws'] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'payglocal_refund', $error->getMessage() );
		}

		$order->add_order_note(
			sprintf(
				'PayGlocal refund requested in %s.',
				(string) $order->get_currency()
			)
		);

		return true;
	}

	public function handle_callback(): void {
		$token = '';
		if ( isset( $_POST['x-gl-token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_POST['x-gl-token'] ) );
		} elseif ( isset( $_REQUEST['x-gl-token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_REQUEST['x-gl-token'] ) );
		}

		try {
			if ( '' === $token ) {
				throw new \RuntimeException( 'Missing PayGlocal response token.' );
			}
			$payload = $this->tokens->verify( $token, $this->get_option( 'public_pem' ) );
			$order   = $this->order_from_payload( $payload );
			if ( ! $order ) {
				throw new \RuntimeException( 'PayGlocal response did not match an order.' );
			}

			$merchant = (string) ( $payload['x-gl-merchantId'] ?? '' );
			if ( '' !== $merchant && $merchant !== $this->get_option( 'merchant_id' ) ) {
				throw new \RuntimeException( 'PayGlocal merchant id did not match.' );
			}

			$this->assert_callback_amount( $order, $payload );
			$status = strtoupper( (string) ( $payload['status'] ?? '' ) );
			$gid    = (string) ( $payload['gid'] ?? $payload['x-gl-gid'] ?? '' );
			$known  = (string) $order->get_meta( '_payglocal_gid' );
			if ( '' !== $known && '' !== $gid && $known !== $gid ) {
				throw new \RuntimeException( 'PayGlocal transaction id did not match the order.' );
			}

			if ( in_array( $status, array( 'SENT_FOR_CAPTURE', 'CAPTURED', 'SUCCESS' ), true ) ) {
				if ( ! $order->is_paid() ) {
					$order->payment_complete( $gid );
					$order->add_order_note( sprintf( 'PayGlocal confirmed %s for %s %s.', $status, $order->get_currency(), $order->get_total() ) );
				}
				wp_safe_redirect( $order->get_checkout_order_received_url() );
				exit;
			}

			$order->update_status( 'failed', sprintf( 'PayGlocal returned %s.', $status ) );
			wc_add_notice( __( 'Payment was not completed.', 'payglocal' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		} catch ( \Throwable $error ) {
			wc_add_notice( esc_html( $error->getMessage() ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Record when a line no longer matches the INR catalog at the rate locked on the order.
	 * The charge still uses the order total, which is the amount checkout showed.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function note_catalog_drift( $order ): void {
		$rate = $order->get_meta( '_payglocal_rate' );
		if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
			return;
		}

		$converter = new Price_Converter(
			array(
				(string) $order->get_currency() => (string) $rate,
			)
		);

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			$base = $item->get_meta( '_payglocal_base_unit_price', true );
			if ( '' === $base || ! is_numeric( $base ) ) {
				continue;
			}
			$matches = Payment_Charge::line_matches(
				(string) $base,
				(string) $item->get_quantity(),
				(string) $item->get_subtotal(),
				(string) $order->get_currency(),
				$converter
			);
			if ( ! $matches ) {
				$order->add_order_note( __( 'A line total differs from the INR catalog converted at the rate saved on this order. PayGlocal will charge the checkout total.', 'payglocal' ) );
				return;
			}
		}
	}

	/**
	 * @param \WC_Order            $order   Order.
	 * @param array<string, mixed> $payload Callback payload.
	 */
	private function assert_callback_amount( $order, array $payload ): void {
		$amount   = $payload['paymentData']['totalAmount'] ?? $payload['totalAmount'] ?? $payload['amount'] ?? null;
		$currency = $payload['paymentData']['txnCurrency'] ?? $payload['txnCurrency'] ?? $payload['currency'] ?? null;

		if ( null !== $currency && strtoupper( (string) $currency ) !== strtoupper( (string) $order->get_currency() ) ) {
			throw new \RuntimeException( 'PayGlocal currency did not match the order.' );
		}

		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return;
		}

		$expected = Payment_Charge::from_order( (string) $order->get_currency(), (string) $order->get_total(), array() );
		$received = Payment_Charge::from_order( (string) $order->get_currency(), (string) $amount, array() );
		if ( $expected['totalAmount'] !== $received['totalAmount'] ) {
			throw new \RuntimeException( 'PayGlocal amount did not match the order.' );
		}
	}

	/**
	 * @param array<string, mixed> $payload Callback payload.
	 * @return \WC_Order|null
	 */
	private function order_from_payload( array $payload ) {
		$unique = (string) ( $payload['merchantUniqueId'] ?? '' );
		$txn    = (string) ( $payload['merchantTxnId'] ?? '' );

		foreach ( array( '_payglocal_merchant_unique_id' => $unique, '_payglocal_merchant_txn_id' => $txn ) as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$orders = wc_get_orders(
				array(
					'limit'      => 1,
					'meta_key'   => $key,
					'meta_value' => $value,
				)
			);
			if ( ! empty( $orders ) ) {
				return $orders[0];
			}
		}

		return null;
	}

	/**
	 * @param \WC_Order                         $order  Order.
	 * @param array{totalAmount: string, txnCurrency: string} $charge Charge.
	 * @return array<string, mixed>
	 */
	private function payload( $order, array $charge, string $txn_id, string $unique ): array {
		return array(
			'merchantTxnId'       => $txn_id,
			'merchantUniqueId'    => $unique,
			'paymentData'         => array(
				'totalAmount' => $charge['totalAmount'],
				'txnCurrency' => $charge['txnCurrency'],
				'billingData' => array(
					'firstName'         => $order->get_billing_first_name(),
					'lastName'          => $order->get_billing_last_name(),
					'addressStreet1'    => $order->get_billing_address_1(),
					'addressStreet2'    => $order->get_billing_address_2(),
					'addressCity'       => $order->get_billing_city(),
					'addressState'      => $order->get_billing_state(),
					'addressPostalCode' => $order->get_billing_postcode(),
					'addressCountry'    => $order->get_billing_country(),
					'emailId'           => $order->get_billing_email(),
				),
			),
			'merchantCallbackURL' => WC()->api_request_url( 'payglocal' ),
		);
	}

	/**
	 * @param array{jwe: string, jws: string} $sealed Tokens. Not used; post receives the parts.
	 * @return array<string, mixed>
	 */
	private function post( string $url, string $jwe, string $jws ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'        => 'text/plain',
					'x-gl-token-external' => $jws,
				),
				'body'    => $jwe,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'PayGlocal rejected the payment request.';
			throw new \RuntimeException( $message );
		}

		return $data;
	}

	private function endpoint( string $path ): string {
		$root = 'production' === $this->get_option( 'mode' ) ? 'https://api.prod.payglocal.in' : 'https://api.uat.payglocal.in';
		return $root . '/gl/v1/' . ltrim( $path, '/' );
	}

	private function currency_allowed( string $currency ): bool {
		if ( ! function_exists( 'payglocal' ) ) {
			return true;
		}
		return payglocal()->currency()->is_enabled()
			? in_array( $currency, payglocal()->currency()->supported(), true ) || 'INR' === $currency
			: true;
	}

	private function unique_id(): string {
		$unique = '';
		for ( $i = 0; $i < 16; $i++ ) {
			$unique .= (string) random_int( 0, 9 );
		}
		return $unique;
	}

	/**
	 * Captured only so it can be discarded. These values never affect the charge.
	 *
	 * @return array<string, mixed>
	 */
	private function ignored_request_amounts(): array {
		$keys = array( 'amount', 'currency', 'totalAmount', 'txnCurrency' );
		$bag  = array();
		foreach ( $keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$bag[ $key ] = wp_unslash( $_POST[ $key ] );
			}
		}
		return $bag;
	}
}
