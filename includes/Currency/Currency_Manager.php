<?php
/**
 * Session currency for guests and logged-in customers.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Currency;

/**
 * Resolves and remembers the currency for the current shopper.
 *
 * The WooCommerce session is the authority. A cookie is written only as a cache
 * hint and is never read back when calculating a payment amount.
 */
final class Currency_Manager {

	public const BASE = Currency_Resolver::BASE;

	private Currency_Resolver $resolver;

	private Exchange_Rates $rates;

	private bool $resolved = false;

	private bool $resolving = false;

	private string $currency = self::BASE;

	private string $country = '';

	private bool $manual = false;

	public function __construct( Currency_Resolver $resolver, Exchange_Rates $rates ) {
		$this->resolver = $resolver;
		$this->rates    = $rates;
	}

	public function is_enabled(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return true;
		}
		return 'yes' === get_option( 'payglocal_conversion_enabled', 'yes' );
	}

	/**
	 * @return array<int, string>
	 */
	public function supported(): array {
		$saved = get_option( 'payglocal_supported_currencies', null );
		if ( ! is_array( $saved ) || array() === $saved ) {
			$saved = Rate_Table::default_supported();
		}

		$saved = array_map(
			static function ( $code ) {
				return strtoupper( (string) $code );
			},
			$saved
		);

		if ( ! in_array( self::BASE, $saved, true ) ) {
			array_unshift( $saved, self::BASE );
		}

		return array_values( array_unique( $saved ) );
	}

	public function boot(): void {
		if ( $this->resolved || $this->resolving ) {
			return;
		}
		if ( ! $this->is_enabled() ) {
			$this->currency = self::BASE;
			$this->country  = '';
			$this->manual   = false;
			$this->resolved = true;
			return;
		}

		$this->resolving = true;
		$this->resolve();
		$this->resolving = false;
	}

	public function get_active_currency(): string {
		if ( ! $this->resolved && ! $this->resolving ) {
			$this->boot();
		}
		return $this->currency;
	}

	public function get_country(): string {
		if ( ! $this->resolved && ! $this->resolving ) {
			$this->boot();
		}
		return $this->country;
	}

	/**
	 * Apply a shopper's country and derive the currency from merchant rules.
	 *
	 * @return array{country: string, currency: string}
	 */
	public function apply_country( string $country ): array {
		$country = strtoupper( sanitize_text_field( $country ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return array(
				'country'  => $this->get_country(),
				'currency' => $this->get_active_currency(),
			);
		}

		$currency = $this->resolver->resolve_for_country( $country, $this->supported(), $this->rates->all() );
		$this->persist( $country, $currency, false );
		$this->sync_customer_country( $country );
		return array(
			'country'  => $country,
			'currency' => $currency,
		);
	}

	/**
	 * Apply a supported currency. Unsupported codes leave the session unchanged.
	 *
	 * @return array{country: string, currency: string}|null
	 */
	public function apply_currency( string $currency ): ?array {
		$accepted = $this->resolver->accept_currency( $currency, $this->supported(), $this->rates->all() );
		if ( null === $accepted ) {
			return null;
		}

		$country = $this->get_country();
		$this->persist( $country, $accepted, true );
		return array(
			'country'  => $country,
			'currency' => $accepted,
		);
	}

	private function resolve(): void {
		$stored  = $this->read_stored();
		$country = $stored['country'];
		$manual  = $stored['manual'];

		if ( '' === $country && ! $manual ) {
			$country = $this->detect_country();
		}

		$rates     = $this->rates->all();
		$supported = $this->supported();

		if ( $manual ) {
			$accepted = $this->resolver->accept_currency( $stored['currency'], $supported, $rates );
			$currency = $accepted ?? $this->resolver->resolve_for_country( $country, $supported, $rates );
			$manual   = null !== $accepted;
		} else {
			$currency = $this->resolver->resolve_for_country( $country, $supported, $rates );
		}

		$this->persist( $country, $currency, $manual );
		$this->align_customer_country( $country );
	}

	/**
	 * @return array{country: string, currency: string, manual: bool}
	 */
	private function read_stored(): array {
		$country  = '';
		$currency = '';
		$manual   = false;

		$session = $this->session();
		if ( $session ) {
			$country  = strtoupper( (string) $session->get( 'payglocal_country', '' ) );
			$currency = strtoupper( (string) $session->get( 'payglocal_currency', '' ) );
			$manual   = 'yes' === (string) $session->get( 'payglocal_manual', '' );
		}

		if ( '' === $country && '' === $currency && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$user_id  = get_current_user_id();
			$country  = strtoupper( (string) get_user_meta( $user_id, '_payglocal_country', true ) );
			$currency = strtoupper( (string) get_user_meta( $user_id, '_payglocal_currency', true ) );
			$manual   = 'yes' === (string) get_user_meta( $user_id, '_payglocal_manual', true );
		}

		if ( '' === $country && '' === $currency ) {
			$guest    = $this->read_guest();
			$country  = $guest['country'];
			$currency = $guest['currency'];
			$manual   = $guest['manual'];
		}

		return array(
			'country'  => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'currency' => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '',
			'manual'   => $manual,
		);
	}

	private function detect_country(): string {
		$server_country = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) : '';
		if ( preg_match( '/^[A-Z]{2}$/', $server_country ) && 'XX' !== $server_country && 'T1' !== $server_country ) {
			return $server_country;
		}

		if ( class_exists( '\WC_Geolocation' ) ) {
			$location = \WC_Geolocation::geolocate_ip();
			if ( is_array( $location ) && ! empty( $location['country'] ) ) {
				$country = strtoupper( (string) $location['country'] );
				if ( preg_match( '/^[A-Z]{2}$/', $country ) ) {
					return $country;
				}
			}
		}

		return '';
	}

	private function persist( string $country, string $currency, bool $manual ): void {
		$this->country  = $country;
		$this->currency = $currency;
		$this->manual   = $manual;
		$this->resolved = true;

		$session = $this->session();
		if ( $session ) {
			$session->set( 'payglocal_country', $country );
			$session->set( 'payglocal_currency', $currency );
			$session->set( 'payglocal_manual', $manual ? 'yes' : 'no' );
		} else {
			$this->write_guest( $country, $currency, $manual );
		}

		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$user_id = get_current_user_id();
			update_user_meta( $user_id, '_payglocal_country', $country );
			update_user_meta( $user_id, '_payglocal_currency', $currency );
			update_user_meta( $user_id, '_payglocal_manual', $manual ? 'yes' : 'no' );
		}

		$this->set_hint_cookie( $currency );
	}

	/**
	 * Keep shipping with the detected country until the shopper chooses another address.
	 */
	private function align_customer_country( string $country ): void {
		$customer = $this->wc_customer();
		if ( '' === $country || null === $customer ) {
			return;
		}

		try {
			$billing = (string) $customer->get_billing_country();
			$base    = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
			$store   = isset( $base['country'] ) ? (string) $base['country'] : '';

			if ( '' === $billing || $billing === $store ) {
				$customer->set_billing_country( $country );
				$customer->set_shipping_country( $country );
			}
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	private function sync_customer_country( string $country ): void {
		$customer = $this->wc_customer();
		if ( null === $customer ) {
			return;
		}
		try {
			$customer->set_billing_country( $country );
			$customer->set_shipping_country( $country );
		} catch ( \Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * WooCommerce customer, only when its session already exists.
	 * Touching the customer before that session exists crashes the request.
	 */
	private function wc_customer() {
		if ( null === $this->session() || ! function_exists( 'WC' ) ) {
			return null;
		}
		try {
			$customer = WC()->customer;
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}
		return is_object( $customer ) ? $customer : null;
	}

	/**
	 * Non-authoritative cookie so a page cache can vary by currency. Never used as input.
	 */
	private function set_hint_cookie( string $currency ): void {
		if ( headers_sent() || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return;
		}
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$secure = function_exists( 'is_ssl' ) ? is_ssl() : false;
		setcookie(
			'payglocal_currency',
			$currency,
			array(
				'expires'  => time() + MONTH_IN_SECONDS,
				'path'     => $path,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Guest choice stored on the server. The currency cookie is still only a cache hint.
	 *
	 * @return array{country: string, currency: string, manual: bool}
	 */
	private function read_guest(): array {
		$empty = array(
			'country'  => '',
			'currency' => '',
			'manual'   => false,
		);
		if ( ! function_exists( 'get_transient' ) ) {
			return $empty;
		}
		$id = $this->guest_id( false );
		if ( '' === $id ) {
			return $empty;
		}
		$stored = get_transient( 'payglocal_guest_' . $id );
		if ( ! is_array( $stored ) ) {
			return $empty;
		}
		$country  = strtoupper( (string) ( $stored['country'] ?? '' ) );
		$currency = strtoupper( (string) ( $stored['currency'] ?? '' ) );
		return array(
			'country'  => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'currency' => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '',
			'manual'   => 'yes' === (string) ( $stored['manual'] ?? '' ),
		);
	}

	private function write_guest( string $country, string $currency, bool $manual ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		$id = $this->guest_id( true );
		if ( '' === $id ) {
			return;
		}
		$ttl = defined( 'MONTH_IN_SECONDS' ) ? MONTH_IN_SECONDS : 2592000;
		set_transient(
			'payglocal_guest_' . $id,
			array(
				'country'  => $country,
				'currency' => $currency,
				'manual'   => $manual ? 'yes' : 'no',
			),
			$ttl
		);
	}

	private function guest_id( bool $create ): string {
		$existing = isset( $_COOKIE['payglocal_sid'] ) ? (string) wp_unslash( $_COOKIE['payglocal_sid'] ) : '';
		if ( function_exists( 'sanitize_text_field' ) ) {
			$existing = sanitize_text_field( $existing );
		}
		if ( preg_match( '/^[a-f0-9]{32}$/', $existing ) ) {
			return $existing;
		}
		if ( ! $create || headers_sent() ) {
			return '';
		}
		try {
			$id = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$secure = function_exists( 'is_ssl' ) ? is_ssl() : false;
		$ttl    = defined( 'MONTH_IN_SECONDS' ) ? MONTH_IN_SECONDS : 2592000;
		setcookie(
			'payglocal_sid',
			$id,
			array(
				'expires'  => time() + $ttl,
				'path'     => $path,
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE['payglocal_sid'] = $id;
		return $id;
	}

	/**
	 * @return \WC_Session|null
	 */
	private function session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$wc = WC();
		if ( ! $wc || ! isset( $wc->session ) || ! $wc->session ) {
			return null;
		}
		return $wc->session;
	}
}
