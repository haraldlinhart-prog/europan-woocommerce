/**
 * EUROPAN checkout widget — balance check + submit gating.
 * Texte/UX-Verhalten bewusst analog zum bestehenden EUROPAN-Widget
 * (europan-widget/vanilla/single-item-logic.js), aber ohne die
 * Bonus/Doppel-Wums-Rechenlogik, da diese Zahlungsart per Definition
 * immer den vollen Betrag verlangt (alles-oder-nichts, kein Teileinsatz).
 */
(function ($) {
    'use strict';

    /**
     * EUROPAN-denominierte Beträge tragen das EUROPAN-Zeichen )( statt € — exakt
     * dieselbe Formatierung wie im kanonischen EUROPAN-Widget
     * (europan-widget/vanilla/summary-logic.js, single-item-logic.js), damit das
     * Zahlungsart-Panel visuell zur restlichen EUROPAN-Familie passt und nicht den
     * Eindruck erweckt, hier ginge es um eine zweite, unabhängige Euro-Zahlung.
     */
    function fmtEP(value) {
        var n = Number(value) || 0;
        return ')( ' + (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2).replace('.', ','));
    }

    function setStatus(state, text) {
        var $light = $('#europan-wc-status-light');
        var $text  = $('#europan-wc-status-text');
        $light.removeClass('europan-wc-light--ready europan-wc-light--ok europan-wc-light--fail');
        $light.addClass('europan-wc-light--' + state);
        $text.text(text);
    }

    function disablePlaceOrder(disabled, reason) {
        var $btn = $('#place_order');
        if (!$btn.length) return;
        $btn.prop('disabled', disabled);
        if (disabled && reason) {
            $btn.attr('title', reason);
        } else {
            $btn.removeAttr('title');
        }
    }

    function checkBalance() {
        var email = $('#europan-wc-email').val();
        var pin   = $('#europan-wc-pin').val();
        var $result = $('#europan-wc-result');

        $result.removeClass('europan-wc-result--error europan-wc-result--ok').text('Wird geprüft …');
        setStatus('ready', 'Prüfe …');

        $.post(EuropanWC.ajaxUrl, {
            action: 'europan_wc_check_balance',
            nonce: EuropanWC.nonce,
            email: email,
            pin: pin
        }).done(function (response) {
            if (!response.success) {
                $result.addClass('europan-wc-result--error').text(response.data.message || 'Prüfung fehlgeschlagen.');
                setStatus('fail', 'Nicht verifiziert');
                $('#europan-wc-verified-token').val('');
                disablePlaceOrder(true, 'Bitte zuerst EUROPAN-Guthaben erfolgreich prüfen.');
                return;
            }

            var data = response.data;
            $('#europan-wc-verified-token').val(data.token);

            if (data.sufficient) {
                $result.addClass('europan-wc-result--ok').html(
                    'Guthaben: <strong>' + fmtEP(data.balance) + '</strong> — ausreichend für diese Bestellung (' + fmtEP(data.cart_total) + ').'
                );
                setStatus('ok', 'Aktiv');
                disablePlaceOrder(false);
            } else {
                $result.addClass('europan-wc-result--error').html(
                    'Ihr Guthaben (' + fmtEP(data.balance) + ') reicht nicht für den vollen Betrag (' + fmtEP(data.cart_total) + '). ' +
                    'Es fehlen <strong>' + fmtEP(data.shortfall) + '</strong>. ' +
                    '<a href="https://europan.group/buy" target="_blank" rel="noopener">Jetzt aufladen →</a>'
                );
                setStatus('fail', 'Guthaben unzureichend');
                disablePlaceOrder(true, 'EUROPAN-Guthaben reicht nicht für den vollen Betrag — Teileinsatz ist bei dieser Zahlungsart nicht möglich.');
            }
        }).fail(function () {
            $result.addClass('europan-wc-result--error').text('EUROPAN-Dienst nicht erreichbar. Bitte später erneut versuchen.');
            setStatus('fail', 'Fehler');
            disablePlaceOrder(true, 'EUROPAN-Prüfung fehlgeschlagen.');
        });
    }

    function isEuropanSelected() {
        var $radio = $('input[name="payment_method"][value="europan"]');
        return $radio.length && $radio.is(':checked');
    }

    function refreshGateState() {
        if (isEuropanSelected() && !$('#europan-wc-verified-token').val()) {
            disablePlaceOrder(true, 'Bitte zuerst EUROPAN-Guthaben prüfen.');
        } else if (!isEuropanSelected()) {
            disablePlaceOrder(false);
        }
    }

    $(document.body).on('click', '#europan-wc-check-btn', function (e) {
        e.preventDefault();
        checkBalance();
    });

    $(document.body).on('change', 'input[name="payment_method"]', refreshGateState);
    $(document.body).on('updated_checkout', refreshGateState);

    $(function () {
        refreshGateState();
    });

})(jQuery);
