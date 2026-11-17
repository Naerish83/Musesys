<?php
/**
 * Plugin Name: Muse Order History Enhancements
 * Plugin URI:  https://muse.systems/
 * Description: Adds a 'Reorder' button to the WooCommerce 'My Account -> Orders' page, allowing for quick re-ordering of previous purchases.
 * Version:     1.0.0
 * Author:      Muse Systems
 * License:     GPL-2.0-or-later
 * Text Domain: muse-order-enhancements
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Adds a 'Reorder' button to the My Account > Orders page actions.
 * This button allows customers to quickly add all items from a previous order to their cart.
 *
 * @param array    $actions The existing order actions.
 * @param WC_Order $order   The order object.
 * @return array   $actions The modified order actions.
 */
add_filter( 'woocommerce_my_account_my_orders_actions', 'muse_add_reorder_button', 10, 2 );

function muse_add_reorder_button( $actions, $order ) {
    // We only want to show the reorder button for orders that can be reordered.
    // This typically includes 'completed', 'processing', etc. 
    if ( ! $order->has_status( apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed', 'processing' ) ) ) ) {
        return $actions;
    }

    // Construct the reorder URL.
    // WooCommerce uses the 'order_again' query arg and we wrap it in a nonce for security.
    $reorder_url = wp_nonce_url(
        add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ),
        'woocommerce-order_again'
    );

    // Prepare the 'reorder' action for injection.
    $reorder_action = array(
        'reorder' => array(
            'url'  => $reorder_url,
            'name' => __( 'Reorder', 'woocommerce' ),
        )
    );
    
    // To place it neatly after the 'view' button, we slice the actions array and merge it back together.
    $view_position = array_search( 'view', array_keys( $actions ) );

    if ( $view_position !== false ) {
        // Slice the array at the 'view' button position and insert the 'reorder' action.
        $actions = array_slice( $actions, 0, $view_position + 1, true ) +
                   $reorder_action +
                   array_slice( $actions, $view_position + 1, null, true );
    } else {
        // Fallback in case 'view' isn't there for some reason. Adds it to the beginning.
        $actions = $reorder_action + $actions;
    }

    return $actions;
}