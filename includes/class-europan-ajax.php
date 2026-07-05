<?php
/**
 * Checkout-side AJAX: only the "Guthaben prüfen" (check balance) call happens here,
 * client-facing. The actual debit happens server-side on europan.direct (see
 * Europan_API_Client::settle()), triggered by woocommerce_payment_complete via
 * Europan_WC_Settlement — never here, never client-triggered.
 */

if (!defined('ABSPATH')) exit;

class Europan_WC_Ajax {

    public static function init() {
        add_action('wp_ajax_europan_wc_check_balance', array(__CLASS__, 'check_balance'));
        add_action('wp_ajax_nopriv_europan_wc_check_balance', array(__CLASS__, 'check_balance'));
    }

    public static function check_balance() {
        check_ajax_referer('europan_wc_nonce', 'nonce');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        // Sanitize first with a directly-recognized sanitize_*(wp_unslash($_POST[...]))
        // pattern, THEN strip to digits-only as a separate step on the already-
        // sanitized local variable — WordPress Plugin Check's static analysis only
        // recognizes the sanitizer when it directly wraps wp_unslash($_POST[...])
        // with nothing else nested in between, so interleaving preg_replace() into
        // that same expression (as a previous version of this line did) went
        // unrecognized even though the end result was equally safe.
        $raw_pin = isset($_POST['pin']) ? sanitize_text_field(wp_unslash($_POST['pin'])) : '';
        $pin     = preg_replace('/\D/', '', $raw_pin);

        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Bitte eine gültige E-Mail-Adresse angeben.'), 400);
        }

        $cart_total = 0.0;
        if (function_exists('WC') && WC()->cart) {
            $cart_total = (float) WC()->cart->get_total('edit');
        }

        // The balance check, the sufficiency decision, AND the verification token are
        // now all issued server-side by europan.direct in a single call — this plugin
        // no longer generates its own token or decides sufficiency locally. That keeps
        // the actual money-relevant logic (does the balance cover THIS amount) in the
        // one place that also performs the debit later (settle()), rather than
        // duplicating that check here and trusting it stays in sync.
        $result = Europan_API_Client::check_balance($email, $pin, $cart_total);

        if (!$result['ok']) {
            wp_send_json_error(array('message' => isset($result['error']) ? $result['error'] : 'Prüfung fehlgeschlagen.'), isset($result['status']) ? $result['status'] : 502);
        }

        // Store the verified email in the WC session purely for our own later
        // cross-check in validate_fields() (does the email at place-order time match
        // the one just verified) — the token itself is what europan.direct actually
        // authenticates against; this session value is a local sanity check, not a
        // security boundary.
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('europan_wc_verified_email', strtolower($email));
        }

        wp_send_json_success(array(
            'balance'    => $result['balance'],
            'cart_total' => $cart_total,
            'sufficient' => !empty($result['sufficient']),
            'shortfall'  => isset($result['shortfall']) ? $result['shortfall'] : 0,
            'token'      => $result['token'],
        ));
    }
}
