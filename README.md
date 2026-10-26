# payitgo for WooCommerce

Accept crypto payments (USDT, USDC, BTC, ETH and more) in a WooCommerce shop with
[payitgo](https://payitgo.co). Customers pay on payitgo's hosted checkout; orders are
marked paid once the payment is final on chain.

Requires WordPress 6.3+, WooCommerce 8.0+ and PHP 7.4+. Works with the block checkout
and the classic checkout, and with WooCommerce's order tables (HPOS).

## Install

1. Download `payitgo-for-woocommerce-X.Y.Z.zip` from the
   [latest release](https://github.com/payitgo/woocommerce/releases/latest), and upload it
   in WordPress (Plugins > Add New > Upload Plugin). Activate it.
2. Copy a secret API key from your payitgo dashboard (Developers > API keys), one with full
   access (a read-only key cannot create payments): a test key to try it out, a live key for
   real payments (it works once you have confirmed your email and turned live mode on in the
   dashboard).
3. In WooCommerce > Settings > Payments > payitgo (crypto), paste the key and enable it.
   On save, the plugin asks payitgo whether the key is a test or a live key (an
   unknown key is reported: copy it again from the dashboard).

## How it works

- **Checkout:** the order becomes a payitgo payment (order id: the WooCommerce order
  number as it is, `1234`; its items as the description, the billing email, and the shop's
  return and cancel pages), and the customer is sent to payitgo's checkout page for it.
  The payment's `metadata` has `wc_order_id`, `wc_order_number` and `shop`: the plugin
  finds the order from `wc_order_id`.
- **Currencies:** the order total is sent as the payment's `amount` in the shop's
  `currency` (USD, EUR, GBP, NGN or any other). payitgo converts it at the rate of the
  moment, and every figure on the payment (`amount_due`, `amount_received`) is in that
  currency, so the order's notes read in the shop's own.
- **Notifications:** each payment is created with the shop's notification address,
  `https://yourdomain.com/wc-api/payitgo/` (or `?wc-api=payitgo`), when the shop is on https. The
  plugin verifies the signature with the signing secret of the key's mode, read from
  payitgo with the key (or pinned in the settings), and ignores notifications it has
  seen, of another mode, or for payments that are not the order's.
- **The order follows the payment's status:**

  | Payment | Order |
  |---|---|
  | `confirmed` | Paid (`processing`, or `completed` for virtual, downloadable products), with the transaction id. On hold instead if the payment was for another amount than the order's total |
  | `detecting` | On hold: the money arrived, confirmations pending |
  | `partially_paid` | A note: the customer can still send the rest |
  | `underpaid` | On hold, with what arrived: refund or settle the rest |
  | `expired`, `failed` | Failed: the customer can pay again (a new payment) |

  A deposit in another coin, or one after the payment closed, adds a note.
- **On the way back:** the order-received page reads the payment, so a shop that
  notifications cannot reach (not on https, or at an address that is not public, such as
  `https://shop.local`) still learns the outcome.

## Settings

| Setting | |
|---|---|
| Secret API key | A test key to try it out, a live key for real payments. The plugin learns which from payitgo |
| Signing secret | Optional: the plugin reads it from payitgo. Pin one only if you must |
| API address | payitgo's API, `https://api.payitgo.co` by default. Leave it as it is |
| Debug log | WooCommerce > Status > Logs, source `payitgo` (errors are always logged) |

## Several shops on one account

Order ids are unique per store at payitgo. Create a store for each shop in the payitgo
dashboard (Stores), and give each shop the key of its own store: their order numbers
never meet. Shops that share one key would be given each other's open payment for the
same order number; the plugin refuses such a payment and logs why.

## Develop

The plugin is plain PHP, with no build step. Its own decisions (signatures, order
references, descriptions, prices, statuses) are tested without WordPress:

```bash
php tests/run.php
```

The plugin is released with payitgo's other SDKs, from
`sdk/woocommerce` of payitgo's API repository; this repository is its public mirror.
