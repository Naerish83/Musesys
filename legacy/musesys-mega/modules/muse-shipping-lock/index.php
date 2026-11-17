<?php
/*
Plugin Name: Wegmans Shipping Lock
Description: Hides "Ship to a different address" checkbox and disables manual shipping entry.
Version: 1.2
Author: MuseSystems
*/

if (!defined('ABSPATH')) {
    exit;
}

add_filter('woocommerce_cart_needs_shipping_address', '__return_true', 9999);
add_filter('woocommerce_cart_has_shipping_address', '__return_true', 9999);

add_filter('woocommerce_checkout_fields', function($fields) {
    unset($fields['shipping']); // completely removes the shipping address section
    return $fields;
}, 9999);

add_filter('woocommerce_checkout_get_value', function($value, $input) {
    if (strpos($input, 'shipping_') === 0) return '';
    return $value;
}, 10, 2);

add_action('wp_head', function() {
    echo '<style>#ship-to-different-address, .woocommerce-shipping-fields { display: none !important; }</style>';
});