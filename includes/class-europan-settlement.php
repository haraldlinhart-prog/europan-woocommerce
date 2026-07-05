<?php
/**
 * Cart-fee logic (the checkout bonus) plus refund handling for EUROPAN orders.
 *
 * NOTE (July 2026 architecture change): the actual charge — debiting the customer and
 * crediting the shop — no longer happens here via a woocommerce_payment_complete hook.
 * It happens SYNCHRONOUSLY inside WC_Gateway_Europan::process_payment(), which calls
 * Europan_API_Client::settle() and gets an immediate success/failure answer before the
 * order is ever marked as paid. That's both simpler and safer than the previous
 * "mark on-hold, debit later via a hook" design, and it's also where the commission
 * percentage now gets resolved — server-side on europan.direct, never sent by this
 * plugin. See class-wc-gateway-europan.php for that flow.
 *
 * What's left here is genuinely independent of that: the pre-payment cart discount
 * (a pure WooCommerce total calculation, no API calls involved) and refund handling
 * (a separate, asymmetric operation that only ever credits the customer back).
 */

if (!defined('ABSPATH')) exit;

class Europan_WC_Settlement {

    public static function init() {
        add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'apply_checkout_bonus_fee'), 20, 1);
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'handle_cancelled_or_refunded'), 10, 1);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'handle_cancelled_or_refunded'), 10, 1);
        add_action('woocommerce_order_refunded', array(__CLASS__, 'handle_partial_refund'), 10, 2);
    }

    /**
     * Applies the shop-configured EUROPAN bonus as a NEGATIVE cart fee (i.e. a
     * discount line) whenever EUROPAN is the currently chosen payment method —
     * for BOTH the classic shortcode checkout and the block-based Cart/Checkout,
     * since both ultimately compute totals through the same WC_Cart::calculate_fees()
     * pipeline and both render fee lines (positive or negative) in their totals
     * table automatically.
     *
     * KNOWN LIMITATION: the classic checkout updates WC()->session's
     * 'chosen_payment_method' live via its own update_order_review AJAX call as
     * soon as a payment method radio is selected, so the discount appears
     * immediately. The block-based checkout does not guarantee the same live sync
     * of the selected method into the session before the order is actually placed —
     * so the discount is GUARANTEED correct at the moment the order is placed
     * (that's the authoritative calculation this whole plugin relies on), but the
     * live preview total shown while just browsing the block checkout may lag by
     * one interaction.
     */
    public static function apply_checkout_bonus_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (empty($cart) || !WC()->session) {
            return;
        }
        if (WC()->session->get('chosen_payment_method') !== 'europan') {
            return;
        }

        $gateway_settings = get_option('woocommerce_europan_settings', array());
        if (empty($gateway_settings['bonus_enabled']) || $gateway_settings['bonus_enabled'] !== 'yes') {
            return;
        }

        $bonus_type  = !empty($gateway_settings['bonus_type']) ? $gateway_settings['bonus_type'] : 'percent';
        $bonus_value = isset($gateway_settings['bonus_value']) ? (float) $gateway_settings['bonus_value'] : 0;
        if ($bonus_value <= 0) {
            return;
        }

        // Base the bonus on line-item + shipping totals BEFORE this fee, never on a
        // running total that might already include other fees — avoids compounding
        // if another plugin also adds cart fees.
        $base = (float) $cart->get_cart_contents_total() + (float) $cart->get_shipping_total();
        if ($base <= 0) {
            return;
        }

        $bonus_amount = ($bonus_type === 'fixed')
            ? round($bonus_value, 2)
            : round($base * ($bonus_value / 100), 2);

        if ($bonus_amount <= 0) {
            return;
        }
        // Never let the discount exceed the base it's calculated from.
        if ($bonus_amount > $base) {
            $bonus_amount = $base;
        }

        // Deliberately NOT using wc_price() in the label: WooCommerce's cart/checkout
        // totals templates escape the fee name with esc_html(), so any HTML markup
        // from wc_price() would show up as literal tags instead of a formatted price.
        $label = ($bonus_type === 'fixed')
            ? 'EUROPAN-Bonus'
            : sprintf('EUROPAN-Bonus (%s%%)', rtrim(rtrim(number_format($bonus_value, 1, ',', '.'), '0'), ','));

        $cart->add_fee($label, -$bonus_amount, false);
    }

    /**
     * Full cancellation/refund of an already-settled EUROPAN order: credit the
     * customer's EUROPAN balance back via europan.direct's refund proxy.
     *
     * NOTE: this intentionally does NOT try to claw back the partner credit
     * automatically — partner reconciliation for cancelled orders should go through
     * manual review, not a silent auto-reversal that could leave a partner's balance
     * negative without their knowledge. Flagged via order note for manual follow-up.
     */
    public static function handle_cancelled_or_refunded($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'europan') {
            return;
        }
        if ($order->get_meta('_europan_wc_settled') !== 'yes') {
            return; // never debited, nothing to refund
        }
        if ($order->get_meta('_europan_wc_refunded') === 'yes') {
            return; // already reversed
        }

        $email  = $order->get_meta('_europan_wc_customer_email');
        $amount = (float) $order->get_meta('_europan_wc_amount');
        $reference = 'WC-' . $order->get_order_number() . '-' . $order_id; // same reference the original settle() used

        $refund = Europan_API_Client::refund($email, $amount, $reference, false);

        if (!empty($refund['ok'])) {
            $order->update_meta_data('_europan_wc_refunded', 'yes');
            $order->add_order_note(sprintf('EUROPAN-Guthaben zurückerstattet: %s (Ref: %s). ⚠️ Partner-Gutschrift wurde NICHT automatisch zurückgebucht — bitte manuell mit dem Partner klären.', wc_price($amount), $reference));
        } else {
            $order->add_order_note('⚠️ EUROPAN-Rückerstattung fehlgeschlagen: ' . (isset($refund['error']) ? $refund['error'] : 'Unbekannter Fehler.') . ' — manuelle Klärung erforderlich.');
        }
        $order->save();
    }

    /**
     * Partial refunds: WooCommerce's own refund amount (not the full order total) is
     * credited back. Same "no automatic partner clawback" caution as above. The
     * refund ID is appended to the reference so multiple partial refunds on the same
     * order each get their own idempotency key on europan.direct.
     */
    public static function handle_partial_refund($order_id, $refund_id) {
        $order  = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund || $order->get_payment_method() !== 'europan') {
            return;
        }
        if ($order->get_meta('_europan_wc_settled') !== 'yes') {
            return;
        }

        $email  = $order->get_meta('_europan_wc_customer_email');
        // WooCommerce stores refund totals as negative numbers on the refund object.
        $amount = abs((float) $refund->get_total());
        if ($amount <= 0) {
            return;
        }

        $reference = 'WC-' . $order->get_order_number() . '-' . $order_id . '-refund-' . $refund_id;

        $result = Europan_API_Client::refund($email, $amount, $reference, true);

        if (!empty($result['ok'])) {
            $order->add_order_note(sprintf('EUROPAN-Teilrückerstattung: %s (Ref: %s). ⚠️ Partner-Gutschrift wurde NICHT automatisch angepasst — bitte manuell klären.', wc_price($amount), $reference));
        } else {
            $order->add_order_note('⚠️ EUROPAN-Teilrückerstattung fehlgeschlagen: ' . (isset($result['error']) ? $result['error'] : 'Unbekannter Fehler.'));
        }
    }
}
