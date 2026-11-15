<?php
/**
 * Shipping rate costs are calculated in INR and then converted once.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Pricing;

use PayGlocal\Currency\Currency_Resolver;
use PayGlocal\Currency\Price_Converter;

/**
 * Shipping rate costs are calculated in INR and then converted once.
 */
final class Shipping_Adjustment {

	/**
	 * @param mixed                    $cost  Rate cost.
	 * @param array<int|string, mixed> $taxes Rate taxes.
	 * @return array{cost: mixed, taxes: array<int|string, mixed>, converted: bool}
	 */
	public function convert_rate( mixed $cost, array $taxes, string $currency, Price_Converter $converter, bool $already ): array {
		if ( $already || Currency_Resolver::BASE === $currency ) {
			return array(
				'cost'      => $cost,
				'taxes'     => $taxes,
				'converted' => $already,
			);
		}

		$converted_taxes = array();
		foreach ( $taxes as $id => $tax ) {
			if ( null === $tax || '' === $tax || ! is_numeric( $tax ) ) {
				$converted_taxes[ $id ] = $tax;
				continue;
			}
			$converted_taxes[ $id ] = $converter->convert_amount( (string) $tax, $currency );
		}

		$converted_cost = ( null !== $cost && '' !== $cost && is_numeric( $cost ) )
			? $converter->convert_amount( (string) $cost, $currency )
			: $cost;

		return array(
			'cost'      => $converted_cost,
			'taxes'     => $converted_taxes,
			'converted' => true,
		);
	}
}
