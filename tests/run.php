<?php
/**
 * The plugin's own decisions, tested without WordPress:
 *
 *   php tests/run.php
 *
 * @package payitgo-for-woocommerce
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-payitgo-wc-helpers.php';

$failures = 0;
$checks   = 0;

/**
 * Records one check.
 *
 * @param bool   $ok   Whether it held.
 * @param string $what What was checked.
 */
function check( $ok, $what ) {
	global $failures, $checks;
	++$checks;
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL: $what\n" );
	}
}

/**
 * Whether verifying throws Payitgo_WC_Signature_Error.
 *
 * @param callable $fn The verification.
 * @return bool
 */
function refused( callable $fn ) {
	try {
		$fn();
	} catch ( Payitgo_WC_Signature_Error $e ) {
		return true;
	}
	return false;
}

// ── Signatures ────────────────────────────────────────────────────────────────
$secret = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
$body   = '{"id":"evt_1","type":"payment.confirmed","livemode":false,"data":{"id":"pay_1","order_id":"WC-42","status":"confirmed"}}';
$t      = 1791619398;
$sig    = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $body, $secret );

$event = Payitgo_WC_Helpers::verify( $body, $sig, $secret, $t + 10 );
check( 'payment.confirmed' === $event['type'] && 'pay_1' === $event['data']['id'], 'a signed notification is verified' );
check( 'evt_1' === Payitgo_WC_Helpers::verify( $body, ' ' . str_replace( ',', ' , ', $sig ), $secret, $t )['id'], 'spaces around the parts are allowed' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, $sig, '5a1c3e7f9b2d4f6a8c0e2b4d6f8a1c3e5b7d9f1a3c5e7b9d1f3a5c7e9b1d3f5a', $t ) ), 'another secret is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( str_replace( 'WC-42', 'WC-43', $body ), $sig, $secret, $t ) ), 'a changed body is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, $sig, $secret, $t + 301 ) ), 'an old signature is refused' );
check( 'evt_1' === Payitgo_WC_Helpers::verify( $body, $sig, $secret, $t + 9999, 0 )['id'], 'tolerance 0 takes any age' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, 'v1=abc', $secret, $t ) ), 'a header without t is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, '', $secret, $t ) ), 'no header is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, $sig, '', $t ) ), 'no secret is refused' );
$junk = 'not json';
check( refused( fn() => Payitgo_WC_Helpers::verify( $junk, 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $junk, $secret ), $secret, $t ) ), 'a signed body that is no event is refused' );

// ── Orders at payitgo ────────────────────────────────────────────────────────
$ours = array( 'metadata' => array( 'wc_order_id' => '42', 'shop' => 'https://shop.example/' ) );
check( 42 === Payitgo_WC_Helpers::order_of( $ours ), "the order is read from the payment's metadata" );
check( 0 === Payitgo_WC_Helpers::order_of( array( 'order_id' => '42' ) ), 'a payment without metadata is no order of ours' );
check( 0 === Payitgo_WC_Helpers::order_of( array( 'metadata' => array( 'wc_order_id' => '42a' ) ) ), 'an order id that is not a number is not ours' );
check( Payitgo_WC_Helpers::made_by( $ours, 'https://shop.example' ), 'a payment made by this shop' );
check( ! Payitgo_WC_Helpers::made_by( $ours, 'https://other.example' ), "another shop's payment" );
check( ! Payitgo_WC_Helpers::made_by( array(), 'https://shop.example' ), 'a payment that names no shop' );

// ── Descriptions ─────────────────────────────────────────────────────────────
check( '2 x Acme T-shirt, Mug' === Payitgo_WC_Helpers::describe( array( array( 'Acme <b>T-shirt</b>', 2 ), array( 'Mug', 1 ) ), 'Order 1' ), 'items are listed with their quantities' );
check( 'Order 1' === Payitgo_WC_Helpers::describe( array(), 'Order 1' ), 'an order without items is named' );
$long = Payitgo_WC_Helpers::describe( array_fill( 0, 80, array( 'Café crème ☕', 3 ) ), 'Order 1' );
check( strlen( $long ) <= Payitgo_WC_Helpers::MAX_DESCRIPTION, 'a long description is cut to what payitgo takes' );
check( 1 === preg_match( '//u', $long ) && '…' === substr( $long, -strlen( '…' ) ), 'it is cut on a character, with an ellipsis' );

// ── Prices ───────────────────────────────────────────────────────────────────
check(
	array(
		'amount'   => '19.99',
		'currency' => 'USD',
	) === Payitgo_WC_Helpers::price( '19.99', 'usd' ),
	'a dollar shop sends its total as amount and currency'
);
check(
	array(
		'amount'   => '18.40',
		'currency' => 'EUR',
	) === Payitgo_WC_Helpers::price( '18.40', 'eur' ),
	'any other currency is sent the same way, as its code'
);
check( '1.250' === Payitgo_WC_Helpers::price( '1.250', 'KWD' )['amount'], 'three decimals are kept' );
check( '10' === Payitgo_WC_Helpers::price( 10.0, 'USD' )['amount'], 'a float total is sent as its decimal' );
check( '19.90' === Payitgo_WC_Helpers::price( ' 19.90 ', 'USD' )['amount'], 'spaces around a total are trimmed' );

$eur = array(
	'amount'     => '18.4',
	'currency'   => 'EUR',
	'amount_due' => '18.95',
);
$usd = array(
	'amount'   => '20',
	'currency' => 'USD',
);
check( Payitgo_WC_Helpers::amounts_match( $eur, '18.40', 'EUR' ), 'a payment in the order currency matches its total' );
check( ! Payitgo_WC_Helpers::amounts_match( $eur, '18.41', 'EUR' ), 'another total does not match' );
check( ! Payitgo_WC_Helpers::amounts_match( $eur, '18.40', 'GBP' ), 'another currency does not match' );
check( Payitgo_WC_Helpers::amounts_match( $usd, '20.00', 'USD' ), 'a dollar payment matches a dollar order' );
check( ! Payitgo_WC_Helpers::amounts_match( $usd, '20.00', 'EUR' ), 'a dollar payment does not pay a euro order' );
check( ! Payitgo_WC_Helpers::amounts_match( array( 'amount' => '20' ), '20.00', 'USD' ), 'a payment without a currency matches no order' );

check( '$20.00' === Payitgo_WC_Helpers::money( '20', 'USD' ), 'dollars read as $20.00' );
check( '$20.61' === Payitgo_WC_Helpers::money( '20.61', 'usd' ), 'the currency code may be in any case' );
check( '18.40 EUR' === Payitgo_WC_Helpers::money( '18.4', 'EUR' ), 'another currency reads as 18.40 EUR' );
check( '1.250 KWD' === Payitgo_WC_Helpers::money( '1.250', 'KWD' ), 'three places are kept' );
check( '0.01234 ETH' === Payitgo_WC_Helpers::money( '0.01234', 'ETH' ), "a price in a coin keeps the coin's places" );
check( '20.00' === Payitgo_WC_Helpers::money( 20, '' ), 'no currency: the amount alone' );

// ── Statuses ─────────────────────────────────────────────────────────────────
foreach ( array(
	'confirmed'      => Payitgo_WC_Helpers::COMPLETE,
	'detecting'      => Payitgo_WC_Helpers::HOLD,
	'underpaid'      => Payitgo_WC_Helpers::HOLD,
	'expired'        => Payitgo_WC_Helpers::FAIL,
	'failed'         => Payitgo_WC_Helpers::FAIL,
	'partially_paid' => Payitgo_WC_Helpers::NOTE,
	'pending'        => Payitgo_WC_Helpers::NONE,
	'something new'  => Payitgo_WC_Helpers::NONE,
) as $status => $want ) {
	check( Payitgo_WC_Helpers::action_for( $status ) === $want, "status $status: $want" );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "$failures of $checks checks failed\n" );
	exit( 1 );
}
echo "ok: $checks checks\n";
