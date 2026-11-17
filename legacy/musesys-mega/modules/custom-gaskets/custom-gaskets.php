<?php
/**
 * Plugin Name: Muse Custom Gasket Add-To-Cart Handler
 * Description: Handles custom-dimension gasket products (4 styles). Safely scoped. 
 * Version: 1.0.0
 * Author: MuseSystems
 */

if (!defined('ABSPATH')) exit;

/*
=====================================================================
=  SECTION B: PRODUCT IDS FOR THE FOUR GASKET STYLES
=====================================================================
*/

define('MUSE_GASKET_DRAWER',       235182);
define('MUSE_GASKET_UNDERCOUNTER', 235184);
define('MUSE_GASKET_UPRIGHT',      235185);
define('MUSE_GASKET_WALKIN',       235186);

$muse_gasket_ids = [
    MUSE_GASKET_DRAWER,
    MUSE_GASKET_UNDERCOUNTER,
    MUSE_GASKET_UPRIGHT,
    MUSE_GASKET_WALKIN
];

/*
=====================================================================
=  SECTION C: FRONTEND FORM INJECTION ON GASKET PRODUCT PAGES
=====================================================================
*/

add_action('woocommerce_before_add_to_cart_button', function() use ($muse_gasket_ids) {
    global $product;
    if (!in_array($product->get_id(), $muse_gasket_ids)) return;

    ?>
    <div class="muse-gasket-wrapper" style="margin:20px 0;padding:15px;border:1px solid #ccc;border-radius:6px;">
        <h3 style="margin-top:0;">Custom Gasket Specifications</h3>

        <p><label><strong># of Sides *</strong><br>
            <select name="muse_gasket_sides" required>
                <option value="">Select…</option>
                <option value="3">3-Sided</option>
                <option value="4">4-Sided</option>
            </select>
        </label></p>

        <p><label><strong>Left to Right Size (inches) *</strong><br>
            <input type="number" name="muse_gasket_width" min="1" step="0.01" required>
        </label></p>

        <p><label><strong>Top to Bottom Size (inches) *</strong><br>
            <input type="number" name="muse_gasket_height" min="1" step="0.01" required>
        </label></p>

        <p><label><strong>Profile # *</strong><br>
            <input type="text" name="muse_gasket_profile" required>
        </label></p>

        <hr>

        <p><em>Optional, but helpful:</em></p>

        <p><label>Manufacturer<br>
            <input type="text" name="muse_gasket_mfg">
        </label></p>

        <p><label>Model #<br>
            <input type="text" name="muse_gasket_model">
        </label></p>

        <p><label>Serial #<br>
            <input type="text" name="muse_gasket_serial">
        </label></p>
    </div>
    <?php
});

/*
=====================================================================
=  SECTION D: VALIDATION — ONLY FOR THE FOUR GASKET PRODUCTS
=====================================================================
*/

add_filter('woocommerce_add_to_cart_validation', function($valid, $product_id) use ($muse_gasket_ids) {

    if (!in_array($product_id, $muse_gasket_ids)) return $valid;

    $required = [
        'muse_gasket_sides'   => 'Number of sides',
        'muse_gasket_width'   => 'Left-to-Right size',
        'muse_gasket_height'  => 'Top-to-Bottom size',
        'muse_gasket_profile' => 'Profile #'
    ];

    foreach ($required as $field => $label) {
        if (empty($_POST[$field])) {
            wc_add_notice("{$label} is required.", 'error');
            return false;
        }
    }

    return true;
}, 10, 2);

/*
=====================================================================
=  SECTION E: STORE THE CUSTOM DATA
=====================================================================
*/

add_filter('woocommerce_add_cart_item_data', function($cart_item_data, $product_id) use ($muse_gasket_ids) {

    if (!in_array($product_id, $muse_gasket_ids)) return $cart_item_data;

    $fields = [
        'muse_gasket_sides',
        'muse_gasket_width',
        'muse_gasket_height',
        'muse_gasket_profile',
        'muse_gasket_mfg',
        'muse_gasket_model',
        'muse_gasket_serial'
    ];

    foreach ($fields as $field) {
        if (isset($_POST[$field]) && $_POST[$field] !== '') {
            $cart_item_data[$field] = sanitize_text_field($_POST[$field]);
        }
    }

    $cart_item_data['unique_key'] = md5(microtime().rand()); // Prevents merging
    return $cart_item_data;

}, 10, 2);

/*
=====================================================================
=  SECTION F: DISPLAY CUSTOM SPECS INSIDE CART / CHECKOUT
=====================================================================
*/

add_filter('woocommerce_get_item_data', function($data, $cart_item) {

    $map = [
        'muse_gasket_sides'  => '# of Sides',
        'muse_gasket_width'  => 'Width (in)',
        'muse_gasket_height' => 'Height (in)',
        'muse_gasket_profile'=> 'Profile #',
        'muse_gasket_mfg'    => 'Manufacturer',
        'muse_gasket_model'  => 'Model #',
        'muse_gasket_serial' => 'Serial #'
    ];

    foreach ($map as $key => $label) {
        if (!empty($cart_item[$key])) {
            $data[] = [
                'name'  => $label,
                'value' => esc_html($cart_item[$key])
            ];
        }
    }

    return $data;

}, 10, 2);

/*
=====================================================================
=  SECTION G: SAVE DATA INTO ORDER ITEMS
=====================================================================
*/

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $cart_item) {

    $map = [
        'muse_gasket_sides',
        'muse_gasket_width',
        'muse_gasket_height',
        'muse_gasket_profile',
        'muse_gasket_mfg',
        'muse_gasket_model',
        'muse_gasket_serial'
    ];

    foreach ($map as $key) {
        if (!empty($cart_item[$key])) {
            $item->add_meta_data($key, $cart_item[$key]);
        }
    }

}, 10, 3);

/*
=====================================================================
=  SECTION H: WEGMANS 2.0 NOTE
=====================================================================

When migrating to Wegmans2.0:

1. REMOVE section A (site lock block)
2. KEEP everything else exactly as-is
3. Replace the product IDs with the real ones
4. Add CSS into the new theme’s structure instead of OGW RTL.css

=====================================================================
*/
