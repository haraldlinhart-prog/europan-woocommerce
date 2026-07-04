<?php
/**
 * Plugin Name: EUROPAN für WooCommerce
 * Plugin URI: https://europan.group
 * Description: EUROPAN-Prepaid-Guthaben als eigene Zahlungsart in WooCommerce. Kunde zahlt den vollen Rechnungsbetrag mit zuvor auf europan.group gekauftem EUROPAN-Guthaben (E-Mail + PIN, alles-oder-nichts). Partner erhält Gutschrift abzüglich konfigurierbarer Netzwerk-Kommission (Modell 2: geschlossener Kreislauf, keine Auszahlung in Euro).
 * Version: 0.1.0
 * Author: PAN21.COM Corporate Consultants Ltd
 * Text Domain: europan-woocommerce
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 *
 * WICHTIG (siehe README.md): Dies ist Variante B ("echte Prepaid-Zahlungsart") aus dem
 * PAN21-EUROPAN-Konzept. Der Kunde zahlt IMMER den vollen Betrag in EUROPAN (kein
 * Teileinsatz, keine Kombination mit anderen Zahlungsarten). Variante A (EUROPAN als
 * Rabatt-Add-on neben bestehender Zahlungsart) ist bewusst NICHT Teil dieses Plugins —
 * das ist ein separater, größerer Baustein (siehe europan-widget Repo, README "Variante A").
 */

if (!defined('ABSPATH')) exit;

define('EUROPAN_WC_VERSION', '0.1.0');
define('EUROPAN_WC_PLUGIN_FILE', __FILE__);
define('EUROPAN_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EUROPAN_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Bail early and show an admin notice if WooCommerce isn't active —
 * never fatal-error a site just because this plugin got activated too early/without WC.
 */
function europan_wc_missing_woocommerce_notice() {
    echo '<div class="notice notice-error"><p><strong>EUROPAN für WooCommerce</strong> benötigt ein aktives WooCommerce. Das Plugin ist deaktiviert, solange WooCommerce fehlt.</p></div>';
}

function europan_wc_init() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'europan_wc_missing_woocommerce_notice');
        return;
    }

    require_once EUROPAN_WC_PLUGIN_DIR . 'includes/class-europan-api-client.php';
    require_once EUROPAN_WC_PLUGIN_DIR . 'includes/class-europan-ajax.php';
    require_once EUROPAN_WC_PLUGIN_DIR . 'includes/class-europan-settlement.php';
    require_once EUROPAN_WC_PLUGIN_DIR . 'includes/class-wc-gateway-europan.php';

    Europan_WC_Ajax::init();
    Europan_WC_Settlement::init();

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'WC_Gateway_Europan';
        return $gateways;
    });
}
// Priority 11: run after WooCommerce's own 'plugins_loaded' registration (prio 10 default),
// so class_exists('WooCommerce') is reliable regardless of plugin load order.
add_action('plugins_loaded', 'europan_wc_init', 11);

/**
 * Declare HPOS (High-Performance Order Storage) compatibility explicitly.
 * Without this, WooCommerce shows a compatibility warning on newer installs.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Default options on activation. Kommissionssatz-Default 3% (Mitte der 2-5%-Spanne,
 * siehe Gespräch Juli 2026) — Partner kann das in den Gateway-Einstellungen ändern.
 */
register_activation_hook(__FILE__, function () {
    if (get_option('europan_wc_commission_pct', null) === null) {
        add_option('europan_wc_commission_pct', 3.0);
    }
});
