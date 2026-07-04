<?php
/**
 * Checkout-side AJAX: only the "Guthaben prüfen" (check balance) call happens here,
 * client-facing. The actual debit happens server-side in Europan_WC_Settlement,
 * triggered by woocommerce_payment_complete — never here, never client-triggered.
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
        $pin   = isset($_POST['pin']) ? preg_replace('/\D/', '', wp_unslash($_POST['pin'])) : '';

        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Bitte eine gültige E-Mail-Adresse angeben.'), 400);
        }

        $result = Europan_API_Client::check_balance($email, $pin);

        if (!$result['ok']) {
            wp_send_json_error(array('message' => $result['error']), $result['status']);
        }

        $cart_total = 0.0;
        if (function_exists('WC') && WC()->cart) {
            $cart_total = (float) WC()->cart->get_total('edit');
        }

        // Alles-oder-nichts: das Guthaben muss den KOMPLETTEN Betrag decken, kein Teileinsatz.
        $sufficient = $result['balance'] >= $cart_total;

        // Server-side "verified" token: short-lived proof that THIS session verified
        // THIS email+PIN combination, so the later place_order step doesn't have to
        // ask for the PIN again but also never trusts an unverified client claim.
        $token = self::issue_verification_token($email, $result['balance']);

        wp_send_json_success(array(
            'balance'    => $result['balance'],
            'cart_total' => $cart_total,
            'sufficient' => $sufficient,
            'shortfall'  => $sufficient ? 0 : round($cart_total - $result['balance'], 2),
            'token'      => $token,
        ));
    }

    /**
     * Short-lived (15 min) server-side session proof that email+PIN were verified.
     * Stored in the WC session (not a client-editable field) so the gateway's
     * process_payment() can re-check it without re-asking for the PIN, and without
     * ever trusting a bare "verified: true" flag sent from the browser.
     */
    private static function issue_verification_token($email, $balance) {
        $token = wp_generate_password(32, false);
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('europan_wc_verified_email', strtolower($email));
            WC()->session->set('europan_wc_verified_balance', $balance);
            WC()->session->set('europan_wc_verified_token', $token);
            WC()->session->set('europan_wc_verified_at', time());
        }
        return $token;
    }
}
