<?php
/**
 * Cart, coupon, shipping, and order currency consistency.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Pricing;

use PayGlocal\Currency\Currency_Manager;
use PayGlocal\Currency\Exchange_Rates;
use PayGlocal\Currency\Price_Converter;

/**
 * Keeps cart, shipping, coupons, and the created order on the active currency.
 */
final class Cart_Adjustments {

	private Currency_Manager $currency;

	private Price_Converter $converter;

	private Exchange_Rates $rates;

	private Coupon_Adjustment $coupons;

	private Shipping_Adjustment $shipping;

	/**
	 * Converted coupon amounts for this request, keyed by object hash.
	 *
	 * @var array<string, string>
	 */
	private array $coupon_amounts = array();

	public function __construct( Currency_Manager $currency, Price_Converter $converter, Exchange_Rates $rates ) {
		$this->currency = $currency;
		$this->converter = $converter;
		$this->rates     = $rates;
		$this->coupons   = new Coupon_Adjustment();
		$this->shipping  = new Shipping_Adjustment();
	}

	public function register(): void {
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'coupon_amount' ), 1000, 2 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'coupon_threshold' ), 1000, 2 );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( $this, 'coupon_threshold' ), 1000, 2 );
		add_filter( 'woocommerce_package_rates', array( $this, 'package_rates' ), 1000, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'shipping_packages' ), 1000 );
		add_filter( 'woocommerce_shipping_free_shipping_option', array( $this, 'free_shipping_option' ), 1000, 3 );
		add_filter( 'woocommerce_cart_hash', array( $this, 'cart_hash' ), 1000, 2 );

		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'checkout_country' ), 1 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'stamp_line' ), 20, 4 );

		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this, 'blocks_customer_country' ), 10, 2 );
	}

	/**
	 * @param mixed      $amount Coupon amount.
	 * @param \WC_Coupon $coupon Coupon.
	 * @return mixed
	 */
	public function coupon_amount( $amount, $coupon ) {
		if ( ! $this->should_convert() || ! is_object( $coupon ) || ! method_exists( $coupon, 'get_discount_type' ) ) {
			return $amount;
		}
		if ( ! is_numeric( $amount ) ) {
			return $amount;
		}

		$key = spl_object_hash( $coupon );
		if ( isset( $this->coupon_amounts[ $key ] ) && abs( (float) $this->coupon_amounts[ $key ] - (float) $amount ) < 0.0000001 ) {
			return $amount;
		}

		$converted = $this->coupons->amount( $amount, (string) $coupon->get_discount_type(), $this->currency->get_active_currency(), $this->converter );
		if ( is_numeric( $converted ) ) {
			$this->coupon_amounts[ $key ] = (string) $converted;
		}
		return $converted;
	}

	/**
	 * @param mixed      $amount Minimum or maximum spend stored in INR.
	 * @param \WC_Coupon $coupon Coupon.
	 * @return mixed
	 */
	public function coupon_threshold( $amount, $coupon ) {
		unset( $coupon );
		if ( ! $this->should_convert() || null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return $amount;
		}
		return $this->converter->convert_amount( (string) $amount, $this->currency->get_active_currency() );
	}

	/**
	 * @param array<string, \WC_Shipping_Rate> $rates   Calculated rates.
	 * @param array<string, mixed>             $package Shipping package.
	 * @return array<string, \WC_Shipping_Rate>
	 */
	public function package_rates( $rates, $package ) {
		unset( $package );
		if ( ! $this->should_convert() || ! is_array( $rates ) ) {
			return $rates;
		}

		$active = $this->currency->get_active_currency();
		foreach ( $rates as $rate ) {
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_meta' ) ) {
				continue;
			}
			$already = $rate->get_meta( 'payglocal_fx' ) === $active;
			$result  = $this->shipping->convert_rate( $rate->get_cost(), $rate->get_taxes(), $active, $this->converter, $already );
			if ( ! $already && $result['converted'] ) {
				$rate->set_cost( $result['cost'] );
				$rate->set_taxes( $result['taxes'] );
				$rate->add_meta_data( 'payglocal_fx', $active, true );
			}
		}

		return $rates;
	}

	/**
	 * @param array<int, array<string, mixed>> $packages Shipping packages.
	 * @return array<int, array<string, mixed>>
	 */
	public function shipping_packages( $packages ) {
		if ( ! $this->currency->is_enabled() || ! is_array( $packages ) ) {
			return $packages;
		}
		foreach ( $packages as $index => $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$packages[ $index ]['payglocal_currency']   = $this->currency->get_active_currency();
			$packages[ $index ]['payglocal_rate_token'] = $this->rates->cache_token();
		}
		return $packages;
	}

	/**
	 * @param mixed  $value  Option value.
	 * @param string $key    Option key.
	 * @param object $method Shipping method.
	 * @return mixed
	 */
	public function free_shipping_option( $value, $key, $method ) {
		unset( $method );
		if ( 'min_amount' !== $key || ! $this->should_convert() || ! is_numeric( $value ) ) {
			return $value;
		}
		return $this->converter->convert_amount( (string) $value, $this->currency->get_active_currency() );
	}

	/**
	 * @param string   $hash Existing cart hash.
	 * @param \WC_Cart $cart Cart.
	 */
	public function cart_hash( $hash, $cart ) {
		unset( $cart );
		if ( ! $this->currency->is_enabled() ) {
			return $hash;
		}
		return md5( (string) $hash . '|' . $this->currency->get_active_currency() . '|' . $this->rates->cache_token() );
	}

	/**
	 * @param string $post_data Checkout posted fields.
	 */
	public function checkout_country( $post_data ): void {
		$parsed = array();
		parse_str( (string) $post_data, $parsed );
		$country = '';
		if ( ! empty( $parsed['billing_country'] ) ) {
			$country = (string) $parsed['billing_country'];
		} elseif ( ! empty( $parsed['shipping_country'] ) ) {
			$country = (string) $parsed['shipping_country'];
		}
		if ( '' === $country ) {
			return;
		}
		$this->currency->apply_country( $country );
		$this->flush_shipping();
	}

	/**
	 * @param \WC_Customer     $customer Customer.
	 * @param \WP_REST_Request $request  Store API request.
	 */
	public function blocks_customer_country( $customer, $request ): void {
		unset( $request );
		if ( ! is_object( $customer ) || ! method_exists( $customer, 'get_billing_country' ) ) {
			return;
		}
		$country = (string) $customer->get_billing_country();
		if ( '' === $country ) {
			$country = (string) $customer->get_shipping_country();
		}
		if ( '' !== $country ) {
			$this->currency->apply_country( $country );
			$this->flush_shipping();
		}
	}

	/**
	 * @param \WC_Order $order Order being created.
	 */
	public function stamp_order( $order ): void {
		if ( ! is_object( $order ) || ! $this->currency->is_enabled() ) {
			return;
		}
		$active = $this->currency->get_active_currency();
		$order->set_currency( $active );
		$order->update_meta_data( '_payglocal_base_currency', Currency_Manager::BASE );
		$order->update_meta_data( '_payglocal_currency', $active );
		$order->update_meta_data( '_payglocal_country', $this->currency->get_country() );
		if ( Currency_Manager::BASE === $active ) {
			$order->update_meta_data( '_payglocal_rate', '1' );
		} else {
			$order->update_meta_data( '_payglocal_rate', $this->converter->rate( $active ) );
		}
	}

	/**
	 * @param \WC_Order_Item_Product $item          Line item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array<string, mixed>   $values        Cart values.
	 * @param \WC_Order              $order         Order.
	 */
	public function stamp_line( $item, $cart_item_key, $values, $order ): void {
		unset( $cart_item_key, $order );
		if ( ! is_object( $item ) || empty( $values['data'] ) || ! is_object( $values['data'] ) ) {
			return;
		}
		$product = $values['data'];
		if ( ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$base = get_post_meta( $product->get_id(), '_price', true );
		if ( '' === $base ) {
			$base = get_post_meta( $product->get_id(), '_regular_price', true );
		}
		if ( '' !== $base && is_numeric( $base ) ) {
			$item->add_meta_data( '_payglocal_base_unit_price', $base, true );
		}
	}

	public function flush_shipping(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}
		$packages = WC()->cart->get_shipping_packages();
		foreach ( array_keys( $packages ) as $index ) {
			WC()->session->set( 'shipping_for_package_' . $index, false );
		}
	}

	private function should_convert(): bool {
		if ( ! $this->currency->is_enabled() || ! \PayGlocal\Storefront::is_request() ) {
			return false;
		}
		return Currency_Manager::BASE !== $this->currency->get_active_currency();
	}
}
