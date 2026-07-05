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

    /**
     * @return string|null
     *
     * Priority order:
     * 1. The gateway's own "API-Key" settings field (WooCommerce → Settings →
     *    Payments → EUROPAN) — this is the PRIMARY, self-service path: the shop
     *    operator signs up for their own EUROPAN partner account at europan.group,
     *    gets their own key, and enters it directly in the plugin's own settings
     *    screen, exactly like every other WooCommerce payment gateway (Stripe,
     *    PayPal, etc.) expects. This is what makes the plugin usable by anyone who
     *    installs it from the WordPress.org directory without server access.
     * 2. The EUROPAN_NOBLE_API_KEY wp-config.php constant, if defined — kept as an
     *    OPTIONAL advanced override for operators who specifically want the key
     *    out of the wp_options table (e.g. multi-site deployments managed by PAN21
     *    itself). Only used as a fallback when the settings field is empty, never
     *    the other way around, so a shop operator's own key always takes priority
     *    over a leftover/shared constant.
     */
    private static function api_key() {
        $gateway_settings = get_option('woocommerce_europan_settings', array());
        $key = !empty($gateway_settings['api_key']) ? $gateway_settings['api_key'] : '';

        if (empty($key) && defined('EUROPAN_NOBLE_API_KEY')) {
            $key = EUROPAN_NOBLE_API_KEY;
        }

        // Filterable so a secrets manager or site-specific override can still
        // supply/replace it, e.g. for staging environments.
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
     * Credit the partner with (order amount − network commission) via noble-limited's
     * EP-credit endpoint.
     *
     * WICHTIG (Stand Juli 2026): Das erklärte Zielmodell für dieses Plugin ist inzwischen
     * eine ECHTE Euro-Auszahlung an das Bankkonto des Shops (finanziert aus dem
     * Gutschein-Vorverkauf auf europan.group/buy, EUROPAN als Float-Halter, Auszahlung
     * abzüglich Provision bei Einlösung) — NICHT mehr der ursprünglich hier dokumentierte
     * geschlossene EP-Kreislauf ohne Auszahlung. Diese Methode wurde dafür aber noch NICHT
     * umgebaut: sie ruft weiterhin /api/v1/partner-credit auf, einen reinen
     * EP-Gutschrift-Endpunkt. Eine echte Bankauszahlung würde einen komplett anderen
     * Backend-Endpunkt, eine Bankverbindungs-Erfassung in den Gateway-Settings und eine
     * Float-/Auszahlungs-Buchhaltung erfordern — keins davon existiert aktuell. Der
     * payout_model-Wert unten ('closed_loop_ep') spiegelt bewusst weiterhin exakt das
     * wider, was diese Methode tatsächlich tut, nicht das Zielmodell — ihn ohne den
     * dazugehörigen Backend-Umbau zu ändern würde der noble-limited-API eine Auszahlungsart
     * vortäuschen, die serverseitig gar nicht existiert.
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
                'payout_model'    => 'closed_loop_ep', // Absichtlich unverändert, siehe Klassen-/Methoden-Kommentar oben — spiegelt den TATSÄCHLICHEN Endpunkt, nicht das Zielmodell.
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
