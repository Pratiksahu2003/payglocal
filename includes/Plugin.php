<?php
/**
 * Plugin bootstrap.
 *
 * @package PayGlocal
 */

namespace PayGlocal;

use PayGlocal\Admin\Admin_Flash;
use PayGlocal\Admin\Currency_Settings;
use PayGlocal\Admin\Notices;
use PayGlocal\Admin\Settings_Screen;
use PayGlocal\Currency\Country_Currency_Map;
use PayGlocal\Currency\Currency_Manager;
use PayGlocal\Currency\Currency_Resolver;
use PayGlocal\Currency\Exchange_Rates;
use PayGlocal\Currency\Price_Converter;
use PayGlocal\Frontend\Ajax;
use PayGlocal\Frontend\Switcher;
use PayGlocal\Gateway\Gateway;
use PayGlocal\Integration\Store_Api;
use PayGlocal\Pricing\Cart_Adjustments;
use PayGlocal\Pricing\Catalog_Query;
use PayGlocal\Pricing\Price_Adjustment;
use PayGlocal\Pricing\Price_Filters;

/**
 * Wires the currency engine, storefront, and PayGlocal gateway.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Currency_Manager $currency;

	private Exchange_Rates $exchange_rates;

	private Price_Converter $converter;

	private Switcher $switcher;

	private function __construct() {
		$resolver              = new Currency_Resolver();
		$this->exchange_rates  = new Exchange_Rates();
		$this->converter       = new Price_Converter( $this->exchange_rates->all() );
		$this->currency        = new Currency_Manager( $resolver, $this->exchange_rates );
		$adjustment            = new Price_Adjustment( $this->converter );
		$prices                = new Price_Filters( $this->currency, $this->converter, $adjustment );
		$cart                  = new Cart_Adjustments( $this->currency, $this->converter, $this->exchange_rates );
		$catalog               = new Catalog_Query( $this->currency, $this->converter );
		$this->switcher        = new Switcher( $this->currency, $resolver, new Country_Currency_Map() );
		$ajax                  = new Ajax( $this->currency, $cart );
		$store                 = new Store_Api( $this->currency, $this->converter );

		add_action( 'init', array( $this, 'boot_currency' ), 20 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( Settings_Screen::class, 'register_menu' ), 70 );
		add_action( 'admin_notices', array( Notices::class, 'render' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PAYGLOCAL_FILE ), array( $this, 'plugin_links' ) );
		add_action( 'payglocal_refresh_exchange_rates', array( $this->exchange_rates, 'refresh' ) );

		$prices->register();
		$cart->register();
		$catalog->register();
		$this->switcher->register();
		$ajax->register();
		$store->register();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function currency(): Currency_Manager {
		return $this->currency;
	}

	public function exchange_rates(): Exchange_Rates {
		return $this->exchange_rates;
	}

	public function converter(): Price_Converter {
		return $this->converter;
	}

	public function switcher(): Switcher {
		return $this->switcher;
	}

	public function boot_currency(): void {
		try {
			$decimals = (int) get_option( 'woocommerce_price_num_decimals', 2 );
			$this->converter->set_base_decimals( $decimals );
			$this->converter->set_rates( $this->exchange_rates->all() );
			$this->currency->boot();
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'payglocal', false, dirname( plugin_basename( PAYGLOCAL_FILE ) ) . '/languages' );
	}

	/**
	 * @param array<int, string> $gateways Gateway class names.
	 * @return array<int, string>
	 */
	public function register_gateway( $gateways ) {
		if ( ! is_array( $gateways ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			return is_array( $gateways ) ? $gateways : array();
		}
		$gateways[] = Gateway::class;
		return $gateways;
	}

	/**
	 * @param array<int, object> $pages Settings pages.
	 * @return array<int, object>
	 */
	public function register_settings( $pages ) {
		if ( ! is_array( $pages ) || ! class_exists( 'WC_Settings_Page' ) ) {
			return is_array( $pages ) ? $pages : array();
		}
		$pages[] = new Currency_Settings();
		return $pages;
	}

	/**
	 * @param array<int, string> $links Plugin row links.
	 * @return array<int, string>
	 */
	public function plugin_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$url = admin_url( 'admin.php?page=payglocal' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payglocal' ) . '</a>' );
		return $links;
	}

	/**
	 * @param mixed       $amount   INR amount.
	 * @param string|null $currency Target currency.
	 * @return mixed
	 */
	public function convert_price( $amount, $currency = null ) {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return $amount;
		}
		$currency = $currency ? strtoupper( $currency ) : $this->currency->get_active_currency();
		if ( 'INR' === $currency ) {
			return $amount;
		}
		return $this->converter->convert_amount( (string) $amount, $currency );
	}
}
