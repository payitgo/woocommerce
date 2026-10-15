=== payitgo for WooCommerce ===
Contributors: payitgo
Tags: crypto, cryptocurrency, usdt, bitcoin, payments
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Accept USDT, USDC, BTC, ETH and other coins in your WooCommerce shop. Customers pay on payitgo's hosted checkout; orders update by themselves.

== Description ==

payitgo is a crypto payment gateway. With this plugin your customers choose "Crypto" at checkout, pay on payitgo's secure hosted page, and come back to your shop. The order is marked paid as soon as the payment is final on chain.

* **Every coin payitgo offers:** USDT on Tron, BNB Smart Chain and Ethereum; USDC on BNB Smart Chain and Ethereum; BTC, ETH, BNB, LTC, DOGE, TRX and XRP. You choose which ones in your payitgo dashboard.
* **Any shop currency:** prices in EUR, GBP, NGN or any other currency are converted to dollars at the rate of the moment; the customer pays the equivalent in crypto.
* **Orders update by themselves:** paid when the payment is final, on hold while it confirms or when it falls short, failed when it expires (the customer can pay again from their account).
* **Nothing to set up for notifications:** each payment tells your shop how it went, signed and verified. A shop not on https checks the payment when the customer comes back.
* **Test mode:** with a test key no coins are needed; the checkout lets you choose how the payment goes (paid, underpaid, expired…).
* **The block checkout and the classic checkout**, and WooCommerce's order tables (HPOS).

You need a payitgo account: sign up at [payitgo.co](https://payitgo.co).

== Installation ==

1. Upload the plugin (Plugins > Add New > Upload Plugin, with the zip) and activate it. WooCommerce must be active.
2. In your payitgo dashboard, copy a secret API key (Developers > API keys): a test key to try it out, a live key for real payments.
3. In WooCommerce > Settings > Payments > payitgo (crypto), paste the key, and enable the payment method.

That is all: the plugin asks payitgo whether the key is a test or a live key when you save the settings. To take real payments, turn live mode on in your payitgo dashboard and use your live key.

== Frequently Asked Questions ==

= When is an order marked paid? =

When payitgo says the payment is final: every coin waits for enough network confirmations. Until then the order is pending, or on hold once the money has arrived and is confirming.

= What if the customer pays too little? =

When the payment closes short, the order goes on hold with a note of what arrived. What arrived is yours; refund the customer or agree on the rest with them.

= What if the payment expires? =

The order fails, and the customer can pay it again from their account: a new payment is made.

= My shop is not in US dollars. =

That is fine: the order total is sent in your shop's currency and converted at the rate of the moment. The order note shows both amounts.

= Several shops share one payitgo account. =

Give each shop its own store in your payitgo dashboard (Stores), and paste each shop the key of its own store. Order ids are unique per store, so the shops' order numbers never meet. Shops that share one key would be given each other's open payment for the same order number: the plugin refuses such a payment and logs why.

= Can I refund from WooCommerce? =

Not yet: crypto refunds are sent by hand. Refund the customer from your wallet, then mark the order refunded.

== Changelog ==

= 0.3.0 =
* The payment's order id is the WooCommerce order number (`1234`), and the plugin finds the order from the payment's metadata.
* The plugin asks payitgo whether the key is a test or a live key when the settings are saved (an unknown key is reported), and learns it from the payments it makes.
* Several shops on one payitgo account: give each shop the key of its own store. The plugin refuses a payment another shop made, and logs why.

= 0.2.0 =
* First release: the hosted checkout from the classic and block checkout, signed notifications, prices in any currency, test mode, HPOS.
