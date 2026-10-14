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
2. Copy a secret API key from your payitgo dashboard (Developers > API keys):
   `sk_test_…` to try it out, `sk_live_…` for real payments.
3. In WooCommerce > Settings > Payments > payitgo (crypto), paste the key and enable it.

## How it works

- **Checkout:** the order becomes a payitgo payment (order id `WC-<order id>`, its items as
  the description, the billing email, and the shop's return and cancel pages), and the
  customer is sent to payitgo's checkout page for it.
- **Currencies:** a shop in US dollars sends `amount_usd`; any other shop sends
  `price_amount` and `price_currency`, which payitgo converts at the rate of the moment.
- **Notifications:** each payment is created with the shop's notification address,
  `https://<shop>/wc-api/payitgo/` (or `?wc-api=payitgo`), when the shop is on https. The
  plugin verifies the signature with the signing secret of the key's mode, read from
  payitgo with the key (or pinned in the settings), and ignores notifications it has
  seen, of another mode, or for payments that are not the order's.
- **The order follows the payment's status:**

  | Payment | Order |
  |---|---|
  | `confirmed` | Paid (`processing`, or `completed` for virtual goods), with the transaction id. On hold instead if the payment was for another amount than the order's total |
  | `detecting` | On hold: the money arrived, confirmations pending |
  | `partially_paid` | A note: the customer can still send the rest |
  | `underpaid` | On hold, with what arrived: refund or settle the rest |
  | `expired`, `failed` | Failed: the customer can pay again (a new payment) |

  A deposit in another coin, or one after the payment closed, adds a note.
- **On the way back:** the order-received page reads the payment, so a shop that
  notifications cannot reach (not on https) still learns the outcome.

## Settings

| Setting | |
|---|---|
| Secret API key | `sk_test_…` or `sk_live_…` |
| Order prefix | `WC-` by default. Shops sharing one payitgo account need different prefixes |
| Signing secret | Optional: the plugin reads it from payitgo. Pin one only if you must |
| API address | `https://api.payitgo.co` |
| Debug log | WooCommerce > Status > Logs, source `payitgo` (errors are always logged) |

## Develop

The plugin is plain PHP, with no build step. Its own decisions (signatures, order
references, descriptions, prices, statuses) are tested without WordPress:

```bash
php tests/run.php
```

The plugin is released with payitgo's other SDKs, from
`sdk/woocommerce` of payitgo's API repository; this repository is its public mirror.
