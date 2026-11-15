<?php
/**
 * Class loader for the PayGlocal plugin. Safe to include from unit tests.
 *
 * @package PayGlocal
 */

if ( ! defined( 'PAYGLOCAL_DIR' ) ) {
	define( 'PAYGLOCAL_DIR', dirname( __DIR__ ) );
}

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'PayGlocal\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = PAYGLOCAL_DIR . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $path ) ) {
			require $path;
		}
	}
);
