<?php
/**
 * Fixed coupon amounts are stored in INR. Percentage coupons stay percentages.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Pricing;

use PayGlocal\Currency\Currency_Resolver;
use PayGlocal\Currency\Price_Converter;

/**
 * Fixed coupon amounts are stored in INR. Percentage coupons stay percentages.
 */
final class Coupon_Adjustment {

	/**
	 * Discount types whose amount is money rather than a percent.
	 *
	 * @var array<int, string>
	 */
	private const FIXED_TYPES = array(
		'fixed_cart',
		'fixed_product',
		'sign_up_fee',
		'recurring_fee',
	);

	/**
	 * @param mixed $amount Coupon amount in INR.
	 * @return mixed
	 */
	public function amount( mixed $amount, string $type, string $currency, Price_Converter $converter ): mixed {
		if ( ! in_array( $type, self::FIXED_TYPES, true ) ) {
			return $amount;
		}
		if ( Currency_Resolver::BASE === $currency || null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return $amount;
		}
		return $converter->convert_amount( (string) $amount, $currency );
	}
}
