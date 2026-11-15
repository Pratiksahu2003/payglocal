<?php
/**
 * Convert INR catalog amounts into the active currency.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Currency;

use InvalidArgumentException;

/**
 * Converts prices from the INR amounts stored on products.
 *
 * Rates are "INR for 1 unit of the foreign currency". A USD rate of 85 means
 * ₹8,500 displays as $100. Converted values are never written back to products.
 */
final class Price_Converter {

	/**
	 * @var array<string, string>
	 */
	private array $rates;

	private int $base_decimals;

	/**
	 * @param array<string, string|float|int> $rates INR per foreign unit.
	 */
	public function __construct( array $rates = array(), int $base_decimals = 2 ) {
		$this->set_rates( $rates );
		$this->base_decimals = max( 0, $base_decimals );
	}

	/**
	 * @param array<string, string|float|int> $rates INR per foreign unit.
	 */
	public function set_rates( array $rates ): void {
		$clean = array();
		foreach ( $rates as $code => $rate ) {
			$code = strtoupper( (string) $code );
			if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
				continue;
			}
			$clean[ $code ] = $this->normalize( (string) $rate );
		}
		$this->rates = $clean;
	}

	public function set_base_decimals( int $decimals ): void {
		$this->base_decimals = max( 0, $decimals );
	}

	public function has_rate( string $currency ): bool {
		$currency = strtoupper( $currency );
		return Currency_Resolver::BASE === $currency || isset( $this->rates[ $currency ] );
	}

	public function rate( string $currency ): string {
		$currency = strtoupper( $currency );
		if ( Currency_Resolver::BASE === $currency ) {
			return '1';
		}
		if ( ! isset( $this->rates[ $currency ] ) ) {
			throw new InvalidArgumentException( 'No exchange rate is cached for ' . $currency . '.' );
		}
		return $this->rates[ $currency ];
	}

	/**
	 * Decimal places used when charging and displaying a currency.
	 */
	public function decimals( string $currency ): int {
		$currency = strtoupper( $currency );
		if ( Currency_Resolver::BASE === $currency ) {
			return $this->base_decimals;
		}

		$zero = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$three = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

		if ( in_array( $currency, $zero, true ) ) {
			return 0;
		}
		if ( in_array( $currency, $three, true ) ) {
			return 3;
		}
		return 2;
	}

	/**
	 * Convert an INR amount into the target currency.
	 */
	public function convert_amount( string $amount, string $currency ): string {
		$currency = strtoupper( $currency );
		$amount   = $this->normalize( $amount );

		if ( Currency_Resolver::BASE === $currency ) {
			return $this->round_half_up( $amount, $this->base_decimals );
		}

		return $this->div_round( $amount, $this->rate( $currency ), $this->decimals( $currency ) );
	}

	/**
	 * Convert a displayed amount back into INR.
	 */
	public function to_base_amount( string $amount, string $currency ): string {
		$currency = strtoupper( $currency );
		$amount   = $this->normalize( $amount );

		if ( Currency_Resolver::BASE === $currency ) {
			return $this->round_half_up( $amount, $this->base_decimals );
		}

		return $this->mul_round( $amount, $this->rate( $currency ), $this->base_decimals );
	}

	/**
	 * Rewrite a Store API minor-unit bound from display currency into the INR value WooCommerce queries.
	 *
	 * WooCommerce divides the request value by 10^active decimals and compares the result with `_price`.
	 * The returned minor value is display-decimals applied to the INR major amount.
	 */
	public function rewrite_query_minor( string $display_minor, int $decimals, string $currency ): string {
		$factor        = bcpow( '10', (string) max( 0, $decimals ), 0 );
		$display_major = bcdiv( $this->normalize( $display_minor ), $factor, $decimals + 6 );
		$base_major    = $this->to_base_amount( $display_major, $currency );

		return $this->round_half_up( bcmul( $base_major, $factor, $decimals + 4 ), 0 );
	}

	/**
	 * Rewrite a minor-unit range that WooCommerce built from an unconverted INR price.
	 */
	public function rewrite_result_minor( string $base_minor, int $decimals, string $currency ): string {
		$factor     = bcpow( '10', (string) max( 0, $decimals ), 0 );
		$base_major = bcdiv( $this->normalize( $base_minor ), $factor, $decimals + 6 );
		$display    = $this->convert_amount( $base_major, $currency );

		return $this->round_half_up( bcmul( $display, $factor, $decimals + 4 ), 0 );
	}

	public function normalize( string $amount ): string {
		$amount = trim( $amount );
		if ( '' === $amount || ! is_numeric( $amount ) ) {
			throw new InvalidArgumentException( 'Amount is not numeric.' );
		}

		if ( preg_match( '/e/i', $amount ) ) {
			$amount = sprintf( '%.8F', (float) $amount );
		}

		$negative = str_starts_with( $amount, '-' );
		if ( $negative ) {
			$amount = substr( $amount, 1 );
		}

		if ( ! preg_match( '/^\d+(\.\d+)?$/', $amount ) ) {
			throw new InvalidArgumentException( 'Amount is not a decimal string.' );
		}

		if ( str_contains( $amount, '.' ) ) {
			$amount = rtrim( rtrim( $amount, '0' ), '.' );
			if ( '' === $amount ) {
				$amount = '0';
			}
		}

		$amount = ltrim( $amount, '0' );
		if ( '' === $amount ) {
			$amount = '0';
		} elseif ( str_starts_with( $amount, '.' ) ) {
			$amount = '0' . $amount;
		}

		return $negative ? '-' . $amount : $amount;
	}

	private function div_round( string $amount, string $divisor, int $decimals ): string {
		$scale    = $decimals + 6;
		$quotient = bcdiv( $amount, $divisor, $scale );
		return $this->round_half_up( $quotient, $decimals );
	}

	private function mul_round( string $amount, string $factor, int $decimals ): string {
		$product = bcmul( $amount, $factor, $decimals + 6 );
		return $this->round_half_up( $product, $decimals );
	}

	/**
	 * Round half away from zero, matching PHP_ROUND_HALF_UP.
	 */
	public function round_half_up( string $number, int $decimals ): string {
		$number   = $this->normalize( $number );
		$negative = str_starts_with( $number, '-' );
		if ( $negative ) {
			$number = substr( $number, 1 );
		}

		if ( ! str_contains( $number, '.' ) ) {
			$number .= '.0';
		}

		$increment = '0.' . str_repeat( '0', max( 0, $decimals ) ) . '5';
		$sum       = bcadd( $number, $increment, $decimals + 1 );
		$rounded   = bcadd( $sum, '0', max( 0, $decimals ) );

		if ( $negative && ! preg_match( '/^0(\.0+)?$/', $rounded ) ) {
			return '-' . $rounded;
		}

		return $rounded;
	}
}
