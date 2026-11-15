<?php
/**
 * Classic catalog price filter and search query bounds.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Pricing;

use PayGlocal\Currency\Currency_Manager;
use PayGlocal\Currency\Price_Converter;

/**
 * Shows the price slider in the active currency and queries products in INR.
 */
final class Catalog_Query {

	private Currency_Manager $currency;

	private Price_Converter $converter;

	/**
	 * @var array<string, mixed>
	 */
	private array $original_get = array();

	private bool $adjusted = false;

	public function __construct( Currency_Manager $currency, Price_Converter $converter ) {
		$this->currency  = $currency;
		$this->converter = $converter;
	}

	public function register(): void {
		add_filter( 'woocommerce_price_filter_widget_min_amount', array( $this, 'display_bound' ), 1000 );
		add_filter( 'woocommerce_price_filter_widget_max_amount', array( $this, 'display_bound' ), 1000 );
		add_filter( 'posts_clauses', array( $this, 'to_base_before_query' ), 9, 2 );
		add_filter( 'posts_clauses', array( $this, 'restore_after_query' ), 11, 2 );
	}

	/**
	 * @param mixed $amount INR bound from the lookup table.
	 * @return mixed
	 */
	public function display_bound( $amount ) {
		if ( ! $this->should_convert() || ! is_numeric( $amount ) ) {
			return $amount;
		}
		return $this->converter->convert_amount( (string) $amount, $this->currency->get_active_currency() );
	}

	/**
	 * @param array<string, string> $clauses SQL clauses.
	 * @param \WP_Query             $query   Query.
	 * @return array<string, string>
	 */
	public function to_base_before_query( $clauses, $query ) {
		if ( $this->adjusted || ! $this->should_convert() || ! $this->is_price_filter_query( $query ) ) {
			return $clauses;
		}

		$active = $this->currency->get_active_currency();
		foreach ( array( 'min_price', 'max_price' ) as $key ) {
			if ( ! isset( $_GET[ $key ] ) || ! is_numeric( wp_unslash( $_GET[ $key ] ) ) ) {
				continue;
			}
			$this->original_get[ $key ] = wp_unslash( $_GET[ $key ] );
			$_GET[ $key ]               = $this->converter->to_base_amount( (string) wp_unslash( $_GET[ $key ] ), $active );
		}

		$this->adjusted = array() !== $this->original_get;
		return $clauses;
	}

	/**
	 * @param array<string, string> $clauses SQL clauses.
	 * @param \WP_Query             $query   Query.
	 * @return array<string, string>
	 */
	public function restore_after_query( $clauses, $query ) {
		unset( $query );
		if ( ! $this->adjusted ) {
			return $clauses;
		}
		foreach ( $this->original_get as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$this->original_get = array();
		$this->adjusted     = false;
		return $clauses;
	}

	/**
	 * @param \WP_Query $query Query.
	 */
	private function is_price_filter_query( $query ): bool {
		if ( ! is_object( $query ) || ! method_exists( $query, 'is_main_query' ) || ! $query->is_main_query() ) {
			return false;
		}
		return isset( $_GET['min_price'] ) || isset( $_GET['max_price'] );
	}

	private function should_convert(): bool {
		if ( ! $this->currency->is_enabled() || ! \PayGlocal\Storefront::is_request() ) {
			return false;
		}
		return Currency_Manager::BASE !== $this->currency->get_active_currency();
	}
}
