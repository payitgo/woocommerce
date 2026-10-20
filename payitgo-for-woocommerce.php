<?php
/**
 * Plugin Name:          payitgo for WooCommerce
 * Plugin URI:           https://github.com/payitgo/woocommerce
 * Description:          Accept crypto payments (USDT, USDC, BTC, ETH and more) with payitgo: customers pay on payitgo's hosted checkout, and orders update by themselves.
 * Version:              0.6.0
 * Author:               payitgo
 * Author URI:           https://payitgo.co
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          payitgo-for-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.3
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 */

defined( 'ABSPATH' ) || exit;

define( 'PAYITGO_WC_VERSION', '0.6.0' );
define( 'PAYITGO_WC_FILE', __FILE__ );
define( 'PAYITGO_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAYITGO_WC_URL', plugin_dir_url( __FILE__ ) );

require_once PAYITGO_WC_DIR . 'includes/class-payitgo-wc-helpers.php';
require_once PAYITGO_WC_DIR . 'includes/class-payitgo-wc-api.php';

// The shop's orders live in WooCommerce's own tables (HPOS) or in posts: the
// plugin reads and writes them only through WooCommerce, so both work. The
// block checkout gets the payment method too.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAYITGO_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PAYITGO_WC_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'payitgo-for-woocommerce', false, dirname( plugin_basename( PAYITGO_WC_FILE ) ) . '/languages' );
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return; // WooCommerce is not active.
		}
		require_once PAYITGO_WC_DIR . 'includes/class-wc-gateway-payitgo.php';
		add_filter(
			'woocommerce_payment_gateways',
			static function ( $gateways ) {
				$gateways[] = 'WC_Gateway_Payitgo';
				return $gateways;
			}
		);
	}
);

add_action(
	'woocommerce_blocks_loaded',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
			return;
		}
		require_once PAYITGO_WC_DIR . 'includes/class-payitgo-wc-blocks.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Payitgo_WC_Blocks() );
			}
		);
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( PAYITGO_WC_FILE ),
	static function ( $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payitgo' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payitgo-for-woocommerce' ) . '</a>' );
		return $links;
	}
);
