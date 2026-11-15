<?php
/**
 * WooCommerce product price hooks.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Pricing;

use PayGlocal\Currency\Currency_Manager;
use PayGlocal\Currency\Price_Converter;

/**
 * Converts product prices at the data layer so every template reads the same currency.
 *
 * Cart HTML hooks are registered so mini-cart, cart, and checkout markup carry the
 * active currency. They do not convert amounts again. Those amounts already come
 * from the product price filters.
 */
final class Price_Filters {

	private Currency_Manager $currency;

	private Price_Converter $converter;

	private Price_Adjustment $adjustment;

	private bool $locked = false;

	public function __construct( Currency_Manager $currency, Price_Converter $converter, Price_Adjustment $adjustment ) {
		$this->currency   = $currency;
		$this->converter  = $converter;
		$this->adjustment = $adjustment;
	}

	public function register(): void {
		$price_hooks = array(
			'woocommerce_product_get_price',
			'woocommerce_product_get_regular_price',
			'woocommerce_product_get_sale_price',
			'woocommerce_product_variation_get_price',
			'woocommerce_product_variation_get_regular_price',
			'woocommerce_product_variation_get_sale_price',
		);

		foreach ( $price_hooks as $hook ) {
			add_filter( $hook, array( $this, 'filter_product_price' ), 1000, 2 );
		}

		add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_price' ), 1000, 3 );
		add_filter( 'woocommerce_variation_prices_regular_price', array( $this, 'filter_variation_price' ), 1000, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'filter_variation_price' ), 1000, 3 );
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'variation_hash' ), 10, 1 );

		add_filter( 'woocommerce_currency', array( $this, 'filter_currency' ), 1000 );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'filter_symbol' ), 1000, 2 );
		add_filter( 'wc_get_price_decimals', array( $this, 'filter_decimals' ), 1000 );
		add_filter( 'wc_price_args', array( $this, 'filter_price_args' ), 1000 );

		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 1000, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'tag_money_html' ), 1000, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'tag_money_html' ), 1000, 3 );
		add_filter( 'woocommerce_cart_subtotal', array( $this, 'tag_money_html_single' ), 1000, 3 );
		add_filter( 'woocommerce_cart_totals_order_total_html', array( $this, 'tag_money_html_single' ), 1000 );

		add_filter( 'woocommerce_subscriptions_product_sign_up_fee', array( $this, 'filter_sign_up_fee' ), 1000, 2 );
	}

	/**
	 * @param mixed $price Raw INR price from product data.
	 * @return mixed
	 */
	public function filter_product_price( $price, $product = null ) {
		if ( ! $this->should_convert() || $this->locked ) {
			return $price;
		}

		$this->locked = true;
		$prop         = (string) current_filter();
		$result       = $this->adjustment->adjust( $price, is_object( $product ) ? $product : null, $prop, $this->currency->get_active_currency() );
		$this->locked = false;
		return $result;
	}

	/**
	 * @param mixed $price Raw variation price.
	 * @return mixed
	 */
	public function filter_variation_price( $price, $variation = null, $parent = null ) {
		unset( $parent );
		return $this->filter_product_price( $price, $variation );
	}

	/**
	 * @param array<int, mixed> $hash Variation price cache key.
	 * @return array<int, mixed>
	 */
	public function variation_hash( $hash ) {
		if ( ! is_array( $hash ) || ! $this->currency->is_enabled() || ! \PayGlocal\Storefront::is_request() ) {
			return $hash;
		}
		$hash[] = $this->currency->get_active_currency();
		return $hash;
	}

	/**
	 * @param string $currency Store currency option.
	 */
	public function filter_currency( $currency ) {
		if ( ! $this->should_localize() ) {
			return $currency;
		}
		return $this->currency->get_active_currency();
	}

	/**
	 * @param string $symbol   Symbol WooCommerce resolved.
	 * @param string $currency Currency the symbol belongs to.
	 */
	public function filter_symbol( $symbol, $currency = '' ) {
		if ( ! $this->should_localize() ) {
			return $symbol;
		}
		$active = $this->currency->get_active_currency();
		if ( $currency && strtoupper( (string) $currency ) !== $active ) {
			return $symbol;
		}
		return $symbol;
	}

	/**
	 * @param int $decimals Store decimal setting.
	 */
	public function filter_decimals( $decimals ) {
		if ( ! $this->should_localize() ) {
			return $decimals;
		}
		$active = $this->currency->get_active_currency();
		if ( Currency_Manager::BASE === $active ) {
			return $decimals;
		}
		return $this->converter->decimals( $active );
	}

	/**
	 * @param array<string, mixed> $args wc_price arguments.
	 * @return array<string, mixed>
	 */
	public function filter_price_args( $args ) {
		if ( ! is_array( $args ) || empty( $args['currency'] ) ) {
			return $args;
		}
		$code = strtoupper( (string) $args['currency'] );
		if ( Currency_Manager::BASE === $code ) {
			return $args;
		}
		$args['decimals'] = $this->converter->decimals( $code );
		return $args;
	}

	/**
	 * @param string $html Price HTML already built from converted amounts.
	 */
	public function filter_price_html( $html, $product = null ) {
		unset( $product );
		if ( ! $this->should_localize() || ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		return $this->tag( $html );
	}

	/**
	 * @param string $html Cart money HTML.
	 */
	public function tag_money_html( $html, $cart_item = null, $cart_item_key = '' ) {
		unset( $cart_item, $cart_item_key );
		return $this->tag_money_html_single( $html );
	}

	/**
	 * @param string $html Money HTML.
	 */
	public function tag_money_html_single( $html ) {
		if ( ! $this->should_localize() || ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		return $this->tag( $html );
	}

	/**
	 * @param mixed $fee Sign-up fee stored in INR.
	 * @return mixed
	 */
	public function filter_sign_up_fee( $fee, $product = null ) {
		unset( $product );
		if ( ! $this->should_convert() || null === $fee || '' === $fee || ! is_numeric( $fee ) ) {
			return $fee;
		}
		return $this->converter->convert_amount( (string) $fee, $this->currency->get_active_currency() );
	}

	private function tag( string $html ): string {
		if ( false !== strpos( $html, 'payglocal-money' ) ) {
			return $html;
		}
		$currency = esc_attr( $this->currency->get_active_currency() );
		return '<span class="payglocal-money" data-currency="' . $currency . '">' . $html . '</span>';
	}

	private function should_convert(): bool {
		if ( ! $this->should_localize() ) {
			return false;
		}
		if ( doing_action( 'woocommerce_before_product_object_save' ) || doing_action( 'woocommerce_admin_process_product_object' ) ) {
			return false;
		}
		return Currency_Manager::BASE !== $this->currency->get_active_currency();
	}

	private function should_localize(): bool {
		return $this->currency->is_enabled() && \PayGlocal\Storefront::is_request();
	}
}
