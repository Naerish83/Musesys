<?php
/**
 * Plugin Name: MuseSys Checkout Thumbnails
 * Plugin URI:  https://www.musesystems.com
 * Description: Displays product thumbnails on the WooCommerce checkout page.
 * Version:     1.0.0
 * Author:      Sylva
 * Author URI:  https://www.musesystems.com
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: musesys-checkout-thumbs
 */

// This is a safety line. If someone tries to access this file directly, it stops them cold.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds the product thumbnail to the item name on the WooCommerce checkout page.
 *
 * @param string     $product_name The product name HTML.
 * @param array      $cart_item    The cart item array.
 * @param string     $cart_item_key The cart item key.
 * @return string The product name with the thumbnail prepended.
 */
function musesys_add_checkout_thumbnails($product_name, $cart_item, $cart_item_key) {
    // We only want this to run on the checkout page.
    if (!is_checkout()) {
        return $product_name;
    }

    // Grab the product object from the cart.
    $product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);

    // If we've got a product, let's get its image.
    if ($product && $product->exists() && $cart_item['quantity'] > 0) {
        $thumbnail = $product->get_image(array(50, 50)); // You can change the size here, Boss.

        // And put the image right in front of the name. Simple as that.
        return $thumbnail . $product_name;
    }

    // If something went wrong, just return the name as-is. No harm done.
    return $product_name;
}

// This is the hook that tells the system when to run our function.
add_filter('woocommerce_cart_item_name', 'musesys_add_checkout_thumbnails', 10, 3);