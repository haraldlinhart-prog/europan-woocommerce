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
            'account_title' => array(
                'title'       => 'EUROPAN-Partnerkonto',
                'type'        => 'title',
                'description' => 'Um EUROPAN als Zahlungsart anzubieten, benötigen Sie einen persönlichen API-Key für Ihr EUROPAN-Partnerkonto. Registrieren Sie sich dafür unter <a href="https://www.europan.direct/partners.html" target="_blank" rel="noopener">europan.direct/partners.html</a> — beim kostenlosen "Free"-Tier erhalten Sie den API-Key sofort, ohne Wartezeit. Ihre Partner-E-Mail-Adresse und die Kommission, die EUROPAN einbehält, werden dabei automatisch mit Ihrem Konto verknüpft — Sie müssen (und können) sie hier nicht separat eintragen.',
            ),
            'api_key' => array(
                'title'       => 'API-Key',
                'type'        => 'password',
                'description' => 'Ihr persönlicher EUROPAN-API-Key aus der Partner-Registrierung auf <a href="https://www.europan.direct/partners.html" target="_blank" rel="noopener">europan.direct</a>. Wird ausschließlich für die serverseitige Kommunikation mit der EUROPAN-API verwendet (Guthaben prüfen, belasten, Gutschriften) — niemals an den Browser des Kunden weitergegeben.',
                'default'     => '',
                'desc_tip'    => true,
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
            'bonus_title' => array(
                'title'       => 'Kundenbonus',
                'type'        => 'title',
                'description' => 'Optionaler zusätzlicher Anreiz für Zahlungen per EUROPAN. Der Bonus wird als eigene Rabatt-Zeile direkt im Warenkorb/Checkout vom Rechnungsbetrag abgezogen, sobald der Kunde EUROPAN als Zahlungsart wählt — er erscheint dort automatisch prozent- und betragsmäßig in der Bestellübersicht. Da diese Zahlungsart ohnehin immer den vollen (bereits reduzierten) Betrag verlangt, ist das — anders als beim ursprünglichen "Doppel-Wums"-Konzept mit Teilzahlung — ohne zusätzliche Komplexität möglich.',
            ),
            'bonus_enabled' => array(
                'title'   => 'Bonus aktivieren',
                'type'    => 'checkbox',
                'label'   => 'Kunden, die mit EUROPAN bezahlen, einen zusätzlichen Bonus gutschreiben',
                'default' => 'no',
            ),
            'bonus_type' => array(
                'title'       => 'Bonus-Art',
                'type'        => 'select',
                'options'     => array(
                    'percent' => 'Prozentual vom Bestellwert',
                    'fixed'   => 'Fester Betrag pro Bestellung',
                ),
                'default'     => 'percent',
                'description' => 'Bezieht sich immer auf den vollen, per EUROPAN bezahlten Bestellbetrag.',
                'desc_tip'    => true,
            ),
            'bonus_value' => array(
                'title'       => 'Bonus-Höhe',
                'type'        => 'number',
                'description' => 'Je nach Bonus-Art entweder ein Prozentsatz (z. B. 2 für 2%) oder ein fester Euro-Betrag (z. B. 5 für 5,00 €).',
                'default'     => 2,
                'custom_attributes' => array('step' => '0.1', 'min' => '0'),
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

        $bonus_html = $this->get_bonus_hint_html();
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

            <?php if ($bonus_html): ?>
                <div class="europan-wc-bonus-hint"><?php echo wp_kses_post($bonus_html); ?></div>
            <?php endif; ?>

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
     * Human-readable bonus announcement shown at checkout when the shop operator has
     * enabled a bonus. Deliberately describes the bonus in general terms ("X% Bonus"
     * or a fixed amount) rather than pre-computing an exact euro figure here — the
     * cart total can still change (coupons, shipping) between page render and order
     * placement, and the actual amount is calculated once, authoritatively, in
     * Europan_WC_Settlement::maybe_credit_bonus() against the final order total.
     * Showing a provisional number here that might not match the final credit would
     * be worse than showing the rule and letting the order confirmation state the
     * exact figure.
     */
    public function get_bonus_hint_html() {
        if ($this->get_option('bonus_enabled', 'no') !== 'yes') {
            return '';
        }

        $type  = $this->get_option('bonus_type', 'percent');
        $value = (float) $this->get_option('bonus_value', 0);
        if ($value <= 0) {
            return '';
        }

        if ($type === 'fixed') {
            $desc = sprintf('%s EUROPAN-Bonus', wc_price($value));
        } else {
            $desc = sprintf('%s%% EUROPAN-Bonus', rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ','));
        }

        return sprintf(
            '<strong>%s</strong> auf jede vollständig per EUROPAN bezahlte Bestellung — wird bereits hier vom Rechnungsbetrag abgezogen, sobald EUROPAN als Zahlungsart ausgewählt ist.',
            esc_html($desc)
        );
    }

    /**
     * Lightweight presence check only. The real enforcement — is the token valid, not
     * expired, not already used, and does the balance actually cover this amount —
     * now happens server-side on europan.direct inside settle() (see
     * Europan_API_Client::settle()), because that's also where the actual debit
     * happens. Duplicating that logic here would just be a second copy that could
     * drift out of sync with the one that actually moves money.
     */
    public function validate_fields() {
        // WooCommerce itself already verifies the 'woocommerce-process_checkout' nonce
        // inside WC_Checkout::process_checkout() BEFORE it ever calls any gateway's
        // validate_fields(); this reads a value from that same already-verified POST.
        $token = isset($_POST['europan_wc_verified_token']) ? sanitize_text_field(wp_unslash($_POST['europan_wc_verified_token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $verified_email = WC()->session ? WC()->session->get('europan_wc_verified_email') : '';

        if (empty($token) || empty($verified_email)) {
            wc_add_notice('Bitte zuerst Ihr EUROPAN-Guthaben prüfen (E-Mail + PIN).', 'error');
            return false;
        }

        return true;
    }

    /**
     * Charges the order synchronously via Europan_API_Client::settle() — which debits
     * the customer and credits the shop (net of a commission looked up server-side on
     * europan.direct) in a single call — BEFORE marking the order as paid. This is
     * simpler and safer than the plugin's previous design (mark "on-hold", debit later
     * via a webhook-style hook): settle() gives an immediate success/failure answer,
     * so a failed charge never results in an order that looks placed.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Same already-verified checkout POST as validate_fields() above.
        $token = isset($_POST['europan_wc_verified_token']) ? sanitize_text_field(wp_unslash($_POST['europan_wc_verified_token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $verified_email = WC()->session ? WC()->session->get('europan_wc_verified_email') : '';

        if (empty($token) || empty($verified_email)) {
            wc_add_notice('EUROPAN-Verifikation fehlt. Bitte erneut versuchen.', 'error');
            return array('result' => 'failure');
        }

        $amount    = (float) $order->get_total();
        $reference = 'WC-' . $order->get_order_number() . '-' . $order_id;

        $settlement = Europan_API_Client::settle($token, $verified_email, $amount, $reference);

        if (empty($settlement['ok'])) {
            wc_add_notice('EUROPAN-Zahlung fehlgeschlagen: ' . (isset($settlement['error']) ? $settlement['error'] : 'Unbekannter Fehler.'), 'error');
            $order->add_order_note('⚠️ EUROPAN-Zahlung fehlgeschlagen: ' . (isset($settlement['error']) ? $settlement['error'] : 'Unbekannter Fehler.'));
            return array('result' => 'failure');
        }

        $order->update_meta_data('_europan_wc_customer_email', $verified_email);
        $order->update_meta_data('_europan_wc_amount', $amount);
        $order->update_meta_data('_europan_wc_settled', 'yes');
        if (!empty($settlement['partner_credited_amount'])) {
            $order->update_meta_data('_europan_wc_partner_credited', $settlement['partner_credited_amount']);
        }
        $order->add_order_note(sprintf('EUROPAN-Guthaben belastet: %s (Ref: %s).', wc_price($amount), $reference));
        if (!empty($settlement['warning'])) {
            // Customer was still charged successfully — the partner-credit side had an
            // issue, flagged here for manual follow-up, not something that should
            // block the sale from the customer's point of view.
            $order->add_order_note('⚠️ ' . $settlement['warning']);
        }
        $order->save();

        $order->payment_complete();

        WC()->cart->empty_cart();

        // Clear the verification email so it can't be reused for another order.
        if (WC()->session) {
            WC()->session->set('europan_wc_verified_email', null);
        }

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }
}
