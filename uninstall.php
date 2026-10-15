<?php
/**
 * Removes the plugin's settings and cached signing secrets when it is deleted.
 * Orders keep their payitgo notes and payment ids.
 *
 * @package payitgo-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$payitgo_settings = get_option( 'woocommerce_payitgo_settings' );
if ( is_array( $payitgo_settings ) && ! empty( $payitgo_settings['key_hash'] ) ) {
	$payitgo_cache = 'payitgo_wc_webhook_secret_' . substr( (string) $payitgo_settings['key_hash'], 0, 16 );
	delete_transient( $payitgo_cache );
	delete_transient( $payitgo_cache . '_read' );
}
delete_transient( 'payitgo_wc_mode_read' );
delete_option( 'woocommerce_payitgo_settings' );
