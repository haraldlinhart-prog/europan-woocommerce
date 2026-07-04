# EUROPAN für WooCommerce

Variante B ("echte Prepaid-Zahlungsart") aus dem PAN21-EUROPAN-Konzept (siehe
`haraldlinhart-prog/europan-widget`, Gespräch Juli 2026). Der Kunde bezahlt **immer den
vollen Rechnungsbetrag** mit zuvor auf europan.group gekauftem EUROPAN-Guthaben —
kein Teileinsatz, keine Kombination mit einer anderen Zahlungsart, keine Bonus-/
Doppel-Wums-Rechnung im Checkout (das ist Variante A, ein separater, größerer Baustein).

## Status: Grundgerüst, noch NICHT produktiv getestet

Wie besprochen: erst mit einem einzelnen Testpartner (z. B. boninvest.de) real
durchspielen — inklusive Stornofall — bevor es an weitere Partner ausgerollt wird.

## Setup

1. Plugin in ein WooCommerce-Shop installieren und aktivieren.
2. In `wp-config.php` den API-Key hinterlegen (niemals in der Datenbank/Admin-UI):
   ```php
   define('EUROPAN_NOBLE_API_KEY', 'kaQ70oIOYL3btvdDsPWlBSd17945h0p9CpEWdlQUmyY');
   ```
3. WooCommerce → Einstellungen → Zahlungen → **EUROPAN**:
   - Aktivieren
   - Partner-E-Mail (das EUROPAN-Konto, dem die Gutschrift zufließt) eintragen
   - Kommissionssatz einstellen (Default 3%, empfohlen 2–5%)

## Geldfluss (Modell 2 — geschlossener Kreislauf)

```
Kunde zahlt vollen Betrag in EP
    ↓ (erst nach woocommerce_payment_complete, nie beim Checkout-Erstellen)
Kunde: Guthaben wird belastet (/api/v1/debit)
    ↓
Partner: Gutschrift = Betrag − Kommission (/api/v1/partner-credit, payout_model: closed_loop_ep)
```

Kein Geld verlässt den EP-Kreislauf in Euro — das ist bewusst so gewählt, siehe
Gespräch Juli 2026 zur Vermeidung einer Nähe zu lizenzpflichtigen Zahlungsdiensten
(PSD2/E-Geld). Eine "Modell 1"-Variante mit echter Euro-Auszahlung an den Partner ist
NICHT Teil dieses Plugins und sollte vor jeder Umsetzung mit Stefan Seuß abgestimmt
werden.

## Was schon eingebaut ist, was im ursprünglichen Stripe-Referenzfluss fehlte

- **Storno/Refund-Rückabwicklung**: `Europan_WC_Settlement::handle_cancelled_or_refunded()`
  und `handle_partial_refund()` buchen dem Kunden sein EP-Guthaben zurück, sobald eine
  bereits abgerechnete Bestellung storniert/erstattet wird. Die Partner-Gutschrift wird
  dabei bewusst NICHT automatisch zurückgebucht (Order-Notiz statt Silent-Reversal) —
  das braucht eine bewusste Entscheidung, keine automatische Blackbox.
- **Idempotenz**: `_europan_wc_settled`-Metafeld verhindert doppelte Belastung, falls
  `woocommerce_payment_complete` mehrfach feuert (kommt in WooCommerce gelegentlich vor).
- **Fehlerfall bei Belastung**: Schlägt der Debit-Call fehl, bleibt die Bestellung
  "on-hold" mit klarer Notiz statt fälschlich als abgeschlossen zu gelten.

## Offene Punkte vor echtem Rollout

- [ ] Noble-limited-API-Endpunkte `/api/v1/credit` und `/api/v1/partner-credit` mit
      `payout_model: closed_loop_ep` müssen serverseitig bei noble-limited existieren/
      angepasst werden — dieses Plugin geht davon aus, dass sie die gleiche
      Auth-/Fehler-Konvention wie `/api/v1/debit` und `/api/v1/balance-by-email` haben.
- [ ] Realer Testlauf mit echten (kleinen) Beträgen bei einem Testpartner.
- [ ] Rate-Limiting/Bruteforce-Schutz auf `europan_wc_check_balance` (aktuell verlässt
      sich das Plugin auf noble-limiteds eigenes 429-Sperrverhalten nach 5 Fehlversuchen —
      sollte reichen, aber im echten Betrieb beobachten).
- [ ] HPOS-Kompatibilität ist deklariert, aber nicht gegen eine echte HPOS-Installation
      getestet.
- [ ] Blocks-Checkout (neuer WooCommerce-Block-Checkout statt Classic-Shortcode)
      wird von `payment_fields()`/`checkout.js` in dieser Form NICHT unterstützt — das
      Grundgerüst geht vom klassischen Checkout aus. Für Block-Checkout wäre ein
      separater `PaymentMethodRegistry`-Block nötig.
