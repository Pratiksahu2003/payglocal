<?php
/**
 * Exchange-rate defaults and API payload parsing.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Currency;

/**
 * Sample and parsed exchange rates. One unit of foreign currency equals this many INR.
 */
final class Rate_Table {

	/**
	 * Rates shipped so conversion works before the first successful refresh.
	 * Merchants should replace them from WooCommerce → Settings → PayGlocal Currency.
	 *
	 * @return array<string, string>
	 */
	public static function sample_rates(): array {
		return array(
			'USD' => '85',
			'EUR' => '100',
			'GBP' => '115',
		);
	}

	/**
	 * Currencies enabled until the merchant saves a different list.
	 * JPY is intentionally absent so an unsupported local currency falls back to INR.
	 *
	 * @return array<int, string>
	 */
	public static function default_supported(): array {
		return array(
			'INR',
			'USD',
			'EUR',
			'GBP',
			'AUD',
			'CAD',
			'SGD',
			'AED',
			'CHF',
			'HKD',
			'NZD',
			'SEK',
			'NOK',
			'DKK',
		);
	}

	/**
	 * Every currency the settings screen can enable.
	 *
	 * @return array<int, string>
	 */
	public static function catalog(): array {
		$codes = array_merge(
			self::default_supported(),
			array( 'JPY', 'CNY', 'HKD', 'NZD', 'ZAR', 'AED', 'SAR', 'QAR', 'THB', 'MYR', 'PHP', 'IDR', 'KRW', 'VND', 'BHD', 'KWD', 'OMR', 'TRY', 'PLN', 'CZK', 'HUF', 'RON', 'BGN', 'MXN', 'BRL', 'ARS', 'CLP', 'COP', 'PEN', 'ILS', 'EGP', 'NGN', 'KES', 'GHS', 'MAD', 'TWD', 'LKR', 'BDT', 'PKR', 'NPR', 'MMK', 'KHR', 'LAK', 'BND' )
		);
		$codes = array_values( array_unique( array_map( 'strtoupper', $codes ) ) );
		sort( $codes );
		return $codes;
	}

	/**
	 * Build INR-per-unit rates from a table quoted as "units of currency per 1 USD".
	 *
	 * @param array<string, string|float|int> $usd_rates Includes INR.
	 * @return array<string, string>
	 */
	public static function from_usd_table( array $usd_rates ): array {
		if ( ! isset( $usd_rates['INR'] ) || ! is_numeric( $usd_rates['INR'] ) || (float) $usd_rates['INR'] <= 0 ) {
			return array();
		}

		$inr_per_usd = (string) $usd_rates['INR'];
		$out         = array(
			'USD' => self::trim_rate( $inr_per_usd ),
		);

		foreach ( $usd_rates as $code => $per_usd ) {
			$code = strtoupper( (string) $code );
			if ( 'INR' === $code || 'USD' === $code || ! is_numeric( $per_usd ) || (float) $per_usd <= 0 ) {
				continue;
			}
			$out[ $code ] = self::trim_rate( bcdiv( $inr_per_usd, (string) $per_usd, 6 ) );
		}

		return $out;
	}

	private static function trim_rate( string $rate ): string {
		if ( str_contains( $rate, '.' ) ) {
			$rate = rtrim( rtrim( $rate, '0' ), '.' );
		}
		return '' === $rate ? '0' : $rate;
	}
}
