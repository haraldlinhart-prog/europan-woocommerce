<?php
/**
 * Thin wrapper around the noble-limited.com REST API — the same endpoints already
 * used by the canonical EUROPAN widget (react/BalanceWidget.tsx, server-routes/*.ts).
 * Kept deliberately dumb: no business logic here, just HTTP + error normalization.
 *
 * IMPORTANT: always call the www. host. The apex domain 308-redirects and the redirect
 * strips the Authorization header, which makes the API look "unreachable" for no
 * obvious reason (see europan-widget/README.md).
 */

if (!defined('ABSPATH')) exit;

class Europan_API_Client {

    const BASE_URL = 'https://www.noble-limited.com';

    /** @return string|null */
    private static function api_key() {
        // Filterable so a site-specific wp-config constant or secrets manager can supply it
        // without ever putting the key in the WP options table.
        $key = defined('EUROPAN_NOBLE_API_KEY') ? EUROPAN_NOBLE_API_KEY : '';
        return apply_filters('europan_wc_noble_api_key', $key);
    }

    /**
     * Verify email+PIN and return the authoritative EUROPAN balance.
     * Never trust a client-supplied balance — this is always a server-side call.
     *
     * @return array { ok: bool, balance?: float, error?: string, status?: int }
     */
    public static function check_balance($email, $pin) {
        $key = self::api_key();
        if (empty($key)) {
            return array('ok' => false, 'error' => 'EUROPAN-Zahlung ist auf dieser Seite nicht konfiguriert (fehlender API-Key).', 'status' => 500);
        }
        if (empty($email) || empty($pin) || !preg_match('/^\d{4}$/', $pin)) {
            return array('ok' => false, 'error' => 'E-Mail und 4-stellige PIN erforderlich.', 'status' => 400);
        }

        $response = wp_remote_post(self::BASE_URL . '/api/v1/balance-by-email', array(
            'timeout' => 12,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body' => wp_json_encode(array(
                'email'   => strtolower($email),
                'pin'     => $pin,
                'coin_id' => 'europan',
            )),
        ));

        return self::normalize_balance_response($response);
    }

    private static function normalize_balance_response($response) {
        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => 'EUROPAN-Dienst nicht erreichbar. Bitte später erneut versuchen.', 'status' => 503);
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status === 404) {
            return array('ok' => false, 'error' => 'Kein EUROPAN-Konto für diese E-Mail gefunden.', 'status' => 404);
        }
        if ($status === 401) {
            return array('ok' => false, 'error' => 'Falsche PIN.', 'status' => 401);
        }
        if ($status === 429) {
            return array('ok' => false, 'error' => 'Zu viele falsche Versuche — bitte in 15 Minuten erneut versuchen.', 'status' => 429);
        }
        if ($status < 200 || $status >= 300 || !is_array($body)) {
            $err = is_array($body) && !empty($body['error']) ? $body['error'] : 'EUROPAN-Dienst-Fehler.';
            return array('ok' => false, 'error' => $err, 'status' => $status ?: 502);
        }

        // WICHTIG: Da check_balance() immer coin_id mitschickt, antwortet
        // /api/v1/balance-by-email mit einem FLACHEN 'balance'-Feld
        // ({coin_id, balance}), NICHT mit dem verschachtelten 'balances'-Objekt
        // (letzteres kommt nur zurück, wenn coin_id NICHT mitgeschickt wird).
        // Vorherige Version las fälschlich $body['balances']['europan'], was bei
        // jeder Anfrage still auf 0 zurückfiel, egal wie hoch das echte Guthaben war.
        $balance = isset($body['balance']) ? (float) $body['balance'] : 0.0;

        return array(
            'ok'      => true,
            'balance' => $balance,
            'email'   => isset($body['email']) ? $body['email'] : strtolower(''),
            'status'  => 200,
        );
    }

    /**
     * Debit the customer's EUROPAN balance. Per noble-limited's own security model,
     * /api/v1/debit only checks the API key — NOT the PIN — so the caller (this plugin)
     * is the sole line of defense that the customer actually owns the account. NEVER
     * call this without having called check_balance() with the same email+PIN first,
     * in the same request lifecycle (see Europan_WC_Settlement).
     *
     * @return array { ok: bool, new_balance?: float, error?: string }
     */
    public static function debit($email, $amount, $description, $reference) {
        $key = self::api_key();
        if (empty($key)) {
            return array('ok' => false, 'error' => 'Not configured');
        }

        $response = wp_remote_post(self::BASE_URL . '/api/v1/debit', array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body' => wp_json_encode(array(
                'email'       => strtolower($email),
                'coin_id'     => 'europan',
                'amount'      => $amount,
                'description' => $description,
                'reference'   => $reference,
            )),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => 'EUROPAN-Dienst nicht erreichbar beim Belasten des Guthabens.');
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            $err = is_array($body) && !empty($body['error']) ? $body['error'] : 'Belastung fehlgeschlagen.';
            return array('ok' => false, 'error' => $err);
        }

        return array(
            'ok'          => true,
            'new_balance' => isset($body['new_balance']) ? (float) $body['new_balance'] : null,
        );
    }

    /**
     * Refund/credit back to the customer's EUROPAN balance — used when a WooCommerce
     * order that was paid via EUROPAN is later refunded or cancelled after settlement.
     * This is the piece that does NOT exist yet in the Stripe-based reference flow
     * (flagged as an open gap in the July 2026 conversation) — implemented here as a
     * straightforward mirror of debit() using noble-limited's credit endpoint.
     *
     * @return array { ok: bool, new_balance?: float, error?: string }
     */
    public static function credit_refund($email, $amount, $description, $reference) {
        $key = self::api_key();
        if (empty($key)) {
            return array('ok' => false, 'error' => 'Not configured');
        }

        $response = wp_remote_post(self::BASE_URL . '/api/v1/credit', array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body' => wp_json_encode(array(
                'email'       => strtolower($email),
                'coin_id'     => 'europan',
                'amount'      => $amount,
                'description' => $description,
                'reference'   => $reference,
            )),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => 'EUROPAN-Dienst nicht erreichbar bei der Rückerstattung.');
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            $err = is_array($body) && !empty($body['error']) ? $body['error'] : 'Rückerstattung fehlgeschlagen.';
            return array('ok' => false, 'error' => $err);
        }

        return array(
            'ok'          => true,
            'new_balance' => isset($body['new_balance']) ? (float) $body['new_balance'] : null,
        );
    }

    /**
     * Credit the partner with (order amount − network commission) — Modell 2 (geschlossener
     * Kreislauf): kein Cash-out, der Partner bekommt EP-Guthaben, keine Euro-Auszahlung.
     * Kommissionssatz kommt aus den Gateway-Settings (Default 3%, siehe Plugin-Bootstrap).
     *
     * @return array { ok: bool, error?: string }
     */
    public static function credit_partner($partner_email, $gross_amount, $commission_pct, $description, $reference) {
        $key = self::api_key();
        if (empty($key)) {
            return array('ok' => false, 'error' => 'Not configured');
        }

        $net_amount = round($gross_amount * (1 - ($commission_pct / 100)), 2);

        $response = wp_remote_post(self::BASE_URL . '/api/v1/partner-credit', array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body' => wp_json_encode(array(
                'partner_email'   => strtolower($partner_email),
                'coin_id'         => 'europan',
                'gross_amount'    => $gross_amount,
                'commission_pct'  => $commission_pct,
                'net_amount'      => $net_amount,
                'payout_model'    => 'closed_loop_ep', // Modell 2 — siehe class-europan-settlement.php
                'description'     => $description,
                'reference'       => $reference,
            )),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'error' => 'Partner-Gutschrift: EUROPAN-Dienst nicht erreichbar.');
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $err  = is_array($body) && !empty($body['error']) ? $body['error'] : 'Partner-Gutschrift fehlgeschlagen.';
            return array('ok' => false, 'error' => $err);
        }

        return array('ok' => true, 'net_amount' => $net_amount);
    }
}
