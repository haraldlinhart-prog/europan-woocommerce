=== EUROPAN for WooCommerce ===
Contributors: europan
Tags: woocommerce, payment gateway, prepaid, ecommerce, currency
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept EUROPAN prepaid balance as a standalone WooCommerce payment method, with an optional shop-configurable customer bonus.

== Description ==

This plugin adds **EUROPAN** — the network currency operated by Noble Private
Capital Ltd (europan.group) — as its own payment method inside WooCommerce.

**How it works for the customer:**

1. The customer selects "Pay with EUROPAN" at checkout.
2. They enter the email address and PIN from their EUROPAN account.
3. The plugin verifies the balance in real time via europan.direct (which itself
   talks to the EUROPAN network) — this plugin never contacts EUROPAN's core
   infrastructure directly, only europan.direct, using the shop's own partner
   API key.
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
* Your shop is credited the full (already, if applicable, discounted) order
  amount — this plugin never deducts anything on EUROPAN's behalf. EUROPAN's
  own service fee is invoiced to you separately and manually; it has nothing
  to do with this plugin's settings.
* Order notes record every charge and any failures that require manual
  follow-up.
* Refunds and cancellations automatically credit the customer's EUROPAN
  balance back (the shop's own credit is intentionally NOT auto-reversed —
  this is flagged for manual reconciliation instead, to avoid silently
  leaving a partner's balance negative).
* Works with both the classic (shortcode) checkout and the block-based
  Cart & Checkout.

This plugin requires an active EUROPAN partner account and API key
(europan.direct) to function — it is not a general-purpose currency plugin.

== Installation ==

1. Install and activate the plugin (requires WooCommerce to be active).
2. Register for a EUROPAN partner account and API key at
   [europan.direct/partners.html](https://www.europan.direct/partners.html) —
   the Free tier issues an active API key immediately, no waiting.
3. Go to WooCommerce → Settings → Payments → EUROPAN.
4. Enable the gateway and enter your API key. That's the only account-specific
   setting — your partner email is resolved automatically from your account,
   and your shop is always credited the full order amount (EUROPAN's own
   service fee, if any, is billed to you separately and manually — it plays
   no role in this plugin).
5. Optionally configure the customer bonus (percentage or fixed amount).
6. Save. EUROPAN now appears as a payment option at checkout.

== Frequently Asked Questions ==

= Can a customer pay part of the order with EUROPAN and the rest with another method? =

No. This gateway always requires the entire order total to be covered by the
customer's EUROPAN balance. There is no partial-payment / split-payment option.

= What happens if the customer's balance doesn't cover the order? =

The order cannot be placed until either the balance is sufficient or a
different payment method is chosen.

= Does the shop owner need a EUROPAN account? =

Yes — a EUROPAN partner account and API key from europan.direct are required
to receive credits for completed orders.

= Is the bonus optional? =

Yes, it is fully optional and disabled by default. Shop owners choose whether
to offer it at all, and whether it's a percentage or a fixed amount.

== Privacy ==

When a customer checks their EUROPAN balance or completes a payment, this
plugin sends the following data to europan.direct (operated by PAN21.COM
Corporate Consultants Ltd on behalf of Noble Private Capital Ltd), using the
shop's own partner API key:

* The email address and PIN entered by the customer (used only to verify the
  balance — never stored by this plugin beyond the current checkout session).
* The order amount and a reference derived from the WooCommerce order number.

No other customer or site data is transmitted, and the plugin itself never
contacts EUROPAN's core account infrastructure directly — only europan.direct,
which is also where the shop owner registers once, manually, for a partner
account and API key.

== Screenshots ==

1. EUROPAN payment method at checkout, with balance verification and optional
   bonus notice.

== Changelog ==

= 0.6.1 =
* Corrected a mistake introduced in 0.6.0: the shop is now credited the FULL
  order amount, with no automatic deduction whatsoever. An earlier version of
  the new europan.direct-based settlement briefly deducted a per-partner
  commission automatically — that was wrong. Any discount the customer
  receives is entirely the shop's own choice (the plugin's own configurable
  bonus feature); EUROPAN's own service fee is invoiced to the shop manually
  and separately, and has no representation anywhere in this plugin's code,
  settings, or the europan.direct database.

= 0.6.0 =
* SECURITY & ARCHITECTURE: this plugin no longer talks to EUROPAN's core account
  infrastructure directly under any circumstances. It previously asked shop
  owners to enter "their own API key" for direct balance-check/debit/credit
  calls — but that infrastructure only ever recognized one shared internal key
  used across the whole EUROPAN network, never per-partner keys. Distributing
  that key to third parties would have allowed debiting any customer's balance
  without their PIN. The plugin now exclusively calls europan.direct using the
  shop's own harmless, scoped partner key; europan.direct performs the actual
  balance/debit/credit operations server-side.
* Removed the "Partner-E-Mail" and "Netzwerk-Kommission" settings entirely —
  both are now resolved automatically from the shop's partner account on
  europan.direct and were never something the shop should have been able to
  self-declare (the commission in particular directly determines the shop's
  own payout, so shop-editable was a real integrity gap).
* Simplified checkout flow: balance verification and the actual charge each
  happen in a single call now (previously multiple separate steps across two
  files), with the charge itself completing synchronously before an order is
  ever marked as placed, rather than being finalized later via a hook.

= 0.5.4 =
* Fixed several incorrect domain references: partner registration and API
  keys come from europan.direct, not europan.group (europan.group is only
  where end customers buy EUROPAN vouchers — that reference was correct and
  is unchanged). Also corrected the description of which API the plugin
  actually talks to at runtime (noble-limited.com), and removed a privacy
  policy link that pointed to a page which doesn't exist.
* Installation instructions now link directly to the live, self-service
  registration page (europan.direct/partners.html).

= 0.5.3 =
* Fixed a Plugin Check nonce-verification warning by moving the phpcs:ignore
  annotation onto the exact flagged line (it previously sat on a separate
  comment line above, where it had no effect).
* Removed every suggested/default commission percentage from the plugin
  (activation-time default, settings field default, "recommended range"
  text, input max cap). The network commission is a per-partner business
  agreement, not something this plugin should hint at or assume — shop
  owners enter exactly the rate agreed with PAN21/EUROPAN, and an unset
  rate is treated as 0%, never a guessed number.

= 0.5.2 =
* Clarified that payouts to shop owners are currently handled manually
  (periodic bank transfer, no automated payout run) and that bank details
  for this must be provided during partner registration on europan.direct —
  not in this plugin's own settings, which never transmits bank data
  anywhere. Also recommends using a dedicated partner email address (never
  used for personal EUROPAN spending) so the earned commission balance stays
  distinguishable from any personal balance on the same account.

= 0.5.1 =
* Documentation correction only, no functional change: removed outdated
  "closed-loop, no cash payout" claims from code comments, settings text and
  this readme. The intended model for partner settlement is now a real Euro
  bank payout (funded from EUROPAN's voucher pre-sale float), but this is not
  yet implemented on the backend — the plugin still issues an EP credit to
  the shop's own EUROPAN balance, exactly as before. This version only makes
  the documentation honest about that gap; nothing about the actual payout
  behaviour changed.

= 0.5.0 =
* The EUROPAN API key is now entered directly in the gateway's own settings
  screen (WooCommerce → Settings → Payments → EUROPAN), like every other
  payment gateway plugin — previously it required editing wp-config.php,
  which isn't practical for a self-service WordPress.org plugin. A
  wp-config.php constant is still supported as an optional override for
  advanced/managed deployments, but is no longer required.

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

= 0.6.0 =
Important security/architecture update — please re-enter your API key after
updating (get it from europan.direct/partners.html if you don't have one yet).
The "Partner-E-Mail" and "Netzwerk-Kommission" settings are gone; nothing to
reconfigure there.

= 0.5.0 =
API key is now entered in the plugin's own settings screen instead of
wp-config.php — please re-enter your key under WooCommerce → Settings →
Payments → EUROPAN after updating.

= 0.4.2 =
Plugin renamed to "EUROPAN for WooCommerce" to comply with WordPress.org
naming rules — no functional changes.

= 0.4.1 =
Cosmetic update: proper logo and EUROPAN currency symbol in the checkout panel.

= 0.4.0 =
Bonus mechanic changed from post-payment credit to an upfront order discount —
shop owners using the bonus feature should re-check their checkout after
updating.
