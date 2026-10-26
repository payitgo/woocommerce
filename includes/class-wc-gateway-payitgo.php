<?php
/**
 * The payitgo payment method: an order is paid on payitgo's hosted checkout,
 * and payitgo's signed notifications (and a look on the way back) move it to
 * paid, on hold or failed.
 *
 * @package payitgo-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The payment method.
 */
class WC_Gateway_Payitgo extends WC_Payment_Gateway {

	/** The order's latest payitgo payment id. */
	const META_PAYMENT = '_payitgo_payment_id';

	/** Every payitgo payment id the order made (a retry after one expired makes another). */
	const META_PAYMENTS = '_payitgo_payment_ids';

	/** The notifications handled for the order, so a repeated one does nothing. */
	const META_EVENTS = '_payitgo_event_ids';

	/** Where the signing secret read from payitgo is kept, per key. */
	const SECRET_CACHE = 'payitgo_wc_webhook_secret_';

	/** Set while the key's mode was just asked for, so payitgo is asked at most every five minutes. */
	const MODE_READ = 'payitgo_wc_mode_read';

	/** Set up the payment method. */
	public function __construct() {
		$this->id                 = 'payitgo';
		$this->method_title       = __( 'payitgo (crypto)', 'payitgo-for-woocommerce' );
		$this->method_description = __( 'Accept USDT, USDC, BTC, ETH and other coins with payitgo. Customers pay on payitgo\'s hosted checkout; orders are marked paid once the payment is final on chain.', 'payitgo-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->order_button_text  = __( 'Pay with crypto', 'payitgo-for-woocommerce' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		if ( 'yes' === $this->enabled && true === $this->test_mode() ) {
			$this->description = trim( $this->description . ' ' . __( 'Test mode: no real coins are needed, the checkout lets you choose how the payment goes.', 'payitgo-for-woocommerce' ) );
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_api_payitgo', array( $this, 'handle_notification' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thank_you' ) );
	}

	/** The settings. */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable', 'payitgo-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept crypto with payitgo', 'payitgo-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'payitgo-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'What customers see at checkout.', 'payitgo-for-woocommerce' ),
				'default'     => __( 'Crypto (USDT, BTC, ETH and more)', 'payitgo-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'payitgo-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'payitgo-for-woocommerce' ),
				'default'     => __( 'Pay with cryptocurrency on payitgo\'s secure checkout.', 'payitgo-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'api_key'        => array(
				'title'       => __( 'Secret API key', 'payitgo-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'From your payitgo dashboard (Developers > API keys): a test key to try it out, a live key for real payments. Several shops on one payitgo account: give each the key of its own store.', 'payitgo-for-woocommerce' ),
				'default'     => '',
			),
			'webhook_secret' => array(
				'title'       => __( 'Signing secret (optional)', 'payitgo-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Leave empty: the plugin reads it from payitgo with your key. Set it only to pin one.', 'payitgo-for-woocommerce' ),
				'default'     => '',
			),
			'base_url'       => array(
				'title'       => __( 'API address', 'payitgo-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Leave as it is unless payitgo tells you otherwise.', 'payitgo-for-woocommerce' ),
				'default'     => Payitgo_WC_API::DEFAULT_BASE_URL,
				'desc_tip'    => true,
			),
			'debug'          => array(
				'title'       => __( 'Debug log', 'payitgo-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log every call and notification', 'payitgo-for-woocommerce' ),
				'description' => __( 'In WooCommerce > Status > Logs, source "payitgo". Errors are always logged.', 'payitgo-for-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	/** The settings page, with what the shop needs to know first. */
	public function admin_options() {
		$notes = array();
		if ( '' !== trim( $this->get_option( 'api_key' ) ) && null === $this->test_mode() ) {
			$this->learn_mode( false ); // not known yet: ask payitgo now
		}
		$test = $this->test_mode();
		if ( '' === trim( $this->get_option( 'api_key' ) ) ) {
			$notes[] = array( 'error', __( 'Add your secret API key to start accepting payments.', 'payitgo-for-woocommerce' ) );
		} elseif ( true === $test ) {
			$notes[] = array( 'warning', __( 'Test mode: this is a test key, payments are simulated. Use a live key to take real payments.', 'payitgo-for-woocommerce' ) );
		} elseif ( null === $test ) {
			$notes[] = array( 'warning', __( 'payitgo could not be reached to check the key yet: save the settings again in a moment.', 'payitgo-for-woocommerce' ) );
		}
		if ( 0 === strpos( $this->notification_url(), 'https://' ) ) {
			/* translators: %s: the shop's notification address. */
			$notes[] = array( 'info', sprintf( __( 'Each payment tells the shop how it went at %s: nothing to set up.', 'payitgo-for-woocommerce' ), '<code>' . esc_html( $this->notification_url() ) . '</code>' ) );
		} else {
			/* translators: %s: the shop's notification address. */
			$notes[] = array( 'warning', sprintf( __( 'This site is not on https, so payitgo cannot notify it: orders are checked when the customer comes back from the checkout. On https, payments notify %s by themselves.', 'payitgo-for-woocommerce' ), '<code>' . esc_html( $this->notification_url() ) . '</code>' ) );
		}
		foreach ( $notes as $note ) {
			printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $note[0] ), wp_kses( $note[1], array( 'code' => array() ) ) );
		}
		parent::admin_options();
	}

	/**
	 * Saves the settings, then asks payitgo about the key: its mode, and its
	 * mode's signing secret.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		if ( '' !== trim( $this->get_option( 'api_key' ) ) ) {
			delete_transient( self::MODE_READ );
			$this->learn_mode( true );
		}
		return $saved;
	}

	/**
	 * Whether the key is a test key (its payments are simulated). The key's
	 * text does not say: payitgo does, asked when the settings are saved or
	 * shown, or told by a payment or the signing secret the key read, and the
	 * answer is kept with the key. Never asked here: a shop's pages do not
	 * wait for payitgo.
	 *
	 * @return bool|null Null while payitgo has not been asked about this key.
	 */
	public function test_mode() {
		if ( '' === trim( $this->get_option( 'api_key' ) ) || $this->get_option( 'key_hash' ) !== $this->key_hash() ) {
			return null;
		}
		return 'test' === $this->get_option( 'key_mode' );
	}

	/**
	 * Asks payitgo which mode the key is in (GET /v1/webhook), at most every
	 * five minutes, and keeps the answer and the mode's signing secret.
	 *
	 * @param bool $report Show what went wrong on the settings page.
	 */
	private function learn_mode( $report ) {
		if ( false !== get_transient( self::MODE_READ ) ) {
			return;
		}
		set_transient( self::MODE_READ, 1, 5 * MINUTE_IN_SECONDS );
		try {
			$webhook = $this->api()->get_webhook();
		} catch ( Payitgo_WC_API_Error $e ) {
			$this->log( 'error', 'the key could not be checked: ' . $e->getMessage() );
			if ( $report ) {
				$message = 401 === $e->status
					? __( 'payitgo does not know this secret API key: copy it again from your payitgo dashboard.', 'payitgo-for-woocommerce' )
					/* translators: %s: why payitgo could not be asked. */
					: sprintf( __( 'payitgo could not check the key (%s): save the settings again in a moment.', 'payitgo-for-woocommerce' ), $e->getMessage() );
				WC_Admin_Settings::add_error( $message );
			}
			return;
		}
		$this->remember_mode( ! empty( $webhook['livemode'] ) );
		$secret = (string) ( $webhook['secret'] ?? '' );
		if ( '' !== $secret ) {
			set_transient( $this->secret_cache(), $secret, DAY_IN_SECONDS );
		}
	}

	/**
	 * Keeps the key's mode, with a hash of the key it is the mode of.
	 *
	 * @param bool $live Whether the key is a live key.
	 */
	private function remember_mode( $live ) {
		$this->update_option( 'key_hash', $this->key_hash() );
		$this->update_option( 'key_mode', $live ? 'live' : 'test' );
	}

	/**
	 * A hash of the settings' key: what its mode and signing secret are kept under.
	 *
	 * @return string
	 */
	private function key_hash() {
		return hash( 'sha256', trim( $this->get_option( 'api_key' ) ) );
	}

	/**
	 * Where the key's signing secret is kept.
	 *
	 * @return string
	 */
	private function secret_cache() {
		return self::SECRET_CACHE . substr( $this->key_hash(), 0, 16 );
	}

	/**
	 * Offered at checkout once it has a key.
	 *
	 * @return bool
	 */
	public function is_available() {
		return parent::is_available() && '' !== trim( $this->get_option( 'api_key' ) );
	}

	/**
	 * Creates the order's payment at payitgo and sends the customer to its
	 * checkout page. Paying an order again (after its payment expired) makes
	 * a new payment; while one is open, it is the one the customer gets.
	 *
	 * @param int $order_id The order.
	 * @return array
	 * @throws Exception When the payment cannot be started: the customer is told why.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			throw new Exception( __( 'This order could not be found.', 'payitgo-for-woocommerce' ) );
		}
		$body = array_merge(
			array(
				'order_id'    => (string) $order->get_order_number(),
				'description' => $this->describe( $order ),
				'success_url' => $this->get_return_url( $order ),
				'cancel_url'  => $order->get_cancel_order_url_raw(),
				'metadata'    => array(
					'wc_order_id'     => (string) $order->get_id(),
					'wc_order_number' => (string) $order->get_order_number(),
					'shop'            => home_url(),
				),
			),
			Payitgo_WC_Helpers::price( $order->get_total(), $order->get_currency() )
		);
		if ( $order->get_billing_email() ) {
			$body['customer_email'] = $order->get_billing_email();
		}
		if ( 0 === strpos( $this->notification_url(), 'https://' ) ) {
			$body['webhook_url'] = $this->notification_url();
		}
		try {
			try {
				$payment = $this->api()->create_payment( $body );
			} catch ( Payitgo_WC_API_Error $e ) {
				if ( 400 !== $e->status || ! isset( $body['webhook_url'] ) || 0 !== strpos( $e->getMessage(), 'webhook_url:' ) ) {
					throw $e;
				}
				// payitgo refuses to notify this address (not public, e.g. an intranet shop): the order is
				// checked when the customer comes back from the checkout instead.
				$this->log( 'warning', sprintf( 'order %d: payitgo cannot notify %s (%s): the order is checked when the customer comes back', $order->get_id(), $body['webhook_url'], $e->getMessage() ) );
				unset( $body['webhook_url'] );
				$payment = $this->api()->create_payment( $body );
			}
		} catch ( Payitgo_WC_API_Error $e ) {
			$this->log( 'error', sprintf( 'order %d: the payment could not be created: %s', $order->get_id(), $e->getMessage() ) );
			/* translators: %s: payitgo's reason. */
			throw new Exception( sprintf( __( 'The crypto payment could not be started (%s). Please try again, or choose another way to pay.', 'payitgo-for-woocommerce' ), $e->getMessage() ) );
		}
		if ( empty( $payment['id'] ) || empty( $payment['checkout_url'] ) ) {
			$this->log( 'error', sprintf( 'order %d: payitgo answered without a checkout page: %s', $order->get_id(), wp_json_encode( $payment ) ) );
			throw new Exception( __( 'The crypto payment could not be started. Please try again, or choose another way to pay.', 'payitgo-for-woocommerce' ) );
		}
		if ( ! Payitgo_WC_Helpers::made_by( $payment, home_url() ) || Payitgo_WC_Helpers::order_of( $payment ) !== $order->get_id() ) {
			// Another shop's open payment for the same order number: the shops share a key.
			$this->log( 'error', sprintf( 'order %d: payitgo answered with payment %s of another shop (%s): give each shop the key of its own store', $order->get_id(), $payment['id'], $payment['metadata']['shop'] ?? '?' ) );
			throw new Exception( __( 'The crypto payment could not be started. Please choose another way to pay.', 'payitgo-for-woocommerce' ) );
		}
		if ( isset( $payment['livemode'] ) && $this->get_option( 'key_hash' ) !== $this->key_hash() ) {
			$this->remember_mode( (bool) $payment['livemode'] ); // the payment says which mode the key is in
		}
		$this->remember( $order, $payment );
		$this->log( 'info', sprintf( 'order %d: payment %s, %s', $order->get_id(), $payment['id'], $payment['checkout_url'] ) );
		return array(
			'result'   => 'success',
			'redirect' => $payment['checkout_url'],
		);
	}

	/**
	 * The address payitgo notifies: WooCommerce's API endpoint for the plugin.
	 *
	 * @return string
	 */
	public function notification_url() {
		return WC()->api_request_url( 'payitgo' );
	}

	/**
	 * Receives a notification from payitgo: verifies it, moves the order on,
	 * and answers 200 (400 to one that is not payitgo's, which is not sent again).
	 */
	public function handle_notification() {
		$payload = (string) file_get_contents( 'php://input' );
		$header  = isset( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ) ) : '';
		try {
			$event = $this->verify( $payload, $header );
		} catch ( Payitgo_WC_Signature_Error $e ) {
			$this->log( 'warning', 'a notification was refused: ' . $e->getMessage() );
			$this->respond( 400, 'invalid signature' );
			return;
		}
		$this->log( 'info', sprintf( 'notification %s: %s', $event['id'] ?? '', $event['type'] ) );
		if ( 0 === strpos( $event['type'], 'payment.' ) && ! empty( $event['data'] ) && is_array( $event['data'] ) ) {
			$this->apply( $event['data'], (string) ( $event['id'] ?? '' ), $event['type'] );
		}
		$this->respond( 200, 'ok' );
	}

	/**
	 * The order-received page: the order is checked at payitgo (a shop that
	 * notifications cannot reach learns the outcome here), and a payment
	 * still on its way is explained.
	 *
	 * @param int $order_id The order.
	 */
	public function thank_you( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$id = (string) $order->get_meta( self::META_PAYMENT );
		if ( '' !== $id && ! $order->is_paid() ) {
			try {
				$this->apply( $this->api()->get_payment( $id ) );
				$order = wc_get_order( $order_id );
			} catch ( Payitgo_WC_API_Error $e ) {
				$this->log( 'warning', sprintf( 'order %d: payment %s could not be read: %s', $order->get_id(), $id, $e->getMessage() ) );
			}
		}
		if ( $order->is_paid() ) {
			return;
		}
		if ( $order->has_status( 'failed' ) ) {
			echo '<p>' . esc_html__( 'Your crypto payment did not go through. You can pay again from your account.', 'payitgo-for-woocommerce' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Your crypto payment is being confirmed on the network. We will email you as soon as it is final.', 'payitgo-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Moves an order on from its payment as payitgo has it now. Only a
	 * payment the order made, in the key's mode, counts; a notification seen
	 * before does nothing.
	 *
	 * @param array  $payment  The payment.
	 * @param string $event_id The notification's id ("" when read directly).
	 * @param string $type     The notification's type ("" when read directly).
	 */
	private function apply( array $payment, $event_id = '', $type = '' ) {
		$order = wc_get_order( Payitgo_WC_Helpers::order_of( $payment ) );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return; // Not one of this shop's orders paid with payitgo.
		}
		$id = (string) ( $payment['id'] ?? '' );
		if ( '' === $id || ! in_array( $id, $this->meta_list( $order, self::META_PAYMENTS ), true ) ) {
			return;
		}
		$test = $this->test_mode();
		if ( isset( $payment['livemode'] ) && null !== $test && (bool) $payment['livemode'] === $test ) {
			return; // The other mode's: never fulfil a real order from a test payment.
		}
		if ( '' !== $event_id ) {
			$seen = $this->meta_list( $order, self::META_EVENTS );
			if ( in_array( $event_id, $seen, true ) ) {
				return;
			}
			$seen[] = $event_id;
			$order->update_meta_data( self::META_EVENTS, array_slice( $seen, -30 ) );
		}
		$token    = (string) ( $payment['token'] ?? '' );
		$received = (string) ( $payment['crypto_received'] ?? '0' );
		$status   = (string) ( $payment['status'] ?? '' );
		switch ( Payitgo_WC_Helpers::action_for( $status ) ) {
			case Payitgo_WC_Helpers::COMPLETE:
				if ( $order->is_paid() ) {
					break;
				}
				if ( ! Payitgo_WC_Helpers::amounts_match( $payment, $order->get_total(), $order->get_currency() ) ) {
					$order->update_status(
						'on-hold',
						/* translators: 1: payment id, 2: its price, 3: the order total. */
						sprintf( __( 'payitgo payment %1$s is final, but it was for %2$s while the order is now %3$s: check it before shipping.', 'payitgo-for-woocommerce' ), $id, $this->price_of( $payment ), Payitgo_WC_Helpers::money( $order->get_total(), $order->get_currency() ) )
					);
					break;
				}
				$order->payment_complete( (string) ( $payment['tx_id'] ?? $id ) );
				/* translators: 1: payment id, 2: amount, 3: coin, 4: transaction id. */
				$order->add_order_note( sprintf( __( 'payitgo payment %1$s is final: %2$s %3$s received (transaction %4$s).', 'payitgo-for-woocommerce' ), $id, $received, $token, $payment['tx_id'] ?? '-' ) );
				break;
			case Payitgo_WC_Helpers::HOLD:
				if ( $order->is_paid() ) {
					break;
				}
				if ( 'underpaid' === $status ) {
					$currency = (string) ( $payment['currency'] ?? '' );
					/* translators: 1: payment id, 2: what arrived, 3: what was due, both in the payment's currency. */
					$note = sprintf( __( 'payitgo payment %1$s closed short: %2$s of %3$s arrived (it is settled to you). Refund the customer or agree on the rest with them.', 'payitgo-for-woocommerce' ), $id, Payitgo_WC_Helpers::money( $payment['amount_received'] ?? '0', $currency ), Payitgo_WC_Helpers::money( $payment['amount_due'] ?? '', $currency ) );
				} else {
					/* translators: 1: payment id, 2: amount, 3: coin. */
					$note = sprintf( __( 'payitgo payment %1$s: %2$s %3$s arrived, waiting for network confirmations.', 'payitgo-for-woocommerce' ), $id, $received, $token );
				}
				if ( $order->has_status( 'on-hold' ) ) {
					$order->add_order_note( $note );
				} else {
					$order->update_status( 'on-hold', $note );
				}
				break;
			case Payitgo_WC_Helpers::FAIL:
				// Only the order's latest payment fails it: a newer one may be on its way.
				if ( $order->is_paid() || ! $order->has_status( array( 'pending', 'on-hold' ) ) || (string) $order->get_meta( self::META_PAYMENT ) !== $id ) {
					break;
				}
				$note = 'expired' === $status
					/* translators: %s: payment id. */
					? sprintf( __( 'payitgo payment %s expired with nothing paid. The customer can pay again.', 'payitgo-for-woocommerce' ), $id )
					/* translators: %s: payment id. */
					: sprintf( __( 'payitgo payment %s failed: its deposit never became final. The customer can pay again.', 'payitgo-for-woocommerce' ), $id );
				$order->update_status( 'failed', $note );
				break;
			case Payitgo_WC_Helpers::NOTE:
				/* translators: 1: payment id, 2: amount received, 3: amount due, 4: coin. */
				$order->add_order_note( sprintf( __( 'payitgo payment %1$s: %2$s of %3$s %4$s arrived; the customer can still send the rest.', 'payitgo-for-woocommerce' ), $id, $received, $payment['crypto_amount'] ?? '', $token ) );
				break;
		}
		if ( 'payment.wrong_asset' === $type ) {
			/* translators: %s: payment id. */
			$order->add_order_note( sprintf( __( 'payitgo payment %s: a deposit in another coin than the one asked for arrived. See the payment in your payitgo dashboard.', 'payitgo-for-woocommerce' ), $id ) );
		} elseif ( 'payment.late_deposit' === $type ) {
			/* translators: %s: payment id. */
			$order->add_order_note( sprintf( __( 'payitgo payment %s: a deposit arrived after the payment had closed. It is settled to you like any other.', 'payitgo-for-woocommerce' ), $id ) );
		}
		$order->save();
	}

	/**
	 * Verifies a notification with the signing secret: the one set, or the
	 * one read from payitgo (read again once when it fails, in case it was
	 * rolled; at most every five minutes).
	 *
	 * @param string $payload The raw body.
	 * @param string $header  The X-Webhook-Signature header.
	 * @return array The event.
	 * @throws Payitgo_WC_Signature_Error When it is not payitgo's.
	 */
	private function verify( $payload, $header ) {
		$pinned = trim( $this->get_option( 'webhook_secret' ) );
		if ( '' !== $pinned ) {
			return Payitgo_WC_Helpers::verify( $payload, $header, $pinned );
		}
		try {
			return Payitgo_WC_Helpers::verify( $payload, $header, $this->signing_secret( false ) );
		} catch ( Payitgo_WC_Signature_Error $e ) {
			return Payitgo_WC_Helpers::verify( $payload, $header, $this->signing_secret( true ) );
		}
	}

	/**
	 * The key's mode's signing secret, read from payitgo and kept a day.
	 *
	 * @param bool $fresh Read it again (at most every five minutes).
	 * @return string
	 */
	private function signing_secret( $fresh ) {
		$cache  = $this->secret_cache();
		$secret = get_transient( $cache );
		if ( false !== $secret && ( ! $fresh || false !== get_transient( $cache . '_read' ) ) ) {
			return (string) $secret;
		}
		set_transient( $cache . '_read', 1, 5 * MINUTE_IN_SECONDS );
		try {
			$webhook = $this->api()->get_webhook();
			$secret  = (string) ( $webhook['secret'] ?? '' );
		} catch ( Payitgo_WC_API_Error $e ) {
			$this->log( 'error', 'the signing secret could not be read: ' . $e->getMessage() );
			return false === $secret ? '' : (string) $secret;
		}
		$this->remember_mode( ! empty( $webhook['livemode'] ) );
		if ( '' !== $secret ) {
			set_transient( $cache, $secret, DAY_IN_SECONDS );
		}
		return $secret;
	}

	/**
	 * Records a payment on its order.
	 *
	 * @param WC_Order $order   The order.
	 * @param array    $payment The payment.
	 */
	private function remember( $order, array $payment ) {
		$ids = $this->meta_list( $order, self::META_PAYMENTS );
		$new = ! in_array( $payment['id'], $ids, true );
		if ( $new ) {
			$ids[] = $payment['id'];
			$order->update_meta_data( self::META_PAYMENTS, $ids );
		}
		$order->update_meta_data( self::META_PAYMENT, $payment['id'] );
		if ( $new ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: payment id, 2: its price, 3: " (test mode)" or nothing. */
					__( 'payitgo payment %1$s created for %2$s%3$s.', 'payitgo-for-woocommerce' ),
					$payment['id'],
					$this->price_of( $payment ),
					empty( $payment['livemode'] ) ? __( ' (test mode)', 'payitgo-for-woocommerce' ) : ''
				)
			);
		}
		$order->save();
	}

	/**
	 * A list kept in an order's meta: none when there is none yet.
	 *
	 * @param WC_Order $order The order.
	 * @param string   $key   The meta key.
	 * @return string[]
	 */
	private function meta_list( $order, $key ) {
		$list = $order->get_meta( $key );
		return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
	}

	/**
	 * A payment's price as people read it, in its currency: "$20.61" or
	 * "18.40 EUR".
	 *
	 * @param array $payment The payment.
	 * @return string
	 */
	private function price_of( array $payment ) {
		return Payitgo_WC_Helpers::money( $payment['amount'] ?? '', $payment['currency'] ?? '' );
	}

	/**
	 * What the order is for, for the checkout page.
	 *
	 * @param WC_Order $order The order.
	 * @return string
	 */
	private function describe( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$lines[] = array( $item->get_name(), $item->get_quantity() );
		}
		/* translators: %s: order number. */
		return Payitgo_WC_Helpers::describe( $lines, sprintf( __( 'Order %s', 'payitgo-for-woocommerce' ), $order->get_order_number() ) );
	}

	/**
	 * The API, with the settings' key.
	 *
	 * @return Payitgo_WC_API
	 */
	private function api() {
		return new Payitgo_WC_API( $this->get_option( 'api_key' ), $this->get_option( 'base_url' ) );
	}

	/**
	 * Logs to WooCommerce's log, source "payitgo": errors always, the rest
	 * with the debug log on.
	 *
	 * @param string $level   A PSR-3 level.
	 * @param string $message The message.
	 */
	private function log( $level, $message ) {
		if ( 'error' !== $level && 'yes' !== $this->get_option( 'debug' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'payitgo' ) );
	}

	/**
	 * Answers a notification and stops.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $text   The body.
	 */
	private function respond( $status, $text ) {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $text );
		exit;
	}
}
