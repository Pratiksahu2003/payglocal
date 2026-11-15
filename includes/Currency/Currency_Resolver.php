<?php
/**
 * Country and merchant rules that pick the payment currency.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Currency;

/**
 * Selects the currency used for display and payment.
 *
 * INR product prices stay in the database. This class only chooses the currency
 * those prices are converted into.
 */
final class Currency_Resolver {

	public const BASE = 'INR';

	private Country_Currency_Map $countries;

	public function __construct( ?Country_Currency_Map $countries = null ) {
		$this->countries = $countries ?? new Country_Currency_Map();
	}

	/**
	 * Currency for a detected or chosen country.
	 *
	 * A known country whose currency PayGlocal cannot charge uses INR.
	 * An unknown country uses USD when that currency is usable, otherwise INR.
	 *
	 * @param string|null       $country   ISO alpha-2, or null/empty when geolocation failed.
	 * @param array<int, string> $supported Merchant currencies, including INR.
	 * @param array<string, string|float|int> $rates INR per 1 unit of foreign currency.
	 */
	public function resolve_for_country( ?string $country, array $supported, array $rates ): string {
		$country = strtoupper( trim( (string) $country ) );

		if ( '' === $country ) {
			return $this->usable( 'USD', $supported, $rates ) ? 'USD' : self::BASE;
		}

		$local = $this->countries->currency_for_country( $country );
		if ( null === $local ) {
			return $this->usable( 'USD', $supported, $rates ) ? 'USD' : self::BASE;
		}

		if ( $this->usable( $local, $supported, $rates ) ) {
			return $local;
		}

		return self::BASE;
	}

	/**
	 * A shopper-selected currency, or null when it must not be used.
	 *
	 * @param array<int, string> $supported Merchant currencies.
	 * @param array<string, string|float|int> $rates Exchange rates.
	 */
	public function accept_currency( string $currency, array $supported, array $rates ): ?string {
		$currency = strtoupper( trim( $currency ) );
		if ( ! $this->usable( $currency, $supported, $rates ) ) {
			return null;
		}
		return $currency;
	}

	/**
	 * @param array<int, string> $supported Merchant currencies.
	 * @param array<string, string|float|int> $rates Exchange rates.
	 */
	public function usable( string $currency, array $supported, array $rates ): bool {
		$currency  = strtoupper( trim( $currency ) );
		$supported = array_map( 'strtoupper', $supported );

		if ( self::BASE === $currency ) {
			return true;
		}

		if ( ! in_array( $currency, $supported, true ) ) {
			return false;
		}

		if ( ! isset( $rates[ $currency ] ) || ! is_numeric( $rates[ $currency ] ) ) {
			return false;
		}

		return (float) $rates[ $currency ] > 0;
	}
}
