=== EUROPAN for WooCommerce ===
Contributors: europan
Tags: woocommerce, payment gateway, prepaid, ecommerce, currency
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept EUROPAN prepaid balance as a standalone WooCommerce payment method, with an optional shop-configurable customer bonus.

== Description ==

This plugin adds **EUROPAN** — the closed-loop network currency operated by
Noble Private Capital Ltd (europan.group) — as its own payment method inside
WooCommerce.

**How it works for the customer:**

1. The customer selects "Pay with EUROPAN" at checkout.
2. They enter the email address and PIN from their EUROPAN account.
3. The plugin verifies the balance against europan.group in real time.
4. If the balance covers the full order amount, the order can be placed — this
   payment method always requires the **full** amount to be covered by EUROPAN
   balance; partial payment combined with another method is not supported.

**Optional customer bonus:**

Shop owners can configure an additional incentive for paying with EUROPAN,
either as a percentage of the order value or as a fixed amount. When enabled,
it appears as its own discount line directly in the cart/checkout totals as
soon as EUROPAN is selected as the payment method — no separate bonus credit
step is needed, the customer simply owes less.

**For the shop owner:**

* Full-amount debit only, verified via email + PIN — no partial charges.
* Configurable network commission withheld before the shop's own EUROPAN
  balance is credited (closed-loop model, no cash payout).
* Order notes record every balance debit, partner credit, and any failures
  that require manual follow-up.
* Refunds and cancellations automatically credit the customer's EUROPAN
  balance back (partner credit is intentionally NOT auto-reversed — this is
  flagged for manual reconciliation instead, to avoid silently leaving a
  partner's balance negative).
* Works with both the classic (shortcode) checkout and the block-based
  Cart & Checkout.

This plugin requires an active EUROPAN partner account and API key
(europan.group) to function — it is not a general-purpose currency plugin.

== Installation ==

1. Install and activate the plugin (requires WooCommerce to be active).
2. Go to WooCommerce → Settings → Payments → EUROPAN.
3. Enable the gateway, enter your EUROPAN partner account email and API key.
4. Optionally configure the customer bonus (percentage or fixed amount).
5. Save. EUROPAN now appears as a payment option at checkout.

== Frequently Asked Questions ==

= Can a customer pay part of the order with EUROPAN and the rest with another method? =

No. This gateway always requires the entire order total to be covered by the
customer's EUROPAN balance. There is no partial-payment / split-payment option.

= What happens if the customer's balance doesn't cover the order? =

The order cannot be placed until either the balance is sufficient or a
different payment method is chosen.

= Does the shop owner need a EUROPAN account? =

Yes — a EUROPAN partner account and API key from europan.group are required to
receive credits for completed orders.

= Is the bonus optional? =

Yes, it is fully optional and disabled by default. Shop owners choose whether
to offer it at all, and whether it's a percentage or a fixed amount.

== Privacy ==

When a customer checks their EUROPAN balance or completes a payment, this
plugin sends the following data to the europan.group / noble-limited.com API
(operated by Noble Private Capital Ltd):

* The email address and PIN entered by the customer (used only to verify the
  balance — never stored by this plugin beyond the current checkout session).
* The order amount and a reference derived from the WooCommerce order number.

No other customer or site data is transmitted. Privacy policy of the receiving
service: https://europan.group/datenschutz

== Screenshots ==

1. EUROPAN payment method at checkout, with balance verification and optional
   bonus notice.

== Changelog ==

= 0.4.2 =
* Renamed the plugin to "EUROPAN for WooCommerce" (English "for") — the
  previous German "für" isn't recognized by the WordPress.org Plugin
  Directory's trademarked-term check for third-party WooCommerce
  extensions, even though the meaning is identical.
* Removed a leftover hidden file (.gitkeep) from the distributed plugin.
* Fixed two Plugin Check warnings around input sanitization/nonce
  verification (functionally no change — the input was already safe,
  the code just needed to match the patterns the automated scanner
  recognizes).
* Updated "Tested up to" to the current WordPress version.

= 0.4.1 =
* Added the official EUROPAN logo as the payment method icon.
* EUROPAN-denominated amounts in the checkout panel now use the EUROPAN
  currency symbol )( instead of € to distinguish them from the order's actual
  Euro total.

= 0.4.0 =
* Changed the customer bonus from a post-payment balance credit to an
  immediate discount on the order total, so it appears directly (with both
  percentage and amount) in the cart/checkout totals table.

= 0.3.1 =
* Fixed the bonus notice not appearing in the block-based Cart & Checkout
  (it previously only rendered in the classic shortcode checkout).

= 0.3.0 =
* Added an optional, shop-configurable customer bonus for EUROPAN payments.

= 0.2.1 =
* Initial public-facing release: EUROPAN as a standalone WooCommerce payment
  gateway, full-amount-only, with partner commission and refund handling.

== Upgrade Notice ==

= 0.4.2 =
Plugin renamed to "EUROPAN for WooCommerce" to comply with WordPress.org
naming rules — no functional changes.

= 0.4.1 =
Cosmetic update: proper logo and EUROPAN currency symbol in the checkout panel.

= 0.4.0 =
Bonus mechanic changed from post-payment credit to an upfront order discount —
shop owners using the bonus feature should re-check their checkout after
updating.
