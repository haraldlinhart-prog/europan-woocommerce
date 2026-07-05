/**
 * Registriert "EUROPAN" als Zahlungsmethode im block-basierten WooCommerce-
 * Checkout (wp-block-woocommerce-checkout). Ohne dieses Skript + die PHP-
 * Klasse Europan_Blocks_Support taucht EUROPAN nur im alten Shortcode-
 * Checkout auf, nicht im neuen Block-Checkout.
 *
 * UX/Logik bewusst 1:1 wie assets/js/checkout.js (klassischer Checkout):
 * E-Mail + PIN -> Guthaben-Check per AJAX -> Alles-oder-nichts-Gate für den
 * "Bestellung aufgeben"-Button. Hier über onPaymentSetup statt #place_order-
 * disable, weil der Block-Checkout keinen klassischen Submit-Button-DOM hat.
 */
(function () {
    'use strict';

    var settings = window.wc.wcSettings.getSetting('europan_data', {});
    var label = settings.title || 'Mit EUROPAN bezahlen';
    var ajaxUrl = settings.ajaxUrl;
    var nonce = settings.nonce;

    var el = window.wp.element.createElement;
    var useState = window.wp.element.useState;
    var useEffect = window.wp.element.useEffect;
    var RawHTML = window.wp.element.RawHTML;

    function fmtEP(value) {
        var n = Number(value) || 0;
        return ')( ' + (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2).replace('.', ','));
    }

    /**
     * Ruft den bestehenden Server-Endpunkt (Europan_WC_Ajax::check_balance,
     * admin-ajax) auf — der gleiche Endpunkt wie im klassischen Checkout,
     * damit die Verifikations-Session serverseitig identisch bleibt und
     * validate_fields() in WC_Gateway_Europan unverändert funktioniert.
     */
    function checkBalance(email, pin, cartTotal, onDone) {
        var body = new URLSearchParams();
        body.set('action', 'europan_wc_check_balance');
        body.set('nonce', nonce);
        body.set('email', email);
        body.set('pin', pin);

        fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (response) { onDone(response, null); })
            .catch(function (err) { onDone(null, err); });
    }

    var EuropanContent = function (props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var cartTotal = (props.cart && props.cart.cartTotals && props.cart.cartTotals.total_price)
            ? Number(props.cart.cartTotals.total_price) / 100
            : 0;

        var stateEmail = useState('');
        var email = stateEmail[0], setEmail = stateEmail[1];
        var statePin = useState('');
        var pin = statePin[0], setPin = statePin[1];
        var stateResult = useState(null);
        var result = stateResult[0], setResult = stateResult[1];
        var stateToken = useState('');
        var token = stateToken[0], setToken = stateToken[1];
        var stateChecking = useState(false);
        var checking = stateChecking[0], setChecking = stateChecking[1];

        useEffect(function () {
            var unsubscribe = eventRegistration.onPaymentSetup(function () {
                if (!token) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Bitte zuerst Ihr EUROPAN-Guthaben prüfen (E-Mail + PIN).',
                    };
                }
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            europan_wc_verified_token: token,
                        },
                    },
                };
            });
            return unsubscribe;
        }, [token, eventRegistration, emitResponse]);

        function handleCheck() {
            setChecking(true);
            setResult({ pending: true });
            checkBalance(email, pin, cartTotal, function (response, err) {
                setChecking(false);
                if (err || !response || !response.success) {
                    var msg = (response && response.data && response.data.message) || 'Prüfung fehlgeschlagen.';
                    setResult({ error: true, message: msg });
                    setToken('');
                    return;
                }
                var data = response.data;
                setToken(data.token);
                if (data.sufficient) {
                    setResult({
                        ok: true,
                        message: 'Guthaben: ' + fmtEP(data.balance) + ' — ausreichend für diese Bestellung (' + fmtEP(data.cart_total) + ').',
                    });
                } else {
                    setResult({
                        error: true,
                        message: 'Ihr Guthaben (' + fmtEP(data.balance) + ') reicht nicht für den vollen Betrag (' +
                            fmtEP(data.cart_total) + '). Es fehlen ' + fmtEP(data.shortfall) + '.',
                    });
                    setToken('');
                }
            });
        }

        return el('div', { className: 'europan-wc-panel' },
            settings.description ? el('p', null, settings.description) : null,
            el('p', { className: 'europan-wc-hint' },
                'EUROPAN ist die Netzwerkwährung von PAN21. Zum Bezahlen benötigen Sie ausreichend ' +
                'Guthaben in Höhe des kompletten Rechnungsbetrags — Teileinsatz ist bei dieser Zahlungsart ' +
                'nicht möglich.'
            ),
            settings.bonusHint
                ? el(RawHTML, { className: 'europan-wc-bonus-hint' }, settings.bonusHint)
                : null,
            el('div', { className: 'europan-wc-form-row' },
                el('input', {
                    type: 'email',
                    placeholder: 'ihre@email.de',
                    value: email,
                    onChange: function (e) { setEmail(e.target.value); },
                }),
                el('input', {
                    type: 'password',
                    inputMode: 'numeric',
                    maxLength: 4,
                    placeholder: 'PIN',
                    value: pin,
                    onChange: function (e) { setPin(e.target.value); },
                }),
                el('button', {
                    type: 'button',
                    className: 'button',
                    disabled: checking,
                    onClick: handleCheck,
                }, checking ? 'Prüfe …' : 'Guthaben prüfen')
            ),
            result && result.message
                ? el('div', {
                    className: 'europan-wc-result ' + (result.error ? 'europan-wc-result--error' : 'europan-wc-result--ok'),
                }, result.message)
                : null,
            el('p', { className: 'europan-wc-hint' },
                'Noch kein Guthaben? ',
                el('a', { href: 'https://europan.group/buy', target: '_blank', rel: 'noopener' }, 'Jetzt kaufen →')
            )
        );
    };

    var EuropanLabel = function () {
        return el('span', null, label);
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'europan',
        label: el(EuropanLabel),
        content: el(EuropanContent),
        edit: el(EuropanContent),
        canMakePayment: function () { return true; },
        ariaLabel: label,
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
