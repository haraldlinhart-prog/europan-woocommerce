<?php
/**
 * All money-moving logic lives here, triggered exclusively by WooCommerce's own
 * order-status hooks — never by anything the browser tells us directly. This is the
 * gateway-agnostic equivalent of settleEuropan() from the Stripe reference flow: same
 * "debit only after confirmed payment" rule, but hung off woocommerce_payment_complete
 * instead of a Stripe-specific webhook (works for ANY underlying WooCommerce gateway
 * — though in this plugin the "gateway" IS EUROPAN itself, so payment_complete fires
 * as soon as validate_fields() confirms the balance covers the order).
 */

if (!defined('ABSPATH')) exit;

class Europan_WC_Settlement {

    public static function init() {
        add_action('woocommerce_payment_complete', array(__CLASS__, 'handle_payment_complete'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'handle_cancelled_or_refunded'), 10, 1);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'handle_cancelled_or_refunded'), 10, 1);
        add_action('woocommerce_order_refunded', array(__CLASS__, 'handle_partial_refund'), 10, 2);
    }

    /**
     * Debit the customer's EUROPAN balance, then credit the partner (order amount
     * minus commission). Idempotent: guarded by an order meta flag so a duplicate
     * payment_complete fire (WooCommerce occasionally fires this more than once)
     * never double-debits the customer.
     */
    public static function handle_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'europan') {
            return;
        }
        if ($order->get_meta('_europan_wc_settled') === 'yes') {
            return; // already settled, do not touch balances twice
        }

        $email  = $order->get_meta('_europan_wc_customer_email');
        $amount = (float) $order->get_meta('_europan_wc_amount');
        if (empty($email) || $amount <= 0) {
            $order->add_order_note('EUROPAN-Abrechnung übersprungen: fehlende Kunden-E-Mail oder Betrag in den Order-Metadaten.');
            return;
        }

        $reference = 'WC-' . $order->get_order_number() . '-' . $order_id;

        $debit = Europan_API_Client::debit(
            $email,
            $amount,
            sprintf('Bestellung #%s', $order->get_order_number()),
            $reference
        );

        if (!$debit['ok']) {
            // Do NOT silently mark this as settled — flag for manual review instead of
            // pretending money moved when it didn't.
            $order->update_status('on-hold', sprintf('EUROPAN-Belastung fehlgeschlagen: %s. Manuelle Prüfung erforderlich, Bestellung NICHT automatisch abgeschlossen.', $debit['error']));
            $order->add_order_note('⚠️ EUROPAN-Belastung fehlgeschlagen: ' . $debit['error']);
            return;
        }

        $order->update_meta_data('_europan_wc_settled', 'yes');
        $order->update_meta_data('_europan_wc_new_balance', $debit['new_balance']);
        $order->add_order_note(sprintf('EUROPAN-Guthaben belastet: %s (Ref: %s). Neues Guthaben: %s.', wc_price($amount), $reference, $debit['new_balance']));

        // Partner-Gutschrift, Modell 2 (geschlossener Kreislauf, keine Euro-Auszahlung) —
        // Kommissionssatz kommt aus den Gateway-Settings des jeweiligen Shops.
        $gateway_settings = get_option('woocommerce_europan_settings', array());
        $partner_email    = !empty($gateway_settings['partner_email']) ? $gateway_settings['partner_email'] : '';
        $commission_pct   = isset($gateway_settings['commission_pct']) ? (float) $gateway_settings['commission_pct'] : (float) get_option('europan_wc_commission_pct', 3.0);

        if (empty($partner_email)) {
            $order->add_order_note('⚠️ Keine Partner-E-Mail in den EUROPAN-Zahlungseinstellungen hinterlegt — Partner-Gutschrift übersprungen. Bitte in WooCommerce → Zahlungen → EUROPAN eintragen.');
        } else {
            $credit = Europan_API_Client::credit_partner(
                $partner_email,
                $amount,
                $commission_pct,
                sprintf('Gutschrift Bestellung #%s (abzgl. %.1f%% Netzwerk-Kommission)', $order->get_order_number(), $commission_pct),
                $reference
            );
            if ($credit['ok']) {
                $order->update_meta_data('_europan_wc_partner_net', $credit['net_amount']);
                $order->add_order_note(sprintf('Partner-Gutschrift erteilt: %s EP (Brutto %s, Kommission %.1f%%).', $credit['net_amount'], wc_price($amount), $commission_pct));
            } else {
                $order->add_order_note('⚠️ Partner-Gutschrift fehlgeschlagen: ' . $credit['error'] . ' — Kunde wurde bereits belastet, manuelle Nachbuchung erforderlich.');
            }
        }

        $order->save();
    }

    /**
     * Full cancellation/refund of an already-settled EUROPAN order: credit the
     * customer's EUROPAN balance back. This is the piece flagged in the July 2026
     * conversation as "gibt es noch gar nicht" — implemented here as the direct
     * mirror of the debit, gated the same way (idempotent, only for orders this
     * plugin actually settled).
     *
     * NOTE: this intentionally does NOT try to claw back the partner credit
     * automatically — partner reconciliation for cancelled orders should go through
     * the existing partner-credit ledger/reporting, not a silent auto-reversal that
     * could leave a partner's balance negative without their knowledge. Flagged via
     * order note for manual/administrative reconciliation.
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
        $reference = 'WC-REFUND-' . $order->get_order_number() . '-' . $order_id;

        $refund = Europan_API_Client::credit_refund(
            $email,
            $amount,
            sprintf('Rückerstattung Bestellung #%s', $order->get_order_number()),
            $reference
        );

        if ($refund['ok']) {
            $order->update_meta_data('_europan_wc_refunded', 'yes');
            $order->add_order_note(sprintf('EUROPAN-Guthaben zurückerstattet: %s (Ref: %s). ⚠️ Partner-Gutschrift wurde NICHT automatisch zurückgebucht — bitte manuell mit dem Partner klären.', wc_price($amount), $reference));
        } else {
            $order->add_order_note('⚠️ EUROPAN-Rückerstattung fehlgeschlagen: ' . $refund['error'] . ' — manuelle Klärung mit noble-limited/EUROPAN erforderlich.');
        }
        $order->save();
    }

    /**
     * Partial refunds: WooCommerce's own refund amount (not the full order total) is
     * credited back. Same "no automatic partner clawback" caution as above.
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

        $reference = 'WC-PARTREFUND-' . $order->get_order_number() . '-' . $refund_id;

        $result = Europan_API_Client::credit_refund(
            $email,
            $amount,
            sprintf('Teilrückerstattung Bestellung #%s', $order->get_order_number()),
            $reference
        );

        if ($result['ok']) {
            $order->add_order_note(sprintf('EUROPAN-Teilrückerstattung: %s (Ref: %s). ⚠️ Partner-Gutschrift wurde NICHT automatisch angepasst — bitte manuell klären.', wc_price($amount), $reference));
        } else {
            $order->add_order_note('⚠️ EUROPAN-Teilrückerstattung fehlgeschlagen: ' . $result['error']);
        }
    }
}
