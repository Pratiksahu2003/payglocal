<?php
/**
 * PayGlocal JWE and JWS tokens.
 *
 * @package PayGlocal
 */

declare(strict_types=1);

namespace PayGlocal\Gateway;

use RuntimeException;

/**
 * Encrypts a payment payload for PayGlocal and signs the encrypted token.
 *
 * JWE uses RSA-OAEP-256 and A128CBC-HS256. JWS uses RS256 over a SHA-256 digest
 * of the JWE, matching the PayGlocal PHP sample.
 */
final class Token_Service {

	/**
	 * @param array<string, mixed> $payload Payment JSON body.
	 * @return array{jwe: string, jws: string}
	 */
	public function seal( array $payload, string $merchant_id, string $public_kid, string $public_pem, string $private_kid, string $private_pem ): array {
		$jwe = $this->encrypt( $payload, $merchant_id, $public_kid, $public_pem );
		$jws = $this->sign( $jwe, $merchant_id, $private_kid, $private_pem );

		return array(
			'jwe' => $jwe,
			'jws' => $jws,
		);
	}

	/**
	 * @param array<string, mixed> $payload Payment JSON body.
	 */
	public function encrypt( array $payload, string $merchant_id, string $public_kid, string $public_pem ): string {
		$public = openssl_pkey_get_public( $public_pem );
		if ( false === $public ) {
			throw new RuntimeException( 'PayGlocal public key could not be read.' );
		}

		$header = array(
			'issued-by' => $merchant_id,
			'enc'       => 'A128CBC-HS256',
			'exp'       => 30000,
			'iat'       => (string) round( microtime( true ) * 1000 ),
			'alg'       => 'RSA-OAEP-256',
			'kid'       => $public_kid,
		);

		$encoded_header = $this->encode_json( $header );
		$protected      = $this->base64url( $encoded_header );
		$plaintext      = $this->encode_json( $payload );
		if ( ! is_string( $plaintext ) ) {
			throw new RuntimeException( 'Payment payload could not be encoded.' );
		}

		$cek     = random_bytes( 32 );
		$mac_key = substr( $cek, 0, 16 );
		$enc_key = substr( $cek, 16, 16 );
		$iv      = random_bytes( 16 );

		$ciphertext = openssl_encrypt( $plaintext, 'aes-128-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Payment payload could not be encrypted.' );
		}

		$encrypted_key = $this->rsa_oaep_encrypt( $cek, $public );
		$aad           = $protected;
		$al            = pack( 'NN', 0, strlen( $aad ) * 8 );
		$tag           = substr( hash_hmac( 'sha256', $aad . $iv . $ciphertext . $al, $mac_key, true ), 0, 16 );

		return $protected
			. '.' . $this->base64url( $encrypted_key )
			. '.' . $this->base64url( $iv )
			. '.' . $this->base64url( $ciphertext )
			. '.' . $this->base64url( $tag );
	}

	public function sign( string $jwe, string $merchant_id, string $private_kid, string $private_pem ): string {
		$private = openssl_pkey_get_private( $private_pem );
		if ( false === $private ) {
			throw new RuntimeException( 'PayGlocal private key could not be read.' );
		}

		$header = array(
			'issued-by'       => $merchant_id,
			'is-digested'     => 'true',
			'alg'             => 'RS256',
			'x-gl-enc'        => 'true',
			'x-gl-merchantId' => $merchant_id,
			'kid'             => $private_kid,
		);

		$body = array(
			'digest'          => base64_encode( hash( 'sha256', $jwe, true ) ),
			'digestAlgorithm' => 'SHA-256',
			'exp'             => 300000,
			'iat'             => (string) round( microtime( true ) * 1000 ),
		);

		return $this->compact_jws( $header, $body, $private );
	}

	/**
	 * Verify a PayGlocal callback token and return its JSON payload.
	 *
	 * @return array<string, mixed>
	 */
	public function verify( string $token, string $public_pem ): array {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			throw new RuntimeException( 'PayGlocal response token is not a JWS.' );
		}

		$public = openssl_pkey_get_public( $public_pem );
		if ( false === $public ) {
			throw new RuntimeException( 'PayGlocal public key could not be read.' );
		}

		$signature = $this->base64url_decode( $parts[2] );
		$valid     = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $public, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $valid ) {
			throw new RuntimeException( 'PayGlocal response signature is invalid.' );
		}

		$payload = json_decode( $this->base64url_decode( $parts[1] ), true );
		if ( ! is_array( $payload ) ) {
			throw new RuntimeException( 'PayGlocal response payload is invalid.' );
		}

		return $payload;
	}

	/**
	 * Decrypt a JWE. Used to prove the encryption round-trips; PayGlocal decrypts live requests.
	 *
	 * @return array<string, mixed>
	 */
	public function decrypt( string $jwe, string $private_pem ): array {
		$parts = explode( '.', $jwe );
		if ( 5 !== count( $parts ) ) {
			throw new RuntimeException( 'JWE is not compact serialization.' );
		}

		$private = openssl_pkey_get_private( $private_pem );
		if ( false === $private ) {
			throw new RuntimeException( 'Private key could not be read.' );
		}

		$encrypted_key = $this->base64url_decode( $parts[1] );
		$iv            = $this->base64url_decode( $parts[2] );
		$ciphertext    = $this->base64url_decode( $parts[3] );
		$tag           = $this->base64url_decode( $parts[4] );
		$cek           = $this->rsa_oaep_decrypt( $encrypted_key, $private );
		$mac_key       = substr( $cek, 0, 16 );
		$enc_key       = substr( $cek, 16, 16 );
		$al            = pack( 'NN', 0, strlen( $parts[0] ) * 8 );
		$expected      = substr( hash_hmac( 'sha256', $parts[0] . $iv . $ciphertext . $al, $mac_key, true ), 0, 16 );

		if ( ! hash_equals( $expected, $tag ) ) {
			throw new RuntimeException( 'JWE authentication tag does not match.' );
		}

		$plaintext = openssl_decrypt( $ciphertext, 'aes-128-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			throw new RuntimeException( 'JWE payload could not be decrypted.' );
		}

		$decoded = json_decode( $plaintext, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'JWE payload is not JSON.' );
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $header Protected header.
	 * @param array<string, mixed> $payload Payload object.
	 */
	private function compact_jws( array $header, array $payload, \OpenSSLAsymmetricKey $private ): string {
		$header_json  = json_encode( $header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload_json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $header_json ) || ! is_string( $payload_json ) ) {
			throw new RuntimeException( 'JWS could not be encoded.' );
		}

		$signing_input = $this->base64url( $header_json ) . '.' . $this->base64url( $payload_json );
		$signature     = '';
		$signed        = openssl_sign( $signing_input, $signature, $private, OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			throw new RuntimeException( 'JWS could not be signed.' );
		}

		return $signing_input . '.' . $this->base64url( $signature );
	}

	private function rsa_oaep_encrypt( string $plaintext, \OpenSSLAsymmetricKey $public ): string {
		$details = openssl_pkey_get_details( $public );
		if ( ! is_array( $details ) || empty( $details['rsa']['n'] ) || empty( $details['rsa']['e'] ) ) {
			throw new RuntimeException( 'Public key is not an RSA key.' );
		}

		$k      = intdiv( (int) $details['bits'], 8 );
		$em     = $this->oaep_encode( $plaintext, $k );
		$cipher = $this->rsa_pow( $em, $details['rsa']['e'], $details['rsa']['n'], $k );
		return $cipher;
	}

	private function rsa_oaep_decrypt( string $ciphertext, \OpenSSLAsymmetricKey $private ): string {
		$details = openssl_pkey_get_details( $private );
		if ( ! is_array( $details ) || empty( $details['rsa']['d'] ) || empty( $details['rsa']['n'] ) ) {
			throw new RuntimeException( 'Private key is not an RSA key.' );
		}

		$k  = intdiv( (int) $details['bits'], 8 );
		$em = $this->rsa_pow( $ciphertext, $details['rsa']['d'], $details['rsa']['n'], $k );
		return $this->oaep_decode( $em );
	}

	private function oaep_encode( string $message, int $k ): string {
		$h_len = 32;
		$m_len = strlen( $message );
		if ( $m_len > $k - ( 2 * $h_len ) - 2 ) {
			throw new RuntimeException( 'Content encryption key is too long for the PayGlocal key.' );
		}

		$l_hash    = hash( 'sha256', '', true );
		$padding   = str_repeat( "\0", $k - $m_len - ( 2 * $h_len ) - 2 );
		$db        = $l_hash . $padding . "\x01" . $message;
		$seed      = random_bytes( $h_len );
		$masked_db = $this->xor_bytes( $db, $this->mgf1( $seed, strlen( $db ) ) );
		$masked_seed = $this->xor_bytes( $seed, $this->mgf1( $masked_db, $h_len ) );

		return "\0" . $masked_seed . $masked_db;
	}

	private function oaep_decode( string $em ): string {
		$h_len = 32;
		if ( strlen( $em ) < ( 2 * $h_len ) + 2 || "\0" !== $em[0] ) {
			throw new RuntimeException( 'OAEP decoding failed.' );
		}

		$masked_seed = substr( $em, 1, $h_len );
		$masked_db   = substr( $em, 1 + $h_len );
		$seed        = $this->xor_bytes( $masked_seed, $this->mgf1( $masked_db, $h_len ) );
		$db          = $this->xor_bytes( $masked_db, $this->mgf1( $seed, strlen( $masked_db ) ) );
		$l_hash      = hash( 'sha256', '', true );

		if ( ! hash_equals( $l_hash, substr( $db, 0, $h_len ) ) ) {
			throw new RuntimeException( 'OAEP label hash does not match.' );
		}

		$rest  = substr( $db, $h_len );
		$index = strpos( $rest, "\x01" );
		if ( false === $index ) {
			throw new RuntimeException( 'OAEP separator is missing.' );
		}

		return substr( $rest, $index + 1 );
	}

	private function mgf1( string $seed, int $length ): string {
		$output  = '';
		$counter = 0;
		while ( strlen( $output ) < $length ) {
			$output .= hash( 'sha256', $seed . pack( 'N', $counter ), true );
			++$counter;
		}
		return substr( $output, 0, $length );
	}

	private function xor_bytes( string $left, string $right ): string {
		$out = '';
		$len = strlen( $left );
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $left[ $i ] ^ $right[ $i ];
		}
		return $out;
	}

	private function rsa_pow( string $message, string $exponent, string $modulus, int $length ): string {
		if ( function_exists( 'gmp_powm' ) ) {
			$c = gmp_powm( $this->binary_to_gmp( $message ), $this->binary_to_gmp( $exponent ), $this->binary_to_gmp( $modulus ) );
			return $this->gmp_to_binary( $c, $length );
		}

		if ( function_exists( 'bcpowmod' ) ) {
			$c = bcpowmod( $this->binary_to_bc( $message ), $this->binary_to_bc( $exponent ), $this->binary_to_bc( $modulus ), 0 );
			return $this->bc_to_binary( $c, $length );
		}

		throw new RuntimeException( 'GMP or BCMath is required to seal PayGlocal requests.' );
	}

	private function binary_to_gmp( string $binary ): \GMP {
		$hex = bin2hex( $binary );
		if ( '' === $hex ) {
			return gmp_init( 0 );
		}
		return gmp_init( $hex, 16 );
	}

	private function gmp_to_binary( \GMP $number, int $length ): string {
		$hex = gmp_strval( $number, 16 );
		if ( 1 === strlen( $hex ) % 2 ) {
			$hex = '0' . $hex;
		}
		$binary = hex2bin( $hex );
		if ( false === $binary ) {
			throw new RuntimeException( 'RSA result could not be encoded.' );
		}
		if ( strlen( $binary ) > $length ) {
			throw new RuntimeException( 'RSA result is longer than the modulus.' );
		}
		return str_pad( $binary, $length, "\0", STR_PAD_LEFT );
	}

	private function binary_to_bc( string $binary ): string {
		$hex = bin2hex( $binary );
		$dec = '0';
		$len = strlen( $hex );
		for ( $i = 0; $i < $len; $i++ ) {
			$dec = bcadd( bcmul( $dec, '16', 0 ), (string) hexdec( $hex[ $i ] ), 0 );
		}
		return $dec;
	}

	private function bc_to_binary( string $decimal, int $length ): string {
		$hex = '';
		$num = $decimal;
		if ( '' === $num ) {
			$num = '0';
		}
		while ( bccomp( $num, '0', 0 ) > 0 ) {
			$hex = dechex( (int) bcmod( $num, '16' ) ) . $hex;
			$num = bcdiv( $num, '16', 0 );
		}
		if ( '' === $hex ) {
			$hex = '0';
		}
		if ( 1 === strlen( $hex ) % 2 ) {
			$hex = '0' . $hex;
		}
		$binary = hex2bin( $hex );
		if ( false === $binary ) {
			throw new RuntimeException( 'RSA result could not be encoded.' );
		}
		return str_pad( $binary, $length, "\0", STR_PAD_LEFT );
	}

	/**
	 * @param array<string, mixed> $data JSON object.
	 */
	private function encode_json( array $data ): string {
		$encoded = json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'PayGlocal payload could not be encoded.' );
		}
		return $encoded;
	}

	private function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private function base64url_decode( string $data ): string {
		$remainder = strlen( $data ) % 4;
		if ( 0 !== $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
		if ( false === $decoded ) {
			throw new RuntimeException( 'Token segment is not valid base64url.' );
		}
		return $decoded;
	}
}
