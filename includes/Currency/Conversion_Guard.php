<?php
/**
 * Stops a converted price from being converted again in the same request.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Currency;

/**
 * Remembers the converted value for each product object and price field.
 *
 * WooCommerce passes the raw database value into price filters on every read.
 * After cart code calls set_price() with an already converted value, the next
 * filter invocation receives that converted value. Returning it unchanged keeps
 * the cart, variations, and price HTML on a single conversion.
 */
final class Conversion_Guard {

	/**
	 * @var array<string, string>
	 */
	private array $converted = array();

	/**
	 * @param callable(mixed): string $convert Receives the incoming raw amount.
	 * @return mixed
	 */
	public function once( object $owner, string $prop, mixed $incoming, callable $convert ): mixed {
		if ( ! $this->is_amount( $incoming ) ) {
			return $incoming;
		}

		$key = spl_object_hash( $owner ) . ':' . $prop;
		if ( isset( $this->converted[ $key ] ) && $this->same( $this->converted[ $key ], $incoming ) ) {
			return $incoming;
		}

		$converted                 = (string) $convert( $incoming );
		$this->converted[ $key ] = $converted;
		return $converted;
	}

	public function reset(): void {
		$this->converted = array();
	}

	private function is_amount( mixed $value ): bool {
		return null !== $value && '' !== $value && is_numeric( $value );
	}

	private function same( mixed $left, mixed $right ): bool {
		if ( ! is_numeric( $left ) || ! is_numeric( $right ) ) {
			return (string) $left === (string) $right;
		}
		return abs( (float) $left - (float) $right ) < 0.0000001;
	}
}
