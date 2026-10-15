<?php
/**
 * The few payitgo API calls the plugin makes, through WordPress's HTTP API
 * (so the site's proxy and SSL settings apply).
 *
 * @package payitgo-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * An answer from payitgo that is not a success: its HTTP status (0 when it
 * could not be reached) and its error text.
 */
class Payitgo_WC_API_Error extends Exception {

	/**
	 * The HTTP status, or 0 when payitgo could not be reached.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Makes the error.
	 *
	 * @param string $message The error's text.
	 * @param int    $status  The HTTP status.
	 */
	public function __construct( $message, $status ) {
		parent::__construct( $message );
		$this->status = (int) $status;
	}
}

/**
 * payitgo's API, with one secret key.
 */
class Payitgo_WC_API {

	/** The API's address unless the settings give another. */
	const DEFAULT_BASE_URL = 'https://api.payitgo.co';

	/**
	 * The secret key, test or live: payitgo says which (get_webhook()).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * The API's address, without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Makes the client.
	 *
	 * @param string $key      The secret key.
	 * @param string $base_url The API's address ("" for the default).
	 */
	public function __construct( $key, $base_url = '' ) {
		$this->key      = trim( (string) $key );
		$this->base_url = rtrim( '' !== trim( (string) $base_url ) ? trim( $base_url ) : self::DEFAULT_BASE_URL, '/' );
	}

	/**
	 * Creates a payment, or returns the open one of the same order id (a
	 * retry never makes a second payment).
	 *
	 * @param array $body The payment's fields (order_id, amount_usd or price_amount and price_currency, …).
	 * @return array The payment.
	 * @throws Payitgo_WC_API_Error When payitgo refuses it or cannot be reached.
	 */
	public function create_payment( array $body ) {
		return $this->request( 'POST', '/v1/payments', $body, 1 );
	}

	/**
	 * A payment, by its id.
	 *
	 * @param string $id The payment's id.
	 * @return array The payment.
	 * @throws Payitgo_WC_API_Error When payitgo refuses it or cannot be reached.
	 */
	public function get_payment( $id ) {
		return $this->request( 'GET', '/v1/payments/' . rawurlencode( $id ) );
	}

	/**
	 * The key's mode's webhook: its URL, its signing secret, and livemode,
	 * the key's mode.
	 *
	 * @return array
	 * @throws Payitgo_WC_API_Error When payitgo refuses it or cannot be reached.
	 */
	public function get_webhook() {
		$answer = $this->request( 'GET', '/v1/webhook' );
		return isset( $answer['webhook'] ) && is_array( $answer['webhook'] ) ? $answer['webhook'] : $answer;
	}

	/**
	 * Makes one call; a network failure or a 5xx is tried again $retries
	 * times (only for calls that are safe to repeat).
	 *
	 * @param string     $method  The HTTP method.
	 * @param string     $path    The path.
	 * @param array|null $body    The JSON body.
	 * @param int        $retries How many more times to try.
	 * @return array The decoded answer.
	 * @throws Payitgo_WC_API_Error When payitgo refuses it or cannot be reached.
	 */
	private function request( $method, $path, $body = null, $retries = 1 ) {
		if ( '' === $this->key ) {
			throw new Payitgo_WC_API_Error( __( 'no payitgo API key is set', 'payitgo-for-woocommerce' ), 0 );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->key,
				'Accept'        => 'application/json',
				'User-Agent'    => 'payitgo-woocommerce/' . PAYITGO_WC_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_remote_request( $this->base_url . $path, $args );
		if ( is_wp_error( $response ) ) {
			if ( $retries > 0 ) {
				return $this->request( $method, $path, $body, $retries - 1 );
			}
			/* translators: %s: why the request failed. */
			throw new Payitgo_WC_API_Error( sprintf( __( 'payitgo could not be reached: %s', 'payitgo-for-woocommerce' ), $response->get_error_message() ), 0 );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$answer = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status >= 500 && $retries > 0 ) {
			return $this->request( $method, $path, $body, $retries - 1 );
		}
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $answer ) && ! empty( $answer['error'] ) ? (string) $answer['error'] : 'HTTP ' . $status;
			throw new Payitgo_WC_API_Error( $message, $status );
		}
		return is_array( $answer ) ? $answer : array();
	}
}
