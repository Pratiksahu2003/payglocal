<?php
/**
 * Payment amount taken from the WooCommerce order, never from the request.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Gateway;

use PayGlocal\Currency\Price_Converter;

/**
 * Builds the amount and currency PayGlocal is asked to charge.
 */
final class Payment_Charge {

	/**
	 * @param array<string, mixed> $request Ignored. Present so callers cannot accidentally pass it through.
	 * @return array{totalAmount: string, txnCurrency: string}
	 */
	public static function from_order( string $currency, string $total, array $request = array() ): array {
		unset( $request );

		$currency = strtoupper( trim( $currency ) );
		$total    = preg_replace( '/[^0-9.\-]/', '', $total );
		if ( ! is_string( $total ) || '' === $total || ! is_numeric( $total ) ) {
			$total = '0';
		}

		$decimals = ( new Price_Converter() )->decimals( $currency );
		$amount   = ( new Price_Converter() )->round_half_up( $total, $decimals );

		return array(
			'totalAmount' => $amount,
			'txnCurrency' => $currency,
		);
	}

	/**
	 * A line stored from the INR catalog matches the order subtotal within rounding tolerance.
	 */
	public static function line_matches( string $base_unit, string $qty, string $actual_subtotal, string $currency, Price_Converter $converter ): bool {
		if ( '' === $base_unit || ! is_numeric( $base_unit ) || ! is_numeric( $qty ) || ! is_numeric( $actual_subtotal ) ) {
			return false;
		}

		$base_line = bcmul( $converter->normalize( $base_unit ), $converter->normalize( $qty ), 6 );
		$expected  = $converter->convert_amount( $base_line, $currency );
		$actual    = $converter->round_half_up( $converter->normalize( $actual_subtotal ), $converter->decimals( $currency ) );
		$decimals  = $converter->decimals( $currency );
		$minor     = bcpow( '10', (string) ( -1 * $decimals ), $decimals + 2 );
		$units     = $converter->normalize( $qty );
		if ( bccomp( $units, '1', 4 ) < 0 ) {
			$units = '1';
		}
		$tolerance = bcmul( $minor, $units, $decimals + 2 );
		$diff      = bcsub( $expected, $actual, $decimals + 2 );
		if ( str_starts_with( $diff, '-' ) ) {
			$diff = substr( $diff, 1 );
		}

		return bccomp( $diff, $tolerance, $decimals + 2 ) <= 0;
	}
}
