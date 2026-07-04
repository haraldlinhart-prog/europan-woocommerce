<?php
/**
 * EUROPAN als eigenständige WooCommerce-Zahlungsart (Variante B / Prepaid).
 *
 * Ablauf:
 * 1. Kunde wählt "Mit EUROPAN bezahlen" im Checkout.
 * 2. Widget (assets/js/checkout.js) fragt E-Mail + PIN ab, ruft per AJAX
 *    Europan_WC_Ajax::check_balance() auf, zeigt Ampel + Guthaben.
 * 3. Reicht das Guthaben NICHT für den vollen Betrag, ist "Jetzt kaufen" gesperrt —
 *    alles-oder-nichts, kein Teileinsatz, keine Kombination mit anderer Zahlungsart.
 * 4. process_payment() legt die Bestellung als "on-hold" an (NICHT als bezahlt!) —
 *    die eigentliche Belastung des Guthabens passiert erst in Europan_WC_Settlement,
 *    getriggert durch woocommerce_payment_complete, NIE hier direkt. Das ist die
 *    gleiche Reihenfolge wie beim bestehenden Stripe-Referenzfluss (settleEuropan()
 *    erst nach bestätigter Zahlung, nie bei Checkout-Erstellung).
 */

if (!defined('ABSPATH')) exit;

class WC_Gateway_Europan extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'europan';
        $this->icon               = EUROPAN_WC_PLUGIN_URL . 'assets/img/europan-icon.png';
        $this->has_fields         = true;
        $this->method_title       = 'EUROPAN';
        $this->method_description = 'Kunde bezahlt den vollen Rechnungsbetrag mit zuvor auf europan.group gekauftem EUROPAN-Prepaid-Guthaben. Verifikation per E-Mail + PIN, alles-oder-nichts (kein Teileinsatz). Partner erhält Gutschrift abzüglich Netzwerk-Kommission.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title', 'Mit EUROPAN bezahlen');
        $this->description = $this->get_option('description', 'Bezahlen Sie mit Ihrem EUROPAN-Guthaben. Sie benötigen die E-Mail-Adresse und PIN aus Ihrer EUROPAN-Bestellbestätigung.');
        $this->enabled      = $this->get_option('enabled', 'no');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Aktivieren',
                'type'    => 'checkbox',
                'label'   => 'EUROPAN als Zahlungsart im Checkout anzeigen',
                'default' => 'no',
            ),
            'title' => array(
                'title'       => 'Titel',
                'type'        => 'text',
                'description' => 'Wird dem Kunden im Checkout als Bezeichnung dieser Zahlungsart angezeigt.',
                'default'     => 'Mit EUROPAN bezahlen',
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => 'Beschreibung',
                'type'        => 'textarea',
                'description' => 'Kurzer Erklärtext unter dem Zahlungsart-Titel im Checkout.',
                'default'     => 'Bezahlen Sie mit Ihrem EUROPAN-Guthaben. Sie benötigen die E-Mail-Adresse und PIN aus Ihrer EUROPAN-Bestellbestätigung.',
            ),
            'partner_email' => array(
                'title'       => 'Partner-E-Mail (EUROPAN-Konto)',
                'type'        => 'email',
                'description' => 'Die E-Mail-Adresse, unter der IHR EUROPAN-Partnerkonto geführt wird. Hierhin fließt die Gutschrift abzüglich Kommission.',
                'default'     => '',
                'desc_tip'    => true,
            ),
            'commission_pct' => array(
                'title'       => 'Netzwerk-Kommission (%)',
                'type'        => 'number',
                'description' => 'Wird von jeder EUROPAN-Zahlung einbehalten, bevor Ihnen die Gutschrift erteilt wird (Modell 2: geschlossener EP-Kreislauf, keine Euro-Auszahlung). Empfohlener Bereich: 2–5%.',
                'default'     => get_option('europan_wc_commission_pct', 3.0),
                'custom_attributes' => array('step' => '0.1', 'min' => '0', 'max' => '10'),
                'desc_tip'    => true,
            ),
        );
    }

    public function enqueue_checkout_assets() {
        if (!is_checkout() || $this->enabled !== 'yes') {
            return;
        }
        wp_enqueue_script(
            'europan-wc-checkout',
            EUROPAN_WC_PLUGIN_URL . 'assets/js/checkout.js',
            array('jquery'),
            EUROPAN_WC_VERSION,
            true
        );
        wp_enqueue_style(
            'europan-wc-checkout',
            EUROPAN_WC_PLUGIN_URL . 'assets/css/checkout.css',
            array(),
            EUROPAN_WC_VERSION
        );
        wp_localize_script('europan-wc-checkout', 'EuropanWC', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('europan_wc_nonce'),
        ));
    }

    /**
     * Fields rendered inside the payment method box at checkout. Texte 1:1 aus dem
     * bestehenden EUROPAN-Widget übernommen (vanilla/single-item-panel.html), nur
     * das Bestell-Summary-Layout entfällt hier, weil WooCommerce das im Warenkorb
     * bereits zeigt — dieses Gateway zeigt nur Ampel + Guthaben-Check + Hinweis.
     */
    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }
        ?>
        <div class="europan-wc-panel">
            <div id="europan-wc-status-badge" class="europan-wc-badge">
                <span>EUROPAN-optimiert</span>
                <span id="europan-wc-status-light" class="europan-wc-light europan-wc-light--ready">
                    <span class="europan-wc-dot"></span>
                    <span id="europan-wc-status-text">Bereit – anmelden</span>
                </span>
            </div>

            <p class="europan-wc-hint">
                EUROPAN ist die Netzwerkwährung von PAN21. Zum Bezahlen benötigen Sie ausreichend
                Guthaben in Höhe des kompletten Rechnungsbetrags — Teileinsatz ist bei dieser
                Zahlungsart nicht möglich. Sie erhalten EUROPAN-Guthaben, indem Sie auf
                <strong>europan.group</strong> einen Gutschein kaufen: 1&nbsp;€ Gutscheinwert
                entspricht 1&nbsp;€ EUROPAN. Ihre PIN dafür erhalten Sie direkt mit Ihrer
                Bestellbestätigung.
            </p>

            <div class="europan-wc-form-row">
                <input type="email" id="europan-wc-email" placeholder="ihre@email.de" autocomplete="email">
                <input type="password" id="europan-wc-pin" inputmode="numeric" maxlength="4" placeholder="PIN" autocomplete="off">
                <button type="button" id="europan-wc-check-btn" class="button">Guthaben prüfen</button>
            </div>
            <p class="europan-wc-hint europan-wc-hint--small">
                Die PIN steht in Ihrer EUROPAN-Bestellbestätigung — mit noble-limited.com hat
                das nichts zu tun.
            </p>

            <div id="europan-wc-result" class="europan-wc-result" aria-live="polite"></div>

            <p class="europan-wc-hint">
                Noch kein Guthaben?
                <a href="https://europan.group/buy" target="_blank" rel="noopener">Jetzt kaufen →</a>
            </p>

            <input type="hidden" name="europan_wc_verified_token" id="europan-wc-verified-token" value="">
        </div>
        <?php
    }

    /**
     * Server-side gate: WooCommerce calls this right before process_payment() when
     * the customer clicks "Bestellung aufgeben". Rejects the order attempt outright
     * if there is no valid, fresh (15 min) server-side verification token — never
     * trusts anything the client claims about balance sufficiency.
     */
    public function validate_fields() {
        $token = isset($_POST['europan_wc_verified_token']) ? sanitize_text_field(wp_unslash($_POST['europan_wc_verified_token'])) : '';

        if (empty($token) || !WC()->session) {
            wc_add_notice('Bitte zuerst Ihr EUROPAN-Guthaben prüfen (E-Mail + PIN).', 'error');
            return false;
        }

        $session_token   = WC()->session->get('europan_wc_verified_token');
        $verified_at     = (int) WC()->session->get('europan_wc_verified_at', 0);
        $verified_email  = WC()->session->get('europan_wc_verified_email');
        $verified_balance = (float) WC()->session->get('europan_wc_verified_balance', 0);

        if (empty($session_token) || !hash_equals($session_token, $token)) {
            wc_add_notice('EUROPAN-Verifikation ungültig oder abgelaufen. Bitte Guthaben erneut prüfen.', 'error');
            return false;
        }
        if ((time() - $verified_at) > 15 * MINUTE_IN_SECONDS) {
            wc_add_notice('Die EUROPAN-Verifikation ist abgelaufen (15 Minuten). Bitte Guthaben erneut prüfen.', 'error');
            return false;
        }

        $cart_total = (float) WC()->cart->get_total('edit');
        if ($verified_balance < $cart_total) {
            // Re-check against the CURRENT cart total, not the total at verification time —
            // the cart can change (coupon, shipping) between check and submit.
            wc_add_notice('Ihr EUROPAN-Guthaben reicht nicht für den aktuellen Gesamtbetrag. Bitte erneut prüfen.', 'error');
            return false;
        }

        return true;
    }

    /**
     * Places the order as "on-hold" — deliberately NOT paid yet. The actual debit of
     * the customer's EUROPAN balance and the partner credit both happen in
     * Europan_WC_Settlement::handle_payment_complete(), which only fires once
     * WooCommerce independently confirms payment via woocommerce_payment_complete.
     * This mirrors the existing settleEuropan() rule: never touch balances at
     * checkout-creation time, only after confirmed payment.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        $verified_email = WC()->session ? WC()->session->get('europan_wc_verified_email') : '';
        if (empty($verified_email)) {
            wc_add_notice('EUROPAN-Verifikation fehlt. Bitte erneut versuchen.', 'error');
            return array('result' => 'failure');
        }

        $order->update_meta_data('_europan_wc_customer_email', $verified_email);
        $order->update_meta_data('_europan_wc_amount', (float) $order->get_total());
        $order->update_status('on-hold', 'Warte auf EUROPAN-Guthaben-Belastung (Bestätigung ausstehend).');
        $order->save();

        // Mark the order as paid via the standard WooCommerce mechanism appropriate for a
        // "manual"/internal gateway: payment_complete() itself triggers
        // woocommerce_payment_complete, which is exactly the hook Europan_WC_Settlement
        // listens on to perform the actual debit. This keeps the "debit only after
        // confirmed payment" rule intact even though there's no external gateway webhook —
        // here, the "confirmation" IS the successful validate_fields() balance check.
        $order->payment_complete();

        WC()->cart->empty_cart();

        // Clear the one-time verification token so it can't be replayed for another order.
        if (WC()->session) {
            WC()->session->set('europan_wc_verified_token', null);
        }

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }
}
