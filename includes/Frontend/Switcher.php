<?php
/**
 * Country and currency switcher.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Frontend;

use PayGlocal\Currency\Country_Currency_Map;
use PayGlocal\Currency\Currency_Manager;
use PayGlocal\Currency\Currency_Resolver;

/**
 * Renders the country and currency selector.
 */
final class Switcher {

	private Currency_Manager $currency;

	private Currency_Resolver $resolver;

	private Country_Currency_Map $countries;

	public function __construct( Currency_Manager $currency, Currency_Resolver $resolver, Country_Currency_Map $countries ) {
		$this->currency  = $currency;
		$this->resolver  = $resolver;
		$this->countries = $countries;
	}

	public function register(): void {
		add_shortcode( 'payglocal_currency_switcher', array( $this, 'shortcode' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		if ( 'yes' === get_option( 'payglocal_show_switcher', 'yes' ) ) {
			add_action( 'wp_footer', array( $this, 'render' ), 20 );
		}
	}

	public function assets(): void {
		if ( is_admin() || ! $this->currency->is_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'payglocal-switcher',
			plugins_url( 'assets/css/switcher.css', PAYGLOCAL_FILE ),
			array(),
			PAYGLOCAL_VERSION
		);

		wp_enqueue_script(
			'payglocal-switcher',
			plugins_url( 'assets/js/switcher.js', PAYGLOCAL_FILE ),
			array(),
			PAYGLOCAL_VERSION,
			true
		);

		wp_localize_script(
			'payglocal-switcher',
			'PayGlocalCurrency',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'payglocal_currency' ),
			)
		);
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function body_class( $classes ) {
		if ( ! is_array( $classes ) || ! $this->currency->is_enabled() ) {
			return $classes;
		}
		$classes[] = 'payglocal-currency-' . strtolower( $this->currency->get_active_currency() );
		return $classes;
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = array() ): string {
		unset( $atts );
		ob_start();
		$this->render();
		return (string) ob_get_clean();
	}

	public function render(): void {
		if ( ! $this->currency->is_enabled() ) {
			return;
		}

		$active_country  = $this->currency->get_country();
		$active_currency = $this->currency->get_active_currency();
		$countries       = $this->country_options();
		$currencies      = $this->currency_options();

		echo '<div class="payglocal-switcher" data-payglocal-switcher>';
		echo '<p class="payglocal-switcher__status" data-payglocal-status aria-live="polite"></p>';

		echo '<label class="payglocal-switcher__field"><span>' . esc_html__( 'Country', 'payglocal' ) . '</span>';
		echo '<select data-payglocal-country>';
		foreach ( $countries as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $active_country, $code, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></label>';

		echo '<label class="payglocal-switcher__field"><span>' . esc_html__( 'Currency', 'payglocal' ) . '</span>';
		echo '<select data-payglocal-currency>';
		foreach ( $currencies as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $active_currency, $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '</div>';
	}

	public function register_widget(): void {
		register_widget( Switcher_Widget::class );
	}

	/**
	 * @return array<string, string>
	 */
	private function country_options(): array {
		$options = array();
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$wc_countries = WC()->countries->get_countries();
			if ( is_array( $wc_countries ) ) {
				foreach ( $wc_countries as $code => $name ) {
					$code = strtoupper( (string) $code );
					if ( $this->countries->knows( $code ) ) {
						$options[ $code ] = (string) $name;
					}
				}
			}
		}

		if ( array() === $options ) {
			foreach ( array( 'IN', 'US', 'DE', 'GB', 'JP', 'FR', 'AU', 'CA', 'SG', 'AE' ) as $code ) {
				$options[ $code ] = $code;
			}
		}

		asort( $options );
		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function currency_options(): array {
		$rates   = array();
		if ( function_exists( 'payglocal' ) ) {
			$rates = payglocal()->exchange_rates()->all();
		}

		$options = array();
		foreach ( $this->currency->supported() as $code ) {
			if ( Currency_Manager::BASE !== $code && ! $this->resolver->usable( $code, $this->currency->supported(), $rates ) ) {
				continue;
			}
			$symbol           = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $code ) : $code;
			$options[ $code ] = $code . ' (' . wp_strip_all_tags( (string) $symbol ) . ')';
		}

		if ( ! isset( $options[ Currency_Manager::BASE ] ) ) {
			$options = array( Currency_Manager::BASE => 'INR' ) + $options;
		}

		return $options;
	}
}
