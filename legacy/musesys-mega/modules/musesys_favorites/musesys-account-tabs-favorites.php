<?php
/**
 * Plugin Name: MuseSys — Account Tabs: Favorites
 * Description: Restores My Account tabs for Favorite Locations and Favorite Products, rendering via existing MuseSys shortcodes.
 * Version: 1.0.0
 * Author: MuseSystems
 */

defined('ABSPATH') || exit;

// Basic guard
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>MuseSys — Account Tabs: Favorites</strong> requires WooCommerce.</p></div>';
        });
    }
}, 1);

/**
 * Register endpoints: /my-account/favorite-locations and /my-account/favorite-products
 */
function musesys_favtabs_add_endpoints() {
    add_rewrite_endpoint('favorite-locations', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('favorite-products', EP_ROOT | EP_PAGES);
}
add_action('init', 'musesys_favtabs_add_endpoints');

/**
 * Flush rewrite rules on activation so endpoints work immediately.
 */
function musesys_favtabs_activate() {
    musesys_favtabs_add_endpoints();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'musesys_favtabs_activate');

/**
 * Optional cleanup on deactivation (not strictly necessary).
 */
function musesys_favtabs_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'musesys_favtabs_deactivate');

/**
 * Add menu items to WooCommerce My Account nav.
 * We place Locations and Products after "Addresses".
 */
function musesys_favtabs_account_menu_items($items) {
    // Build a new ordered array
    $new = [];

    foreach ($items as $key => $label) {
        $new[$key] = $label;

        if ($key === 'edit-address') {
            // Insert our entries right after Addresses
            $new['favorite-locations'] = __('Favorite Locations', 'musesys');
            $new['favorite-products']  = __('Favorite Products', 'musesys');
        }
    }

    // If 'edit-address' wasn't present for some reason, append at the end
    if (!isset($new['favorite-locations'])) {
        $new['favorite-locations'] = __('Favorite Locations', 'musesys');
    }
    if (!isset($new['favorite-products'])) {
        $new['favorite-products'] = __('Favorite Products', 'musesys');
    }

    return $new;
}
// DISABLED: Handled by MuseSys Account Dashboard
// add_filter('woocommerce_account_menu_items', 'musesys_favtabs_account_menu_items', 20, 1);

/**
 * Content renderers for each endpoint.
 * We delegate to your existing shortcodes so you keep a single source of UI truth.
 */

function musesys_favtabs_render_locations() {
    if (!is_user_logged_in()) {
        echo '<p>'.esc_html__('Please sign in to manage favorite locations.', 'musesys').'</p>';
        return;
    }
    // Uses the drop-in I gave you (v1.3). If you renamed, update the shortcode here.
    echo do_shortcode('[musesys_favorite_locations]');
}
// DISABLED: Handled by MuseSys Account Dashboard
// add_action('woocommerce_account_favorite-locations_endpoint', 'musesys_favtabs_render_locations');

function musesys_favtabs_render_products() {
    if (!is_user_logged_in()) {
        echo '<p>'.esc_html__('Please sign in to manage favorite products.', 'musesys').'</p>';
        return;
    }
    // Your existing products favorites shortcode. If your slug differs, change it here.
    echo do_shortcode('[musesys_favorite_products]');
}
// DISABLED: Handled by MuseSys Account Dashboard
// add_action('woocommerce_account_favorite-products_endpoint', 'musesys_favtabs_render_products');

/**
 * Make sure query vars are recognized (defensive for some hosts).
 */
function musesys_favtabs_query_vars($vars) {
    $vars[] = 'favorite-locations';
    $vars[] = 'favorite-products';
    return $vars;
}
// DISABLED: Handled by MuseSys Account Dashboard
// add_filter('query_vars', 'musesys_favtabs_query_vars');
