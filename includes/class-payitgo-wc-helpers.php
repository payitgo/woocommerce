<?php
/**
 * What the plugin decides on its own, without WordPress: verifying a
 * notification's signature, naming orders at payitgo, describing them, and
 * what a payment's status means for its order. Tested on its own (tests/).
 *
 * @package payitgo-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * A notification that payitgo did not send, or that is too old.
 */
class Payitgo_WC_Signature_Error extends Exception {}

/**
 * The plugin's decisions.
 */
final class Payitgo_WC_Helpers {

	/** How old a notification's signature may be, in seconds. */
	const TOLERANCE = 300;

	/** The longest description payitgo takes, in bytes. */
	const MAX_DESCRIPTION = 500;

	/** What an order does on a payment's status (see action_for). */
	const COMPLETE = 'complete'; // The money is final: the order is paid.
	const HOLD     = 'hold';     // Money arrived but the order needs a person, or confirmations.
	const FAIL     = 'fail';     // Nothing usable arrived: the customer can pay again.
	const NOTE     = 'note';     // Worth a note on the order, nothing more.
	const NONE     = 'none';     // Nothing to do.

	/**
	 * Verifies a notification (its raw body and X-Webhook-Signature header,
	 * "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>">") with the mode's
	 * signing secret, and returns the event.
	 *
	 * @param string   $payload   The raw request body.
	 * @param string   $header    The X-Webhook-Signature header.
	 * @param string   $secret    The signing secret (whsec_…).
	 * @param int|null $now       The time to check against (tests).
	 * @param int      $tolerance How old the signature may be, in seconds (0: any).
	 * @return array The event: id, type, livemode, created_at, data.
	 * @throws Payitgo_WC_Signature_Error When it is not payitgo's, or too old.
	 */
	public static function verify( $payload, $header, $secret, $now = null, $tolerance = self::TOLERANCE ) {
		if ( '' === (string) $secret ) {
			throw new Payitgo_WC_Signature_Error( 'no signing secret' );
		}
		$parts = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', $part, 2 );
			if ( 2 === count( $kv ) ) {
				$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}
		if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
			throw new Payitgo_WC_Signature_Error( 'missing or malformed X-Webhook-Signature header' );
		}
		$expected = hash_hmac( 'sha256', $parts['t'] . '.' . $payload, $secret );
		if ( ! hash_equals( $expected, strtolower( $parts['v1'] ) ) ) {
			throw new Payitgo_WC_Signature_Error( 'signature does not match' );
		}
		$now = null === $now ? time() : $now;
		if ( $tolerance > 0 && ( ! ctype_digit( $parts['t'] ) || abs( $now - (int) $parts['t'] ) > $tolerance ) ) {
			throw new Payitgo_WC_Signature_Error( 'timestamp outside the tolerance' );
		}
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			throw new Payitgo_WC_Signature_Error( 'not an event' );
		}
		return $event;
	}

	/**
	 * The order id payitgo knows an order by: the shop's prefix and its id.
	 * payitgo's order ids are unique per account, so shops sharing a key use
	 * different prefixes.
	 *
	 * @param string     $prefix The shop's prefix ("WC-").
	 * @param int|string $id     The WooCommerce order id.
	 * @return string
	 */
	public static function order_ref( $prefix, $id ) {
		return $prefix . $id;
	}

	/**
	 * The WooCommerce order id in a payment's order_id, or 0 when it is not
	 * one of this shop's.
	 *
	 * @param string $prefix The shop's prefix.
	 * @param string $ref    The payment's order_id.
	 * @return int
	 */
	public static function order_id( $prefix, $ref ) {
		$ref = (string) $ref;
		if ( '' !== $prefix && 0 !== strpos( $ref, $prefix ) ) {
			return 0;
		}
		$id = substr( $ref, strlen( $prefix ) );
		return ctype_digit( $id ) ? (int) $id : 0;
	}

	/**
	 * What is being paid for, as the checkout page shows it: "2 x T-shirt,
	 * 1 x Mug", cut to what payitgo takes.
	 *
	 * @param array  $lines    Each item's name => quantity (or a list of [name, quantity]).
	 * @param string $fallback Used when there are no items ("Order 1234").
	 * @return string
	 */
	public static function describe( array $lines, $fallback ) {
		$parts = array();
		foreach ( $lines as $name => $qty ) {
			if ( is_array( $qty ) ) {
				list( $name, $qty ) = $qty;
			}
			$name = trim( payitgo_wc_strip_tags( (string) $name ) );
			if ( '' !== $name ) {
				$parts[] = ( (int) $qty > 1 ? (int) $qty . ' x ' : '' ) . $name;
			}
		}
		$text = $parts ? implode( ', ', $parts ) : (string) $fallback;
		if ( strlen( $text ) <= self::MAX_DESCRIPTION ) {
			return $text;
		}
		$ellipsis = '…';
		$cut      = substr( $text, 0, self::MAX_DESCRIPTION - strlen( $ellipsis ) );
		// Never end inside a multibyte character.
		while ( '' !== $cut && ! preg_match( '//u', $cut ) ) {
			$cut = substr( $cut, 0, -1 );
		}
		return rtrim( $cut, " ,\t" ) . $ellipsis;
	}

	/**
	 * The payment's price fields for an order total in the shop's currency:
	 * amount_usd for a dollar shop, else price_amount and price_currency
	 * (payitgo converts them at the rate of the moment).
	 *
	 * @param string $total    The order total, as WooCommerce has it.
	 * @param string $currency The order's currency code.
	 * @return array
	 */
	public static function price( $total, $currency ) {
		$amount = self::decimal( $total );
		if ( 'USD' === strtoupper( $currency ) ) {
			return array( 'amount_usd' => $amount );
		}
		return array(
			'price_amount'   => $amount,
			'price_currency' => strtoupper( $currency ),
		);
	}

	/**
	 * Whether a payment is for the order's total, in the order's currency:
	 * its price_amount and price_currency, or its usd_amount for a dollar
	 * order, to the cent.
	 *
	 * @param array  $payment  The payment, as payitgo sends it.
	 * @param string $total    The order total.
	 * @param string $currency The order's currency.
	 * @return bool
	 */
	public static function amounts_match( array $payment, $total, $currency ) {
		$currency = strtoupper( $currency );
		if ( ! empty( $payment['price_currency'] ) ) {
			return strtoupper( $payment['price_currency'] ) === $currency
				&& self::thousandths( $payment['price_amount'] ?? '' ) === self::thousandths( $total );
		}
		return 'USD' === $currency && self::thousandths( $payment['usd_amount'] ?? '' ) === self::thousandths( $total );
	}

	/**
	 * What a payment's status means for its order. The status is the truth:
	 * notifications can arrive late and out of order.
	 *
	 * @param string $status The payment's status.
	 * @return string One of COMPLETE, HOLD, FAIL, NOTE, NONE.
	 */
	public static function action_for( $status ) {
		switch ( $status ) {
			case 'confirmed':
				return self::COMPLETE;
			case 'detecting':
			case 'underpaid':
				return self::HOLD;
			case 'expired':
			case 'failed':
				return self::FAIL;
			case 'partially_paid':
				return self::NOTE;
			default:
				return self::NONE;
		}
	}

	/**
	 * An amount as a decimal string, as WooCommerce stores it ("19.99",
	 * "1.250" for a currency of three places); anything else with two places.
	 *
	 * @param mixed $value The amount.
	 * @return string
	 */
	public static function decimal( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^\d+(\.\d+)?$/', $value ) ) {
			return $value;
		}
		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * An amount in thousandths, to compare amounts written differently ("10",
	 * "10.00"; three places for the currencies that have them).
	 *
	 * @param mixed $value The amount.
	 * @return int
	 */
	private static function thousandths( $value ) {
		return (int) round( (float) $value * 1000 );
	}
}

if ( ! function_exists( 'payitgo_wc_strip_tags' ) ) {
	/**
	 * wp_strip_all_tags when WordPress is loaded, strip_tags otherwise (tests).
	 *
	 * @param string $text The text.
	 * @return string
	 */
	function payitgo_wc_strip_tags( $text ) {
		return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
	}
}
