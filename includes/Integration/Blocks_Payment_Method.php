<?php
/**
 * WooCommerce Blocks payment method.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Integration;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers PayGlocal as a checkout block payment method.
 */
final class Blocks_Payment_Method extends AbstractPaymentMethodType {

	/**
	 * Gateway id.
	 *
	 * @var string
	 */
	protected $name = 'payglocal';

	/**
	 * @var array<string, string>
	 */
	private $settings = array();

	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_payglocal_settings', array() );
		if ( ! is_array( $this->settings ) ) {
			$this->settings = array();
		}
	}

	public function is_active(): bool {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * @return array<int, string>
	 */
	public function get_payment_method_script_handles(): array {
		wp_register_script(
			'payglocal-blocks',
			plugins_url( 'assets/js/blocks.js', PAYGLOCAL_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			PAYGLOCAL_VERSION,
			true
		);

		return array( 'payglocal-blocks' );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->settings['title'] ?? __( 'PayGlocal', 'payglocal' ),
			'description' => $this->settings['description'] ?? __( 'Pay in your selected currency.', 'payglocal' ),
			'supports'    => array( 'products', 'refunds' ),
		);
	}
}
