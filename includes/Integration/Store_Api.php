<?php
/**
 * Store API and Blocks currency data.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Integration;

use PayGlocal\Currency\Currency_Manager;
use PayGlocal\Currency\Price_Converter;

/**
 * Keeps WooCommerce Blocks and the Store API on the same currency as classic templates.
 *
 * Product prices in the Store API already pass through the product price filters.
 * Price-range bounds and min/max query arguments come from the INR lookup table,
 * so those values are converted here.
 */
final class Store_Api {

	private Currency_Manager $currency;

	private Price_Converter $converter;

	/**
	 * @var array<string, bool>
	 */
	private array $normalized = array();

	/**
	 * @var array<string, bool>
	 */
	private array $responses = array();

	public function __construct( Currency_Manager $currency, Price_Converter $converter ) {
		$this->currency  = $currency;
		$this->converter = $converter;
	}

	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'normalize_price_query' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'convert_price_range' ), 10, 3 );

		add_action(
			'woocommerce_blocks_loaded',
			function () {
				$this->register_store_data();
				$this->register_payment_method();
			}
		);
	}

	/**
	 * @param mixed            $result  Short-circuit result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function normalize_price_query( $result, $server, $request ) {
		unset( $server );
		if ( ! $request instanceof \WP_REST_Request || ! $this->should_convert() || ! $this->is_store_route( $request ) ) {
			return $result;
		}

		$key = spl_object_hash( $request );
		if ( isset( $this->normalized[ $key ] ) ) {
			return $result;
		}
		$this->normalized[ $key ] = true;

		$decimals = $this->converter->decimals( $this->currency->get_active_currency() );
		foreach ( array( 'min_price', 'max_price' ) as $param ) {
			$value = $request->get_param( $param );
			if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
				continue;
			}
			$request->set_param(
				$param,
				$this->converter->rewrite_query_minor( (string) $value, $decimals, $this->currency->get_active_currency() )
			);
		}

		return $result;
	}

	/**
	 * @param mixed            $result  Response.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function convert_price_range( $result, $server, $request ) {
		unset( $server );
		if ( ! $result instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}
		if ( ! $this->should_convert() || ! $this->is_store_route( $request ) ) {
			return $result;
		}

		$key = spl_object_hash( $result );
		if ( isset( $this->responses[ $key ] ) ) {
			return $result;
		}
		$this->responses[ $key ] = true;

		$data = $result->get_data();
		if ( ! is_array( $data ) || empty( $data['price_range'] ) || ! is_array( $data['price_range'] ) ) {
			return $result;
		}

		$data['price_range'] = $this->convert_range( $data['price_range'] );
		$result->set_data( $data );
		return $result;
	}

	/**
	 * @param array<string, mixed> $range Price range node.
	 * @return array<string, mixed>
	 */
	private function convert_range( array $range ): array {
		$active   = $this->currency->get_active_currency();
		$decimals = isset( $range['currency_minor_unit'] ) ? (int) $range['currency_minor_unit'] : $this->converter->decimals( $active );

		foreach ( array( 'min_price', 'max_price', 'min_amount', 'max_amount' ) as $key ) {
			if ( ! isset( $range[ $key ] ) || ! is_numeric( $range[ $key ] ) ) {
				continue;
			}
			$range[ $key ] = $this->converter->rewrite_result_minor( (string) $range[ $key ], $decimals, $active );
		}

		$range['currency_code']       = $active;
		$range['currency_minor_unit'] = $this->converter->decimals( $active );
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$range['currency_symbol'] = get_woocommerce_currency_symbol( $active );
		}

		return $range;
	}

	private function register_store_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		$endpoint = 'cart';
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema' ) ) {
			$endpoint = \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => $endpoint,
				'namespace'       => 'payglocal',
				'data_callback'   => function () {
					return array(
						'currency'     => $this->currency->get_active_currency(),
						'country'      => $this->currency->get_country(),
						'baseCurrency' => Currency_Manager::BASE,
					);
				},
				'schema_callback' => static function () {
					return array(
						'currency'     => array(
							'description' => 'Currency used for prices and payment.',
							'type'        => 'string',
							'readonly'    => true,
						),
						'country'      => array(
							'description' => 'Country used to choose the currency.',
							'type'        => 'string',
							'readonly'    => true,
						),
						'baseCurrency' => array(
							'description' => 'Currency stored on products.',
							'type'        => 'string',
							'readonly'    => true,
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => 'payglocal',
					'callback'  => function ( $data ) {
						if ( ! is_array( $data ) ) {
							return;
						}
						if ( ! empty( $data['country'] ) ) {
							$this->currency->apply_country( (string) $data['country'] );
							return;
						}
						if ( ! empty( $data['currency'] ) ) {
							$this->currency->apply_currency( (string) $data['currency'] );
						}
					},
				)
			);
		}
	}

	private function register_payment_method(): void {
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
					return;
				}
				if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}
				$registry->register( new Blocks_Payment_Method() );
			}
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 */
	private function is_store_route( $request ): bool {
		$route = $request->get_route();
		return is_string( $route ) && false !== strpos( $route, '/wc/store/' );
	}

	private function should_convert(): bool {
		return $this->currency->is_enabled() && Currency_Manager::BASE !== $this->currency->get_active_currency();
	}
}
