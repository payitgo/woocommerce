<?php
/**
 * The payment method in the block checkout (WooCommerce's default checkout).
 *
 * @package payitgo-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * payitgo in the block checkout: the same title and description as the
 * classic checkout; paying runs the gateway's process_payment.
 */
final class Payitgo_WC_Blocks extends AbstractPaymentMethodType {

	/**
	 * The payment method's id, as the gateway has it.
	 *
	 * @var string
	 */
	protected $name = 'payitgo';

	/** Reads the gateway's settings. */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_payitgo_settings', array() );
	}

	/**
	 * Offered when the gateway is.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['payitgo'] ) && $gateways['payitgo']->is_available();
	}

	/**
	 * The script that registers the payment method in the checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'payitgo-wc-blocks',
			PAYITGO_WC_URL . 'assets/js/checkout-block.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			PAYITGO_WC_VERSION,
			true
		);
		return array( 'payitgo-wc-blocks' );
	}

	/**
	 * What the script shows.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways['payitgo'] ?? null;
		return array(
			'title'       => $gateway ? $gateway->title : $this->get_setting( 'title' ),
			'description' => $gateway ? $gateway->description : $this->get_setting( 'description' ),
			'button'      => $gateway ? $gateway->order_button_text : '',
			'supports'    => $gateway ? array_values( $gateway->supports ) : array( 'products' ),
		);
	}
}
