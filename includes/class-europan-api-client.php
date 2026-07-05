<?php
/**
 * Thin wrapper around europan.direct's WooCommerce-settlement API
 * (api/woocommerce/check-balance, settle, refund).
 *
 * IMPORTANT ARCHITECTURE NOTE (July 2026): this plugin used to talk directly to
 * noble-limited.com using an "API key" entered in the gateway settings. That was a
 * serious security flaw: noble-limited.com's customer-money endpoints
 * (/api/v1/balance-by-email, /api/v1/debit, /api/v1/credit) accept ONLY the one
 * shared NOBLE_INTERNAL_API_KEY used internally across the whole PAN21 network —
 * there is no per-partner API key concept there. Handing that internal key to any
 * third-party shop would let it debit ANY EUROPAN customer's balance without a PIN
 * (noble-limited's own /api/v1/debit only checks the API key, not the PIN).
 *
 * This plugin therefore NEVER talks to noble-limited.com directly. It authenticates
 * to europan.direct with its own harmless, per-partner epd_live_ key (issued at
 * registration on europan.direct/partners.html), and europan.direct — which holds
 * the real internal key server-side — proxies the actual balance/debit/credit calls
 * after its own checks (see europan-direct repo, api/woocommerce/*.js).
 *
 * One practical consequence: the commission percentage is no longer sent by this
 * plugin at all. It's looked up server-side on europan.direct from a field the shop
 * itself can never see or edit — see the settle() method below.
 */

if (!defined('ABSPATH')) exit;

class Europan_API_Client {

    const BASE_URL = 'https://www.europan.direct';

    /** @return string|null */
    private static function api_key() {
        $gateway_settings = get_option('woocommerce_europan_settings', array());
        $key = !empty($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';

        // Filterable so a secrets manager or site-specific override can still
        // supply/replace it, e.g. for staging environments.
        return apply_filters('europan_wc_partner_api_key', $key);
    }

    private static function post($path, $payload) {
        $key = self::api_key();
        if (empty($key)) {
            return array('ok' => false, 'error' => 'EUROPAN-Zahlung ist auf dieser Seite nicht konfiguriert (fehlender API-Key).', 'status' => 500);
        }

        $response = wp_remote_post(self::BASE_URL . $path, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => 'EUROPAN-Dienst nicht erreichbar. Bitte später erneut versuchen.', 'status' => 503);
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body)) {
            return array('ok' => false, 'error' => 'Unerwartete Antwort vom EUROPAN-Dienst.', 'status' => $status ?: 502);
        }
        if ($status < 200 || $status >= 300) {
            return array_merge(array('ok' => false, 'status' => $status), $body);
        }

        return array_merge(array('ok' => true, 'status' => $status), $body);
    }

    /**
     * Verify email+PIN and get the authoritative EUROPAN balance, plus a short-lived
     * verification token that settle() needs to actually charge the order. Always a
     * server-side call — never trust a client-supplied balance.
     *
     * @return array { ok: bool, balance?: float, sufficient?: bool, shortfall?: float,
     *                 token?: string, error?: string }
     */
    public static function check_balance($email, $pin, $order_amount) {
        if (empty($email) || empty($pin) || !preg_match('/^\d{4}$/', $pin)) {
            return array('ok' => false, 'error' => 'E-Mail und 4-stellige PIN erforderlich.');
        }

        return self::post('/api/woocommerce/check-balance', array(
            'customer_email' => strtolower($email),
            'customer_pin'   => $pin,
            'order_amount'   => (float) $order_amount,
        ));
    }

    /**
     * Charge the order: debits the customer the full amount and credits the shop's
     * own EUROPAN account the SAME full amount — no deduction. Any discount the
     * customer received (the shop's own configurable "EUROPAN-Bonus" — see
     * Europan_WC_Settlement::apply_checkout_bonus_fee()) is the shop's own discount,
     * already reflected in $order_amount by the time it gets here. EUROPAN's own
     * service fee (currently ~4.8%, published on europan.direct and kept up to date
     * by hand) is billed to the shop manually and separately — it is never computed,
     * sent, or applied anywhere in this plugin. Requires a fresh token from
     * check_balance(); one-time use, consumed regardless of outcome.
     *
     * @return array { ok: bool, customer_debited?: bool, partner_credited?: bool,
     *                 partner_credited_amount?: float, warning?: string, error?: string,
     *                 already_processed?: bool }
     */
    public static function settle($token, $email, $order_amount, $order_reference) {
        return self::post('/api/woocommerce/settle', array(
            'token'           => $token,
            'customer_email'  => strtolower($email),
            'order_amount'    => (float) $order_amount,
            'order_reference' => $order_reference,
        ));
    }

    /**
     * Credit the customer's EUROPAN balance back after a WooCommerce refund or
     * cancellation. Does NOT attempt to claw back the partner's credit automatically
     * (same reasoning as always: avoid silently leaving a partner's balance negative
     * without their knowledge) — that stays a manual reconciliation step.
     *
     * @return array { ok: bool, amount_credited?: float, error?: string }
     */
    public static function refund($email, $amount, $order_reference, $partial = false) {
        return self::post('/api/woocommerce/refund', array(
            'customer_email'  => strtolower($email),
            'amount'          => (float) $amount,
            'order_reference' => $order_reference,
            'partial'         => (bool) $partial,
        ));
    }
}
