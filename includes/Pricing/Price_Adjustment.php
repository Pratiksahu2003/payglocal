<?php
/**
 * Converts one WooCommerce price field from the INR value passed into a filter.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Pricing;

use PayGlocal\Currency\Conversion_Guard;
use PayGlocal\Currency\Currency_Resolver;
use PayGlocal\Currency\Price_Converter;

/**
 * Converts one WooCommerce price field from the INR value passed into a filter.
 */
final class Price_Adjustment {

	private Price_Converter $converter;

	private Conversion_Guard $guard;

	public function __construct( Price_Converter $converter, ?Conversion_Guard $guard = null ) {
		$this->converter = $converter;
		$this->guard     = $guard ?? new Conversion_Guard();
	}

	public function guard(): Conversion_Guard {
		return $this->guard;
	}

	/**
	 * @param mixed $price Raw INR amount, an empty sale price, or a value already converted.
	 * @return mixed
	 */
	public function adjust( mixed $price, ?object $product, string $prop, string $currency ): mixed {
		if ( Currency_Resolver::BASE === $currency || ! $this->is_amount( $price ) ) {
			return $price;
		}

		$convert = function ( mixed $incoming ) use ( $currency ): string {
			return $this->converter->convert_amount( (string) $incoming, $currency );
		};

		if ( null === $product ) {
			return $convert( $price );
		}

		return $this->guard->once( $product, $prop, $price, $convert );
	}

	private function is_amount( mixed $price ): bool {
		return null !== $price && '' !== $price && is_numeric( $price );
	}
}
