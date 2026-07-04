<?php
/**
 * Registriert EUROPAN als Zahlungsmethode für den neuen block-basierten
 * WooCommerce-Checkout (Cart & Checkout Blocks). Ohne diese Klasse taucht das
 * Gateway zwar im klassischen Shortcode-Checkout auf, aber NICHT im
 * Block-Checkout — seit WooCommerce 8.x der Standard bei neu angelegten
 * Kasse-Seiten (Block "Kasse" statt [woocommerce_checkout]-Shortcode).
 *
 * Zusammenspiel mit class-wc-gateway-europan.php: Diese Klasse übernimmt nur
 * REGISTRIERUNG + Server-Side-Render der Beschreibung für den Block-Checkout.
 * Die eigentliche Logik (validate_fields, process_payment, Settlement) bleibt
 * unverändert in WC_Gateway_Europan — der Block-Checkout ruft am Ende genauso
 * process_payment() auf wie der klassische Checkout.
 */

if (!defined('ABSPATH')) exit;

final class Europan_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

    /**
     * @var WC_Gateway_Europan
     */
    private $gateway;

    protected $name = 'europan';

    public function initialize() {
        $this->settings = get_option('woocommerce_europan_settings', array());
        $gateways       = WC()->payment_gateways->payment_gateways();
        $this->gateway  = isset($gateways['europan']) ? $gateways['europan'] : null;
    }

    /**
     * Ob die Zahlungsmethode im Block-Checkout überhaupt angeboten wird.
     * Spiegelt exakt die gleiche "enabled"-Logik wie der klassische Checkout.
     */
    public function is_active() {
        return $this->gateway && $this->gateway->enabled === 'yes';
    }

    /**
     * JS-Skript, das die Zahlungsmethode im Block-Checkout-Frontend registriert
     * (registerPaymentMethod). Separates Skript von assets/js/checkout.js, das
     * weiterhin für den klassischen Checkout zuständig bleibt.
     */
    public function get_payment_method_script_handles() {
        wp_register_script(
            'europan-blocks-integration',
            EUROPAN_WC_PLUGIN_URL . 'assets/js/blocks-checkout.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            EUROPAN_WC_VERSION,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('europan-blocks-integration', 'europan-woocommerce');
        }

        return array('europan-blocks-integration');
    }

    /**
     * Daten, die vom PHP an das JS-Registrierungsskript durchgereicht werden
     * (Titel, Beschreibung, Icon, Ajax-URL/Nonce für den Guthaben-Check).
     * Im Block-Checkout gibt es kein wp_localize_script wie im klassischen Flow,
     * daher läuft das hier über get_payment_method_data().
     */
    public function get_payment_method_data() {
        return array(
            'title'       => $this->gateway ? $this->gateway->title : 'Mit EUROPAN bezahlen',
            'description' => $this->gateway ? $this->gateway->description : '',
            'icon'        => EUROPAN_WC_PLUGIN_URL . 'assets/img/europan-icon.png',
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('europan_wc_nonce'),
            'supports'    => $this->get_supported_features(),
        );
    }

    public function get_supported_features() {
        return $this->gateway ? array_filter($this->gateway->supports, array($this->gateway, 'supports')) : array('products');
    }
}
