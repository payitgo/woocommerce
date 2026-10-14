<?php
/**
 * Removes the plugin's settings and cached signing secrets when it is deleted.
 * Orders keep their payitgo notes and payment ids.
 *
 * @package payitgo-for-woocommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_payitgo_settings' );
foreach ( array( 'test', 'live' ) as $mode ) {
	delete_transient( 'payitgo_wc_webhook_secret_' . $mode );
	delete_transient( 'payitgo_wc_webhook_secret_' . $mode . '_read' );
}
