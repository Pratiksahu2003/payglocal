<?php
/**
 * Cached INR exchange rates.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Currency;

/**
 * Reads and refreshes the exchange rates used by the storefront.
 */
final class Exchange_Rates {

	public const OPTION = 'payglocal_exchange_rates';

	/**
	 * @return array<string, string>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$rates  = array();
		if ( is_array( $stored ) && isset( $stored['rates'] ) && is_array( $stored['rates'] ) ) {
			$rates = $stored['rates'];
		}

		$merged = Rate_Table::sample_rates();
		foreach ( $rates as $code => $rate ) {
			$code = strtoupper( (string) $code );
			if ( is_numeric( $rate ) && (float) $rate > 0 ) {
				$merged[ $code ] = (string) $rate;
			}
		}

		return $merged;
	}

	public function cache_token(): string {
		$stored  = get_option( self::OPTION, array() );
		$updated = is_array( $stored ) && isset( $stored['updated'] ) ? (string) $stored['updated'] : 'sample';
		return md5( $updated . '|' . wp_json_encode( $this->all() ) );
	}

	public function updated_at(): int {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && isset( $stored['updated'] ) ? (int) $stored['updated'] : 0;
	}

	public function source(): string {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && isset( $stored['source'] ) ? (string) $stored['source'] : 'sample';
	}

	/**
	 * @param array<string, string> $rates INR per foreign unit.
	 */
	public function save( array $rates, string $source ): void {
		update_option(
			self::OPTION,
			array(
				'rates'   => $rates,
				'updated' => time(),
				'source'  => $source,
			),
			true
		);
	}

	/**
	 * Replace cached rates from a public FX feed. Manual mode leaves merchant rates untouched.
	 */
	public function refresh(): bool {
		$mode = get_option( 'payglocal_rate_mode', 'automatic' );
		if ( 'manual' === $mode ) {
			return false;
		}

		$table = $this->download();
		if ( array() === $table ) {
			return false;
		}

		$this->save( $table, 'automatic' );
		return true;
	}

	/**
	 * @return array<string, string>
	 */
	private function download(): array {
		$sources = array(
			'https://open.er-api.com/v6/latest/USD',
			'https://api.frankfurter.app/latest?from=USD',
		);

		foreach ( $sources as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 12,
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				continue;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				continue;
			}
			$rates = array();
			if ( isset( $body['rates'] ) && is_array( $body['rates'] ) ) {
				$rates = $body['rates'];
			}
			$parsed = Rate_Table::from_usd_table( $rates );
			if ( isset( $parsed['USD'] ) ) {
				return $parsed;
			}
		}

		return array();
	}
}
