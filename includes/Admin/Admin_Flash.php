<?php
/**
 * One-request admin messages.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Admin;

/**
 * Stores admin errors and success notes until the next admin screen.
 */
final class Admin_Flash {

	/**
	 * @return array{errors: array<int, string>, success: array<int, string>}
	 */
	public static function consume(): array {
		$key   = self::key();
		$flash = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $flash ) ) {
			return array(
				'errors'  => array(),
				'success' => array(),
			);
		}

		return array(
			'errors'  => self::strings( $flash['errors'] ?? array() ),
			'success' => self::strings( $flash['success'] ?? array() ),
		);
	}

	public static function add_error( string $message ): void {
		self::add( 'errors', $message );
	}

	public static function add_success( string $message ): void {
		self::add( 'success', $message );
	}

	private static function add( string $bucket, string $message ): void {
		$message = trim( $message );
		if ( '' === $message ) {
			return;
		}
		$key   = self::key();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			$flash = array(
				'errors'  => array(),
				'success' => array(),
			);
		}
		$flash[ $bucket ][] = $message;
		$flash[ $bucket ]   = array_values( array_unique( self::strings( $flash[ $bucket ] ) ) );
		set_transient( $key, $flash, MINUTE_IN_SECONDS );
	}

	private static function key(): string {
		$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return 'payglocal_admin_flash_' . $user;
	}

	/**
	 * @param mixed $values Stored messages.
	 * @return array<int, string>
	 */
	private static function strings( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}
		$clean = array();
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$clean[] = $value;
			}
		}
		return $clean;
	}
}
