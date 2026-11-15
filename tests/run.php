<?php
/**
 * Currency engine tests. Run with: php tests/run.php
 *
 * @package PayGlocal
 */

require dirname( __DIR__ ) . '/includes/autoload.php';

use PayGlocal\Admin\Config_Check;
use PayGlocal\Currency\Conversion_Guard;
use PayGlocal\Currency\Currency_Resolver;
use PayGlocal\Currency\Price_Converter;
use PayGlocal\Currency\Rate_Table;
use PayGlocal\Gateway\Payment_Charge;
use PayGlocal\Gateway\Token_Service;
use PayGlocal\Pricing\Coupon_Adjustment;
use PayGlocal\Pricing\Price_Adjustment;
use PayGlocal\Pricing\Shipping_Adjustment;

$failures = 0;

function check( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo "ok  {$message}\n";
		return;
	}
	$failures++;
	echo "FAIL {$message}\n";
}

$rates = array(
	'USD' => '85',
	'EUR' => '100',
	'GBP' => '115',
);
$converter = new Price_Converter( $rates );
$supported = array( 'INR', 'USD', 'EUR', 'GBP' );
$resolver  = new Currency_Resolver();

check( '100.00' === $converter->convert_amount( '8500', 'USD' ), 'USD converts 8500 INR at 85 to 100.00' );
check( '85.00' === $converter->convert_amount( '8500', 'EUR' ), 'EUR converts 8500 INR at 100 to 85.00' );
check( '73.91' === $converter->convert_amount( '8500', 'GBP' ), 'GBP converts 8500 INR at 115 to 73.91' );
check( '8500.00' === $converter->convert_amount( '8500', 'INR' ), 'INR amount is not divided' );
check( '0.00' === $converter->convert_amount( '0', 'USD' ), 'zero stays zero' );

$sections = array( 'homepage', 'shop', 'product', 'related', 'mini-cart', 'cart', 'checkout' );
foreach ( array( 'IN' => '8500.00', 'US' => '100.00', 'DE' => '85.00' ) as $country => $expected ) {
	$currency = $resolver->resolve_for_country( $country, $supported, $rates );
	foreach ( $sections as $section ) {
		$amount = $converter->convert_amount( '8500', $currency );
		check( $expected === $amount, "{$section} for {$country} is {$expected} {$currency}" );
	}
}

check( 'INR' === $resolver->resolve_for_country( 'IN', $supported, $rates ), 'India uses INR' );
check( 'USD' === $resolver->resolve_for_country( 'US', $supported, $rates ), 'United States uses USD' );
check( 'EUR' === $resolver->resolve_for_country( 'DE', $supported, $rates ), 'Germany uses EUR' );
check( 'INR' === $resolver->resolve_for_country( 'JP', $supported, $rates ), 'Japan falls back to INR when JPY is unsupported' );
check( 'USD' === $resolver->resolve_for_country( null, $supported, $rates ), 'unknown country defaults to USD' );
check( 'USD' === $resolver->resolve_for_country( '', $supported, $rates ), 'empty country defaults to USD' );
check( 'INR' === $resolver->resolve_for_country( null, array( 'INR', 'EUR' ), $rates ), 'unknown country uses INR when USD is unsupported' );
check( 'INR' === $resolver->resolve_for_country( 'US', array( 'INR', 'EUR' ), $rates ), 'USD without merchant support falls back to INR' );
check( 'INR' === $resolver->resolve_for_country( 'AU', array( 'INR', 'USD', 'AUD' ), $rates ), 'supported currency without a rate falls back to INR' );
check( 'USD' === $resolver->resolve_for_country( 'ZZ', $supported, $rates ), 'unmapped country uses the USD default' );
check( null === $resolver->accept_currency( 'JPY', $supported, $rates ), 'an unsupported currency parameter is rejected' );
check( 'EUR' === $resolver->accept_currency( 'eur', $supported, $rates ), 'a supported currency selection is accepted' );

$prices = new Price_Adjustment( $converter, new Conversion_Guard() );
$product = new stdClass();
check( '' === $prices->adjust( '', $product, 'sale', 'USD' ), 'empty sale price stays empty' );
check( '100.00' === $prices->adjust( '8500', $product, 'price', 'USD' ), 'first price read converts once' );
check( '100.00' === $prices->adjust( '8500', $product, 'price', 'USD' ), 'second raw read converts from INR again' );
check( '100.00' === $prices->adjust( '100.00', $product, 'price', 'USD' ), 'already converted value is not divided again' );
check( '85.00' === $prices->adjust( '8500', $product, 'regular', 'EUR' ), 'regular price uses its own field' );

$coupons = new Coupon_Adjustment();
check( '10' === $coupons->amount( '10', 'percent', 'USD', $converter ), 'percent coupons stay percentages' );
check( '10.00' === $coupons->amount( '850', 'fixed_cart', 'USD', $converter ), 'fixed coupons convert from INR' );

$shipping = new Shipping_Adjustment();
$once = $shipping->convert_rate( '85', array( 1 => '8.5' ), 'USD', $converter, false );
check( '1.00' === $once['cost'] && '0.10' === $once['taxes'][1], 'shipping cost and tax convert once' );
$twice = $shipping->convert_rate( $once['cost'], $once['taxes'], 'USD', $converter, true );
check( '1.00' === $twice['cost'] && '0.10' === $twice['taxes'][1], 'shipping is not converted twice' );

check( '850000' === $converter->rewrite_query_minor( '10000', 2, 'USD' ), 'a $100 filter bound queries INR 8500' );
check( '10000' === $converter->rewrite_result_minor( '850000', 2, 'USD' ), 'an INR 8500 range displays as $100' );

$spoofed = array(
	'totalAmount' => '1.00',
	'txnCurrency' => 'JPY',
	'amount'      => '1',
	'currency'    => 'JPY',
);
$charge = Payment_Charge::from_order( 'USD', '100.00', $spoofed );
check( '100.00' === $charge['totalAmount'] && 'USD' === $charge['txnCurrency'], 'payment ignores a posted amount and currency' );
check( Payment_Charge::line_matches( '8500', '1', '100.00', 'USD', $converter ), 'catalog line matches the converted subtotal' );
check( ! Payment_Charge::line_matches( '8500', '1', '1.00', 'USD', $converter ), 'a manipulated line total does not match the catalog' );

$parsed = Rate_Table::from_usd_table(
	array(
		'INR' => '85',
		'EUR' => '0.85',
		'USD' => '1',
	)
);
check( '85' === $parsed['USD'] && '100' === $parsed['EUR'], 'USD feed becomes INR per currency unit' );

$config = getenv( 'OPENSSL_CONF' );
if ( ! is_string( $config ) || ! is_file( $config ) ) {
	$candidate = dirname( PHP_BINARY ) . '/extras/ssl/openssl.cnf';
	$config    = is_file( $candidate ) ? $candidate : '';
}
$key_args = array(
	'private_key_bits' => 2048,
	'private_key_type' => OPENSSL_KEYTYPE_RSA,
);
if ( '' !== $config ) {
	$key_args['config'] = $config;
}
$key = openssl_pkey_new( $key_args );
while ( $error = openssl_error_string() ) {
	if ( false === $key ) {
		echo $error . "\n";
	}
}
check( false !== $key, 'test RSA key is generated' );
if ( false !== $key ) {
	$details = openssl_pkey_get_details( $key );
	openssl_pkey_export( $key, $private_pem, null, '' !== $config ? array( 'config' => $config ) : array() );
	$public_pem = $details['key'];
	$tokens     = new Token_Service();
	$payload    = array(
		'merchantTxnId'    => 'PG1000ABCD',
		'merchantUniqueId' => '1234567890123456',
		'paymentData'      => array(
			'totalAmount' => '100.00',
			'txnCurrency' => 'USD',
		),
		'merchantCallbackURL' => 'https://shop.example/wc-api/payglocal',
	);
	$sealed = $tokens->seal( $payload, 'merchant', 'public-kid', $public_pem, 'private-kid', $private_pem );
	$opened = $tokens->decrypt( $sealed['jwe'], $private_pem );
	check( '100.00' === $opened['paymentData']['totalAmount'] && 'USD' === $opened['paymentData']['txnCurrency'], 'JWE round-trip keeps the payment amount and currency' );

	$verified = $tokens->verify( $sealed['jws'], $public_pem );
	$digest   = base64_encode( hash( 'sha256', $sealed['jwe'], true ) );
	check( $digest === ( $verified['digest'] ?? '' ), 'JWS digest matches the encrypted payment body' );

	$parts    = explode( '.', $sealed['jws'] );
	$parts[2] = $parts[2] === 'aaaa' ? 'bbbb' : 'aaaa';
	$tampered = false;
	try {
		$tokens->verify( implode( '.', $parts ), $public_pem );
	} catch ( RuntimeException $error ) {
		$tampered = true;
	}
	check( $tampered, 'a modified payment signature is rejected' );

	$invalid = Config_Check::codes(
		array(
			'enabled'            => 'yes',
			'merchant_id'        => '',
			'public_kid'         => '',
			'private_kid'        => '',
			'public_pem'         => 'not-a-key',
			'private_pem'        => '',
			'conversion_enabled' => 'yes',
			'store_currency'     => 'USD',
			'supported'          => array( 'INR', 'USD', 'AUD' ),
			'rates'              => array( 'USD' => '85' ),
			'openssl'            => true,
			'rsa_math'           => true,
		)
	);
	check( in_array( 'merchant_id', $invalid, true ), 'enabling payments without a merchant ID is an admin error' );
	check( in_array( 'public_pem_invalid', $invalid, true ), 'a bad public key is rejected in admin' );
	check( in_array( 'private_pem_missing', $invalid, true ), 'a missing private key is an admin error' );
	check( in_array( 'store_currency', $invalid, true ), 'a non-INR store currency is reported to the admin' );
	check( in_array( 'currency_without_rate:AUD', $invalid, true ), 'an enabled currency without a rate is reported to the admin' );
	check( Config_Check::blocks_gateway( $invalid ), 'incomplete credentials keep the gateway off' );

	$public_pem = $details['key'];
	$valid      = Config_Check::codes(
		array(
			'enabled'            => 'yes',
			'merchant_id'        => 'merchant',
			'public_kid'         => 'public-kid',
			'private_kid'        => 'private-kid',
			'public_pem'         => $public_pem,
			'private_pem'        => $private_pem,
			'conversion_enabled' => 'yes',
			'store_currency'     => 'INR',
			'supported'          => array( 'INR', 'USD' ),
			'rates'              => array( 'USD' => '85' ),
			'openssl'            => true,
			'rsa_math'           => true,
		)
	);
	check( array() === $valid, 'a complete merchant configuration has no admin errors' );
	check( Config_Check::public_key_is_valid( $public_pem ), 'admin accepts the PayGlocal public key' );
	check( Config_Check::private_key_is_valid( $private_pem ), 'admin accepts the merchant private key' );
}

if ( $failures > 0 ) {
	echo "\n{$failures} failed\n";
	exit( 1 );
}

echo "\nAll checks passed\n";
exit( 0 );
