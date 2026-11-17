<?php
/**
 * Plugin Name: MuseSys – ZIP Normalizer (Shipping-only)
 * Description: Reads "zip" from selector address JSON and maps it into WooCommerce shipping_postcode only (no billing changes).
 * Version: 1.1.1
 * Author: Muse Systems
 */

if (!defined('ABSPATH')) exit;

final class MuseSys_Zip_Normalizer {
    public static function boot() {
        // Normalize posted data before Woo consumes it
        add_filter('woocommerce_checkout_posted_data', array(__CLASS__, 'normalize_zip_from_json'), 9, 1);

        // Backfill on the order object right before save (shipping only)
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'ensure_order_shipping_postcode'), 10, 2);
    }

    /** Extract ZIP from selector JSON or common fallbacks */
    private static function get_zip_from_sources($posted) {
        $json_keys = array(
            'musesys_address_json',
            'address_json',
            'shipping_address_json',
            'musesys_selector_address',
            'selector_address',
        );

        $zip = '';

        // 1) JSON payloads in POST
        foreach ($json_keys as $k) {
            if (!isset($posted[$k])) continue;
            $raw  = $posted[$k];
            $data = is_array($raw) ? $raw : json_decode((string)$raw, true);
            if (is_array($data) && !empty($data['zip'])) { $zip = (string)$data['zip']; break; }
        }

        // 2) Flat POST fallbacks
        if ($zip === '') {
            foreach (array('zip','zipcode','postal_code','shipping_zip','shipping_zipcode','shipping_postal_code','shipping_postcode') as $k) {
                if (!empty($posted[$k])) { $zip = (string)$posted[$k]; break; }
            }
        }

        // 3) Woo session (set by selector)
        if ($zip === '' && function_exists('WC') && WC()->session) {
            $sess = WC()->session->get('musesys_confirmed_address');
            if ($sess) {
                if (is_string($sess)) {
                    $arr = json_decode($sess, true);
                    if (is_array($arr)) $sess = $arr;
                }
                if (is_array($sess)) {
                    if (!empty($sess['zip']))           $zip = (string)$sess['zip'];
                    elseif (!empty($sess['postcode']))  $zip = (string)$sess['postcode'];
                    elseif (!empty($sess['postal_code'])) $zip = (string)$sess['postal_code'];
                }
            }
        }

        // Sanitize to 5 or 9 digits (XXXXX or XXXXX-XXXX)
        if ($zip !== '') {
            $digits = preg_replace('/\D+/', '', strtoupper(trim($zip)));
            if (strlen($digits) === 9) return substr($digits, 0, 5) . '-' . substr($digits, 5);
            if (strlen($digits) === 5) return $digits;
            // fallback: strip non digits/hyphen
            return preg_replace('/[^0-9\-]/', '', $zip);
        }

        return '';
    }

    /** Map into posted data keys Woo expects (shipping only) */
    public static function normalize_zip_from_json($data) {
        if (!empty($data['shipping_postcode'])) return $data;
        $zip = self::get_zip_from_sources($data);
        if ($zip !== '') $data['shipping_postcode'] = $zip;
        return $data;
    }

    /** Ensure order object carries the ZIP even if posted field was blank (shipping only) */
    public static function ensure_order_shipping_postcode($order, $data) {
        if (!empty($order->get_shipping_postcode())) return;
        $zip = self::get_zip_from_sources($data);
        if ($zip !== '') $order->set_shipping_postcode($zip);
    }
}
MuseSys_Zip_Normalizer::boot();
