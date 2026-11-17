<?php
/**
 * Plugin Name: Disable Woo Related Products
 * Description: Disables WooCommerce related products output site-wide.
 * Author: MuseSystems
 * Version: 2.0
 */

if (!defined('ABSPATH')) { exit; }

// Remove related products from product pages
remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);

// Prevent related product queries entirely
add_filter('woocommerce_related_products', '__return_empty_array', 10);