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
$secret = 'whsec_test';
$body   = '{"id":"evt_1","type":"payment.confirmed","livemode":false,"data":{"id":"pay_1","order_id":"WC-42","status":"confirmed"}}';
$t      = 1791619398;
$sig    = 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $body, $secret );

$event = Payitgo_WC_Helpers::verify( $body, $sig, $secret, $t + 10 );
check( 'payment.confirmed' === $event['type'] && 'pay_1' === $event['data']['id'], 'a signed notification is verified' );
check( 'evt_1' === Payitgo_WC_Helpers::verify( $body, ' ' . str_replace( ',', ' , ', $sig ), $secret, $t )['id'], 'spaces around the parts are allowed' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, $sig, 'whsec_other', $t ) ), 'another secret is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( str_replace( 'WC-42', 'WC-43', $body ), $sig, $secret, $t ) ), 'a changed body is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, $sig, $secret, $t + 301 ) ), 'an old signature is refused' );
check( 'evt_1' === Payitgo_WC_Helpers::verify( $body, $sig, $secret, $t + 9999, 0 )['id'], 'tolerance 0 takes any age' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, 'v1=abc', $secret, $t ) ), 'a header without t is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, '', $secret, $t ) ), 'no header is refused' );
check( refused( fn() => Payitgo_WC_Helpers::verify( $body, $sig, '', $t ) ), 'no secret is refused' );
$junk = 'not json';
check( refused( fn() => Payitgo_WC_Helpers::verify( $junk, 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $junk, $secret ), $secret, $t ) ), 'a signed body that is no event is refused' );

// ── Orders at payitgo ────────────────────────────────────────────────────────
check( 'WC-42' === Payitgo_WC_Helpers::order_ref( 'WC-', 42 ), 'an order is named with the prefix' );
check( 42 === Payitgo_WC_Helpers::order_id( 'WC-', 'WC-42' ), 'the order id is read back' );
check( 0 === Payitgo_WC_Helpers::order_id( 'WC-', 'SHOP2-42' ), "another shop's order is not ours" );
check( 0 === Payitgo_WC_Helpers::order_id( 'WC-', 'WC-42a' ), 'an order id that is not a number is not ours' );
check( 0 === Payitgo_WC_Helpers::order_id( 'WC-', 'WC-' ), 'a prefix alone is not an order' );
check( 7 === Payitgo_WC_Helpers::order_id( '', '7' ), 'no prefix works too' );

// ── Descriptions ─────────────────────────────────────────────────────────────
check( '2 x Acme T-shirt, Mug' === Payitgo_WC_Helpers::describe( array( array( 'Acme <b>T-shirt</b>', 2 ), array( 'Mug', 1 ) ), 'Order 1' ), 'items are listed with their quantities' );
check( 'Order 1' === Payitgo_WC_Helpers::describe( array(), 'Order 1' ), 'an order without items is named' );
$long = Payitgo_WC_Helpers::describe( array_fill( 0, 80, array( 'Café crème ☕', 3 ) ), 'Order 1' );
check( strlen( $long ) <= Payitgo_WC_Helpers::MAX_DESCRIPTION, 'a long description is cut to what payitgo takes' );
check( 1 === preg_match( '//u', $long ) && '…' === substr( $long, -strlen( '…' ) ), 'it is cut on a character, with an ellipsis' );

// ── Prices ───────────────────────────────────────────────────────────────────
check( array( 'amount_usd' => '19.99' ) === Payitgo_WC_Helpers::price( '19.99', 'usd' ), 'a dollar shop sends amount_usd' );
check(
	array(
		'price_amount'   => '18.40',
		'price_currency' => 'EUR',
	) === Payitgo_WC_Helpers::price( '18.40', 'eur' ),
	'another currency is sent as price_amount and price_currency'
);
check( '1.250' === Payitgo_WC_Helpers::price( '1.250', 'KWD' )['price_amount'], 'three decimals are kept' );
check( '10' === Payitgo_WC_Helpers::price( 10.0, 'USD' )['amount_usd'], 'a float total is sent as its decimal' );
check( '19.90' === Payitgo_WC_Helpers::price( ' 19.90 ', 'USD' )['amount_usd'], 'spaces around a total are trimmed' );

$eur = array(
	'usd_amount'     => '20.61',
	'price_amount'   => '18.4',
	'price_currency' => 'EUR',
);
check( Payitgo_WC_Helpers::amounts_match( $eur, '18.40', 'EUR' ), 'a payment in the order currency matches its total' );
check( ! Payitgo_WC_Helpers::amounts_match( $eur, '18.41', 'EUR' ), 'another total does not match' );
check( ! Payitgo_WC_Helpers::amounts_match( $eur, '18.40', 'GBP' ), 'another currency does not match' );
check( Payitgo_WC_Helpers::amounts_match( array( 'usd_amount' => '20' ), '20.00', 'USD' ), 'a dollar payment matches a dollar order' );
check( ! Payitgo_WC_Helpers::amounts_match( array( 'usd_amount' => '20' ), '20.00', 'EUR' ), 'a dollar payment does not pay a euro order' );

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
