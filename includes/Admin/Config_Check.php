<?php
/**
 * Validates PayGlocal admin settings without saving them.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Admin;

/**
 * Turns a settings snapshot into admin-facing error codes.
 */
final class Config_Check {

	/**
	 * Codes that must keep the gateway disabled.
	 *
	 * @var array<int, string>
	 */
	private const GATEWAY_CODES = array(
		'merchant_id',
		'public_kid',
		'private_kid',
		'public_pem_missing',
		'public_pem_invalid',
		'private_pem_missing',
		'private_pem_invalid',
		'openssl_missing',
		'rsa_math_missing',
	);

	/**
	 * @param array<string, mixed> $settings Normalized settings.
	 * @return array<int, string>
	 */
	public static function codes( array $settings ): array {
		$codes   = array();
		$enabled = 'yes' === (string) ( $settings['enabled'] ?? 'no' );
		$openssl = ! empty( $settings['openssl'] );
		$math    = ! empty( $settings['rsa_math'] );

		if ( 'INR' !== strtoupper( (string) ( $settings['store_currency'] ?? 'INR' ) ) && 'yes' === (string) ( $settings['conversion_enabled'] ?? 'yes' ) ) {
			$codes[] = 'store_currency';
		}
		if ( ! $openssl ) {
			$codes[] = 'openssl_missing';
		}
		if ( ! $math ) {
			$codes[] = 'rsa_math_missing';
		}

		if ( $enabled && '' === trim( (string) ( $settings['merchant_id'] ?? '' ) ) ) {
			$codes[] = 'merchant_id';
		}
		if ( $enabled && '' === trim( (string) ( $settings['public_kid'] ?? '' ) ) ) {
			$codes[] = 'public_kid';
		}
		if ( $enabled && '' === trim( (string) ( $settings['private_kid'] ?? '' ) ) ) {
			$codes[] = 'private_kid';
		}

		$codes = array_merge( $codes, self::key_codes( (string) ( $settings['public_pem'] ?? '' ), 'public', $enabled, $openssl ) );
		$codes = array_merge( $codes, self::key_codes( (string) ( $settings['private_pem'] ?? '' ), 'private', $enabled, $openssl ) );

		if ( 'yes' === (string) ( $settings['conversion_enabled'] ?? 'yes' ) ) {
			$rates = is_array( $settings['rates'] ?? null ) ? $settings['rates'] : array();
			$supported = is_array( $settings['supported'] ?? null ) ? $settings['supported'] : array();
			foreach ( $supported as $code ) {
				$code = strtoupper( (string) $code );
				if ( 'INR' === $code || ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
					continue;
				}
				if ( ! isset( $rates[ $code ] ) || ! is_numeric( $rates[ $code ] ) || (float) $rates[ $code ] <= 0 ) {
					$codes[] = 'currency_without_rate:' . $code;
				}
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * @param array<int, string> $codes Error codes.
	 */
	public static function blocks_gateway( array $codes ): bool {
		foreach ( $codes as $code ) {
			if ( in_array( $code, self::GATEWAY_CODES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int, string> $codes Error codes.
	 * @return array<int, string>
	 */
	public static function messages( array $codes ): array {
		$messages = array();
		foreach ( $codes as $code ) {
			$messages[] = self::message( $code );
		}
		return $messages;
	}

	public static function public_key_is_valid( string $pem ): bool {
		return self::key_is_valid( $pem, false );
	}

	public static function private_key_is_valid( string $pem ): bool {
		return self::key_is_valid( $pem, true );
	}

	public static function message( string $code ): string {
		$messages = array(
			'store_currency'      => 'WooCommerce currency is not INR. Catalog prices are Indian Rupees, so set the store currency to INR.',
			'merchant_id'         => 'Enter the PayGlocal merchant ID before enabling payments.',
			'public_kid'          => 'Enter the PayGlocal public key ID before enabling payments.',
			'private_kid'         => 'Enter the merchant private key ID before enabling payments.',
			'public_pem_missing'  => 'Upload the PayGlocal public key before enabling payments.',
			'public_pem_invalid'  => 'The PayGlocal public key is not a valid RSA PEM and was not saved.',
			'private_pem_missing' => 'Upload the merchant private key before enabling payments.',
			'private_pem_invalid' => 'The merchant private key is not a valid RSA PEM and was not saved.',
			'openssl_missing'     => 'PHP OpenSSL is required before PayGlocal can sign payments.',
			'rsa_math_missing'    => 'PHP GMP or BCMath is required before PayGlocal can encrypt payments.',
		);

		if ( isset( $messages[ $code ] ) ) {
			return $messages[ $code ];
		}
		if ( str_starts_with( $code, 'currency_without_rate:' ) ) {
			$currency = substr( $code, strlen( 'currency_without_rate:' ) );
			return $currency . ' is enabled but has no exchange rate. Shoppers who would use it see INR until you save a rate.';
		}

		return 'PayGlocal settings need attention.';
	}

	/**
	 * @return array<int, string>
	 */
	private static function key_codes( string $pem, string $which, bool $enabled, bool $openssl ): array {
		$pem = trim( $pem );
		if ( '' === $pem ) {
			return $enabled ? array( $which . '_pem_missing' ) : array();
		}
		if ( ! $openssl ) {
			return array();
		}
		$valid = 'public' === $which ? self::public_key_is_valid( $pem ) : self::private_key_is_valid( $pem );
		return $valid ? array() : array( $which . '_pem_invalid' );
	}

	private static function key_is_valid( string $pem, bool $private ): bool {
		if ( ! function_exists( 'openssl_pkey_get_public' ) || ! function_exists( 'openssl_pkey_get_private' ) ) {
			return false;
		}
		$key = $private ? openssl_pkey_get_private( $pem ) : openssl_pkey_get_public( $pem );
		if ( false === $key ) {
			return false;
		}
		$details = openssl_pkey_get_details( $key );
		return is_array( $details ) && OPENSSL_KEYTYPE_RSA === ( $details['type'] ?? null );
	}
}
