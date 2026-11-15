<?php
/**
 * Widget wrapper for the currency switcher.
 *
 * @package PayGlocal
 */

namespace PayGlocal\Frontend;

/**
 * Places the switcher in a widget area.
 */
final class Switcher_Widget extends \WP_Widget {

	public function __construct() {
		parent::__construct(
			'payglocal_currency_switcher',
			__( 'PayGlocal Currency', 'payglocal' ),
			array(
				'description' => __( 'Country and currency selector for PayGlocal prices.', 'payglocal' ),
			)
		);
	}

	/**
	 * @param array<string, string> $args     Widget args.
	 * @param array<string, mixed>  $instance Instance.
	 */
	public function widget( $args, $instance ): void {
		unset( $instance );
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( function_exists( 'payglocal' ) ) {
			payglocal()->switcher()->render();
		}
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
